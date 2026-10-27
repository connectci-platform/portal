<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\ood_software\Service\RepoSyncService;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\ood_software\Kernel\Traits\AppverseModerationTestTrait;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests that a background repo sync writes in place.
 *
 * Cron syncs every repo every few hours. A save that makes a revision each
 * time floods the revision table, and one that makes a new default revision
 * buries a pending ready_for_review forward revision of a published repo.
 *
 * @group ood_software
 */
class BackgroundRepoSyncTest extends KernelTestBase {

  use AppverseModerationTestTrait;
  use ProdConfigTrait;

  /**
   * The repo URL every test syncs.
   */
  protected string $repoUrl = 'https://github.com/example/mono';

  /**
   * The repo sync service.
   */
  protected RepoSyncService $sync;

  /**
   * A declared two-app appverse.yml.
   */
  protected string $yml;

  /**
   * GitHub metadata for the repo.
   *
   * @var array<string, mixed>
   */
  protected array $meta = ['organization' => 'x'];

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
    'file', 'ood_software',
  ];

  /**
   * Canonical repo URL used throughout this test class.
   */

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
    $moduleSource = new FileStorage(
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
      assert($storage instanceof ConfigEntityStorageInterface);
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

    $this->sync = $this->container->get('ood_software.repo_sync');
    $this->yml = Yaml::dump([
      'title' => 'Mono Repo',
      'description' => 'A multi-app test repo.',
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
  }

  /**
   * Repeated background syncs add no revisions and keep the repo's state.
   */
  public function testRepeatedBackgroundSyncAddsNoRevisions(): void {
    $repo = $this->sync->resolveRepo($this->repoUrl, $this->yml, $this->meta);
    $repo->set('moderation_state', 'ready_for_review')->save();
    $before = $this->revisionCount($repo);
    $this->sync->resolveRepoInBackground($this->repoUrl, $this->yml, $this->meta);
    $this->sync->resolveRepoInBackground($this->repoUrl, $this->yml, $this->meta);
    $this->assertSame($before, $this->revisionCount($repo));
    $this->assertLatestState('ready_for_review', $repo);
  }

  /**
   * A background sync of a published repo leaves its forward revision latest.
   */
  public function testForwardRevisionOfPublishedRepoSurvivesSync(): void {
    $repo = $this->sync->resolveRepo($this->repoUrl, $this->yml, $this->meta);
    $repo = $this->addForwardRevision($repo, 'ready_for_review');
    $before = $this->revisionCount($repo);
    $this->assertSame('published', $this->defaultRevision($repo)->get('moderation_state')->value);

    $this->sync->resolveRepoInBackground($this->repoUrl, $this->yml, $this->meta);

    $this->assertLatestState('ready_for_review', $repo);
    $this->assertSame('published', $this->defaultRevision($repo)->get('moderation_state')->value);
    $this->assertSame($before, $this->revisionCount($repo));
  }

  /**
   * An archived upstream still archives the repo, as an editorial save.
   */
  public function testArchivedUpstreamStillArchives(): void {
    $repo = $this->sync->resolveRepo($this->repoUrl, $this->yml, $this->meta);
    $this->sync->resolveRepoInBackground($this->repoUrl, $this->yml, $this->meta + ['isArchived' => TRUE]);
    $this->assertLatestState('archived', $repo);
  }

}
