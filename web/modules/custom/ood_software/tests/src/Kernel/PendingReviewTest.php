<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Controller\AppverseHubController;
use Drupal\ood_software\Service\AppverseReviewService;
use Drupal\ood_software\Service\RepoNotificationService;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Entity\View;
use Drupal\views\ResultRow;
use Drupal\views\Views;

/**
 * A published repo with a pending ready_for_review revision (D8-2882).
 *
 * ready_for_review is the one appverse_editorial state that is not a default
 * revision, so sending a published repo for review leaves it live and adds a
 * forward revision. Everything that reads only the default revision (the hub
 * badge, the reviewer queue) used to be blind to it.
 *
 * @group ood_software
 */
class PendingReviewTest extends KernelTestBase {

  use ProdConfigTrait;
  use UserCreationTrait;

  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'options',
    'datetime', 'link', 'taxonomy', 'path', 'path_alias',
    'content_moderation', 'workflows', 'key', 'flag', 'views', 'file', 'ood_software',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('flag', ['flag_counts']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig([
      'system', 'filter', 'user', 'node', 'taxonomy',
      'content_moderation', 'workflows',
    ]);
    $this->importProdConfig([
      // Hub cards and the catalog cache look up each repo's newest review
      // by field_arv_repo, so the review type and that field must exist.
      'node.type.appverse_review',
      'field.storage.node.field_arv_repo',
      'field.field.node.appverse_review.field_arv_repo',
      'node.type.appverse_repo',
      'node.type.appverse_app',
      'workflows.workflow.appverse_editorial',
      'field.storage.node.field_repo_url',
      'field.field.node.appverse_repo.field_repo_url',
      'field.storage.node.field_appverse_repo',
      'field.field.node.appverse_app.field_appverse_repo',
      'field.storage.node.field_repo_validation_st',
      'field.field.node.appverse_repo.field_repo_validation_st',
      'field.storage.node.field_repo_last_synced',
      'field.field.node.appverse_repo.field_repo_last_synced',
      'field.storage.node.field_appverse_software_implemen',
      'field.field.node.appverse_app.field_appverse_software_implemen',
      'field.storage.node.field_appverse_app_subpath',
      'field.field.node.appverse_app.field_appverse_app_subpath',
      // Implementation tags (resolved + unresolved).
      'field.storage.node.field_add_implementation_tags',
      'field.field.node.appverse_app.field_add_implementation_tags',
      // Discovery tags (repo-level, resolved).
      'field.storage.node.field_repo_tags',
      'field.field.node.appverse_repo.field_repo_tags',
    ]);
    // field_repo_shape and field_appverse_unresolved_tags live in module config/install.
    $moduleSource = new FileStorage(
      DRUPAL_ROOT . '/modules/custom/ood_software/config/install'
    );
    $configManager = \Drupal::service('config.manager');
    $etm = \Drupal::entityTypeManager();
    foreach ([
      'field.storage.node.field_repo_shape',
      'field.field.node.appverse_repo.field_repo_shape',
      'field.storage.node.field_appverse_unresolved_tags',
      'field.field.node.appverse_app.field_appverse_unresolved_tags',
    ] as $name) {
      $data = $moduleSource->read($name);
      if ($data === FALSE) {
        throw new \RuntimeException("Missing ood_software install config: $name");
      }
      $storage = $etm->getStorage($configManager->getEntityTypeIdByName($name));
      $idKey = $storage->getEntityType()->getKey('id');
      if (!empty($data[$idKey]) && $storage->load($data[$idKey])) {
        continue;
      }
      assert($storage instanceof ConfigEntityStorageInterface);
      $storage->createFromStorageRecord($data)->save();
    }
    // Vocabularies required by the tag fields.
    Vocabulary::create(['vid' => 'appverse_implementation_tags', 'name' => 'Implementation Tags'])->save();
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();

    \Drupal::moduleHandler()->loadInclude('ood_software', 'module');
    $this->importViewConfigRaw('views.view.my_appverse');

    // Burn uid 1. Drupal's uid-1 superuser bypasses every permission check, so
    // any admin user created in a test would falsely satisfy
    // hasPermission('administer appverse content') regardless of the gate.
    // Reserving uid 1 here makes the permission gate genuinely exercised in
    // every test that creates an "admin" user.
    $this->createUser();
  }

