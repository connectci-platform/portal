<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Service\AppverseCacheService;

/**
 * End-to-end guard for the "cache stale after publish" cascade bug.
 *
 * The original production bug: when a Repo was first-published, its member
 * apps were published via a cascade of app-only saves that fell inside a 60s
 * regen throttle and were dropped, so the regenerated JSON showed the Repo
 * with zero apps until cron. The refactor replaced the throttle with deferred
 * once-per-request invalidation (markDirty + flushIfDirty), where
 * flushIfDirty() ultimately calls generate().
 *
 * This test asserts the SETTLED state that generate() must produce: a
 * published Repo and its published member apps land in appverse-data.json
 * together, a draft Repo (and its apps) is absent, and unpublishing a Repo
 * removes it on the next regeneration.
 *
 * @coversDefaultClass \Drupal\ood_software\Service\AppverseCacheService
 * @group ood_software
 */
class AppverseCachePublishTest extends KernelTestBase {

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
    // buildAppData reads the computed flag_count field, which queries the
    // flag module's flag_counts table.
    $this->installSchema('flag', ['flag_counts']);

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
    // exact subset this test needs (mirrors SyncInferredMemberAppTest's
    // pattern; avoids installConfig(['ood_software']) which fails on
    // ultimate_cron.job.ood_software without schema in kernel context).
    $this->importProdConfig([
      'node.type.appverse_repo',
      'node.type.appverse_app',
      'node.type.appverse_software',
      'workflows.workflow.appverse_editorial',
      // field_repo_url is read unguarded by buildReposData (->uri).
      'field.storage.node.field_repo_url',
      'field.field.node.appverse_repo.field_repo_url',
      // Member-app parent reference; the cascade filter and member resolution
      // both read field_appverse_repo.
      'field.storage.node.field_appverse_repo',
      'field.field.node.appverse_app.field_appverse_repo',
      // Repo fields read unguarded by buildReposData (->value / ->uri).
      'field.storage.node.field_repo_validation_st',
      'field.field.node.appverse_repo.field_repo_validation_st',
      'field.storage.node.field_repo_description',
      'field.field.node.appverse_repo.field_repo_description',
      'field.storage.node.field_repo_maintainer_name',
      'field.field.node.appverse_repo.field_repo_maintainer_name',
      'field.storage.node.field_repo_maintainer_url',
      'field.field.node.appverse_repo.field_repo_maintainer_url',
      'field.storage.node.field_repo_stars',
      'field.field.node.appverse_repo.field_repo_stars',
      'field.storage.node.field_repo_last_commit',
      'field.field.node.appverse_repo.field_repo_last_commit',
      'field.storage.node.field_repo_www_url',
      'field.field.node.appverse_repo.field_repo_www_url',
      'field.storage.node.field_repo_docs_url',
      'field.field.node.appverse_repo.field_repo_docs_url',
      'field.storage.node.field_repo_readme',
      'field.field.node.appverse_repo.field_repo_readme',
      'field.storage.node.field_repo_last_synced',
      'field.field.node.appverse_repo.field_repo_last_synced',
      // buildSoftwareData groups every published app by its software ref.
      'field.storage.node.field_appverse_software_implemen',
      'field.field.node.appverse_app.field_appverse_software_implemen',
      // buildSoftwareData reads these software fields unguarded (->uri /
      // ->processed) when a software node is present.
      'field.storage.node.field_appverse_software_website',
      'field.field.node.appverse_software.field_appverse_software_website',
      'field.storage.node.field_appverse_software_doc',
      'field.field.node.appverse_software.field_appverse_software_doc',
      'field.field.node.appverse_software.body',
      // buildAppData reads these app fields unguarded (->uri / ->value).
      'field.storage.node.field_appverse_github_url',
      'field.field.node.appverse_app.field_appverse_github_url',
      'field.storage.node.field_appverse_stars',
      'field.field.node.appverse_app.field_appverse_stars',
      'field.storage.node.field_appverse_lastupdated',
      'field.field.node.appverse_app.field_appverse_lastupdated',
      'field.storage.node.field_appverse_maintainer_name',
      'field.field.node.appverse_app.field_appverse_maintainer_name',
    ]);

