<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\ood_software\Controller\AppverseHubController;
use Drupal\ood_software\Plugin\GitHubService;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Regression test: resync of a single-app repo applies app-level metadata.
 *
 * Bug: AppverseHubController::resync() calls resolveRepo() (which writes only
 * repo-level fields) and, for the multi-app case, delegates to the batch
 * helper. But a SHAPE 1 declared single-app repo (root appverse.yml with
 * top-level app metadata and NO apps[] list) falls through to the
 * "Single-app / inferred repo: resolveRepo already synced the one app"
 * branch — which is FALSE for the declared single-app shape. resolveRepo()
 * never syncs the member app; only applyDeclaredSingleApp() does, and resync
 * never calls it (only AddRepoForm::submitDeclared does).
 *
 * The real-world scenario: a repo already registered on the INFERRED shape
 * (manifest.yml only, one member app) later gains a single-app appverse.yml.
 * The maintainer clicks Re-sync. The repo flips to 'declared', but the app's
 * declared metadata (software, app_type, implementation tags) is never
 * applied — the member app keeps its inferred, software-less state.
 *
 * This test drives the controller's resync() directly with a stubbed
 * GitHubService returning a single-app appverse.yml, and asserts the member
 * app gets its declared `software:` reference.
 *
 * @group ood_software
 */
