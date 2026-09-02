<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Regression guard for per-app implementation-tag resolution.
 *
 * A duplicate write in RepoSyncService::applyDeclaredApp re-resolved the
 * declared per-app `implementation_tags:` against the generic `tags`
 * vocabulary (vid='tags') and clobbered field_add_implementation_tags,
 * silently dropping valid implementation tags. The duplicate block was
 * deleted; the correct block always sets the field from terms resolved
 * against the appverse_implementation_tags vocabulary. These tests fail if
 * that bug is reintroduced.
 *
 * Note the two-vocabulary distinction: the per-app `implementation_tags:`
 * key resolves against appverse_implementation_tags, while a repo-level
 * `tags:` key (not exercised here at the app level) targets the generic
 * `tags` discovery vocabulary. testTagOnlyInGenericTagsVocabIsNotWritten
 * proves a value living only in the generic `tags` vocab is rejected, not
 * written, when declared as an app-level implementation tag.
 *
 * @coversDefaultClass \Drupal\ood_software\Service\RepoSyncService
 * @group ood_software
 */
class DeclaredAppTagsTest extends KernelTestBase {

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
      // The per-app implementation-tag field — the field under test.
      'field.storage.node.field_add_implementation_tags',
      'field.field.node.appverse_app.field_add_implementation_tags',
      // appverse_organization taxonomy used by resolveOrganizationTerm.
      'taxonomy.vocabulary.appverse_organization',
    ]);

    // field_repo_shape lives in the module's config/install.
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

    // The two competing vocabularies. field_add_implementation_tags targets
    // appverse_implementation_tags; the deleted duplicate write wrongly used
    // the generic `tags` vocab.
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

    // Real implementation-tag terms that the declared `tags:` resolve to.
    Term::create(['vid' => 'appverse_implementation_tags', 'name' => 'containerized'])->save();
    Term::create(['vid' => 'appverse_implementation_tags', 'name' => 'gpu-enabled'])->save();
    // A term ONLY in the generic vocab — deliberately not 'containerized' —
    // to prove the vocab distinction.
    Term::create(['vid' => 'tags', 'name' => 'containers'])->save();
    // App type term so app_type resolves.
    Term::create(['vid' => 'appverse_app_type', 'name' => 'batch-connect-basic'])->save();

    // Seed an org term so resolveOrganizationTerm() can match.
    Term::create(['vid' => 'appverse_organization', 'name' => 'x'])->save();

    // A published Software node so the declared `software:` resolves and the
    // app is not rejected on the software hard-gate. This keeps these tests
    // focused on tag behaviour: an unresolved implementation tag is now
    // drop-and-flag (app stays valid), so the only thing that could flip the
    // app to 'rejected' must be tags, not a missing software.
    Node::create([
      'type' => 'appverse_software',
      'title' => 'Jupyter',
      'status' => 1,
    ])->save();
  }

  /**
   * Create the parent appverse_repo node used by every test.
   */
  protected function createRepo(): Node {
    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => 'Test Repo',
      'field_repo_url' => ['uri' => 'https://github.com/x/y'],
      'field_repo_shape' => 'declared',
    ]);
    $repo->save();
    return $repo;
  }

  /**
   * Build a parsed-root-yaml declaring one app, with the given tags.
   */
  protected function rootYml(array $tags): array {
    return [
      'apps' => [
        [
          'path' => 'jup',
          'name' => 'Jupyter',
          'description' => 'desc',
          'app_type' => 'batch-connect-basic',
          'software' => 'Jupyter',
          'implementation_tags' => $tags,
          'maintainer' => ['name' => 'Team', 'support_url' => 'https://e.org'],
        ],
      ],
    ];
  }

  /**
   * Load the single member app created under the repo at subpath 'jup'.
   */
  protected function loadApp(Node $repo): Node {
    $ids = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appverse_app')
      ->condition('field_appverse_repo', $repo->id())
      ->condition('field_appverse_app_subpath', 'jup')
      ->execute();
    self::assertNotEmpty($ids, 'Expected a member app to have been created.');
    return Node::load((int) reset($ids));
  }

  /**
   * Look up a term id by name within a vocabulary.
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
   * The set of term ids referenced by field_add_implementation_tags.
   *
   * @return int[]
   */
  protected function appTagTids(Node $app): array {
    $tids = [];
    foreach ($app->get('field_add_implementation_tags')->getValue() as $item) {
      $tids[] = (int) $item['target_id'];
    }
    sort($tids);
    return $tids;
  }

  /**
   * Declared tags must resolve against the implementation-tag vocabulary.
   *
   * @covers ::applyDeclaredApp
   */
  public function testDeclaredAppTagsResolveAgainstImplementationVocab(): void {
    $sync = $this->container->get('ood_software.repo_sync');
    $repo = $this->createRepo();

    $sync->applyDeclaredApps(
      $repo,
      $this->rootYml(['containerized', 'gpu-enabled']),
      ['jup' => ['manifestYml' => NULL, 'appverseYml' => NULL]],
      'https://github.com/x/y',
      [],
    );

    $app = $this->loadApp($repo);

    $expected = [
      $this->tidByName('appverse_implementation_tags', 'containerized'),
      $this->tidByName('appverse_implementation_tags', 'gpu-enabled'),
    ];
    sort($expected);

    // Before the fix this field was clobbered to empty by the generic-`tags`
    // re-resolution where 'containerized'/'gpu-enabled' don't exist.
    self::assertSame(
      $expected,
      $this->appTagTids($app),
      'field_add_implementation_tags must hold both impl-tag term ids.'
    );
  }

  /**
   * Re-syncing with no tags clears the field.
   *
   * @covers ::applyDeclaredApp
   */
  public function testRemovingTagsClearsTheField(): void {
    $sync = $this->container->get('ood_software.repo_sync');
    $repo = $this->createRepo();

    $sync->applyDeclaredApps(
      $repo,
      $this->rootYml(['containerized', 'gpu-enabled']),
      ['jup' => ['manifestYml' => NULL, 'appverseYml' => NULL]],
      'https://github.com/x/y',
      [],
    );
    $app = $this->loadApp($repo);
    self::assertNotEmpty($this->appTagTids($app), 'Precondition: tags present after first sync.');

    // Second sync: same app, no tags.
    $sync->applyDeclaredApps(
      $repo,
      $this->rootYml([]),
      ['jup' => ['manifestYml' => NULL, 'appverseYml' => NULL]],
      'https://github.com/x/y',
      [],
    );

    $app = $this->loadApp($repo);
    self::assertSame([], $this->appTagTids($app), 'Removed tags must clear the field.');
  }

  /**
   * A tag present only in the generic `tags` vocab is not written + flagged.
   *
   * The vocab distinction is unchanged: a value living only in the generic
   * `tags` vocab still does not resolve against appverse_implementation_tags,
   * so it is NOT written to field_add_implementation_tags. What changed is the
   * handling of the unresolved tag — it is now drop-and-flag, not reject: the
   * app stays 'valid' and the unknown tag is recorded as a flag in
   * validation_er for a reviewer.
   *
   * @covers ::applyDeclaredApp
   */
  public function testTagOnlyInGenericTagsVocabIsNotWritten(): void {
    $sync = $this->container->get('ood_software.repo_sync');
    $repo = $this->createRepo();

    // 'containers' exists ONLY in the generic `tags` vocab.
    $sync->applyDeclaredApps(
      $repo,
      $this->rootYml(['containers']),
      ['jup' => ['manifestYml' => NULL, 'appverseYml' => NULL]],
      'https://github.com/x/y',
      [],
    );

    $app = $this->loadApp($repo);

    self::assertSame(
      [],
      $this->appTagTids($app),
      'A generic-vocab-only tag must not be written to the impl-tag field.'
    );
    // Drop-and-flag: the unresolved implementation tag no longer rejects.
    self::assertSame('valid', $app->get('field_appverse_app_validation_st')->value);

    $errors = [];
    foreach ($app->get('field_appverse_app_validation_er')->getValue() as $item) {
      $errors[] = $item['value'];
    }
    $joined = implode("\n", $errors);
    self::assertStringContainsString('containers', $joined);
    self::assertStringContainsString('implementation_tags', $joined);
  }

  /**
   * Import config objects from the prod default snapshot.
   *
   * Mirrors SyncInferredMemberAppTest::importProdConfig.
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