    // field_repo_shape lives in module's config/install — same pattern as
    // SyncInferredMemberAppTest.
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
  }

  /**
   * Build an appverse_repo node with the minimum fields the cache reads.
   *
   * @param string $title
   *   Repo title.
   * @param string $url
   *   Repo URL (field_repo_url uri).
   * @param string $state
   *   Moderation state: 'published' or 'draft'.
   */
  private function makeRepo(string $title, string $url, string $state): NodeInterface {
    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => $title,
      'field_repo_url' => ['uri' => $url],
      'field_repo_shape' => 'declared',
      'moderation_state' => $state,
    ]);
    $repo->save();
    return $repo;
  }

  /**
   * Build an appverse_app member of $repo. No software reference needed.
   *
   * @param string $title
   *   App title.
   * @param \Drupal\node\NodeInterface $repo
   *   Parent repo node.
   * @param string $state
   *   Moderation state: 'published' or 'draft'.
   */
  private function makeApp(string $title, NodeInterface $repo, string $state, ?NodeInterface $software = NULL): NodeInterface {
    $values = [
      'type' => 'appverse_app',
      'title' => $title,
      'field_appverse_repo' => $repo->id(),
      'moderation_state' => $state,
    ];
    if ($software) {
      $values['field_appverse_software_implemen'] = $software->id();
    }
    $app = Node::create($values);
    $app->save();
    return $app;
  }

  /**
   * Build an appverse_software node.
   *
   * @param string $title
   *   Software title.
   * @param string $state
   *   Moderation state: 'published' or 'draft'.
   */
  private function makeSoftware(string $title, bool $published): NodeInterface {
    // appverse_software is NOT under the editorial moderation workflow (that
    // covers only appverse_app + appverse_repo), so its visibility is driven
    // by the raw published status, not a moderation_state.
    $sw = Node::create([
      'type' => 'appverse_software',
      'title' => $title,
      'status' => $published ? 1 : 0,
    ]);
    $sw->save();
    return $sw;
  }

  /**
   * Run generate() and return the decoded JSON cache.
   */
  private function generateAndDecode(): array {
    $cache = $this->container->get('ood_software.appverse_cache');
    self::assertTrue($cache->generate(), 'AppverseCacheService::generate() should succeed.');
    $raw = file_get_contents(AppverseCacheService::CACHE_FILE);
    self::assertIsString($raw, 'Cache file should be readable after generate().');
    $data = json_decode($raw, TRUE);
    self::assertIsArray($data, 'Cache JSON should decode to an array.');
    return $data;
  }

  /**
   * Find a repo entry by title in the decoded cache.
   */
  private function findRepo(array $data, string $title): ?array {
    foreach ($data['repos'] ?? [] as $repo) {
      if (($repo['title'] ?? NULL) === $title) {
        return $repo;
      }
    }
    return NULL;
  }

  /**
   * Collect every app title appearing under any repo's apps array.
   *
   * @return string[]
   */
  private function allRepoAppTitles(array $data): array {
    $titles = [];
    foreach ($data['repos'] ?? [] as $repo) {
      foreach ($repo['apps'] ?? [] as $app) {
        $titles[] = $app['title'] ?? NULL;
      }
    }
    return $titles;
  }

  /**
   * A published repo and its published member apps land in the JSON together.
   *
   * This is the regression guard for the cascade-publish staleness bug.
   *
   * @covers ::generate
   * @covers ::buildReposData
   * @covers ::buildAppData
   */
  public function testPublishedRepoWithMemberAppsAppearsInCache(): void {
    $repo = $this->makeRepo('Mono', 'https://github.com/OSC/mono', 'published');
    $this->makeApp('App One', $repo, 'published');
    $this->makeApp('App Two', $repo, 'published');

    $data = $this->generateAndDecode();

    $entry = $this->findRepo($data, 'Mono');
    self::assertNotNull($entry, 'Published repo "Mono" should appear in the repos array.');

    $appTitles = array_map(fn($a) => $a['title'] ?? NULL, $entry['apps']);
    self::assertCount(2, $entry['apps'], 'Repo "Mono" should carry both member apps.');
    self::assertContains('App One', $appTitles, '"App One" should appear under repo "Mono".');
    self::assertContains('App Two', $appTitles, '"App Two" should appear under repo "Mono".');
  }

  /**
   * A draft repo is absent and its apps do not leak into any repo.
   *
   * @covers ::generate
   * @covers ::buildReposData
   * @covers ::applyRepoCascadeFilter
   */
  public function testDraftRepoIsAbsentFromCache(): void {
    $repo = $this->makeRepo('Hidden', 'https://github.com/OSC/hidden', 'draft');
    $this->makeApp('Orphan', $repo, 'published');

    $data = $this->generateAndDecode();

    self::assertNull(
      $this->findRepo($data, 'Hidden'),
      'Draft repo "Hidden" should not appear in the repos array (status=1 only).'
    );
    self::assertNotContains(
      'Orphan',
      $this->allRepoAppTitles($data),
      'App "Orphan" should be excluded by the cascade filter (parent repo unpublished).'
    );
  }

  /**
   * Unpublishing a repo removes it (and its app) on the next regeneration.
   *
   * Proves regeneration reflects the new settled state — the deferred-flush
   * guarantee, exercised through generate().
   *
   * @covers ::generate
   * @covers ::buildReposData
   * @covers ::applyRepoCascadeFilter
   */
  public function testUnpublishingRepoRemovesItOnRegenerate(): void {
    $repo = $this->makeRepo('Toggle', 'https://github.com/OSC/toggle', 'published');
    $this->makeApp('Member', $repo, 'published');

    $data = $this->generateAndDecode();
    $entry = $this->findRepo($data, 'Toggle');
    self::assertNotNull($entry, 'Published repo "Toggle" should appear initially.');
    self::assertContains(
      'Member',
      array_map(fn($a) => $a['title'] ?? NULL, $entry['apps']),
      'App "Member" should appear under "Toggle" initially.'
    );

    // Transition the repo to draft (drives status=0 via content_moderation).
    $repo->set('moderation_state', 'draft');
    $repo->setNewRevision(TRUE);
    if (method_exists($repo, 'setValidationRequired')) {
      $repo->setValidationRequired(FALSE);
    }
    $repo->save();

    $data = $this->generateAndDecode();
    self::assertNull(
      $this->findRepo($data, 'Toggle'),
      'After unpublish, repo "Toggle" should be absent on regenerate.'
    );
    self::assertNotContains(
      'Member',
      $this->allRepoAppTitles($data),
      'After unpublish, app "Member" should no longer appear under any repo.'
    );
  }

  /**
   * A member app whose software is UNPUBLISHED must not leak into the cache.
   *
   * buildReposData keys published apps by nid from published-software data.
   * A member app referencing an unpublished software drops out of that map;
   * the fix must skip it rather than emit it as a repo-only app with an
   * empty softwareId. A genuinely software-less member app still appears.
   *
   * @covers ::buildReposData
   */
  public function testMemberAppWithUnpublishedSoftwareIsExcluded(): void {
    $repo = $this->makeRepo('MonoSw', 'https://github.com/OSC/monosw', 'published');
    $hiddenSw = $this->makeSoftware('Hidden Software', FALSE);
    $liveSw = $this->makeSoftware('Live Software', TRUE);

    // Published app whose software is unpublished — must be excluded.
    $this->makeApp('Leaky App', $repo, 'published', $hiddenSw);
    // Published app whose software is published — must appear.
    $this->makeApp('Visible App', $repo, 'published', $liveSw);
    // Published app with NO software ref — legitimate repo-only, must appear.
    $this->makeApp('Repo-Only App', $repo, 'published');

    $data = $this->generateAndDecode();
    $titles = $this->allRepoAppTitles($data);

    self::assertNotContains(
      'Leaky App',
      $titles,
      'App whose software is unpublished must NOT appear in the public catalog.'
    );
    self::assertContains('Visible App', $titles, 'App with published software should appear.');
    self::assertContains('Repo-Only App', $titles, 'Software-less repo-only app should still appear.');
  }

  /**
   * Import config objects from the prod default snapshot.
   *
   * Mirrors SyncInferredMemberAppTest::importProdConfig — scrubs
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
