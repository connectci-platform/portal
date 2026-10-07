<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Controller\AppverseHubController;
use Drupal\ood_software\Service\AppverseReviewService;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;

/**
 * Re-submitting a live repo whose apps were sent back (appverse-planning#48).
 *
 * After a mixed decision the repo is live with some apps sent back. The
 * contributor re-submits the whole repo: a new AI review starts, the apps
 * sent back return to Ready for review, and the rest stays live.
 *
 * @group ood_software
 */
class LiveResubmitTest extends KernelTestBase {

  use AssertMailTrait;
  use ProdConfigTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'options',
    'datetime', 'link', 'taxonomy', 'path', 'path_alias', 'file',
    'content_moderation', 'workflows', 'key', 'flag', 'ood_software',
  ];

  /**
   * The repo's owner, the contributor.
   */
  protected UserInterface $owner;

  /**
   * Repos the mocked review service was asked to dispatch.
   *
   * @var array<int, int|string|null>
   */
  protected array $dispatched = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'filter', 'user', 'node', 'content_moderation', 'workflows']);
    $this->importProdConfig([
      'node.type.appverse_repo',
      'node.type.appverse_app',
      'node.type.appverse_review',
      'workflows.workflow.appverse_editorial',
      'field.storage.node.field_arv_repo',
      'field.field.node.appverse_review.field_arv_repo',
      'field.storage.node.field_arv_decision_sent_at',
      'field.field.node.appverse_review.field_arv_decision_sent_at',
      'field.storage.node.field_appverse_repo',
      'field.field.node.appverse_app.field_appverse_repo',
      'field.storage.node.field_review_status',
      'field.field.node.appverse_repo.field_review_status',
    ]);
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('system.site')->set('name', 'Appverse')->set('mail', 'site@example.org')->save();
    // User 1 bypasses access checks, so take that id first.
    $this->createUser([], 'admin');
    $this->createUser(['administer appverse content'], 'reviewer', FALSE, ['mail' => 'reviewer@example.com']);
    $this->owner = $this->createUser([], 'owner', FALSE, ['mail' => 'owner@example.com']);
    $this->setCurrentUser($this->owner);
  }

  /**
   * The whole repo is reviewed again; only the apps sent back move.
   */
  public function testResubmitLiveRepo(): void {
    [$repo, $accepted, $sentBack] = $this->liveMonorepo();

    $this->controller(TRUE)->sendForReview($repo);

    $this->assertSame([$repo->id()], $this->dispatched);
    $this->assertState('published', $repo);
    $this->assertState('published', $accepted);
    $this->assertState('ready_for_review', $sentBack);
    $this->assertCount(1, array_filter($this->getMails(), fn ($m) => $m['key'] === 'resubmitted' && $m['to'] === 'reviewer@example.com'), 'The reviewers are told.');
  }

  /**
   * When the run can't start, nothing moves and nobody is told.
   */
  public function testNothingMovesWhenTheRunCannotStart(): void {
    [$repo, , $sentBack] = $this->liveMonorepo();

    $this->controller(FALSE)->sendForReview($repo);

    $this->assertState('needs_adjustment', $sentBack);
    $this->assertSame([], $this->getMails());
  }

  /**
   * A live repo with nothing sent back has nothing to re-submit.
   */
  public function testNothingToResubmit(): void {
    $repo = $this->makeRepo('published');
    $this->makeApp($repo, 'published');

    $this->controller(TRUE)->sendForReview($repo);

    $this->assertSame([], $this->dispatched);
  }

  /**
   * A run already in flight is not started twice.
   */
  public function testNotWhileARunIsInFlight(): void {
    [$repo, , $sentBack] = $this->liveMonorepo();
    $repo->set('field_review_status', 'in_progress')->save();

    $this->controller(TRUE)->sendForReview($repo);

    $this->assertSame([], $this->dispatched);
    $this->assertState('needs_adjustment', $sentBack);
  }

  /**
   * A new repo with one app accepted with suggestions and one sent back
   * waits for the reviewer's Publish; the contributor can still re-submit.
   */
  public function testResubmitRepoAwaitingPublish(): void {
    $repo = $this->makeRepo('ready_for_review');
    $suggested = $this->makeApp($repo, 'ready_for_review');
    $sentBack = $this->makeApp($repo, 'needs_adjustment');
    $this->assertNotEmpty(_ood_software_hub_repo_actions($repo, FALSE, 'ready_for_review', FALSE)['send_for_review'], 'the hub offers Re-submit');

    $this->controller(TRUE)->sendForReview($repo);

    $this->assertSame([$repo->id()], $this->dispatched);
    $this->assertState('ready_for_review', $repo);
    $this->assertState('ready_for_review', $suggested);
    $this->assertState('ready_for_review', $sentBack);
  }

  /**
   * A contributor can take their app down but not put one up: the review
   * sent this one back.
   */
  public function testContributorCannotPublishAnApp(): void {
    [, , $sentBack] = $this->liveMonorepo();
    $this->controller(TRUE)->toggleAppPublish($sentBack);
    $this->assertState('needs_adjustment', $sentBack);

    [, $live] = $this->liveMonorepo();
    $this->controller(TRUE)->toggleAppPublish($live);
    $this->assertState('draft', $live);
  }

  /**
   * The contributor's hub card offers Re-submit only with an app sent back.
   */
  public function testHubOffersResubmit(): void {
    [$repo] = $this->liveMonorepo();
    $actions = _ood_software_hub_repo_actions($repo, FALSE, 'published', TRUE);
    $this->assertNotEmpty($actions['send_for_review']);

    $clean = $this->makeRepo('published');
    $this->makeApp($clean, 'published');
    $this->assertEmpty(_ood_software_hub_repo_actions($clean, FALSE, 'published', TRUE)['send_for_review']);
  }

  /**
   * The hub controller, with a review service whose dispatch returns $ok.
   */
  protected function controller(bool $ok): AppverseHubController {
    $reviews = $this->createMock(AppverseReviewService::class);
    $reviews->method('dispatchForNode')->willReturnCallback(function (NodeInterface $node) use ($ok) {
      if ($ok) {
        $this->dispatched[] = $node->id();
      }
      return $ok;
    });
    $this->container->set('ood_software.review_dispatcher', $reviews);
    return AppverseHubController::create($this->container);
  }

  /**
   * A live monorepo after a mixed decision: one app live, one sent back.
   *
   * @return array{0: \Drupal\node\NodeInterface, 1: \Drupal\node\NodeInterface, 2: \Drupal\node\NodeInterface}
   */
  protected function liveMonorepo(): array {
    $repo = $this->makeRepo('published');
    return [$repo, $this->makeApp($repo, 'published'), $this->makeApp($repo, 'needs_adjustment')];
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
   * Creates a repo owned by the contributor.
   */
  protected function makeRepo(string $state): NodeInterface {
    $repo = Node::create(['type' => 'appverse_repo', 'title' => 'example/mono', 'uid' => $this->owner->id(), 'moderation_state' => $state]);
    $repo->save();
    return $repo;
  }

  /**
   * Creates a member app of a repo.
   */
  protected function makeApp(NodeInterface $repo, string $state): NodeInterface {
    $app = Node::create([
      'type' => 'appverse_app',
      'title' => 'App ' . $state,
      'uid' => $this->owner->id(),
      'field_appverse_repo' => $repo->id(),
      'moderation_state' => $state,
    ]);
    $app->save();
    return $app;
  }

}
