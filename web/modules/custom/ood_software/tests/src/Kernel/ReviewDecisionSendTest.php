<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Controller\AppverseHubController;
use Drupal\ood_software\Service\ReviewDecisionApplier;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;

/**
 * Sending a decision against the real workflow (appverse-planning#50).
 *
 * Each app ends in its own state, a decision goes out once, never on a
 * superseded review, and the hub's repo routes refuse a reviewed repo.
 *
 * @group ood_software
 */
class ReviewDecisionSendTest extends KernelTestBase {

  use AssertMailTrait;
  use ProdConfigTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'options',
    'datetime', 'link', 'taxonomy', 'path', 'path_alias', 'file',
    'content_moderation', 'workflows', 'key', 'flag',
    'entity_reference_revisions', 'paragraphs', 'ood_software',
  ];

  /**
   * The repo's owner, the contributor.
   */
  protected UserInterface $owner;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'user', 'node', 'content_moderation', 'workflows']);
    $this->importProdConfig([
      'node.type.appverse_repo',
      'node.type.appverse_app',
      'node.type.appverse_review',
      'paragraphs.paragraphs_type.review_verdict',
      'workflows.workflow.appverse_editorial',
      'workflows.workflow.appverse_review_workflow',
      'field.storage.node.field_appverse_repo',
      'field.field.node.appverse_app.field_appverse_repo',
      'field.storage.node.field_arv_repo',
      'field.field.node.appverse_review.field_arv_repo',
      'field.storage.node.field_arv_decision_sent_at',
      'field.field.node.appverse_review.field_arv_decision_sent_at',
      'field.storage.node.field_arv_sent_decisions',
      'field.field.node.appverse_review.field_arv_sent_decisions',
      'field.storage.node.field_arv_decision_sent_by',
      'field.field.node.appverse_review.field_arv_decision_sent_by',
      'field.storage.node.field_arv_withdrawn_at',
      'field.field.node.appverse_review.field_arv_withdrawn_at',
      'field.storage.node.field_arv_verdicts',
      'field.field.node.appverse_review.field_arv_verdicts',
      'field.storage.paragraph.field_rvv_app_ref',
      'field.field.paragraph.review_verdict.field_rvv_app_ref',
      'field.storage.paragraph.field_rvv_app_id',
      'field.field.paragraph.review_verdict.field_rvv_app_id',
      'field.storage.paragraph.field_rvv_conclusion',
      'field.field.paragraph.review_verdict.field_rvv_conclusion',
    ]);
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('system.site')->set('name', 'AppVerse')->set('mail', 'site@example.org')->save();

    // User 1 bypasses access checks, so take that id first.
    $this->createUser([], 'admin');
    $this->owner = $this->createUser([], 'owner');
    $reviewer = $this->createUser(['administer appverse content'], 'reviewer');
    $this->assertInstanceOf(AccountInterface::class, $reviewer);
    $this->setCurrentUser($reviewer);
  }

  /**
   * Each decision on a new single-app repo, the app moving with its repo.
   *
   * @dataProvider newRepoCases
   */
  public function testDecisionOnANewRepo(string $decision, string $repoState, string $appState, string $reviewState): void {
    $repo = $this->makeRepo('ready_for_review');
    $app = $this->makeApp($repo, 'ready_for_review');
    $review = $this->makeReview($repo, [$decision => $app]);

    $this->assertNull($this->applier()->send($review, 'Thanks for the submission.'));

    $this->assertState($repoState, $repo);
    $this->assertState($appState, $app);
    $this->assertState($reviewState, $review);
    $this->assertFalse($this->reload($review)->get('field_arv_decision_sent_at')->isEmpty());
    // What was sent is stored, for Publish and the page (appverse-planning#51).
    $sent = ReviewDecisionApplier::sent($this->reload($review));
    $this->assertSame([$decision], array_values($sent['apps']));
    $this->assertSame('Thanks for the submission.', $sent['response']);
    $this->assertCount(1, $this->mails(), 'One decision email.');
  }

  /**
   * Decision => repo, app and review states after sending.
   *
   * @return array<string, array<int, string>>
   */
  public static function newRepoCases(): array {
    return [
      'accept' => ['accept', 'published', 'published', 'published'],
      // The app waits for the review page's Publish.
      'accept with suggestions' => ['accept_with_suggestions', 'ready_for_review', 'ready_for_review', 'in_review'],
      // The app goes back with its repo, not left awaiting review.
      'request changes' => ['request_changes', 'needs_adjustment', 'needs_adjustment', 'in_review'],
      'reject' => ['reject', 'declined', 'declined', 'in_review'],
    ];
  }

  /**
   * Request changes on a live repo takes it and its apps out of the catalog.
   */
  public function testRequestChangesOnALiveRepo(): void {
    $repo = $this->makeRepo('published');
    $app = $this->makeApp($repo, 'published');
    $review = $this->makeReview($repo, ['request_changes' => $app]);

    $this->assertNull($this->applier()->send($review, 'Please fix the form.'));

    $this->assertState('needs_adjustment', $repo);
    $this->assertState('needs_adjustment', $app);
    $this->assertFalse($this->reload($app)->isPublished());
  }

  /**
   * In a monorepo each app goes its own way, and the repo goes live with one.
   */
  public function testMixedMonorepo(): void {
    $repo = $this->makeRepo('ready_for_review');
    $accepted = $this->makeApp($repo, 'ready_for_review', 'Jupyter');
    $sentBack = $this->makeApp($repo, 'ready_for_review', 'RStudio');
    $review = $this->makeReview($repo, ['accept' => $accepted, 'request_changes' => $sentBack]);

    $this->assertNull($this->applier()->send($review, 'RStudio needs a LICENSE.'));

    $this->assertState('published', $repo);
    $this->assertState('published', $accepted);
    $this->assertState('needs_adjustment', $sentBack);
  }

  /**
   * A second send, as a double submit makes, sends nothing more.
   */
  public function testASecondSendIsRefused(): void {
    $repo = $this->makeRepo('ready_for_review');
    $review = $this->makeReview($repo, ['request_changes' => $this->makeApp($repo, 'ready_for_review')]);

    $this->assertNull($this->applier()->send($review, 'Please fix the form.'));
    // The form holds the review as it was loaded, before the first send.
    $this->assertNotNull($this->applier()->send($review, 'Please fix the form.'));
    $this->assertCount(1, $this->mails(), 'Still one decision email.');
  }

  /**
   * No decision goes out on a review a newer one has superseded.
   */
  public function testNoDecisionOnASupersededReview(): void {
    $repo = $this->makeRepo('ready_for_review');
    $app = $this->makeApp($repo, 'ready_for_review');
    $old = $this->makeReview($repo, ['accept' => $app], \Drupal::time()->getRequestTime() - 3600);
    $this->makeReview($repo, ['request_changes' => $app]);

    $this->assertNotNull($this->applier()->decisionBlocker($old));
    $this->assertNotNull($this->applier()->send($old, ''));
    $this->assertState('ready_for_review', $repo);
    $this->assertTrue($this->reload($old)->get('field_arv_decision_sent_at')->isEmpty());
    $this->assertCount(0, $this->mails());
  }

  /**
   * The hub's repo Publish and Request changes refuse a reviewed repo.
   */
  public function testHubRepoRoutesRefuseAReviewedRepo(): void {
    $controller = AppverseHubController::create(\Drupal::getContainer());
    $reviewer = \Drupal::currentUser();
    $legacy = $this->makeRepo('ready_for_review');
    $reviewed = $this->makeRepo('ready_for_review');
    $this->makeReview($reviewed, []);

    $this->assertTrue($controller->adminWithoutReviewAccess($reviewer, $legacy)->isAllowed());
    $this->assertTrue($controller->adminWithoutReviewAccess($reviewer, $reviewed)->isForbidden());
    $this->assertFalse($controller->adminWithoutReviewAccess($this->owner, $legacy)->isAllowed());
  }

  /**
   * The decision applier.
   */
  protected function applier(): ReviewDecisionApplier {
    $applier = \Drupal::service('ood_software.review_decision_applier');
    assert($applier instanceof ReviewDecisionApplier);
    return $applier;
  }

  /**
   * Asserts a node's moderation state, read fresh.
   */
  protected function assertState(string $expected, NodeInterface $node): void {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $latest = $storage->loadRevision($storage->getLatestRevisionId($node->id()));
    $this->assertInstanceOf(NodeInterface::class, $latest);
    $this->assertSame($expected, $latest->get('moderation_state')->value, $node->bundle() . ' ' . $node->label());
  }

  /**
   * A node loaded fresh.
   */
  protected function reload(NodeInterface $node): NodeInterface {
    $fresh = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
    $this->assertInstanceOf(NodeInterface::class, $fresh);
    return $fresh;
  }

  /**
   * The decision emails collected so far.
   *
   * @return array<int, array<string, mixed>>
   */
  protected function mails(): array {
    return array_values(array_filter(
      $this->getMails(),
      fn (array $mail): bool => ($mail['key'] ?? '') === 'review_decision',
    ));
  }

  /**
   * Creates a repo owned by the contributor.
   */
  protected function makeRepo(string $state): NodeInterface {
    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => 'example/repo',
      'uid' => $this->owner->id(),
      'moderation_state' => $state,
    ]);
    $repo->save();
    return $repo;
  }

  /**
   * Creates a member app of a repo.
   */
  protected function makeApp(NodeInterface $repo, string $state, string $title = 'Example App'): NodeInterface {
    $app = Node::create([
      'type' => 'appverse_app',
      'title' => $title,
      'uid' => $this->owner->id(),
      'field_appverse_repo' => $repo->id(),
      'moderation_state' => $state,
    ]);
    $app->save();
    return $app;
  }

  /**
   * Creates an undecided review with a verdict per app.
   *
   * @param \Drupal\node\NodeInterface $repo
   *   The reviewed repo.
   * @param array<string, \Drupal\node\NodeInterface> $verdicts
   *   Decision => the app it decides.
   * @param int|null $created
   *   When the review was created.
   */
  protected function makeReview(NodeInterface $repo, array $verdicts, ?int $created = NULL): NodeInterface {
    $paragraphs = [];
    foreach ($verdicts as $decision => $app) {
      $paragraph = Paragraph::create([
        'type' => 'review_verdict',
        'field_rvv_app_ref' => $app->id(),
        'field_rvv_app_id' => $app->label(),
        'field_rvv_conclusion' => $decision,
      ]);
      $paragraph->save();
      $paragraphs[] = $paragraph;
    }
    $review = Node::create([
      'type' => 'appverse_review',
      'title' => 'Review: ' . $repo->label(),
      'field_arv_repo' => $repo->id(),
      'field_arv_verdicts' => $paragraphs,
      'moderation_state' => 'draft',
      'created' => $created ?? \Drupal::time()->getRequestTime(),
    ]);
    $review->save();
    return $review;
  }

}
