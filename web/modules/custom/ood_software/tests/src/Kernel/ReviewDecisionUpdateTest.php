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
 * Updating a sent decision against the real workflow (appverse-planning#56).
 *
 * The repo, its apps and the review end where the new decision leaves them,
 * through intermediate states where the workflow has no direct transition,
 * without starting an AI run; the earlier decision stays on record; and the
 * update is refused once the contributor has re-submitted.
 *
 * @group ood_software
 */
class ReviewDecisionUpdateTest extends KernelTestBase {

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
      'field.storage.node.field_review_dispatched_at',
      'field.field.node.appverse_repo.field_review_dispatched_at',
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
   * Sends $first on a new single-app repo, then updates it to $second.
   *
   * @return array{0: \Drupal\node\NodeInterface, 1: \Drupal\node\NodeInterface, 2: \Drupal\node\NodeInterface}
   *   The repo, the app and the review.
   */
  protected function sendThenUpdate(string $first, string $second, string $repoState = 'ready_for_review', string $appState = 'ready_for_review'): array {
    $repo = $this->makeRepo($repoState);
    $app = $this->makeApp($repo, $appState);
    $review = $this->makeReview($repo, [$first => $app]);
    $this->assertNull($this->applier()->send($review, 'First response.'));
    $review = $this->reload($review);
    foreach ($review->get('field_arv_verdicts')->referencedEntities() as $verdict) {
      $verdict->set('field_rvv_conclusion', $second)->save();
    }
    $this->assertNull($this->applier()->update($this->reload($review), 'Updated response.'));
    return [$repo, $app, $review];
  }

  /**
   * Changes requested, then accepted with suggestions: back to ready to
   * publish, without starting an AI run.
   */
  public function testChangesRequestedToSuggestions(): void {
    [$repo, $app, $review] = $this->sendThenUpdate('request_changes', 'accept_with_suggestions');
    $this->assertState('ready_for_review', $repo);
    $this->assertState('ready_for_review', $app);
    $this->assertState('in_review', $review);
    $this->assertTrue($this->reload($repo)->get('field_review_dispatched_at')->isEmpty(), 'no AI run was dispatched');

    $sent = ReviewDecisionApplier::sent($this->reload($review));
    $this->assertSame(['accept_with_suggestions'], array_values($sent['apps']));
    $this->assertSame('Updated response.', $sent['response']);
    $this->assertCount(1, $sent['history'], 'the earlier decision stays on record');
    $this->assertSame(['request_changes'], array_values($sent['history'][0]['apps']));

    $mails = $this->mails();
    $this->assertCount(2, $mails, 'the decision, then its update');
    $this->assertStringStartsWith('Updated: ', (string) $mails[1]['subject']);
  }

  /**
   * Changes requested, then declined: needs_adjustment has no direct way to
   * declined, so it goes by ready_for_review.
   */
  public function testChangesRequestedToDeclined(): void {
    [$repo, $app] = $this->sendThenUpdate('request_changes', 'reject');
    $this->assertState('declined', $repo);
    $this->assertState('declined', $app);
    $this->assertTrue($this->reload($repo)->get('field_review_dispatched_at')->isEmpty(), 'passing through ready_for_review started no run');
  }

  /**
   * Accepted, then changes requested: the repo and the review leave the
   * public site.
   */
  public function testAcceptedToChangesRequested(): void {
    [$repo, $app, $review] = $this->sendThenUpdate('accept', 'request_changes');
    $this->assertState('needs_adjustment', $repo);
    $this->assertState('needs_adjustment', $app);
    $this->assertState('in_review', $review);
    $this->assertFalse($this->reload($repo)->isPublished());
    $this->assertFalse($this->reload($review)->isPublished());
  }

  /**
   * Declined, then accepted: declined has no direct way to published.
   */
  public function testDeclinedToAccepted(): void {
    [$repo, $app, $review] = $this->sendThenUpdate('reject', 'accept');
    $this->assertState('published', $repo);
    $this->assertState('published', $app);
    $this->assertState('published', $review);
  }

  /**
   * Once the contributor re-submits, the decision belongs to the new round.
   */
  public function testNoUpdateAfterResubmission(): void {
    $repo = $this->makeRepo('ready_for_review');
    $app = $this->makeApp($repo, 'ready_for_review');
    $review = $this->makeReview($repo, ['request_changes' => $app]);
    $this->assertNull($this->applier()->send($review, 'Please fix.'));
    $this->assertNull($this->applier()->updateBlocker($this->reload($review)), 'updatable until re-submitted');

    $repo = $this->reload($repo);
    $repo->set('field_review_dispatched_at', (int) $this->reload($review)->get('field_arv_decision_sent_at')->value + 60);
    $repo->setSyncing(TRUE);
    $repo->save();
    $this->assertNotNull($this->applier()->updateBlocker($this->reload($review)));
    $this->assertNotNull($this->applier()->update($this->reload($review), 'Too late.'));
    $this->assertCount(1, $this->mails(), 'no update email');
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