class ResyncSingleAppMetadataTest extends KernelTestBase {

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
  protected string $repoUrl = 'https://github.com/OSC/bc_osc_rstudio';

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
      $storage->createFromStorageRecord($data)->save();
    }

    Vocabulary::create(['vid' => 'appverse_implementation_tags', 'name' => 'Implementation tags'])->save();
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    Vocabulary::create(['vid' => 'appverse_app_type', 'name' => 'App type'])->save();

    Term::create(['vid' => 'appverse_app_type', 'name' => 'batch-connect-basic'])->save();
    Term::create(['vid' => 'appverse_implementation_tags', 'name' => 'gpu-enabled'])->save();
    Term::create(['vid' => 'appverse_organization', 'name' => 'OSC'])->save();

    Node::create(['type' => 'appverse_software', 'title' => 'RStudio', 'status' => 1])->save();
  }

  /**
   * A root appverse.yml with top-level app metadata and no apps[] list.
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
   * Build an AppverseHubController with a stubbed GitHubService.
   *
   * The stub reports the repo as declared (single-app appverse.yml present),
   * returning the SHAPE 1 root yaml from getAppverseYmlText(). Everything else
   * is a benign fixed value — resync() only reads the metadata getters below.
   */
  private function makeController(): AppverseHubController {
    $rootYml = $this->singleAppRootYml();
    $repoUrl = $this->repoUrl;

    $github = new class(
      $this->container->get('http_client'),
      $this->container->get('key.repository'),
      $this->container->get('messenger'),
      $this->container->get('logger.factory'),
      $this->container->get('entity_type.manager'),
    ) extends GitHubService {

      // phpcs:disable Drupal.Commenting.FunctionComment, Drupal.Commenting.VariableComment
      public string $rootYml = '';
      public string $stubRepoUrl = '';

      public function parseUrl($repo) {
        return TRUE;
      }

      public function getData() {}

      public function getAppverseYmlText(): ?string {
        return $this->rootYml;
      }

      public function getStars() {
        return 0;
      }

      public function getLastComittedDate() {
        return '2000000000';
      }

      public function getOrganization() {
        return 'OSC';
      }

      public function getDescription() {
        return 'RStudio Server on HPC via Open OnDemand.';
      }

      public function getReadme() {
        return '';
      }

      public function getLicense() {
        return NULL;
      }

      public function getLicenseLink() {
        return NULL;
      }

      public function getIsArchived() {
        return FALSE;
      }

      public function getRepoUrl(): string {
        return $this->stubRepoUrl;
      }

      // phpcs:enable Drupal.Commenting.FunctionComment, Drupal.Commenting.VariableComment
    };
    $github->rootYml = $rootYml;
    $github->stubRepoUrl = $repoUrl;

    return new AppverseHubController(
      $this->container->get('ood_software.repo_sync'),
      $github,
      $this->container->get('datetime.time'),
      $this->container->get('content_moderation.moderation_information'),
      $this->container->get('request_stack'),
      $this->container->get('ood_software.repo_member_apps'),
    );
  }

  /**
   * Resync applies declared app-level metadata to a single-app member.
   *
   * When a repo registered on the inferred shape later gains a single-app
   * appverse.yml, resync must apply the declared app metadata (software,
   * app_type, tags) to the existing member app — not just flip the repo shape.
   */
  public function testResyncSingleAppAppliesDeclaredMetadata(): void {
    // Push a request so hubRedirectUrl()/currentUser() work during redirect.
    // Attach a session to avoid the deprecation warning about session-less
    // requests on the request_stack.
    $request = Request::create('/');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    // Start from the INFERRED shape: a repo + one member app with no
    // software reference, exactly as the add-repo form leaves an inferred
    // single-app repo.
    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => 'bc_osc_rstudio',
      'field_repo_url' => ['uri' => $this->repoUrl],
      'field_repo_shape' => 'inferred',
      'moderation_state' => 'published',
      'status' => 1,
    ]);
    $repo->save();

    $app = Node::create([
      'type' => 'appverse_app',
      'title' => 'RStudio (inferred)',
      'field_appverse_repo' => $repo->id(),
      'field_appverse_github_url' => ['uri' => $this->repoUrl],
      'field_appverse_app_subpath' => '',
      'moderation_state' => 'draft',
    ]);
    $app->save();

    // Sanity: the member app has no software before resync.
    self::assertTrue($app->get('field_appverse_software_implemen')->isEmpty(),
      'Inferred member app must start without a software reference.');

    // Act: resync via the controller, with the single-app appverse.yml now
    // present on the repo.
    $this->makeController()->resync($repo);

    // Reload the member app.
    \Drupal::entityTypeManager()->getStorage('node')->resetCache();
    $apps = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appverse_app')
      ->condition('field_appverse_repo', $repo->id())
      ->execute();
    self::assertCount(1, $apps, 'Still exactly one member app after resync.');
    $freshApp = Node::load((int) reset($apps));

    // ASSERT: the declared `software:` was applied to the member app.
    $swNid = (int) $freshApp->get('field_appverse_software_implemen')->target_id;
    self::assertGreaterThan(0, $swNid,
      'Resync must apply the declared software reference to the single-app member.');
    self::assertSame('RStudio', Node::load($swNid)->label());
  }

  /**
   * Resync preserves an already-published app's node identity and state.
   *
   * The contributor docs promise that adding an appverse.yml to a repo already
   * in the catalog updates the existing entry in place — same entry, same URL,
   * same review status — rather than replacing it or sending it back to draft.
   * A listed app is published, so that is the state that must survive.
   */
  public function testResyncPreservesPublishedAppIdentityAndState(): void {
    $request = Request::create('/');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => 'bc_osc_rstudio',
      'field_repo_url' => ['uri' => $this->repoUrl],
      'field_repo_shape' => 'inferred',
      'moderation_state' => 'published',
      'status' => 1,
    ]);
    $repo->save();

    // An app already LISTED in the catalog: published, not draft.
    $app = Node::create([
      'type' => 'appverse_app',
      'title' => 'RStudio (inferred)',
      'field_appverse_repo' => $repo->id(),
      'field_appverse_github_url' => ['uri' => $this->repoUrl],
      'field_appverse_app_subpath' => '',
      'moderation_state' => 'published',
    ]);
    $app->save();
    $originalAppId = (int) $app->id();
    self::assertSame('published', $app->get('moderation_state')->value);

    $this->makeController()->resync($repo);

    \Drupal::entityTypeManager()->getStorage('node')->resetCache();
    $apps = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appverse_app')
      ->condition('field_appverse_repo', $repo->id())
      ->execute();
    self::assertCount(1, $apps, 'Resync must not create a second app.');

    $freshApp = Node::load((int) reset($apps));

    // Same node — the entry is updated in place, not replaced. This is what
    // keeps the app's catalog URL stable.
    self::assertSame($originalAppId, (int) $freshApp->id(),
      'Resync must update the existing app node, not create a replacement.');

    // Still published — a resync must not send a listed app back to draft.
    self::assertSame('published', $freshApp->get('moderation_state')->value,
      'Resync must preserve an already-published app\'s moderation state.');
    self::assertTrue($freshApp->isPublished(),
      'Resync must leave an already-published app published.');

    // And the declared metadata still landed.
    self::assertGreaterThan(0, (int) $freshApp->get('field_appverse_software_implemen')->target_id,
      'Declared software must still be applied on the published-app path.');
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
