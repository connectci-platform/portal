<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Covers persistence of structured unresolved implementation tags.
 *
 * RepoSyncService must always write field_appverse_unresolved_tags on every
 * sync: set to the declared-but-unresolved tag strings when any exist, or
 * cleared to [] when all tags resolve. This lets the reviewer hub render
 * per-tag [Create term] actions without re-parsing prose messages.
 *
 * @coversDefaultClass \Drupal\ood_software\Service\RepoSyncService
 * @group ood_software
 */
class UnresolvedTagsFieldTest extends KernelTestBase {

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
      // The per-app implementation-tag field.
      'field.storage.node.field_add_implementation_tags',
      'field.field.node.appverse_app.field_add_implementation_tags',
      'taxonomy.vocabulary.appverse_organization',
    ]);

    // Import fields from the module's config/install (not in the prod
    // snapshot). field_repo_shape is required because applyDeclared()
    // reads and writes it; field_appverse_unresolved_tags is the per-app
    // counterpart to the repo-level field_repo_unresolved_tags.
    $moduleSource = new FileStorage(
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
      $entity = $storage->createFromStorageRecord($data);
      $entity->save();
    }

    // Vocabularies.
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

    // Seed implementation-tag terms. Only 'gpu-enabled' is seeded so that
    // 'totally-bogus' reliably fails to resolve.
    Term::create(['vid' => 'appverse_implementation_tags', 'name' => 'gpu-enabled'])->save();

    // App type term so app_type resolves.
    Term::create(['vid' => 'appverse_app_type', 'name' => 'batch-connect-basic'])->save();
    // Org term so resolveOrganizationTerm() can match the GitHub owner.
    Term::create(['vid' => 'appverse_organization', 'name' => 'x'])->save();

    // A published Software node so the declared `software:` resolves.
    Node::create([
      'type' => 'appverse_software',
      'title' => 'Jupyter',
      'status' => 1,
    ])->save();
  }

  /**
   * Sync a single-app repo declaring the given implementation tags and return
   * the resulting app node.
   *
   * @param string[] $tags
   *
   * @return \Drupal\node\Entity\Node
   */
  protected function syncAppWithTags(array $tags): Node {
    $sync = $this->container->get('ood_software.repo_sync');

    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => 'Test Repo',
      'field_repo_url' => ['uri' => 'https://github.com/x/y'],
      'field_repo_shape' => 'declared',
    ]);
    $repo->save();

    $rootYml = [
      'apps' => [
        [
          'path' => 'app',
          'name' => 'Jupyter',
          'description' => 'desc',
          'app_type' => 'batch-connect-basic',
          'software' => 'Jupyter',
          'implementation_tags' => $tags,
          'maintainer' => ['name' => 'Team', 'support_url' => 'https://e.org'],
        ],
      ],
    ];

    $sync->applyDeclaredApps(
      $repo,
      $rootYml,
      ['app' => ['manifestYml' => NULL, 'appverseYml' => NULL]],
      'https://github.com/x/y',
      [],
    );

    $ids = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appverse_app')
      ->condition('field_appverse_repo', $repo->id())
      ->condition('field_appverse_app_subpath', 'app')
      ->execute();
    self::assertNotEmpty($ids, 'Expected an app node at subpath "app".');
    return Node::load((int) reset($ids));
  }

  /**
   * Unresolved tags are persisted to the field; the app stays valid.
   *
   * @covers ::applyDeclaredApp
   */
  public function testUnresolvedImplementationTagsArePersisted(): void {
    // Only 'gpu-enabled' is seeded in appverse_implementation_tags.
    $app = $this->syncAppWithTags(['gpu-enabled', 'totally-bogus']);
    $vals = array_column($app->get('field_appverse_unresolved_tags')->getValue(), 'value');
    self::assertSame(['totally-bogus'], $vals);
    // Drop-and-flag: the app stays valid despite the unresolved tag.
    self::assertSame('valid', $app->get('field_appverse_app_validation_st')->value);
  }

  /**
   * The field clears to [] when all declared tags resolve.
   *
   * @covers ::applyDeclaredApp
   */
  public function testUnresolvedFieldClearsWhenAllResolve(): void {
    // 'gpu-enabled' is seeded and resolves.
    $app = $this->syncAppWithTags(['gpu-enabled']);
    self::assertSame([], $app->get('field_appverse_unresolved_tags')->getValue());
  }

  /**
   * Import config objects from the prod default snapshot.
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
