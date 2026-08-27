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
 * Covers persistence of unresolved repo-level discovery tags.
 *
 * A discovery-tag miss is a FLAG, not a degrade — the repo must NOT be marked
 * degraded/invalid solely because a declared `tags:` value didn't resolve.
 * Unresolved values are persisted to field_repo_unresolved_tags; the field is
 * always set (cleared to [] when all resolve) so it never goes stale.
 *
 * @coversDefaultClass \Drupal\ood_software\Service\RepoSyncService
 * @group ood_software
 */
class DiscoveryTagFlagTest extends KernelTestBase {

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
      // The discovery tag field — field_repo_tags holds resolved TIDs.
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

    // Import module-only fields from config/install (not in prod snapshot).
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
   * Sync a repo from a root appverse.yml data array.
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
   * Unresolved discovery tags are flagged; resolved ones are stored; no degrade.
   *
   * Declares 'containerized' (seeded) and 'containerised' (British spelling,
   * no matching term). The resolved tag goes to field_repo_tags; the unresolved
   * value goes to field_repo_unresolved_tags; and the repo is NOT degraded.
   *
   * @covers ::applyDeclared
   */
  public function testUnresolvedDiscoveryTagIsFlaggedNotDegraded(): void {
    // Seed only the American spelling — the British spelling has no term.
    Term::create(['vid' => 'tags', 'name' => 'containerized'])->save();

    $repo = $this->syncDeclaredRepo([
      'description' => 'x',
      'tags' => ['containerized', 'containerised'],
    ]);

    // Resolved tag is stored in field_repo_tags.
    $tids = array_column($repo->get('field_repo_tags')->getValue(), 'target_id');
    $names = array_map(fn($t) => Term::load($t)->label(), $tids);
    self::assertSame(['containerized'], $names, 'Resolved tag written to field_repo_tags.');

    // Unresolved tag is flagged in field_repo_unresolved_tags.
    $unresolved = array_column($repo->get('field_repo_unresolved_tags')->getValue(), 'value');
    self::assertSame(['containerised'], $unresolved, 'Unresolved tag written to field_repo_unresolved_tags.');

    // Discovery-tag miss is a FLAG, not a degrade — repo stays valid.
    self::assertSame('valid', $repo->get('field_repo_validation_st')->value,
      'Repo validation status must not be degraded by an unresolved discovery tag alone.');
  }

  /**
   * The unresolved field clears to [] when all discovery tags resolve.
   *
   * @covers ::applyDeclared
   */
  public function testUnresolvedFieldClearsWhenAllDiscoveryTagsResolve(): void {
    Term::create(['vid' => 'tags', 'name' => 'containerized'])->save();

    $repo = $this->syncDeclaredRepo([
      'description' => 'x',
      'tags' => ['containerized'],
    ]);

    self::assertSame([], $repo->get('field_repo_unresolved_tags')->getValue(),
      'field_repo_unresolved_tags must be empty when all declared tags resolved.');
  }

  /**
   * The unresolved field is set even when no tags are declared.
   *
   * @covers ::applyDeclared
   */
  public function testUnresolvedFieldSetWhenNoTagsDeclared(): void {
    $repo = $this->syncDeclaredRepo(['description' => 'x']);

    self::assertSame([], $repo->get('field_repo_unresolved_tags')->getValue(),
      'field_repo_unresolved_tags must be empty (not missing) when no tags declared.');
  }

}
