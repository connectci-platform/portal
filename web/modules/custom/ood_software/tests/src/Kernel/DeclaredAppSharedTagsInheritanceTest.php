<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Covers repo-level shared_implementation_tags inheritance for monorepo apps.
 *
 * In a monorepo (root appverse.yml with an apps[] list), a root-level
 * `shared_implementation_tags` list is inherited ADDITIVELY by every member
 * app: each app's effective implementation tags are the UNION of its own
 * `implementation_tags:` and the shared list, deduplicated. It is never an
 * override and has no opt-out. The combined list runs through the same
 * resolve/suggest/reject path as the app's own tags, against the
 * appverse_implementation_tags vocabulary (the SAME vocab as per-app
 * `implementation_tags:`, NOT the generic `tags` discovery vocab).
 *
 * @coversDefaultClass \Drupal\ood_software\Service\RepoSyncService
 * @group ood_software
 */
class DeclaredAppSharedTagsInheritanceTest extends KernelTestBase {

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

    // The implementation-tag vocabulary (target of field_add_implementation_tags)
    // and a deliberately-separate generic `tags` vocab to keep the vocab
    // distinction honest.
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

    // Real implementation-tag terms the declared/shared tags resolve to.
    Term::create(['vid' => 'appverse_implementation_tags', 'name' => 'containerized'])->save();
    Term::create(['vid' => 'appverse_implementation_tags', 'name' => 'gpu-enabled'])->save();
    Term::create(['vid' => 'appverse_implementation_tags', 'name' => 'jupyter'])->save();

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
   * Load the single member app created on the single-app declared path.
   *
   * The single-app path stores the app at subpath '' and looks it up by
   * parent repo (an empty-string subpath does not round-trip a string-field
   * equality query), so we mirror that here.
   */
  protected function loadSingleApp(Node $repo): Node {
    $existing = $this->container->get('entity_type.manager')->getStorage('node')->loadByProperties([
      'type' => 'appverse_app',
      'field_appverse_repo' => $repo->id(),
    ]);
    self::assertNotEmpty($existing, 'Expected a single member app under the repo.');
    return reset($existing);
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
   * The sorted set of term ids referenced by field_add_implementation_tags.
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
   * An app's own tags are unioned with the repo-level shared tags.
   *
   * @covers ::applyDeclaredApps
   * @covers ::applyDeclaredApp
   */
  public function testSharedTagsAreUnionedWithAppTags(): void {
    $sync = $this->container->get('ood_software.repo_sync');
    $repo = $this->createRepo();

    $rootYml = [
      'shared_implementation_tags' => ['containerized'],
      'apps' => [
        [
          'path' => 'jup',
          'name' => 'Jupyter',
          'description' => 'desc',
          'app_type' => 'batch-connect-basic',
          'software' => 'Jupyter',
          'implementation_tags' => ['jupyter'],
          'maintainer' => ['name' => 'Team', 'support_url' => 'https://e.org'],
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

    $expected = [
      $this->tidByName('appverse_implementation_tags', 'containerized'),
      $this->tidByName('appverse_implementation_tags', 'jupyter'),
    ];
    sort($expected);

    self::assertSame($expected, $this->appTagTids($app), 'Effective tags must be app tags UNION shared tags. Errors: ' . $this->errors($app));
    self::assertSame('valid', $this->status($app), 'Errors: ' . $this->errors($app));
  }

  /**
   * An app with no tags of its own inherits the shared tags.
   *
   * @covers ::applyDeclaredApps
   * @covers ::applyDeclaredApp
   */
  public function testAppWithoutTagsInheritsSharedTags(): void {
    $sync = $this->container->get('ood_software.repo_sync');
    $repo = $this->createRepo();

    $rootYml = [
      'shared_implementation_tags' => ['containerized'],
      'apps' => [
        [
          'path' => 'jup',
          'name' => 'Jupyter',
          'description' => 'desc',
          'app_type' => 'batch-connect-basic',
          'software' => 'Jupyter',
          // No tags of its own.
          'maintainer' => ['name' => 'Team', 'support_url' => 'https://e.org'],
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

    $expected = [$this->tidByName('appverse_implementation_tags', 'containerized')];
    self::assertSame($expected, $this->appTagTids($app), 'An app with no tags must get the shared ones. Errors: ' . $this->errors($app));
    self::assertSame('valid', $this->status($app), 'Errors: ' . $this->errors($app));
  }

  /**
   * A tag declared at both levels is deduped: one ref, no doubled error.
   *
   * @covers ::applyDeclaredApps
   * @covers ::applyDeclaredApp
   */
  public function testTagDeclaredAtBothLevelsIsDeduped(): void {
    $sync = $this->container->get('ood_software.repo_sync');
    $repo = $this->createRepo();

    $rootYml = [
      'shared_implementation_tags' => ['containerized'],
      'apps' => [
        [
          'path' => 'jup',
          'name' => 'Jupyter',
          'description' => 'desc',
          'app_type' => 'batch-connect-basic',
          'software' => 'Jupyter',
          // Declares 'containerized' too — must not double up.
          'implementation_tags' => ['containerized', 'jupyter'],
          'maintainer' => ['name' => 'Team', 'support_url' => 'https://e.org'],
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

    $containerizedTid = $this->tidByName('appverse_implementation_tags', 'containerized');
    $jupyterTid = $this->tidByName('appverse_implementation_tags', 'jupyter');
    $expected = [$containerizedTid, $jupyterTid];
    sort($expected);

    $actual = $this->appTagTids($app);
    self::assertSame($expected, $actual, 'Duplicated tag must appear exactly once. Errors: ' . $this->errors($app));
    // Exactly two refs (no third duplicate of containerized).
    self::assertCount(2, $app->get('field_add_implementation_tags')->getValue());
    self::assertSame('valid', $this->status($app), 'Errors: ' . $this->errors($app));
  }

  /**
   * An unresolved shared tag is dropped and flagged, with a single error.
   *
   * Drop-and-flag, not reject: an unresolved inherited implementation tag does
   * NOT delist the app. The app stays 'valid'; the tags that DID resolve are
   * still written; each unresolved tag is dropped (not applied) and recorded as
   * a single flag in validation_er for a reviewer to map or create the term.
   * Dedup still applies, so a tag declared at both the shared and app level
   * yields one flag, not a doubled one.
   *
   * @covers ::applyDeclaredApps
   * @covers ::applyDeclaredApp
   */
  public function testUnresolvedSharedTagDroppedAndFlaggedOnce(): void {
    $sync = $this->container->get('ood_software.repo_sync');
    $repo = $this->createRepo();

    $rootYml = [
      // 'bogus-tag' is not in the implementation-tag vocab.
      'shared_implementation_tags' => ['bogus-tag'],
      'apps' => [
        [
          'path' => 'jup',
          'name' => 'Jupyter',
          'description' => 'desc',
          'app_type' => 'batch-connect-basic',
          'software' => 'Jupyter',
          // App also declares the same bad tag — must yield one flag.
          'implementation_tags' => ['bogus-tag', 'jupyter'],
          'maintainer' => ['name' => 'Team', 'support_url' => 'https://e.org'],
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

    // Drop-and-flag: the app stays valid despite the unresolved tag.
    self::assertSame('valid', $this->status($app), 'An unresolved implementation tag must drop-and-flag, not reject. Errors: ' . $this->errors($app));
    // The valid one still resolves and is written.
    self::assertSame(
      [$this->tidByName('appverse_implementation_tags', 'jupyter')],
      $this->appTagTids($app)
    );
    // Only one tag flag mentioning the bad value (deduped, not doubled).
    $tagErrorCount = substr_count($this->errors($app), 'bogus-tag');
    self::assertSame(1, $tagErrorCount, 'A tag declared at both levels must produce a single flag, not a doubled one.');
  }

  /**
   * Per-app implementation_tags: drop the unknown, keep the valid, flag it.
   *
   * The core drop-and-flag contract for a plain per-app implementation_tags
   * case (no inheritance involved). An app declares two tags: one that exists
   * as a term and one that does not. The resolved term is written and only it;
   * the unknown tag is dropped (not written) and recorded as a flag; the app
   * stays 'valid' (NOT rejected) so it remains listed.
   *
   * @covers ::applyDeclaredApps
   * @covers ::applyDeclaredApp
   */
  public function testUnresolvedAppTagDroppedAndFlaggedKeepsAppValid(): void {
    $sync = $this->container->get('ood_software.repo_sync');
    $repo = $this->createRepo();

    $rootYml = [
      'apps' => [
        [
          'path' => 'jup',
          'name' => 'Jupyter',
          'description' => 'desc',
          'app_type' => 'batch-connect-basic',
          'software' => 'Jupyter',
          // One real term ('gpu-enabled') + one that doesn't exist.
          'implementation_tags' => ['gpu-enabled', 'totally-bogus-tag'],
          'maintainer' => ['name' => 'Team', 'support_url' => 'https://e.org'],
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

    // Stay listed: the unknown tag does not delist an otherwise-valid app.
    self::assertSame('valid', $this->status($app), 'An unresolved implementation tag must not reject the app. Errors: ' . $this->errors($app));
    // Keep the valid: only the resolved term is written, the bogus one dropped.
    self::assertSame(
      [$this->tidByName('appverse_implementation_tags', 'gpu-enabled')],
      $this->appTagTids($app),
      'Only the resolved tag must be written; the unknown one is dropped.'
    );
    // Flag it: a message naming the bogus tag is recorded for a reviewer.
    self::assertStringContainsString('totally-bogus-tag', $this->errors($app));
  }

  /**
   * shared_implementation_tags on a single-app declared repo is ignored.
   *
   * shared_implementation_tags is a monorepo-only concept. On the single-app
   * path it must not be treated as the app's own tags nor cause a spurious
   * unresolved-tag rejection. Here the shared list is a bogus value; if it
   * leaked into the app's tag resolution the app would be rejected.
   *
   * @covers ::applyDeclaredSingleApp
   * @covers ::applyDeclaredApp
   */
  public function testSharedTagsIgnoredOnSingleAppPath(): void {
    $sync = $this->container->get('ood_software.repo_sync');
    $repo = $this->createRepo();

    $rootYml = [
      // A single-app repo: no apps[] list, root IS the app.
      'name' => 'Jupyter',
      'description' => 'desc',
      'app_type' => 'batch-connect-basic',
      'software' => 'Jupyter',
      'implementation_tags' => ['jupyter'],
      'maintainer' => ['name' => 'Team', 'support_url' => 'https://e.org'],
      // Deliberately bogus shared tags — must be ignored on this path.
      'shared_implementation_tags' => ['bogus-tag'],
    ];

    $sync->applyDeclaredSingleApp(
      $repo,
      $rootYml,
      ['manifestYml' => NULL, 'appverseYml' => NULL],
      'https://github.com/x/y',
      [],
    );
    // applyDeclaredSingleApp returns the app without saving; persist it like
    // the AddRepoForm does so we can re-load and assert on stored values.
    $created = $this->loadSingleApp($repo);

    // Only the app's own tag resolved; the bogus shared tag was ignored.
    self::assertSame(
      [$this->tidByName('appverse_implementation_tags', 'jupyter')],
      $this->appTagTids($created)
    );
    self::assertSame('valid', $this->status($created), 'Single-app must not be rejected by ignored shared tags. Errors: ' . $this->errors($created));
    self::assertStringNotContainsString('bogus-tag', $this->errors($created));
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
