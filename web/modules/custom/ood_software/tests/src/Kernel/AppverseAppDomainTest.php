<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\domain\Entity\Domain;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Symfony\Component\Yaml\Yaml;

/**
 * Covers D8-2888: appverse_app nodes always land on the OOD domain.
 *
 * Apps created by RepoSyncService skip the field widget's add_current_domain,
 * so ood_software_node_presave() fills an empty field_domain_access and the
 * 10016 deploy hook backfills apps already stored without one.
 *
 * @group ood_software
 */
class AppverseAppDomainTest extends KernelTestBase {

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
    'domain',
    'domain_access',
    // ood_software.gh depends on the `key` module's key.repository service.
    'key',
    // ood_software_node_insert() calls the `flag` service.
    'flag',
    'file', 'ood_software',
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
      'domain',
    ]);

    $this->importProdConfig([
      'node.type.appverse_repo',
      'node.type.appverse_app',
      'node.type.appverse_software',
      'workflows.workflow.appverse_editorial',
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
      'field.storage.node.field_appverse_software_implemen',
      'field.field.node.appverse_app.field_appverse_software_implemen',
      'field.storage.node.field_appverse_app_validation_st',
      'field.field.node.appverse_app.field_appverse_app_validation_st',
      'field.storage.node.field_appverse_app_validation_er',
      'field.field.node.appverse_app.field_appverse_app_validation_er',
      'field.storage.node.field_add_implementation_tags',
      'field.field.node.appverse_app.field_add_implementation_tags',
      // The domain field exactly as production defines it.
      'field.storage.node.field_domain_access',
      'field.field.node.appverse_app.field_domain_access',
      'taxonomy.vocabulary.appverse_organization',
    ]);

    // Module-owned config, as DeclaredSingleAppTest does.
    $this->importModuleInstallConfig([
      'field.storage.node.field_repo_shape',
      'field.field.node.appverse_repo.field_repo_shape',
      'field.storage.node.field_appverse_unresolved_tags',
      'field.field.node.appverse_app.field_appverse_unresolved_tags',
      'field.storage.node.field_repo_unresolved_tags',
      'field.field.node.appverse_repo.field_repo_unresolved_tags',
    ]);

    Vocabulary::create(['vid' => 'appverse_implementation_tags', 'name' => 'Implementation tags'])->save();
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    Vocabulary::create(['vid' => 'appverse_app_type', 'name' => 'App type'])->save();
    Term::create(['vid' => 'appverse_app_type', 'name' => 'batch-connect-basic'])->save();
    Term::create(['vid' => 'appverse_organization', 'name' => 'OSC'])->save();
    Node::create(['type' => 'appverse_software', 'title' => 'RStudio', 'status' => 1])->save();

    foreach ([
      'openondemand_cyberinfrastructure_org' => 'ondemand.example.org',
      'amp_cyberinfrastructure_org' => 'support.example.org',
    ] as $id => $hostname) {
      Domain::create([
        'id' => $id,
        'hostname' => $hostname,
        'name' => $id,
        'scheme' => 'https',
        'status' => 1,
      ])->save();
    }
  }

  /**
   * Imports config from the ood_software module's config/install directory.
   *
   * @param string[] $names
   *   Config object names (without .yml).
   */
  protected function importModuleInstallConfig(array $names): void {
    $source = new FileStorage(
      DRUPAL_ROOT . '/modules/custom/ood_software/config/install'
    );
    $configManager = \Drupal::service('config.manager');
    $entityTypeManager = \Drupal::entityTypeManager();
    foreach ($names as $name) {
      $data = $source->read($name);
      if ($data === FALSE) {
        throw new \RuntimeException("Missing ood_software install config: $name");
      }
      $storage = $entityTypeManager->getStorage($configManager->getEntityTypeIdByName($name));
      $idKey = $storage->getEntityType()->getKey('id');
      if (!empty($data[$idKey]) && $storage->load($data[$idKey])) {
        continue;
      }
      $storage->createFromStorageRecord($data)->save();
    }
  }

  /**
   * Returns the domain ids stored on a node.
   *
   * @return string[]
   *   Domain ids from field_domain_access.
   */
  protected function domainIds(NodeInterface $node): array {
    return array_column($node->get('field_domain_access')->getValue(), 'target_id');
  }

  /**
   * Creates a repo node.
   */
  protected function createRepo(string $shape): NodeInterface {
    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => 'Domain Test Repo',
      'field_repo_url' => ['uri' => 'https://github.com/OSC/bc_osc_domain'],
      'field_repo_shape' => $shape,
    ]);
    $repo->save();
    return $repo;
  }

  /**
   * Repo metadata as the sync receives it.
   *
   * @return array<string, mixed>
   *   Metadata array.
   */
  protected function repoMetadata(int $stars): array {
    return [
      'name' => 'Domain App',
      'description' => 'Desc.',
      'organization' => 'OSC',
      'stars' => $stars,
      'lastCommittedDate' => 1,
      'readme' => '',
    ];
  }

  /**
   * Creates an inferred member app through RepoSyncService.
   */
  protected function createInferredApp(): NodeInterface {
    $repo = $this->createRepo('inferred');
    return $this->container->get('ood_software.repo_sync')->syncInferredMemberApp(
      $repo,
      'https://github.com/OSC/bc_osc_domain',
      ['name' => 'Domain App', 'description' => 'Desc.', 'role' => 'jupyter'],
      $this->repoMetadata(1),
    );
  }

  /**
   * An inferred member app created by the sync lands on the OOD domain.
   */
  public function testInferredSyncSetsDomain(): void {
    $app = $this->createInferredApp();
    self::assertSame(['openondemand_cyberinfrastructure_org'], $this->domainIds($app));
  }

  /**
   * A declared single-app create lands on the OOD domain.
   */
  public function testDeclaredSyncSetsDomain(): void {
    $sync = $this->container->get('ood_software.repo_sync');
    $url = 'https://github.com/OSC/bc_osc_rstudio';
    $yml = <<<YAML
title: "RStudio Server"
description: "RStudio Server on HPC via Open OnDemand."
software: "RStudio"
app_type: "batch-connect-basic"
maintainer:
  name: "OSC User Support"
  support_url: "https://example.org/support"
YAML;
    $repo = $sync->resolveRepo($url, $yml, ['organization' => 'OSC']);
    $app = $sync->applyDeclaredSingleApp(
      $repo,
      Yaml::parse($yml),
      ['manifestYml' => NULL, 'appverseYml' => NULL, 'readme' => ''],
      $url,
      ['organization' => 'OSC'],
    );
    self::assertSame(['openondemand_cyberinfrastructure_org'], $this->domainIds($app));
  }

  /**
   * An app already assigned to another domain keeps exactly that value.
   */
  public function testExistingDomainIsNotOverwritten(): void {
    $app = $this->createInferredApp();
    $app->set('field_domain_access', ['amp_cyberinfrastructure_org']);
    $app->save();

    // Re-sync through the app's own repo so the sync updates this node.
    $repo = $app->get('field_appverse_repo')->entity;
    $again = $this->container->get('ood_software.repo_sync')->syncInferredMemberApp(
      $repo,
      'https://github.com/OSC/bc_osc_domain',
      ['name' => 'Domain App', 'description' => 'Desc.', 'role' => 'jupyter'],
      $this->repoMetadata(2),
    );
    self::assertSame($app->id(), $again->id(), 'Re-sync should update the existing app.');

    $reloaded = Node::load($app->id());
    self::assertSame(['amp_cyberinfrastructure_org'], $this->domainIds($reloaded));
  }

  /**
   * Removes every stored domain value for the given node, all revisions.
   */
  protected function wipeDomain(int $nid): void {
    $db = \Drupal::database();
    $db->delete('node__field_domain_access')->condition('entity_id', $nid)->execute();
    $db->delete('node_revision__field_domain_access')->condition('entity_id', $nid)->execute();
    \Drupal::entityTypeManager()->getStorage('node')->resetCache();
  }

  /**
   * Runs the 10016 deploy hook to completion.
   */
  protected function runBackfill(): void {
    $path = \Drupal::service('extension.list.module')->getPath('ood_software');
    require_once DRUPAL_ROOT . '/' . $path . '/ood_software.deploy.php';
    $sandbox = [];
    $guard = 0;
    do {
      ood_software_deploy_10016_backfill_app_domain($sandbox);
    } while (($sandbox['#finished'] ?? 0) < 1 && ++$guard < 50);
  }

  /**
   * The backfill fills the domain without a new revision or state change.
   */
  public function testBackfillSetsDomainWithoutNewRevision(): void {
    $app = $this->createInferredApp();
    $nid = (int) $app->id();
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $revisionsBefore = count($storage->getQuery()->accessCheck(FALSE)->allRevisions()->condition('nid', $nid)->execute());
    $stateBefore = $app->get('moderation_state')->value;

    $this->wipeDomain($nid);
    self::assertTrue(Node::load($nid)->get('field_domain_access')->isEmpty());

    $this->runBackfill();

    $storage->resetCache();
    $reloaded = Node::load($nid);
    self::assertSame(['openondemand_cyberinfrastructure_org'], $this->domainIds($reloaded));
    self::assertSame($stateBefore, $reloaded->get('moderation_state')->value);
    $revisionsAfter = count($storage->getQuery()->accessCheck(FALSE)->allRevisions()->condition('nid', $nid)->execute());
    self::assertSame($revisionsBefore, $revisionsAfter);

    // An app with no domain falls back to the default domain's grants, so the
    // backfill save must rewrite node_access onto the OOD domain only.
    $oodGid = (int) Domain::load('openondemand_cyberinfrastructure_org')->getDomainId();
    $gids = \Drupal::database()->select('node_access', 'na')
      ->fields('na', ['gid'])
      ->condition('nid', $nid)
      ->condition('realm', ['domain_id', 'domain_unpublished'], 'IN')
      ->execute()
      ->fetchCol();
    self::assertSame([$oodGid], array_values(array_unique(array_map('intval', $gids))));
  }

  /**
   * The backfill also fills a pending forward revision.
   */
  public function testBackfillFillsPendingForwardRevision(): void {
    $app = $this->createInferredApp();
    $nid = (int) $app->id();
    $storage = \Drupal::entityTypeManager()->getStorage('node');

    // Publish, then add a ready_for_review forward revision on top.
    $app->set('moderation_state', 'published');
    $app->save();
    $published = Node::load($nid);
    $published->set('moderation_state', 'ready_for_review');
    $published->setNewRevision(TRUE);
    $published->save();

    $defaultVid = (int) Node::load($nid)->getRevisionId();
    $latestVid = (int) $storage->getLatestRevisionId($nid);
    self::assertNotSame($defaultVid, $latestVid, 'Expected a forward revision.');

    $this->wipeDomain($nid);
    $this->runBackfill();

    $storage->resetCache();
    self::assertSame(['openondemand_cyberinfrastructure_org'], $this->domainIds($storage->loadRevision($defaultVid)));
    self::assertSame(['openondemand_cyberinfrastructure_org'], $this->domainIds($storage->loadRevision($latestVid)));
    self::assertSame($latestVid, (int) $storage->getLatestRevisionId($nid));
    self::assertSame($defaultVid, (int) Node::load($nid)->getRevisionId());
  }

}
