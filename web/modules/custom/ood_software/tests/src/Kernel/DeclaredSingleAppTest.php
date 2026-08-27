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
 * Covers the SHAPE 1 single-app declared repo path.
 *
 * A root appverse.yml that declares top-level app metadata (software,
 * app_type, description, maintainer, title) but NO apps[] list is the
 * documented single-app repo (docs/appverse.yml SHAPE 1). The repo IS the
 * one app, at the repo root (subpath ''). Before the fix, the declared
 * submit path created the Repo node and ZERO apps. Now
 * RepoSyncService::applyDeclaredSingleApp() creates exactly one member app
 * at subpath '' by reusing applyDeclaredApp().
 *
 * @coversDefaultClass \Drupal\ood_software\Service\RepoSyncService
 * @group ood_software
 */
class DeclaredSingleAppTest extends KernelTestBase {

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
      // Repo bundle fields written by applyDeclared().
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
      // body is a base-table field; attach it to appverse_app.
      'field.field.node.appverse_app.body',
      // appverse_app fields written by applyDeclaredApp().
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
      'field.storage.node.field_repo_unresolved_tags',
      'field.field.node.appverse_repo.field_repo_unresolved_tags',
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

    // Vocabularies the sync resolves against.
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

    // App-type term so app_type resolves.
    Term::create(['vid' => 'appverse_app_type', 'name' => 'batch-connect-basic'])->save();
    // Implementation-tag term so the single-app root `implementation_tags:`
    // resolves against the appverse_implementation_tags vocab.
    Term::create(['vid' => 'appverse_implementation_tags', 'name' => 'gpu-enabled'])->save();
    // Org term so resolveOrganizationTerm() can match the GitHub owner.
    Term::create(['vid' => 'appverse_organization', 'name' => 'OSC'])->save();

    // A published Software node so the declared `software:` resolves.
    Node::create([
      'type' => 'appverse_software',
      'title' => 'RStudio',
      'status' => 1,
    ])->save();
  }

  /**
   * A root appverse.yml with top-level app metadata and no apps[] list.
   *
   * Mirrors docs/appverse.yml SHAPE 1. Note `title` (not `name`) — the
   * single-app path maps the root title onto the app's name. The app's
   * IMPLEMENTATION tags are declared at the root with `implementation_tags:`
   * (a bare root `tags:` is repo-level DISCOVERY tags, handled separately in
   * applyDeclared()).
   */
  protected function singleAppRootYml(): string {
    return <<<YAML
title: "RStudio Server"
description: "RStudio Server on HPC via Open OnDemand."
software: "RStudio"
app_type: "batch-connect-basic"
implementation_tags:
  - "gpu-enabled"
maintainer:
  name: "OSC User Support"
  support_url: "https://example.org/support"
YAML;
  }

  /**
   * Member apps under a repo.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  protected function memberApps(NodeInterface $repo): array {
    $ids = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appverse_app')
      ->condition('field_appverse_repo', $repo->id())
      ->execute();
    return Node::loadMultiple($ids);
  }

  /**
   * A declared single-app repo creates exactly one app at subpath ''.
   *
   * @covers ::applyDeclaredSingleApp
   */
  public function testSingleAppRepoCreatesOneRootApp(): void {
    $sync = $this->container->get('ood_software.repo_sync');
    $url = 'https://github.com/OSC/bc_osc_rstudio';

    // Build the Repo via resolveRepo (declared shape — appverse.yml present).
    $repo = $sync->resolveRepo($url, $this->singleAppRootYml(), [
      'organization' => 'OSC',
    ]);
    self::assertSame('appverse_repo', $repo->bundle());
    self::assertSame('declared', $repo->get('field_repo_shape')->value);

    // Parse the same root yaml the form layer would parse, with no apps[].
    $parsedRootYml = \Symfony\Component\Yaml\Yaml::parse($this->singleAppRootYml());
    self::assertArrayNotHasKey('apps', $parsedRootYml, 'SHAPE 1 has no apps[] list.');

    $app = $sync->applyDeclaredSingleApp(
      $repo,
      $parsedRootYml,
      ['manifestYml' => NULL, 'appverseYml' => NULL, 'readme' => ''],
      $url,
      ['organization' => 'OSC'],
    );

    // Exactly ONE app, at subpath '', linked to the repo.
    $apps = $this->memberApps($repo);
    self::assertCount(1, $apps, 'Expected exactly one member app.');
    self::assertSame((int) $app->id(), (int) reset($apps)->id());

    self::assertSame('appverse_app', $app->bundle());
    self::assertSame((int) $repo->id(), (int) $app->get('field_appverse_repo')->target_id);
    self::assertSame('', (string) $app->get('field_appverse_app_subpath')->value);

    // Declared title maps onto the app name; description onto the body.
    self::assertSame('RStudio Server', $app->label());
    self::assertSame('RStudio Server on HPC via Open OnDemand.', $app->get('body')->value);

    // app_type resolved to the seeded term.
    $appTypeTid = (int) $app->get('field_appverse_app_type')->target_id;
    self::assertGreaterThan(0, $appTypeTid);
    self::assertSame('batch-connect-basic', Term::load($appTypeTid)->label());

    // software resolved to the seeded Software node.
    $swNid = (int) $app->get('field_appverse_software_implemen')->target_id;
    self::assertGreaterThan(0, $swNid);
    self::assertSame('RStudio', Node::load($swNid)->label());

    // Root-level `implementation_tags:` flow into the app's implementation
    // tags. This is the single-app implementation-tag key: a bare root
    // `tags:` would be repo-level DISCOVERY tags, NOT implementation tags.
    $tagTids = [];
    foreach ($app->get('field_add_implementation_tags')->getValue() as $item) {
      $tagTids[] = (int) $item['target_id'];
    }
    self::assertCount(1, $tagTids, 'Single-app root implementation_tags must resolve to one term.');
    self::assertSame('gpu-enabled', Term::load($tagTids[0])->label());

    // All required fields present + software matched => valid.
    self::assertSame('valid', $app->get('field_appverse_app_validation_st')->value);

    // New app starts in draft (mirrors syncInferredMemberApp).
    self::assertSame('draft', $app->get('moderation_state')->value);
  }

  /**
   * Re-running the single-app sync updates the same app, no duplicate.
   *
   * @covers ::applyDeclaredSingleApp
   */
  public function testSingleAppSyncIsIdempotent(): void {
    $sync = $this->container->get('ood_software.repo_sync');
    $url = 'https://github.com/OSC/bc_osc_rstudio';
    $repo = $sync->resolveRepo($url, $this->singleAppRootYml(), ['organization' => 'OSC']);
    $parsedRootYml = \Symfony\Component\Yaml\Yaml::parse($this->singleAppRootYml());

    $a = $sync->applyDeclaredSingleApp($repo, $parsedRootYml, [], $url, ['organization' => 'OSC']);
    $b = $sync->applyDeclaredSingleApp($repo, $parsedRootYml, [], $url, ['organization' => 'OSC']);

    self::assertSame((int) $a->id(), (int) $b->id(), 'Second sync must update the same app.');
    self::assertCount(1, $this->memberApps($repo), 'Still exactly one member app.');
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
