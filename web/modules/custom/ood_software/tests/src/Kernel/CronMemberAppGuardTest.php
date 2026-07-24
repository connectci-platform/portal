<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Plugin\GitHubService;
use Drupal\ood_software\Plugin\QueueWorker\AppverseAppUpdater;
use Drupal\ood_software\Service\RepoSyncService;

/**
 * Guards that the cron app-updater does NOT clobber monorepo member apps.
 *
 * AppverseAppUpdater reads ROOT-level repo data and, when the repo has new
 * commits, writes body/README/app_type onto the app. A monorepo member app
 * gets those fields from its OWN subpath (owned by RepoSyncService), so the
 * worker must skip that write for any app with a non-empty
 * field_appverse_app_subpath. Without the guard the member's per-subpath body
 * is overwritten with root (often empty) content.
 *
 * @group ood_software
 */
class CronMemberAppGuardTest extends KernelTestBase {

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
    'ood_software',
  ];

  use Traits\ProdConfigTrait;

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
    $this->installSchema('node', ['node_access']);
    $this->installConfig([
      'system', 'filter', 'user', 'node', 'taxonomy',
      'content_moderation', 'workflows',
    ]);

    $this->importProdConfig([
      'node.type.appverse_repo',
      'node.type.appverse_app',
      'workflows.workflow.appverse_editorial',
      'field.storage.node.field_repo_url',
      'field.field.node.appverse_repo.field_repo_url',
      'field.storage.node.field_appverse_repo',
      'field.field.node.appverse_app.field_appverse_repo',
      'field.field.node.appverse_app.body',
      'field.storage.node.field_appverse_github_url',
      'field.field.node.appverse_app.field_appverse_github_url',
      'field.storage.node.field_appverse_app_subpath',
      'field.field.node.appverse_app.field_appverse_app_subpath',
      'field.storage.node.field_appverse_readme',
      'field.field.node.appverse_app.field_appverse_readme',
      'field.storage.node.field_appverse_lastupdated',
      'field.field.node.appverse_app.field_appverse_lastupdated',
      'field.storage.node.field_appverse_stars',
      'field.field.node.appverse_app.field_appverse_stars',
      'field.storage.node.field_appverse_app_type',
      'field.field.node.appverse_app.field_appverse_app_type',
      // Repo validation fields — the shape-change flag writes to these.
      'field.storage.node.field_repo_validation_st',
      'field.field.node.appverse_repo.field_repo_validation_st',
      'field.storage.node.field_repo_validation_er',
      'field.field.node.appverse_repo.field_repo_validation_er',
      'field.storage.node.field_repo_last_synced',
      'field.field.node.appverse_repo.field_repo_last_synced',
    ]);

    // field_repo_shape lives in the module's config/install.
    $moduleSource = new FileStorage(
      DRUPAL_ROOT . '/modules/custom/ood_software/config/install'
    );
    foreach ([
      'field.storage.node.field_repo_shape',
      'field.field.node.appverse_repo.field_repo_shape',
    ] as $name) {
      $data = $moduleSource->read($name);
      $etm = \Drupal::entityTypeManager();
      $storage = $etm->getStorage(
        \Drupal::service('config.manager')->getEntityTypeIdByName($name)
      );
      $idKey = $storage->getEntityType()->getKey('id');
      if (!($data[$idKey] ?? NULL) || !$storage->load($data[$idKey])) {
        $storage->createFromStorageRecord($data)->save();
      }
    }
  }

  /**
   * Build the worker with a stubbed GitHubService reporting a NEW commit.
   */
  private function makeWorker(NodeInterface $repo): AppverseAppUpdater {
    $gh = new class(
      $this->container->get('http_client'),
      $this->container->get('key.repository'),
      $this->container->get('messenger'),
      $this->container->get('logger.factory'),
      $this->container->get('entity_type.manager'),
    ) extends GitHubService {

      public function parseUrl(string $repo): bool {
        return TRUE;
      }

      public function getData(): ?array { return []; }

      // A last-committed date that will differ from the app's stored
      // lastupdated, so the "new commits" branch is entered.
      public function getLastComittedDate(): ?int {
        return 2000000000;
      }

      // Root data — what would clobber a member app if the guard is absent.
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
        return 0;
      }

      public function getRepoUrl(): string {
        return 'https://github.com/OSC/mono';
      }

      public function getRepoName(): ?string {
        return 'mono';
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
    };

    // Stub resolveRepo/findRepoByUrl to return the existing repo (no network).
    // Records whether resolveRepo was called so tests can assert the
    // shape-change path does NOT reshape.
    $repoSync = new class($repo) extends RepoSyncService {

      private NodeInterface $repo;
      public bool $resolveRepoCalled = FALSE;

      public function __construct(NodeInterface $repo) {
        $this->repo = $repo;
      }

      public function resolveRepo(string $repoUrl, ?string $appverseYmlText, array $repoMetadata): NodeInterface {
        $this->resolveRepoCalled = TRUE;
        return $this->repo;
      }

      public function findRepoByUrl(string $repoUrl): ?NodeInterface {
        return $this->repo;
      }

    };

    return new AppverseAppUpdater(
      [], 'appverse_app_updater', [],
      $this->container->get('entity_type.manager'),
      $gh,
      $repoSync,
      $this->container->get('logger.factory')->get('ood_software'),
      $this->container->get('datetime.time'),
    );
  }

  private function makeRepo(): NodeInterface {
    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => 'Mono',
      'field_repo_url' => ['uri' => 'https://github.com/OSC/mono'],
      'field_repo_shape' => 'declared',
      'moderation_state' => 'published',
    ]);
    $repo->save();
    return $repo;
  }

  /**
   * A member app (has a subpath) keeps its per-subpath body after cron.
   */
  public function testMemberAppBodyNotClobbered(): void {
    $repo = $this->makeRepo();
    $app = Node::create([
      'type' => 'appverse_app',
      'title' => 'Member',
      'field_appverse_repo' => $repo->id(),
      'field_appverse_app_subpath' => 'jupyter',
      'field_appverse_github_url' => ['uri' => 'https://github.com/OSC/mono'],
      'body' => [['format' => 'markdown', 'value' => 'PER-SUBPATH BODY']],
      'field_appverse_lastupdated' => [['value' => '1000']],
      'moderation_state' => 'published',
    ]);
    $app->save();

    $this->makeWorker($repo)->processItem(['nid' => $app->id()]);

    $reloaded = Node::load($app->id());
    self::assertSame(
      'PER-SUBPATH BODY',
      $reloaded->get('body')->value,
      'Member app body must NOT be clobbered with root content by cron.'
    );
  }

  /**
   * A non-member app (no subpath) still gets root content synced (control).
   */
  public function testNonMemberAppBodyStillSynced(): void {
    $repo = $this->makeRepo();
    $app = Node::create([
      'type' => 'appverse_app',
      'title' => 'Standalone',
      'field_appverse_repo' => $repo->id(),
      // No subpath — this app's content DOES come from the repo root.
      'field_appverse_github_url' => ['uri' => 'https://github.com/OSC/mono'],
      'body' => [['format' => 'markdown', 'value' => 'OLD BODY']],
      'field_appverse_lastupdated' => [['value' => '1000']],
      'moderation_state' => 'published',
    ]);
    $app->save();

    $this->makeWorker($repo)->processItem(['nid' => $app->id()]);

    $reloaded = Node::load($app->id());
    self::assertSame(
      'ROOT DESCRIPTION',
      $reloaded->get('body')->value,
      'Standalone (no-subpath) app SHOULD receive root content on new commits.'
    );
  }

  /**
   * A shape change (inferred repo gains an appverse.yml) is FLAGGED, not
   * transformed: cron sets the repo degraded and does NOT reshape (resolveRepo
   * is not called on that path). The attended Resync does the real work.
   */
  public function testShapeChangeIsFlaggedNotTransformed(): void {
    // Repo registered as INFERRED (no appverse.yml at registration), carrying a
    // pre-existing validation error that the flag must NOT clobber.
    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => 'WasInferred',
      'field_repo_url' => ['uri' => 'https://github.com/OSC/mono'],
      'field_repo_shape' => 'inferred',
      'field_repo_validation_st' => 'valid',
      'field_repo_validation_er' => ['A pre-existing error the maintainer must still see.'],
      'moderation_state' => 'published',
    ]);
    $repo->save();

    $app = Node::create([
      'type' => 'appverse_app',
      'title' => 'Root App',
      'field_appverse_repo' => $repo->id(),
      'field_appverse_github_url' => ['uri' => 'https://github.com/OSC/mono'],
      'moderation_state' => 'published',
    ]);
    $app->save();

    // GH stub that now reports a root appverse.yml → isDeclaredRepo() TRUE.
    $gh = new class(
      $this->container->get('http_client'),
      $this->container->get('key.repository'),
      $this->container->get('messenger'),
      $this->container->get('logger.factory'),
      $this->container->get('entity_type.manager'),
    ) extends GitHubService {
      public function parseUrl(string $repo): bool { return TRUE; }
      public function getData(): ?array { return []; }
      public function getLastComittedDate(): ?int { return 2000000000; }
      public function getDescription(): ?string { return 'D'; }
      public function getReadme(): ?string { return 'R'; }
      public function getAppTypeIds(): array { return []; }
      public function getStars(): ?int { return 0; }
      public function getRepoUrl(): string { return 'https://github.com/OSC/mono'; }
      public function getRepoName(): ?string { return 'mono'; }
      public function getRepoDescription(): string { return ''; }
      public function getOrganization(): ?string { return ''; }
      // Now HAS a root appverse.yml → isDeclaredRepo() returns TRUE.
      // (isDeclaredRepo reads the $appverseYmlText property, which parseUrl
      // would set in real cron; override it directly in the stub.)
      public function getAppverseYmlText(): ?string { return "apps:\n  - path: jupyter\n"; }
      public function isDeclaredRepo(): bool { return TRUE; }
    };

    $repoSync = new class($repo) extends RepoSyncService {
      private NodeInterface $repo;
      public bool $resolveRepoCalled = FALSE;
      public function __construct(NodeInterface $repo) { $this->repo = $repo; }
      public function resolveRepo(string $u, ?string $y, array $m): NodeInterface {
        $this->resolveRepoCalled = TRUE;
        return $this->repo;
      }
      public function findRepoByUrl(string $u): ?NodeInterface { return $this->repo; }
    };

    $worker = new AppverseAppUpdater(
      [], 'appverse_app_updater', [],
      $this->container->get('entity_type.manager'),
      $gh,
      $repoSync,
      $this->container->get('logger.factory')->get('ood_software'),
      $this->container->get('datetime.time'),
    );
    $worker->processItem(['nid' => $app->id()]);

    self::assertFalse($repoSync->resolveRepoCalled, 'Cron must NOT reshape (resolveRepo not called) on a pending shape change.');

    $reloadedRepo = Node::load($repo->id());
    self::assertSame('degraded', $reloadedRepo->get('field_repo_validation_st')->value, 'Repo is flagged degraded for the shape change.');
    $errs = array_column($reloadedRepo->get('field_repo_validation_er')->getValue(), 'value');
    self::assertContains('A pre-existing error the maintainer must still see.', $errs, 'Pre-existing validation errors are preserved, not clobbered.');
    self::assertTrue((bool) array_filter($errs, fn($e) => str_contains($e, 're-synced')), 'A neutral, publicly-safe shape-change note is appended (no admin-only instruction).');
    self::assertSame('inferred', $reloadedRepo->get('field_repo_shape')->value, 'Shape is left unchanged (not flipped to declared).');
  }

  /**
   * The REVERSE shape change (declared repo LOST its appverse.yml) is also
   * flagged, not transformed — cron must not flip it to inferred and orphan
   * the declared members.
   */
  public function testReverseShapeChangeIsFlaggedNotTransformed(): void {
    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => 'WasDeclared',
      'field_repo_url' => ['uri' => 'https://github.com/OSC/mono'],
      'field_repo_shape' => 'declared',
      'field_repo_validation_st' => 'valid',
      'moderation_state' => 'published',
    ]);
    $repo->save();
    $app = Node::create([
      'type' => 'appverse_app',
      'title' => 'Member',
      'field_appverse_repo' => $repo->id(),
      'field_appverse_github_url' => ['uri' => 'https://github.com/OSC/mono'],
      'field_appverse_app_subpath' => 'jupyter',
      'moderation_state' => 'published',
    ]);
    $app->save();

    // GH stub reporting NO root appverse.yml now → isDeclaredRepo() FALSE.
    $gh = new class(
      $this->container->get('http_client'),
      $this->container->get('key.repository'),
      $this->container->get('messenger'),
      $this->container->get('logger.factory'),
      $this->container->get('entity_type.manager'),
    ) extends GitHubService {
      public function parseUrl(string $repo): bool { return TRUE; }
      public function getData(): ?array { return []; }
      public function getLastComittedDate(): ?int { return 2000000000; }
      public function getDescription(): ?string { return 'D'; }
      public function getReadme(): ?string { return 'R'; }
      public function getAppTypeIds(): array { return []; }
      public function getStars(): ?int { return 0; }
      public function getRepoUrl(): string { return 'https://github.com/OSC/mono'; }
      public function getRepoName(): ?string { return 'mono'; }
      public function getRepoDescription(): string { return ''; }
      public function getOrganization(): ?string { return ''; }
      public function getAppverseYmlText(): ?string { return NULL; }
      public function isDeclaredRepo(): bool { return FALSE; }
    };
    $repoSync = new class($repo) extends RepoSyncService {
      private NodeInterface $repo;
      public bool $resolveRepoCalled = FALSE;
      public function __construct(NodeInterface $repo) { $this->repo = $repo; }
      public function resolveRepo(string $u, ?string $y, array $m): NodeInterface {
        $this->resolveRepoCalled = TRUE;
        return $this->repo;
      }
      public function findRepoByUrl(string $u): ?NodeInterface { return $this->repo; }
    };
    $worker = new AppverseAppUpdater(
      [], 'appverse_app_updater', [],
      $this->container->get('entity_type.manager'),
      $gh,
      $repoSync,
      $this->container->get('logger.factory')->get('ood_software'),
      $this->container->get('datetime.time'),
    );
    $worker->processItem(['nid' => $app->id()]);

    self::assertFalse($repoSync->resolveRepoCalled, 'Cron must NOT reshape declared→inferred.');
    $reloadedRepo = Node::load($repo->id());
    self::assertSame('degraded', $reloadedRepo->get('field_repo_validation_st')->value, 'Reverse shape change is flagged degraded.');
    self::assertSame('declared', $reloadedRepo->get('field_repo_shape')->value, 'Shape left unchanged (not flipped to inferred).');
  }

}
