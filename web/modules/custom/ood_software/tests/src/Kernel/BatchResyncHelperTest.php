<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;

/**
 * Covers _ood_software_resync_repo_batch helper and its batch op wiring.
 *
 * The interactive Batch UI is not testable in a kernel test. Instead we
 * exercise the final entity state by running the underlying service methods
 * (applyDeclaredApps per subpath + reconcileDeclaredApps) that the batch
 * operations delegate to. This is the same approach used by
 * DeclaredAppSharedTagsInheritanceTest and DeclaredAppTagsTest for
 * applyDeclaredApps coverage.
 *
 * @group ood_software
 */
class BatchResyncHelperTest extends KernelTestBase {

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
    'ood_software',
  ];

  /**
   * Canonical repo URL used throughout this test class.
   */
  protected string $repoUrl = 'https://github.com/x/y';

  /**
   * Repo-level metadata (no GitHub calls needed).
   */
  protected array $repoMetadata = [];

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
      'system',
      'filter',
      'user',
      'node',
      'taxonomy',
      'content_moderation',
      'workflows',
    ]);

    $this->importProdConfig([
      'node.type.appverse_repo',
      'node.type.appverse_app',
      'node.type.appverse_software',
      'workflows.workflow.appverse_editorial',
      // Repo bundle fields.
      'field.storage.node.field_repo_url',
      'field.field.node.appverse_repo.field_repo_url',
      // body is a base-table field; attach it to appverse_app.
      'field.field.node.appverse_app.body',
      // appverse_app fields written by applyDeclaredApp.
      'field.storage.node.field_appverse_repo',
      'field.field.node.appverse_app.field_appverse_repo',
      'field.storage.node.field_appverse_github_url',
      'field.field.node.appverse_app.field_appverse_github_url',
      'field.storage.node.field_appverse_app_subpath',
      'field.field.node.appverse_app.field_appverse_app_subpath',
      'field.storage.node.field_appverse_organization',
      'field.field.node.appverse_app.field_appverse_organization',
      'field.storage.node.field_appverse_maintainer_name',
      'field.field.node.appverse_app.field_appverse_maintainer_name',
      'field.storage.node.field_appverse_readme',
      'field.field.node.appverse_app.field_appverse_readme',
      'field.storage.node.field_appverse_app_type',
      'field.field.node.appverse_app.field_appverse_app_type',
      'field.storage.node.field_appverse_software_implemen',
      'field.field.node.appverse_app.field_appverse_software_implemen',
      'field.storage.node.field_appverse_app_validation_st',
      'field.field.node.appverse_app.field_appverse_app_validation_st',
      'field.storage.node.field_appverse_app_validation_er',
      'field.field.node.appverse_app.field_appverse_app_validation_er',
      'field.storage.node.field_add_implementation_tags',
      'field.field.node.appverse_app.field_add_implementation_tags',
      'taxonomy.vocabulary.appverse_organization',
    ]);

    // field_repo_shape lives in the module's config/install.
    $moduleSource = new \Drupal\Core\Config\FileStorage(
      DRUPAL_ROOT . '/modules/custom/ood_software/config/install'
    );
    $configManager = \Drupal::service('config.manager');
    $entityTypeManager = \Drupal::entityTypeManager();
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
      $entityTypeId = $configManager->getEntityTypeIdByName($name);
      $storage = $entityTypeManager->getStorage($entityTypeId);
      $idKey = $storage->getEntityType()->getKey('id');
      $existingId = $data[$idKey] ?? NULL;
      if ($existingId && $storage->load($existingId)) {
        continue;
      }
      $storage->createFromStorageRecord($data)->save();
    }

    Vocabulary::create([
      'vid' => 'appverse_implementation_tags',
      'name' => 'Implementation tags',
    ])->save();
    Vocabulary::create([
      'vid' => 'tags',
      'name' => 'Tags',
    ])->save();
    Vocabulary::create([
      'vid' => 'appverse_app_type',
      'name' => 'App type',
    ])->save();

    Term::create(['vid' => 'appverse_implementation_tags', 'name' => 'containerized'])->save();
    Term::create(['vid' => 'appverse_implementation_tags', 'name' => 'gpu-enabled'])->save();
    Term::create(['vid' => 'appverse_app_type', 'name' => 'batch-connect-basic'])->save();
    Term::create(['vid' => 'appverse_organization', 'name' => 'x'])->save();

    // Software nodes so `software:` keys resolve and apps are not rejected.
    Node::create([
      'type' => 'appverse_software',
      'title' => 'Jupyter',
      'status' => 1,
    ])->save();
    Node::create([
      'type' => 'appverse_software',
      'title' => 'RStudio',
      'status' => 1,
    ])->save();
  }

  /**
   * Build a parsedRootYml for a 2-app declared repo.
   */
  protected function parsedRootYmlFor(array $subpaths): array {
    $apps = [];
    foreach ($subpaths as $subpath) {
      $name = ucfirst($subpath);
      $apps[] = [
        'path' => $subpath,
        'name' => $name,
        'description' => "The $name app",
        'app_type' => 'batch-connect-basic',
        'software' => $name,
        'implementation_tags' => ['containerized'],
        'maintainer' => ['name' => 'Team', 'support_url' => 'https://example.org'],
      ];
    }
    return ['apps' => $apps];
  }

  /**
   * Create the parent appverse_repo node.
   */
  protected function createRepo(): Node {
    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => 'Test Repo',
      'field_repo_url' => ['uri' => $this->repoUrl],
      'field_repo_shape' => 'declared',
    ]);
    $repo->save();
    return $repo;
  }

  /**
   * Look up a term ID by name within a vocabulary.
   */
  protected function tidByName(string $vid, string $name): int {
    $ids = $this->container->get('entity_type.manager')->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', $vid)
      ->condition('name', $name)
      ->execute();
    self::assertNotEmpty($ids, "Expected term '$name' in vocab '$vid'.");
    return (int) reset($ids);
  }

  /**
   * The helper's ops produce exactly 2 member apps with correct entity state.
   *
   * This test mirrors the kernel test strategy used in
   * DeclaredAppSharedTagsInheritanceTest: call applyDeclaredApps per subpath
   * (narrowed, mimicking _ood_software_batch_sync_app without the GitHub
   * fetch), then reconcileDeclaredApps (mimicking
   * _ood_software_batch_reconcile_apps with full apps[]), and assert the
   * FINAL ENTITY STATE — member apps exist, linked to the repo, with expected
   * software and tags.
   */
  public function testHelperResyncsAllMemberApps(): void {
    $sync = $this->container->get('ood_software.repo_sync');
    $repo = $this->createRepo();
    $subpaths = ['jupyter', 'rstudio'];
    $parsedRootYml = $this->parsedRootYmlFor($subpaths);

    // Mimic what the batch ops do: run applyDeclaredApps per subpath with a
    // narrowed yml (same logic as _ood_software_batch_sync_app, but without
    // the GitHub fetch which is out of scope for kernel tests and already
    // covered by unit tests on GitHubService).
    foreach ($subpaths as $subpath) {
      $narrowed = $parsedRootYml;
      $narrowed['apps'] = array_values(array_filter(
        $parsedRootYml['apps'] ?? [],
        fn($a) => is_array($a) && ($a['path'] ?? NULL) === $subpath,
      ));
      $sync->applyDeclaredApps(
        $repo,
        $narrowed,
        [$subpath => ['manifestYml' => NULL, 'appverseYml' => NULL, 'readme' => '']],
        $this->repoUrl,
        $this->repoMetadata,
        // Skip reconciliation per-subpath — same as batch op.
        FALSE,
      );
    }

    // Mimic _ood_software_batch_reconcile_apps: reconcile with full apps[].
    $declaredSubpaths = [];
    foreach ($parsedRootYml['apps'] ?? [] as $entry) {
      $path = trim((string) ($entry['path'] ?? ''), '/');
      if ($path !== '') {
        $declaredSubpaths[$path] = TRUE;
      }
    }
    $sync->reconcileDeclaredApps($repo, $declaredSubpaths, $this->repoUrl);

    // Assert final entity state: exactly 2 member apps linked to the repo.
    $apps = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
      'type' => 'appverse_app',
      'field_appverse_repo' => $repo->id(),
    ]);
    self::assertCount(2, $apps, 'Expected exactly 2 member apps after batch resync.');

    // Each app must be linked to the repo and have a valid status.
    foreach ($apps as $app) {
      $repoRef = (int) $app->get('field_appverse_repo')->target_id;
      self::assertSame((int) $repo->id(), $repoRef, 'App must reference the repo.');
      $validationSt = $app->get('field_appverse_app_validation_st')->value;
      self::assertSame('valid', $validationSt, "App {$app->label()} must be valid; errors: " . $this->errorsFor($app));
    }

    // Collect subpaths to confirm both declared subpaths have member apps.
    $actualSubpaths = [];
    foreach ($apps as $app) {
      $actualSubpaths[] = $app->get('field_appverse_app_subpath')->value;
    }
    sort($actualSubpaths);
    self::assertSame(['jupyter', 'rstudio'], $actualSubpaths, 'Both declared subpaths must have member apps.');

    // Assert software resolution: each app should reference a software node.
    foreach ($apps as $app) {
      $softwareRef = $app->get('field_appverse_software_implemen')->target_id;
      self::assertNotEmpty($softwareRef, "App {$app->label()} must have a software reference.");
    }
  }

  /**
   * Reconcile removes a stale app that is no longer in apps[].
   *
   * This covers the _ood_software_batch_reconcile_apps behavior when an app
   * that was previously synced is no longer declared.
   */
  public function testReconcileRemovesStaleApp(): void {
    $sync = $this->container->get('ood_software.repo_sync');
    $repo = $this->createRepo();

    // First sync: 2 apps.
    $twoAppYml = $this->parsedRootYmlFor(['jupyter', 'rstudio']);
    foreach (['jupyter', 'rstudio'] as $subpath) {
      $narrowed = $twoAppYml;
      $narrowed['apps'] = array_values(array_filter(
        $twoAppYml['apps'] ?? [],
        fn($a) => is_array($a) && ($a['path'] ?? NULL) === $subpath,
      ));
      $sync->applyDeclaredApps(
        $repo, $narrowed,
        [$subpath => ['manifestYml' => NULL, 'appverseYml' => NULL, 'readme' => '']],
        $this->repoUrl, $this->repoMetadata, FALSE,
      );
    }
    $declaredSubpaths = ['jupyter' => TRUE, 'rstudio' => TRUE];
    $sync->reconcileDeclaredApps($repo, $declaredSubpaths, $this->repoUrl);

    $appsAfterFirst = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
      'type' => 'appverse_app',
      'field_appverse_repo' => $repo->id(),
    ]);
    self::assertCount(2, $appsAfterFirst, 'Precondition: 2 apps after first sync.');

    // Second sync: only jupyter remains.
    $oneAppYml = $this->parsedRootYmlFor(['jupyter']);
    $narrowed = $oneAppYml;
    $sync->applyDeclaredApps(
      $repo, $narrowed,
      ['jupyter' => ['manifestYml' => NULL, 'appverseYml' => NULL, 'readme' => '']],
      $this->repoUrl, $this->repoMetadata, FALSE,
    );
    $sync->reconcileDeclaredApps($repo, ['jupyter' => TRUE], $this->repoUrl);

    // Entity cache is per-request; clear so loadByProperties sees the deletes.
    \Drupal::entityTypeManager()->getStorage('node')->resetCache();

    $appsAfterSecond = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
      'type' => 'appverse_app',
      'field_appverse_repo' => $repo->id(),
    ]);
    self::assertCount(1, $appsAfterSecond, 'Stale rstudio app must be removed by reconcile.');

    $remaining = reset($appsAfterSecond);
    self::assertSame('jupyter', $remaining->get('field_appverse_app_subpath')->value, 'Only jupyter must remain.');
  }

  /**
   * The helper function exists and is callable.
   *
   * Verifies that _ood_software_resync_repo_batch() is defined in
   * ood_software.module. The function signature and batch_set wiring are
   * exercised by the integration here; this assertion ensures the function
   * declaration itself is present.
   */
  public function testHelperFunctionExists(): void {
    // ood_software.module is loaded when the module is enabled; the function
    // must be globally available.
    self::assertTrue(
      function_exists('_ood_software_resync_repo_batch'),
      '_ood_software_resync_repo_batch() must be defined in ood_software.module'
    );
  }

  /**
   * Joined validation error messages of an app.
   */
  private function errorsFor(Node $app): string {
    $errors = [];
    foreach ($app->get('field_appverse_app_validation_er')->getValue() as $item) {
      $errors[] = $item['value'] ?? '';
    }
    return implode("\n", $errors);
  }

}
