<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Symfony\Component\Yaml\Yaml;

/**
 * Discovery tags (repo-level `tags:`) must use the exact resolver, not LIKE.
 *
 * The old resolveTagTerm() used a LIKE query, which treats `_` as a
 * single-character SQL wildcard. A declared tag like `c_t` could therefore
 * match any 3-character term (e.g. "cat", "cot") — or, with MySQL's ci
 * collation, could match unexpected terms. This test pins the correct
 * behaviour: only exact case-insensitive matches count; declared values with
 * wildcard-like characters must NOT match anything that doesn't literally equal
 * them.
 *
 * @coversDefaultClass \Drupal\ood_software\Service\RepoSyncService
 * @group ood_software
 */
class DiscoveryTagResolverTest extends KernelTestBase {

  use Traits\ProdConfigTrait;

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
   * The repo sync service under test.
   *
   * @var \Drupal\ood_software\Service\RepoSyncService
   */
  protected $repoSync;

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
      // The discovery tag field — the field under test.
      'field.storage.node.field_repo_tags',
      'field.field.node.appverse_repo.field_repo_tags',
      'field.storage.node.field_repo_readme',
      'field.field.node.appverse_repo.field_repo_readme',
      // appverse_app fields required by applyDeclared's full path.
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

    // The `tags` vocabulary — portal-wide discovery tags.
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    // Other vocabularies the sync path touches.
    Vocabulary::create([
      'vid' => 'appverse_implementation_tags',
      'name' => 'Implementation tags',
    ])->save();
    Vocabulary::create([
      'vid' => 'appverse_app_type',
      'name' => 'App type',
    ])->save();

    // Org term so resolveOrganizationTerm() can match.
    Term::create(['vid' => 'appverse_organization', 'name' => 'x'])->save();

    $this->repoSync = $this->container->get('ood_software.repo_sync');
  }

  /**
   * Build an appverse.yml string from a data array and sync the repo.
   *
   * @param array $rootData
   *   Scalar-value pairs to serialise as the root appverse.yml.
   *
   * @return \Drupal\node\NodeInterface
   *   The Repo node (reloaded after save so field values are fresh).
   */
  protected function syncDeclaredRepo(array $rootData): NodeInterface {
    $yaml = Yaml::dump($rootData, 4, 2);
    $url = 'https://github.com/x/y';
    $repo = $this->repoSync->resolveRepo($url, $yaml, []);
    // Reload so get() returns persisted field values.
    return Node::load($repo->id());
  }

  /**
   * Exact (case-insensitive) match only; a wildcard-like value must not match.
   *
   * Declares two tags: 'Containerized' (case-shifted version of the seeded
   * term) and 'c_t' (which under SQL LIKE is a 3-char wildcard pattern that
   * would match the seeded term 'cat'). After the fix only 'containerized'
   * resolves; 'c_t' resolves to nothing even though the seeded term 'cat'
   * would match under LIKE.
   *
   * @covers ::applyDeclared
   */
  public function testDiscoveryTagsUseExactResolverNotLike(): void {
    Term::create(['vid' => 'tags', 'name' => 'containerized'])->save();
    // 'cat' is a 3-char term; under LIKE, `c_t` would match it as a wildcard.
    Term::create(['vid' => 'tags', 'name' => 'cat'])->save();
    $repo = $this->syncDeclaredRepo(['description' => 'x', 'tags' => ['Containerized', 'c_t']]);
    $tids = array_column($repo->get('field_repo_tags')->getValue(), 'target_id');
    $names = array_map(fn($t) => Term::load($t)->label(), $tids);
    self::assertSame(['containerized'], $names, 'Exact (ci) match only; c_t must not wildcard-match.');
  }

}
