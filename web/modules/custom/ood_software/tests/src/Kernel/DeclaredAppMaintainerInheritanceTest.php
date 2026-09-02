<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Covers repo-level maintainer inheritance for monorepo (apps[]) apps.
 *
 * In a monorepo (root appverse.yml with an apps[] list), an app entry that
 * does NOT declare its own `maintainer` inherits the repo-level top-level
 * `maintainer` mapping. An app that declares its own maintainer overrides
 * the repo-level one. The inheritance rule is whole-block-only: the entire
 * root maintainer mapping is inherited solely when the app declares no
 * `maintainer` key at all; a partial app-level maintainer is NOT merged with
 * the root's (it's the app's own problem, so it falls through to the normal
 * rejected-on-missing path).
 *
 * @coversDefaultClass \Drupal\ood_software\Service\RepoSyncService
 * @group ood_software
 */
class DeclaredAppMaintainerInheritanceTest extends KernelTestBase {

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
      'field.storage.node.field_add_implementation_tags',
      'field.field.node.appverse_app.field_add_implementation_tags',
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

    Vocabulary::create([
      'vid' => 'appverse_implementation_tags',
      'name' => 'Implementation tags',
    ])->save();
    Vocabulary::create([
      'vid' => 'appverse_app_type',
      'name' => 'App type',
    ])->save();

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
   * Load a member app under the repo at the given subpath.
   */
  protected function loadApp(Node $repo, string $subpath): Node {
    $ids = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appverse_app')
      ->condition('field_appverse_repo', $repo->id())
      ->condition('field_appverse_app_subpath', $subpath)
      ->execute();
    self::assertNotEmpty($ids, "Expected a member app at subpath '$subpath'.");
    return Node::load((int) reset($ids));
  }

  /**
   * Validation status of an app.
   */
  protected function status(Node $app): string {
    return (string) $app->get('field_appverse_app_validation_st')->value;
  }

  /**
   * Joined validation error messages of an app.
   */
  protected function errors(Node $app): string {
    $errors = [];
    foreach ($app->get('field_appverse_app_validation_er')->getValue() as $item) {
      $errors[] = $item['value'];
    }
    return implode("\n", $errors);
  }

  /**
   * An app with no maintainer inherits the repo-level maintainer.
   *
   * @covers ::applyDeclaredApps
   * @covers ::applyDeclaredApp
   */
  public function testAppWithoutMaintainerInheritsRepoMaintainer(): void {
    $sync = $this->container->get('ood_software.repo_sync');
    $repo = $this->createRepo();

    $rootYml = [
      // Repo-level (top-level) maintainer.
      'maintainer' => [
        'name' => 'Repo Team',
        'support_url' => 'https://repo.example.org/support',
      ],
      'apps' => [
        [
          'path' => 'jup',
          'name' => 'Jupyter',
          'description' => 'desc',
          'app_type' => 'batch-connect-basic',
          'software' => 'Jupyter',
          // No maintainer of its own.
        ],
      ],
    ];

    $sync->applyDeclaredApps(
      $repo,
      $rootYml,
      ['jup' => ['manifestYml' => NULL, 'appverseYml' => NULL]],
      'https://github.com/x/y',
      [],
    );

    $app = $this->loadApp($repo, 'jup');

    // Inherited the repo maintainer name; valid (not rejected for missing
    // maintainer.name / maintainer.support_url).
    self::assertSame('valid', $this->status($app), 'App should be valid via inherited maintainer. Errors: ' . $this->errors($app));
    self::assertSame('Repo Team', (string) $app->get('field_appverse_maintainer_name')->value);
  }

  /**
   * An app with its own maintainer overrides the repo-level maintainer.
   *
   * @covers ::applyDeclaredApps
   * @covers ::applyDeclaredApp
   */
  public function testAppWithOwnMaintainerOverridesRepoMaintainer(): void {
    $sync = $this->container->get('ood_software.repo_sync');
    $repo = $this->createRepo();

    $rootYml = [
      'maintainer' => [
        'name' => 'Repo Team',
        'support_url' => 'https://repo.example.org/support',
      ],
      'apps' => [
        [
          'path' => 'jup',
          'name' => 'Jupyter',
          'description' => 'desc',
          'app_type' => 'batch-connect-basic',
          'software' => 'Jupyter',
          'maintainer' => [
            'name' => 'App Owner',
            'support_url' => 'https://app.example.org/support',
          ],
        ],
      ],
    ];

    $sync->applyDeclaredApps(
      $repo,
      $rootYml,
      ['jup' => ['manifestYml' => NULL, 'appverseYml' => NULL]],
      'https://github.com/x/y',
      [],
    );

    $app = $this->loadApp($repo, 'jup');

    self::assertSame('valid', $this->status($app), 'Errors: ' . $this->errors($app));
    self::assertSame(
      'App Owner',
      (string) $app->get('field_appverse_maintainer_name')->value,
      'App-level maintainer must override the repo-level one.'
    );
  }

  /**
   * A partial app-level maintainer is NOT merged with the repo's.
   *
   * The whole-block-only rule: because the app declared a `maintainer` key
   * (even an incomplete one), the root maintainer is not inherited. The
   * missing support_url then trips the normal rejected-on-missing path.
   *
   * @covers ::applyDeclaredApps
   * @covers ::applyDeclaredApp
   */
  public function testPartialAppMaintainerIsNotMergedWithRepoMaintainer(): void {
    $sync = $this->container->get('ood_software.repo_sync');
    $repo = $this->createRepo();

    $rootYml = [
      'maintainer' => [
        'name' => 'Repo Team',
        'support_url' => 'https://repo.example.org/support',
      ],
      'apps' => [
        [
          'path' => 'jup',
          'name' => 'Jupyter',
          'description' => 'desc',
          'app_type' => 'batch-connect-basic',
          'software' => 'Jupyter',
          // Declares only name — no support_url. Per the whole-block rule
          // the repo's support_url is NOT filled in.
          'maintainer' => [
            'name' => 'App Owner',
          ],
        ],
      ],
    ];

    $sync->applyDeclaredApps(
      $repo,
      $rootYml,
      ['jup' => ['manifestYml' => NULL, 'appverseYml' => NULL]],
      'https://github.com/x/y',
      [],
    );

    $app = $this->loadApp($repo, 'jup');

    // Because the app declared its own (partial) maintainer, the repo's
    // maintainer block is NOT inherited and its support_url is NOT merged in.
    // Missing support_url therefore trips the rejected-on-missing path —
    // proving the whole-block-only rule (had we merged sub-fields, the repo's
    // support_url would satisfy the requirement and the app would be valid).
    self::assertSame('rejected', $this->status($app));
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
