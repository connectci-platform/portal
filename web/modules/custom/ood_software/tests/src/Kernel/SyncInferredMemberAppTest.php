<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * @coversDefaultClass \Drupal\ood_software\Service\RepoSyncService
 * @group ood_software
 */
class SyncInferredMemberAppTest extends KernelTestBase {

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
    // ood_software.gh depends on the `key` module's key.repository service.
    'key',
    // ood_software_node_insert() on appverse_app nodes calls the `flag` service.
    'flag',
    'ood_software',
  ];

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

    // Core base configs.
    $this->installConfig([
      'system',
      'filter',
      'user',
      'node',
      'taxonomy',
      'content_moderation',
      'workflows',
    ]);

    // Bundles + fields live in sites/default/config/default — import the
    // exact subset this test needs (mirrors RepoNotificationServiceTest's
    // pattern; avoids installConfig(['ood_software']) which fails on
    // ultimate_cron.job.ood_software without schema in kernel context).
    $this->importProdConfig([
      'node.type.appverse_repo',
      'node.type.appverse_app',
      'workflows.workflow.appverse_editorial',
      // Bundle's field used by createBlankRepo for loadRepoByUrl.
      'field.storage.node.field_repo_url',
      'field.field.node.appverse_repo.field_repo_url',
      // body is a base-table field on node bundles; attach it to appverse_app.
      'field.field.node.appverse_app.body',
      // appverse_app fields written by syncInferredMemberApp.
      'field.storage.node.field_appverse_repo',
      'field.field.node.appverse_app.field_appverse_repo',
      'field.storage.node.field_appverse_github_url',
      'field.field.node.appverse_app.field_appverse_github_url',
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
      // appverse_organization taxonomy used by resolveOrganizationTerm.
      'taxonomy.vocabulary.appverse_organization',
    ]);

    // field_repo_shape lives in module's config/install — same pattern as
    // RepoNotificationServiceTest.
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
      $entity = $storage->createFromStorageRecord($data);
      $entity->save();
    }

    // Seed an org term so resolveOrganizationTerm() finds a match.
    Term::create(['vid' => 'appverse_organization', 'name' => 'OSC'])->save();
  }

  /**
   * @covers ::syncInferredMemberApp
   */
  public function testCreatesMemberAppFromRootManifest(): void {
    $sync = $this->container->get('ood_software.repo_sync');

    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => 'Test Inferred Repo',
      'field_repo_url' => ['uri' => 'https://github.com/OSC/bc_osc_jupyter'],
      'field_repo_shape' => 'inferred',
    ]);
    $repo->save();

    $rootManifest = [
      'name' => 'Test Notebooks',
      'description' => 'Jupyter notebooks for testing.',
      'role' => 'jupyter',
      'category' => 'data-science',
    ];
    $repoMetadata = [
      'name' => 'Test Notebooks',
      'description' => 'Jupyter notebooks for testing.',
      'organization' => 'OSC',
      'stars' => 7,
      'lastCommittedDate' => 1716000000,
      'readme' => '# Test',
    ];

    $app = $sync->syncInferredMemberApp(
      $repo,
      'https://github.com/OSC/bc_osc_jupyter',
      $rootManifest,
      $repoMetadata,
    );

    self::assertInstanceOf(NodeInterface::class, $app);
    self::assertSame('appverse_app', $app->bundle());
    self::assertSame('Test Notebooks', $app->label());
    self::assertSame((int) $repo->id(), (int) $app->get('field_appverse_repo')->target_id);

    // Organization is a term reference, NOT a string. Assert by term name.
    $tid = (int) $app->get('field_appverse_organization')->target_id;
    self::assertGreaterThan(0, $tid);
    self::assertSame('OSC', Term::load($tid)->label());

    self::assertSame(7, (int) $app->get('field_appverse_stars')->value);
    // Body uses markdown format (matches AppverseAppUpdater + declared sync).
    self::assertSame('markdown', $app->get('body')->format);
    // New inferred member apps start in draft.
    self::assertSame('draft', $app->get('moderation_state')->value);
  }

  /**
   * @covers ::syncInferredMemberApp
   */
  public function testIsIdempotent(): void {
    $sync = $this->container->get('ood_software.repo_sync');
    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => 'Idempotent Repo',
      'field_repo_url' => ['uri' => 'https://github.com/OSC/bc_osc_jupyter'],
      'field_repo_shape' => 'inferred',
    ]);
    $repo->save();

    $rootManifest = ['name' => 'X', 'description' => 'Y', 'role' => 'jupyter'];
    $meta = ['name' => 'X', 'description' => 'Y', 'organization' => 'OSC', 'stars' => 1, 'lastCommittedDate' => 1, 'readme' => ''];

    $a = $sync->syncInferredMemberApp($repo, 'https://github.com/OSC/bc_osc_jupyter', $rootManifest, $meta);
    $b = $sync->syncInferredMemberApp($repo, 'https://github.com/OSC/bc_osc_jupyter', $rootManifest, $meta);

    self::assertSame((int) $a->id(), (int) $b->id(), 'Second call should update the same app, not create a duplicate.');
  }

  /**
   * Import config objects from the prod default snapshot.
   *
   * Mirrors RepoNotificationServiceTest::importProdConfig — scrubs
   * third_party_settings and module dependencies for unmet modules so
   * the ConfigSchemaChecker doesn't trip in the kernel-test env.
   *
   * @param string[] $names
   *   Config object names (without .yml).
   */
  protected function importProdConfig(array $names): void {
    $source = new FileStorage(
      DRUPAL_ROOT . '/sites/default/config/default'
    );
    $target = \Drupal::service('config.storage');
    $configManager = \Drupal::service('config.manager');
    $entityTypeManager = \Drupal::entityTypeManager();
    foreach ($names as $name) {
      $data = $source->read($name);
      if ($data === FALSE) {
        throw new \RuntimeException("Missing prod config: $name");
      }
      if (isset($data['third_party_settings']) && is_array($data['third_party_settings'])) {
        foreach (array_keys($data['third_party_settings']) as $module) {
          if (!\Drupal::moduleHandler()->moduleExists($module)) {
            unset($data['third_party_settings'][$module]);
          }
        }
      }
      if (isset($data['dependencies']['module']) && is_array($data['dependencies']['module'])) {
        $data['dependencies']['module'] = array_values(array_filter(
          $data['dependencies']['module'],
          fn($m) => \Drupal::moduleHandler()->moduleExists($m)
        ));
      }
      $entityTypeId = $configManager->getEntityTypeIdByName($name);
      if ($entityTypeId) {
        $storage = $entityTypeManager->getStorage($entityTypeId);
        $idKey = $storage->getEntityType()->getKey('id');
        $existingId = $data[$idKey] ?? NULL;
        if ($existingId && $storage->load($existingId)) {
          continue;
        }
        $entity = $storage->createFromStorageRecord($data);
        $entity->save();
      }
      else {
        $target->write($name, $data);
      }
    }
  }

}
