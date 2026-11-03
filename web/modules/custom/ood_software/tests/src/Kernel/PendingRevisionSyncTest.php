<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\node\NodeStorageInterface;
use Drupal\ood_software\Plugin\GitHubService;
use Drupal\ood_software\Plugin\QueueWorker\AppverseAppUpdater;
use Drupal\ood_software\Service\AppverseReviewService;
use Drupal\ood_software\Service\RepoSyncService;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\user\Entity\User;

/**
 * A sync must not swallow a review that is pending on a published node.
 *
 * The ready_for_review state is the one workflow state that is not a default
 * revision, so a published repo or app sent for review keeps its live default
 * revision and gains a forward (pending) revision. RepoSyncService and the cron updater
 * load the default revision and save it, which used to make that save the
 * newest revision and silently drop the pending review. A sync now writes its
 * values to the default revision (the catalog stays current) and then to the
 * pending revision, leaving the review pending, without re-sending review
 * email or re-dispatching an AI run.
 *
 * @group ood_software
 */
class PendingRevisionSyncTest extends KernelTestBase {

  use AssertMailTrait;
  use ProdConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'options',
    'datetime',
    'link',
    'taxonomy',
    'path',
    'path_alias',
    'content_moderation',
    'workflows',
    'key',
    'flag',
    'domain',
    'file', 'ood_software',
  ];

  /**
   * Canonical repo URL used throughout this test class.
   */
  protected string $repoUrl = 'https://github.com/OSC/bc_osc_rstudio';

  /**
   * Node ids the review dispatcher was asked to consider.
   *
   * @var int[]
   */
  protected array $dispatched = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('flagging');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('flag', ['flag_counts']);
    $this->installConfig([
      'system', 'filter', 'user', 'node', 'taxonomy',
      'content_moderation', 'workflows',
    ]);
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('system.site')->set('name', 'Appverse')->set('mail', 'site@example.org')->save();

    $this->importProdConfig([
      'node.type.appverse_repo',
      'node.type.appverse_app',
      'workflows.workflow.appverse_editorial',
      'field.storage.node.field_repo_url',
      'field.field.node.appverse_repo.field_repo_url',
      'field.storage.node.field_repo_description',
      'field.field.node.appverse_repo.field_repo_description',
      'field.storage.node.field_repo_maintainer_name',
      'field.field.node.appverse_repo.field_repo_maintainer_name',
      'field.storage.node.field_repo_validation_st',
      'field.field.node.appverse_repo.field_repo_validation_st',
      'field.storage.node.field_repo_validation_er',
      'field.field.node.appverse_repo.field_repo_validation_er',
      'field.storage.node.field_repo_last_synced',
      'field.field.node.appverse_repo.field_repo_last_synced',
      'field.storage.node.field_repo_stars',
      'field.field.node.appverse_repo.field_repo_stars',
      'field.storage.node.field_repo_last_commit',
      'field.field.node.appverse_repo.field_repo_last_commit',
      'field.storage.node.field_repo_organization',
      'field.field.node.appverse_repo.field_repo_organization',
      'field.storage.node.field_repo_www_url',
      'field.field.node.appverse_repo.field_repo_www_url',
      'field.storage.node.field_repo_readme',
      'field.field.node.appverse_repo.field_repo_readme',
      'field.field.node.appverse_app.body',
      'field.storage.node.field_appverse_repo',
      'field.field.node.appverse_app.field_appverse_repo',
      'field.storage.node.field_appverse_github_url',
      'field.field.node.appverse_app.field_appverse_github_url',
      'field.storage.node.field_appverse_app_subpath',
      'field.field.node.appverse_app.field_appverse_app_subpath',
      'field.storage.node.field_appverse_organization',
      'field.field.node.appverse_app.field_appverse_organization',
      'field.storage.node.field_appverse_stars',
      'field.field.node.appverse_app.field_appverse_stars',
      'field.storage.node.field_appverse_lastupdated',
      'field.field.node.appverse_app.field_appverse_lastupdated',
      'field.storage.node.field_appverse_readme',
      'field.field.node.appverse_app.field_appverse_readme',
      'field.storage.node.field_appverse_app_type',
      'field.field.node.appverse_app.field_appverse_app_type',
      'field.storage.node.field_appverse_license_link',
      'field.field.node.appverse_app.field_appverse_license_link',
      'field.storage.node.field_appverse_app_validation_st',
      'field.field.node.appverse_app.field_appverse_app_validation_st',
      'field.storage.node.field_domain_access',
      'field.field.node.appverse_app.field_domain_access',
      'taxonomy.vocabulary.appverse_organization',
    ]);

    $moduleSource = new FileStorage(
      DRUPAL_ROOT . '/modules/custom/ood_software/config/install'
    );
    $configManager = \Drupal::service('config.manager');
    $entityTypeManager = \Drupal::entityTypeManager();
    foreach ([
      'field.storage.node.field_repo_shape',
      'field.field.node.appverse_repo.field_repo_shape',
    ] as $name) {
      $data = $moduleSource->read($name);
      $storage = $entityTypeManager->getStorage($configManager->getEntityTypeIdByName($name));
      $idKey = $storage->getEntityType()->getKey('id');
      if (!($data[$idKey] ?? NULL) || !$storage->load($data[$idKey])) {
        assert($storage instanceof ConfigEntityStorageInterface);
        $storage->createFromStorageRecord($data)->save();
      }
    }

    // A reviewer who would be mailed if a sync re-sent the review.
    $this->createUserWithMail('admin@example.com');
    $this->createUserWithMail('reviewer@example.com');

    // Count review dispatches: a sync is not a contributor submitting.
    $reviews = $this->createMock(AppverseReviewService::class);
    $reviews->method('onTransition')->willReturnCallback(function (NodeInterface $node): void {
      $this->dispatched[] = (int) $node->id();
    });
    $this->container->set('ood_software.review_dispatcher', $reviews);
  }

  /**
   * Creates a user with an email address.
   */
  protected function createUserWithMail(string $mail): void {
    User::create(['name' => $mail, 'mail' => $mail, 'status' => 1])->save();
  }

  /**
   * The node storage.
   */
  protected function nodeStorage(): NodeStorageInterface {
    return \Drupal::entityTypeManager()->getStorage('node');
  }

  /**
   * A published repo.
   */
  protected function publishedRepo(): NodeInterface {
    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => 'bc_osc_rstudio',
      'field_repo_url' => ['uri' => $this->repoUrl],
      'field_repo_shape' => 'inferred',
      'field_repo_description' => 'OLD DESC',
      'moderation_state' => 'published',
    ]);
    $repo->save();
    return $repo;
  }

  /**
   * A published, repo-root app.
   */
  protected function publishedApp(NodeInterface $repo): NodeInterface {
    $app = Node::create([
      'type' => 'appverse_app',
      'title' => 'RStudio',
      'field_appverse_repo' => $repo->id(),
      'field_appverse_github_url' => ['uri' => $this->repoUrl],
      'body' => [['format' => 'markdown', 'value' => 'OLD BODY']],
      'field_appverse_lastupdated' => [['value' => '1000']],
      'moderation_state' => 'published',
    ]);
    $app->save();
    return $app;
  }

  /**
   * Sends a published node for review, leaving a pending revision.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The published node.
   * @param array<string, mixed> $edits
   *   Field values the pending revision carries on its own.
   */
  protected function sendForReview(NodeInterface $node, array $edits = []): void {
    $pending = $this->nodeStorage()->loadUnchanged($node->id());
    foreach ($edits as $field => $value) {
      $pending->set($field, $value);
    }
    $pending->set('moderation_state', 'ready_for_review');
    $pending->setNewRevision(TRUE);
    // The submission itself is not under test: keep its mail and dispatch out.
    $pending->_ood_software_suppress_notifications = TRUE;
    $pending->save();

    $this->resetSideEffects();
    $default = $this->nodeStorage()->loadUnchanged($node->id());
    $moderation = $this->container->get('content_moderation.moderation_information');
    self::assertTrue($moderation->hasPendingRevision($default), 'Precondition: a review is pending.');
    self::assertTrue($default->isPublished(), 'Precondition: the default revision is live.');
  }

  /**
   * Forgets mail and dispatches so far.
   */
  protected function resetSideEffects(): void {
    $this->dispatched = [];
    \Drupal::state()->set('system.test_mail_collector', []);
  }

  /**
   * Asserts the review is still pending and both revisions carry the value.
   *
   * @param int $nid
   *   The node id.
   * @param string $field
   *   The synced field.
   * @param string $property
   *   The field property compared.
   * @param mixed $expected
   *   The expected value of the property.
   */
  protected function assertReviewKept(int $nid, string $field, string $property, $expected): void {
    $storage = $this->nodeStorage();
    $storage->resetCache();
    $default = $storage->load($nid);
    /** @var \Drupal\node\NodeInterface $latest */
    $latest = $storage->loadRevision($storage->getLatestRevisionId($nid));
    $moderation = $this->container->get('content_moderation.moderation_information');

    self::assertTrue($moderation->hasPendingRevision($default), 'The review is still pending after the sync.');
    self::assertSame('ready_for_review', $latest->get('moderation_state')->value, 'The newest revision is still Ready for review.');
    self::assertSame('published', $default->get('moderation_state')->value, 'The default revision is still published.');
    self::assertTrue($default->isPublished(), 'The live default revision stays published.');
    self::assertSame($expected, $default->get($field)->$property, 'The catalog (default revision) got the synced value.');
    self::assertSame($expected, $latest->get($field)->$property, 'The pending revision got the synced value too.');
    self::assertSame([], $this->dispatched, 'A sync does not dispatch an AI review.');
    self::assertSame([], $this->getMails(), 'A sync does not email anyone about the review.');
  }

  /**
   * An inferred repo resync keeps a pending review.
   */
  public function testInferredRepoResyncKeepsPendingReview(): void {
    $repo = $this->publishedRepo();
    $this->sendForReview($repo, ['field_repo_www_url' => ['uri' => 'https://example.org/pending-edit']]);

    $this->container->get('ood_software.repo_sync')->resolveRepo($this->repoUrl, NULL, [
      'name' => 'bc_osc_rstudio',
      'description' => 'NEW DESC',
      'stars' => 42,
    ]);

    $this->assertReviewKept((int) $repo->id(), 'field_repo_description', 'value', 'NEW DESC');
    /** @var \Drupal\node\NodeInterface $latest */
    $latest = $this->nodeStorage()->loadRevision($this->nodeStorage()->getLatestRevisionId($repo->id()));
    self::assertSame('https://example.org/pending-edit', $latest->get('field_repo_www_url')->getValue()[0]['uri'], 'Edits made only in the pending revision survive the sync.');
  }

  /**
   * An inferred member-app sync keeps a pending review.
   */
  public function testInferredMemberAppSyncKeepsPendingReview(): void {
    $repo = $this->publishedRepo();
    $app = $this->publishedApp($repo);
    $this->sendForReview($app);

    $this->container->get('ood_software.repo_sync')->syncInferredMemberApp(
      $repo, $this->repoUrl, ['name' => 'RStudio', 'description' => 'NEW BODY'], ['stars' => 7],
    );

    $this->assertReviewKept((int) $app->id(), 'body', 'value', 'NEW BODY');
  }

  /**
   * The cron updater keeps a pending review.
   */
  public function testCronUpdaterKeepsPendingReview(): void {
    $repo = $this->publishedRepo();
    $app = $this->publishedApp($repo);
    $this->sendForReview($app);

    $this->makeWorker($repo)->processItem(['nid' => $app->id()]);

    $this->assertReviewKept((int) $app->id(), 'body', 'value', 'ROOT DESCRIPTION');
  }

  /**
   * Control: with no pending review a sync saves exactly as it always has.
   */
  public function testSyncWithoutPendingRevisionIsUnchanged(): void {
    $repo = $this->publishedRepo();
    $app = $this->publishedApp($repo);
    $this->resetSideEffects();

    $this->container->get('ood_software.repo_sync')->resolveRepo($this->repoUrl, NULL, [
      'name' => 'bc_osc_rstudio',
      'description' => 'NEW DESC',
    ]);
    $this->makeWorker($repo)->processItem(['nid' => $app->id()]);

    $storage = $this->nodeStorage();
    $storage->resetCache();
    $moderation = $this->container->get('content_moderation.moderation_information');
    foreach ([$repo, $app] as $node) {
      $default = $storage->load($node->id());
      self::assertFalse($moderation->hasPendingRevision($default), 'No pending revision appears.');
      self::assertSame('published', $default->get('moderation_state')->value);
      self::assertSame((int) $default->getRevisionId(), (int) $storage->getLatestRevisionId($node->id()), 'The default revision is the newest.');
    }
    self::assertSame('NEW DESC', $storage->load($repo->id())->get('field_repo_description')->value);
    self::assertSame('ROOT DESCRIPTION', $storage->load($app->id())->get('body')->value);
  }

  /**
   * The cron worker, with a GitHub stub reporting a new commit.
   */
  protected function makeWorker(NodeInterface $repo): AppverseAppUpdater {
    $gh = new class(
      $this->container->get('http_client'),
      $this->container->get('key.repository'),
      $this->container->get('messenger'),
      $this->container->get('logger.factory'),
      $this->container->get('entity_type.manager'),
    ) extends GitHubService {

      // phpcs:disable Drupal.Commenting.FunctionComment
      public function parseUrl(string $repo): bool {
        return TRUE;
      }

      public function getData(): ?array {
        return [];
      }

      public function getLastComittedDate(): ?int {
        return 2000000000;
      }

      public function getDescription(): ?string {
        return 'ROOT DESCRIPTION';
      }

      public function getReadme(): ?string {
        return 'ROOT README';
      }

      public function getAppTypeIds(): array {
        return [];
      }

      public function getStars(): ?int {
        return 3;
      }

      public function getRepoUrl(): string {
        return 'https://github.com/OSC/bc_osc_rstudio';
      }

      public function getRepoName(): ?string {
        return 'bc_osc_rstudio';
      }

      public function getRepoDescription(): string {
        return '';
      }

      public function getOrganization(): ?string {
        return '';
      }

      public function getAppverseYmlText(): ?string {
        return NULL;
      }

      public function getIsArchived(): ?bool {
        return FALSE;
      }

      // phpcs:enable Drupal.Commenting.FunctionComment
    };

    // No network: resolveRepo hands back the repo the test already has.
    $repoSync = new class($repo) extends RepoSyncService {

      public function __construct(private NodeInterface $repo) {}

      // phpcs:disable Drupal.Commenting.FunctionComment
      public function resolveRepo(string $repoUrl, ?string $appverseYmlText, array $repoMetadata): NodeInterface {
        return $this->repo;
      }

      public function findRepoByUrl(string $repoUrl): ?NodeInterface {
        return $this->repo;
      }

      // phpcs:enable Drupal.Commenting.FunctionComment
    };

    return new AppverseAppUpdater(
      [], 'appverse_app_updater', [],
      $this->container->get('entity_type.manager'),
      $gh,
      $repoSync,
      $this->container->get('logger.factory')->get('ood_software'),
      $this->container->get('datetime.time'),
      $this->container->get('ood_software.in_place_saver'),
    );
  }

}