  /**
   * Helper: create a published appverse_repo with the given shape.
   *
   * @param array<string, mixed> $values
   */
  private function makeRepo(array $values = []): NodeInterface {
    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => $values['title'] ?? 'Example Repo',
      'field_repo_shape' => $values['shape'] ?? 'inferred',
      'field_repo_url' => ['uri' => $values['url'] ?? 'https://github.com/example/repo'],
      'field_repo_last_synced' => $values['last_synced'] ?? 1717000000,
      'field_repo_validation_st' => $values['validation_st'] ?? 'valid',
      'moderation_state' => $values['moderation_state'] ?? 'published',
    ]);
    $repo->save();
    return $repo;
  }

  /**
   * Helper: create a member appverse_app under a repo.
   *
   * @param array<string, mixed> $values
   */
  private function makeApp(NodeInterface $repo, array $values = []): NodeInterface {
    $app = Node::create([
      'type' => 'appverse_app',
      'title' => $values['title'] ?? 'Example App',
      'field_appverse_repo' => $repo->id(),
      'field_appverse_app_subpath' => $values['subpath'] ?? 'sub',
      'moderation_state' => $values['moderation_state'] ?? 'published',
    ]);
    $app->save();
    return $app;
  }

  /**
   * Writes the view config raw; see ManageReposFilterDefaultsTest.
   */
  private function importViewConfigRaw(string $name): void {
    $source = new FileStorage(DRUPAL_ROOT . '/sites/default/config/default');
    $data = $source->read($name);
    if ($data === FALSE) {
      throw new \RuntimeException("Missing prod config: $name");
    }
    \Drupal::service('config.storage')->write($name, $data);
  }

  private function storage(): \Drupal\Core\Entity\EntityStorageInterface {
    return $this->container->get('entity_type.manager')->getStorage('node');
  }

  /**
   * Saves a ready_for_review revision on top of the latest one.
   */
  private function sendPending(NodeInterface $node): void {
    $latest = $this->latest($node);
    $latest->set('moderation_state', 'ready_for_review');
    $latest->setNewRevision(TRUE);
    $latest->save();
  }

  private function fresh(NodeInterface $node): NodeInterface {
    $this->storage()->resetCache([$node->id()]);
    $loaded = $this->storage()->load($node->id());
    assert($loaded instanceof NodeInterface);
    return $loaded;
  }

  private function latest(NodeInterface $node): NodeInterface {
    $latest = $this->storage()->loadRevision($this->storage()->getLatestRevisionId($node->id()));
    assert($latest instanceof NodeInterface);
    return $latest;
  }

  private function hasPending(NodeInterface $node): bool {
    return $this->container->get('content_moderation.moderation_information')->hasPendingRevision($this->fresh($node));
  }

  /**
   * Nids the reviewer queue returns for an exposed moderation filter.
   *
   * @param array<int, string> $states
   *   Exposed moderation_state values.
   *
   * @return int[]
   *   Distinct repo nids.
   */
  private function queue(array $states): array {
    $view = Views::getView('my_appverse');
    $view->setDisplay('page_admin');
    $view->setExposedInput(['moderation_state' => $states]);
    $view->execute();
    return array_values(array_unique(array_map(
      static fn (ResultRow $row) => (int) $row->_entity->id(),
      $view->result,
    )));
  }

  private function admin(): void {
    \Drupal::currentUser()->setAccount($this->createUser(['administer appverse content', 'bypass node access']));
  }

  public function testAppSentForReviewUnderPublishedRepoIsQueued(): void {
    $repo = $this->makeRepo(['moderation_state' => 'published']);
    $plain = $this->makeRepo(['title' => 'Plain', 'moderation_state' => 'published']);
    $app = $this->makeApp($repo, ['moderation_state' => 'draft']);

    $app->set('moderation_state', 'ready_for_review');
    $app->setNewRevision(TRUE);
    $app->save();

    $default = $this->fresh($repo);
    $this->assertSame('published', $default->get('moderation_state')->value);
    $this->assertTrue($default->isPublished(), 'The repo must stay live in the catalog while its app is under review.');
    $this->assertTrue($this->hasPending($repo), 'The repo must carry a pending revision.');
    $this->assertSame('ready_for_review', $this->latest($repo)->get('moderation_state')->value);

    $queued = $this->queue(['appverse_editorial-ready_for_review']);
    $this->assertContains((int) $repo->id(), $queued, 'A pending review must reach the reviewer queue.');
    $this->assertNotContains((int) $plain->id(), $queued, 'A plain published repo is not in the queue.');
  }

  public function testQueueFindsDefaultAndPendingButOnlyForTheSelectedState(): void {
    $rfr = $this->makeRepo(['title' => 'Rfr', 'moderation_state' => 'ready_for_review']);
    $draft = $this->makeRepo(['title' => 'Draft', 'moderation_state' => 'draft']);
    $pending = $this->makeRepo(['title' => 'Pending', 'moderation_state' => 'published']);
    $this->sendPending($pending);

    $queued = $this->queue(['appverse_editorial-ready_for_review']);
    $this->assertContains((int) $rfr->id(), $queued);
    $this->assertContains((int) $pending->id(), $queued);
    $this->assertNotContains((int) $draft->id(), $queued);

    // Filtering on another state must not drag the pending repo in.
    $this->assertSame([(int) $draft->id()], $this->queue(['appverse_editorial-draft']));
  }

  /**
   * Only my_appverse widens the filter; other views keep core's behavior.
   *
   * The plugin class is swapped site-wide (access_news, moderated_content and
   * others use moderation_state_filter), so the widening must be scoped by the
   * view or those views list pending revisions they never did (D8-2882).
   */
  public function testWideningIsScopedToMyAppverse(): void {
    $this->admin();
    $pending = $this->makeRepo(['title' => 'Pending', 'moderation_state' => 'published']);
    $this->sendPending($pending);
    $rfr = $this->makeRepo(['title' => 'Rfr', 'moderation_state' => 'ready_for_review']);

    View::create([
      'id' => 'other_moderated',
      'label' => 'Other moderated',
      'base_table' => 'node_field_data',
      'base_field' => 'nid',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_plugin' => 'default',
          'position' => 0,
          'display_options' => [
            'access' => ['type' => 'none'],
            'query' => ['type' => 'views_query', 'options' => []],
            'pager' => ['type' => 'none', 'options' => []],
            'style' => ['type' => 'default'],
            'row' => ['type' => 'fields'],
            'fields' => [
              'nid' => ['id' => 'nid', 'table' => 'node_field_data', 'field' => 'nid', 'plugin_id' => 'field', 'entity_type' => 'node', 'entity_field' => 'nid'],
            ],
            'filters' => [
              'moderation_state' => [
                'id' => 'moderation_state',
                'table' => 'node_field_data',
                'field' => 'moderation_state',
                'entity_type' => 'node',
                'plugin_id' => 'moderation_state_filter',
                'operator' => 'in',
                'value' => ['appverse_editorial-ready_for_review' => 'appverse_editorial-ready_for_review'],
                'group' => 1,
              ],
            ],
          ],
        ],
      ],
    ])->save();

    $view = Views::getView('other_moderated');
    $view->setDisplay('default');
    $view->execute();
    $other = array_map(static fn (ResultRow $row) => (int) $row->nid, $view->result);
    $this->assertContains((int) $rfr->id(), $other, 'Control: the filter works in the other view.');
    $this->assertNotContains((int) $pending->id(), $other, 'A view other than my_appverse must keep core behavior.');

    $this->assertContains((int) $pending->id(), $this->queue(['appverse_editorial-ready_for_review']), 'my_appverse still sees the pending review.');
  }

  public function testQueueDropsRepoOnceItsPendingReviewIsPublished(): void {
    $repo = $this->makeRepo(['moderation_state' => 'published']);
    $this->sendPending($repo);
    $this->assertContains((int) $repo->id(), $this->queue(['appverse_editorial-ready_for_review']));

    $latest = $this->latest($repo);
    $latest->set('moderation_state', 'published');
    $latest->setNewRevision(TRUE);
    $latest->save();
    $this->assertNotContains((int) $repo->id(), $this->queue(['appverse_editorial-ready_for_review']));
  }

  public function testPendingReviewBadgeOnRepo(): void {
    $owner = $this->createUser([], 'contributor');
    \Drupal::currentUser()->setAccount($owner);
    $repo = $this->makeRepo(['moderation_state' => 'published']);
    $repo->setOwnerId($owner->id())->save();

    // Control: no pending revision.
    $hub = _ood_software_build_hub_row($this->fresh($repo));
    $this->assertSame('Published', (string) $hub['status']['label']);
    $this->assertFalse($hub['review_pending']);

    $this->sendPending($repo);
    $hub = _ood_software_build_hub_row($this->fresh($repo));
    $this->assertSame('Published, review pending', (string) $hub['status']['label']);
    $this->assertSame('warning', $hub['status']['modifier']);
    $this->assertTrue($hub['review_pending']);
    $this->assertTrue($hub['published'], 'The repo is still live.');
    $this->assertNull($hub['actions']['send_for_review'], 'No Send for review/Re-submit while a review is pending.');
    $this->assertNull($hub['actions']['send_for_review_inline']);
    $this->assertNull($hub['actions']['publish']);
    $this->assertNull($hub['actions']['request_changes']);
  }

  public function testPendingReviewActionsForAdmin(): void {
    $this->admin();
    $repo = $this->makeRepo(['moderation_state' => 'published']);
    $control = $this->makeRepo(['title' => 'Control', 'moderation_state' => 'published']);

    $hub = _ood_software_build_hub_row($this->fresh($control));
    $this->assertNull($hub['actions']['publish']);
    $this->assertNotNull($hub['actions']['unpublish']);

    $this->sendPending($repo);
    $hub = _ood_software_build_hub_row($this->fresh($repo));
    $this->assertNotNull($hub['actions']['publish'], 'An admin can publish the pending review.');
    $this->assertNotNull($hub['actions']['request_changes']);
    $this->assertNull($hub['actions']['send_for_review']);
  }

  public function testSentBackResubmitStillOfferedWithoutPendingReview(): void {
    // appverse-planning#48: a live repo with an app sent back keeps its
    // Re-submit while no review is pending.
    $owner = $this->createUser([], 'contributor');
    \Drupal::currentUser()->setAccount($owner);
    $repo = $this->makeRepo(['moderation_state' => 'published']);
    $repo->setOwnerId($owner->id())->save();
    $this->makeApp($repo, ['moderation_state' => 'needs_adjustment']);
    $hub = _ood_software_build_hub_row($this->fresh($repo));
    $this->assertNotNull($hub['actions']['send_for_review']);
  }

  public function testPendingReviewBadgeOnMemberApp(): void {
    $repo = $this->makeRepo(['moderation_state' => 'published']);
    $a = $this->makeApp($repo, ['title' => 'a', 'subpath' => 'a', 'moderation_state' => 'published']);
    $this->makeApp($repo, ['title' => 'b', 'subpath' => 'b', 'moderation_state' => 'published']);
    $this->sendPending($a);

    $hub = _ood_software_build_hub_row($this->fresh($repo));
    $byTitle = [];
    foreach ($hub['apps'] as $row) {
      $byTitle[$row['title']] = $row;
    }
    $this->assertSame('Published, review pending', (string) $byTitle['a']['status']['label']);
    $this->assertTrue($byTitle['a']['published']);
    $this->assertSame('Published', (string) $byTitle['b']['status']['label']);
  }

  public function testAdminPublishMakesPendingRevisionTheDefault(): void {
    $this->admin();
    $repo = $this->makeRepo(['title' => 'Live title', 'moderation_state' => 'published']);
    $latest = $this->latest($repo);
    $latest->set('title', 'Pending title');
    $latest->set('moderation_state', 'ready_for_review');
    $latest->setNewRevision(TRUE);
    $latest->save();
    $this->assertSame('Live title', $this->fresh($repo)->label());
    $this->assertTrue($this->hasPending($repo));

    \Drupal::classResolver(AppverseHubController::class)->adminPublish($this->fresh($repo));

    $after = $this->fresh($repo);
    $this->assertSame('Pending title', $after->label(), 'The pending content becomes the live content.');
    $this->assertSame('published', $after->get('moderation_state')->value);
    $this->assertTrue($after->isPublished());
    $this->assertFalse($this->hasPending($repo));
  }

  public function testRepoAutoSentForReviewNotifiesAndDispatchesOnce(): void {
    $calls = ['notify' => [], 'dispatch' => []];
    $notifier = $this->createMock(RepoNotificationService::class);
    $notifier->method('notifyTransition')->willReturnCallback(function (NodeInterface $n, ?string $prev) use (&$calls) {
      $calls['notify'][] = [$n->get('moderation_state')->value, $prev];
    });
    $reviews = $this->createMock(AppverseReviewService::class);
    $reviews->method('onTransition')->willReturnCallback(function (NodeInterface $n, ?string $prev) use (&$calls) {
      $calls['dispatch'][] = [$n->get('moderation_state')->value, $prev];
    });
    $this->container->set('ood_software.repo_notifier', $notifier);
    $this->container->set('ood_software.review_dispatcher', $reviews);

    $repo = $this->makeRepo(['moderation_state' => 'published']);
    $app = $this->makeApp($repo, ['moderation_state' => 'draft']);
    $calls = ['notify' => [], 'dispatch' => []];

    $app->set('moderation_state', 'ready_for_review');
    $app->setNewRevision(TRUE);
    $app->save();

    $this->assertSame([['ready_for_review', 'published']], $calls['notify'], 'Reviewers are told exactly once.');
    $this->assertSame([['ready_for_review', 'published']], $calls['dispatch'], 'The AI run is dispatched exactly once.');

    // A second app sent for review finds the review already pending: no
    // second revision, no second notification, no second dispatch.
    $revisions = count($this->storage()->revisionIds($this->fresh($repo)));
    $second = $this->makeApp($repo, ['title' => 'two', 'subpath' => 'two', 'moderation_state' => 'draft']);
    $calls = ['notify' => [], 'dispatch' => []];
    $second->set('moderation_state', 'ready_for_review');
    $second->setNewRevision(TRUE);
    $second->save();
    $this->assertCount($revisions, $this->storage()->revisionIds($this->fresh($repo)));
    $this->assertSame([], $calls['notify']);
    $this->assertSame([], $calls['dispatch']);
  }

  /**
   * Saving an already-pending revision again does not re-notify (D8-2882).
   *
   * Core sets $node->original to the default revision, so a second save of the
   * pending revision (an admin editing the latest revision) used to report
   * "published" as the previous state and send the reviewer email and AI run
   * again.
   */
  public function testResavingPendingRevisionDoesNotRenotify(): void {
    $calls = ['notify' => [], 'dispatch' => []];
    $notifier = $this->createMock(RepoNotificationService::class);
    $notifier->method('notifyTransition')->willReturnCallback(function (NodeInterface $n, ?string $prev) use (&$calls) {
      $calls['notify'][] = [$n->get('moderation_state')->value, $prev];
    });
    $reviews = $this->createMock(AppverseReviewService::class);
    $reviews->method('onTransition')->willReturnCallback(function (NodeInterface $n, ?string $prev) use (&$calls) {
      $calls['dispatch'][] = [$n->get('moderation_state')->value, $prev];
    });
    $this->container->set('ood_software.repo_notifier', $notifier);
    $this->container->set('ood_software.review_dispatcher', $reviews);

    $repo = $this->makeRepo(['moderation_state' => 'published']);
    $calls = ['notify' => [], 'dispatch' => []];

    $this->sendPending($repo);
    $this->assertSame([['ready_for_review', 'published']], $calls['notify'], 'The first submission notifies once.');
    $this->assertSame([['ready_for_review', 'published']], $calls['dispatch'], 'The first submission dispatches once.');

    $calls = ['notify' => [], 'dispatch' => []];
    $latest = $this->latest($repo);
    $latest->set('title', 'Edited while pending');
    $latest->set('moderation_state', 'ready_for_review');
    $latest->setNewRevision(TRUE);
    $latest->save();

    // The real services act only when the state changes, so a previous state
    // equal to the new one (ready_for_review) is a no-op for both.
    $this->assertSame([['ready_for_review', 'ready_for_review']], $calls['notify'], 'The pending revision is not reported as coming from published.');
    $this->assertSame([['ready_for_review', 'ready_for_review']], $calls['dispatch']);
    $this->assertTrue($this->hasPending($repo));
    $this->assertSame('Edited while pending', $this->latest($repo)->label());
  }

}
