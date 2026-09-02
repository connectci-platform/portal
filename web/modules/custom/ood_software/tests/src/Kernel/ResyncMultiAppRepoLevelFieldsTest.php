<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Symfony\Component\Yaml\Yaml;

/**
 * Regression test: resync of a declared multi-app repo refreshes repo fields.
 *
 * Bug: AppverseHubController::resync() previously called only
 * _ood_software_resync_repo_batch() for multi-app repos, which batches
 * per-app ops but does NOT write repo-level fields. Those fields
 * (field_repo_validation_st, field_repo_last_synced,
 * field_repo_unresolved_tags, field_repo_tags) are written ONLY by
 * RepoSyncService::applyDeclared(), called via resolveRepo(). The fix
 * calls resolveRepo() first, then the helper only for the multi-app path.
 *
 * This test drives the same sequence the fixed resync() uses:
 *   1. resolveRepo() — writes repo-level fields
 *   2. applyDeclaredApps() per subpath (narrowed) — writes per-app fields
 *   3. reconcileDeclaredApps() — removes stale apps
 *
 * and asserts that field_repo_unresolved_tags and field_repo_last_synced
 * are set after the sequence, proving applyDeclared ran.
 *
 * @group ood_software
 */
class ResyncMultiAppRepoLevelFieldsTest extends KernelTestBase {

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
      // Repo bundle fields written by applyDeclared() / resolveRepo().
      'field.storage.node.field_repo_url',
      'field.field.node.appverse_repo.field_repo_url',
      'field.storage.node.field_repo_description',
      'field.field.node.appverse_repo.field_repo_description',
      'field.storage.node.field_repo_maintainer_name',
      'field.field.node.appverse_repo.field_repo_maintainer_name',
      'field.storage.node.field_repo_maintainer_url',
      'field.field.node.appverse_repo.field_repo_maintainer_url',
      'field.storage.node.field_repo_shared_paths',
      'field.field.node.appverse_repo.field_repo_shared_paths',
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
      'field.storage.node.field_repo_docs_url',
      'field.field.node.appverse_repo.field_repo_docs_url',
      'field.storage.node.field_repo_tags',
      'field.field.node.appverse_repo.field_repo_tags',
      'field.storage.node.field_repo_readme',
      'field.field.node.appverse_repo.field_repo_readme',
      // appverse_app fields written by applyDeclaredApp().
      'field.field.node.appverse_app.body',
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
      // appverse_organization taxonomy used by resolveOrganizationTerm().
      'taxonomy.vocabulary.appverse_organization',
    ]);

    // Import fields from the module's config/install (not in the prod
    // snapshot). field_repo_shape is required because applyDeclared()
    // reads and writes it. field_repo_unresolved_tags holds discovery-tag
    // misses at the repo level; field_appverse_unresolved_tags is the
    // per-app counterpart.
    $moduleSource = new \Drupal\Core\Config\FileStorage(
      DRUPAL_ROOT . '/modules/custom/ood_software/config/install'
    );
    $configManager = \Drupal::service('config.manager');
    $entityTypeManager = \Drupal::entityTypeManager();
    foreach ([
      'field.storage.node.field_repo_shape',
      'field.field.node.appverse_repo.field_repo_shape',
      'field.storage.node.field_repo_unresolved_tags',
      'field.field.node.appverse_repo.field_repo_unresolved_tags',
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

    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    Vocabulary::create(['vid' => 'appverse_implementation_tags', 'name' => 'Implementation tags'])->save();
    Vocabulary::create(['vid' => 'appverse_app_type', 'name' => 'App type'])->save();

    Term::create(['vid' => 'appverse_implementation_tags', 'name' => 'containerized'])->save();
    Term::create(['vid' => 'appverse_app_type', 'name' => 'batch-connect-basic'])->save();
    Term::create(['vid' => 'appverse_organization', 'name' => 'x'])->save();

    // Software nodes so `software:` keys resolve and apps are not rejected.
    Node::create(['type' => 'appverse_software', 'title' => 'Jupyter', 'status' => 1])->save();
    Node::create(['type' => 'appverse_software', 'title' => 'RStudio', 'status' => 1])->save();
  }

  /**
   * Resync of a declared multi-app repo refreshes repo-level fields.
   *
   * Regression coverage for the bug where
   * AppverseHubController::resync() called only
   * _ood_software_resync_repo_batch() for multi-app repos, skipping
   * resolveRepo() and leaving field_repo_validation_st,
   * field_repo_last_synced, and field_repo_unresolved_tags stale.
   *
   * This test drives the same service-method sequence as the fixed
   * resync() path (resolveRepo first, then batch ops) and asserts that
   * the repo-level fields are written — the specific gap that the bug
   * left undetected.
   */
  public function testResyncMultiAppRepoWritesRepoLevelFields(): void {
    $sync = $this->container->get('ood_software.repo_sync');

    $appverseYml = Yaml::dump([
      'title' => 'Test Repo',
      'description' => 'A multi-app test repo.',
      'tags' => ['nonexistent-discovery-tag'],
      'apps' => [
        [
          'path' => 'jupyter',
          'name' => 'Jupyter',
          'description' => 'Jupyter app',
          'app_type' => 'batch-connect-basic',
          'software' => 'Jupyter',
          'maintainer' => ['name' => 'Team', 'support_url' => 'https://example.org'],
        ],
        [
          'path' => 'rstudio',
          'name' => 'RStudio',
          'description' => 'RStudio app',
          'app_type' => 'batch-connect-basic',
          'software' => 'RStudio',
          'maintainer' => ['name' => 'Team', 'support_url' => 'https://example.org'],
        ],
      ],
    ], 4, 2);

    $parsedRootYml = Yaml::parse($appverseYml);
    $repoMetadata = ['organization' => 'x'];

    // Step 1: resolveRepo() — writes repo-level fields. This is the call
    // that AppverseHubController::resync() was missing for multi-app repos.
    $repo = $sync->resolveRepo($this->repoUrl, $appverseYml, $repoMetadata);
    $repoId = (int) $repo->id();

    // Step 2: batch ops per subpath — same logic as batch_sync_app ops.
    $subpaths = ['jupyter', 'rstudio'];
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
        $repoMetadata,
        FALSE,
      );
    }

    // Step 3: reconcile — same as _ood_software_batch_reconcile_apps.
    $declaredSubpaths = array_fill_keys($subpaths, TRUE);
    $sync->reconcileDeclaredApps($repo, $declaredSubpaths, $this->repoUrl);

    // Reload so field values reflect what was persisted to the database.
    \Drupal::entityTypeManager()->getStorage('node')->resetCache();
    $fresh = Node::load($repoId);
    self::assertNotNull($fresh, 'Repo node must still exist after resync sequence.');

    // ASSERT: field_repo_last_synced is set — proves applyDeclared ran.
    $lastSynced = $fresh->get('field_repo_last_synced')->value;
    self::assertNotEmpty($lastSynced,
      'field_repo_last_synced must be set after resync — applyDeclared must have run.');

    // ASSERT: field_repo_unresolved_tags is set (to the unresolvable tag)
    // — proves applyDeclared ran the discovery-tag resolution path.
    $unresolved = array_column($fresh->get('field_repo_unresolved_tags')->getValue(), 'value');
    self::assertSame(['nonexistent-discovery-tag'], $unresolved,
      'field_repo_unresolved_tags must contain the declared tag that had no matching term — applyDeclared must have run.');

    // ASSERT: field_repo_validation_st is 'valid' — applyDeclared did not
    // mark the repo as stale_invalid for an unresolved discovery tag
    // (a tag miss is a flag, not a degrade).
    self::assertSame('valid', $fresh->get('field_repo_validation_st')->value,
      'field_repo_validation_st must be valid after a successful declared resync.');

    // Sanity: both member apps were created by the batch ops.
    $appIds = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appverse_app')
      ->condition('field_appverse_repo', $repoId)
      ->execute();
    self::assertCount(2, $appIds, 'Both declared apps must exist after the resync sequence.');
  }

}
