<?php

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ood_software\Service\RepoSyncService;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for RepoSyncService::applyDeclared edge cases.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Service\RepoSyncService
 */
class RepoSyncServiceTest extends UnitTestCase {

  /**
   * Mock node storage that records all create() and save() calls.
   */
  protected EntityStorageInterface $nodeStorage;

  /**
   * The service under test.
   */
  protected RepoSyncService $service;

  /**
   * The mock node that resolveRepo returns.
   */
  protected NodeInterface $node;

  /**
   * Captured field sets on the node for assertion in each test.
   *
   * @var array<string, mixed>
   */
  protected array $nodeFields = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->nodeFields = [];

    // Mock the node. set() captures the field name + value into $nodeFields,
    // save() is a no-op, setTitle() goes through set('title', ...).
    $this->node = $this->createMock(NodeInterface::class);
    $this->node->method('set')
      ->willReturnCallback(function (string $field, $value) {
        $this->nodeFields[$field] = $value;
        return $this->node;
      });
    $this->node->method('setTitle')
      ->willReturnCallback(function (string $title) {
        $this->nodeFields['title'] = $title;
        return $this->node;
      });
    // SAVED_NEW = 1 (defined in Drupal core, not autoloaded in unit tests).
    $this->node->method('save')->willReturn(1);
    $this->node->method('id')->willReturn(1);

    // Query mock — always returns empty (no existing Repo found).
    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('execute')->willReturn([]);

    // Node storage mock: getQuery returns the query above; create() returns
    // the prepared $node; load*() returns null/empty.
    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);
    $this->nodeStorage->method('getQuery')->willReturn($query);
    $this->nodeStorage->method('create')->willReturn($this->node);
    $this->nodeStorage->method('load')->willReturn(NULL);
    $this->nodeStorage->method('loadMultiple')->willReturn([]);

    // Term storage mock — also returns no matches.
    $termStorage = $this->createMock(EntityStorageInterface::class);
    $termQuery = $this->createMock(QueryInterface::class);
    $termQuery->method('condition')->willReturnSelf();
    $termQuery->method('range')->willReturnSelf();
    $termQuery->method('accessCheck')->willReturnSelf();
    $termQuery->method('execute')->willReturn([]);
    $termStorage->method('getQuery')->willReturn($termQuery);

    // EntityTypeManager: dispatches by entity type ID.
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnCallback(
      function (string $entity_type) use ($termStorage) {
        return $entity_type === 'taxonomy_term' ? $termStorage : $this->nodeStorage;
      }
    );

    // Logger.
    $logger = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    // Time service is now injected via constructor (PHPStan flagged
    // \Drupal::time() calls as needing DI; service signature updated).
    $time = $this->createMock(\Drupal\Component\Datetime\TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(1234567890);

    // GitHubService is injected for software resolution. These tests don't
    // exercise the multi-app per-app sync path, so a bare mock is fine;
    // the few methods needed (resolveSoftwareForApp) aren't called from
    // applyDeclared (the Repo-level path under test).
    $githubService = $this->createMock(\Drupal\ood_software\Plugin\GitHubService::class);

    $this->service = new RepoSyncService($entityTypeManager, $loggerFactory, $time, $githubService);
  }

  /**
   * Well-formed appverse.yml with all keys → status valid; all fields populated.
   *
   * @covers ::applyDeclared
   */
  public function testApplyDeclaredHappyPath(): void {
    $yml = <<<YAML
appverse:
  min_version: "1.0"

title: Happy Path Repo
description: A happy-path test repo.

website: https://example.org
docs: https://docs.example.org

maintainer:
  name: Test Maintainer
  support_url: https://example.org/support

tags:
  - simulation

shared_paths:
  - app-templates/
YAML;

    $this->service->resolveRepo('https://github.com/test/happy', $yml, ['organization' => 'Test Org']);

    $this->assertSame('Happy Path Repo', $this->nodeFields['title'] ?? NULL);
    $this->assertSame('A happy-path test repo.', $this->nodeFields['field_repo_description'] ?? NULL);
    $this->assertSame('valid', $this->nodeFields['field_repo_validation_st'] ?? NULL);
    $this->assertSame([], $this->nodeFields['field_repo_validation_er'] ?? NULL);
  }

  /**
   * Malformed YAML → ParseException is caught; status stale_invalid; error populated.
   *
   * @covers ::applyDeclared
   */
  public function testApplyDeclaredMalformedYaml(): void {
    $yml = 'title: [unclosed';

    $this->service->resolveRepo('https://github.com/test/malformed', $yml, []);

    $this->assertSame('stale_invalid', $this->nodeFields['field_repo_validation_st'] ?? NULL);
    $this->assertIsArray($this->nodeFields['field_repo_validation_er'] ?? NULL);
    $this->assertNotEmpty($this->nodeFields['field_repo_validation_er']);
    $this->assertStringContainsString('parse error', $this->nodeFields['field_repo_validation_er'][0]);
  }

  /**
   * Top-level YAML list (e.g. "- foo") → status stale_invalid with mapping-required error.
   *
   * @covers ::applyDeclared
   */
  public function testApplyDeclaredTopLevelList(): void {
    $yml = "- not a mapping\n- another item";

    $this->service->resolveRepo('https://github.com/test/list-top', $yml, []);

    $this->assertSame('stale_invalid', $this->nodeFields['field_repo_validation_st'] ?? NULL);
    $errors = $this->nodeFields['field_repo_validation_er'] ?? [];
    $this->assertNotEmpty($errors);
    $this->assertStringContainsString('YAML mapping', $errors[0]);
  }

  /**
   * Scalar top-level YAML → status stale_invalid.
   *
   * @covers ::applyDeclared
   */
  public function testApplyDeclaredScalarTopLevel(): void {
    $yml = 'just-a-string';

    $this->service->resolveRepo('https://github.com/test/scalar', $yml, []);

    $this->assertSame('stale_invalid', $this->nodeFields['field_repo_validation_st'] ?? NULL);
  }

  /**
   * Empty YAML (parses to NULL) → status stale_invalid.
   *
   * @covers ::applyDeclared
   */
  public function testApplyDeclaredEmptyInput(): void {
    $this->service->resolveRepo('https://github.com/test/empty', '', []);

    $this->assertSame('stale_invalid', $this->nodeFields['field_repo_validation_st'] ?? NULL);
  }

  /**
   * Future schema version (min_version > 1.0) → stale_invalid with version error.
   *
   * @covers ::applyDeclared
   */
  public function testApplyDeclaredFutureSchemaVersion(): void {
    $yml = <<<YAML
appverse:
  min_version: "2.0"

title: Future Repo
YAML;

    $this->service->resolveRepo('https://github.com/test/future', $yml, []);

    $this->assertSame('stale_invalid', $this->nodeFields['field_repo_validation_st'] ?? NULL);
    $errors = $this->nodeFields['field_repo_validation_er'] ?? [];
    $this->assertNotEmpty($errors);
    $this->assertStringContainsString('min_version', $errors[0]);
  }

  /**
   * Missing maintainer block in YAML falls back to repoMetadata.organization for the
   * maintainer name string (sync still derives display name).
   *
   * @covers ::applyDeclared
   */
  public function testApplyDeclaredFallsBackToRepoOrg(): void {
    $yml = <<<YAML
title: No Maintainer Block
description: Demo
YAML;

    $this->service->resolveRepo('https://github.com/test/no-maintainer', $yml, ['organization' => 'Fallback Org']);

    // Title parses fine, maintainer name falls back to the repo owner string,
    // status remains valid.
    $this->assertSame('No Maintainer Block', $this->nodeFields['title'] ?? NULL);
    $this->assertSame('Fallback Org', $this->nodeFields['field_repo_maintainer_name'] ?? NULL);
    $this->assertSame('valid', $this->nodeFields['field_repo_validation_st'] ?? NULL);
  }

  /**
   * applyDeclared writes field_repo_shape='declared'.
   *
   * Consumers of appverse_repo nodes branch on this field to
   * distinguish multi-app (declared via appverse.yml) from single-app
   * (inferred via manifest.yml) shapes.
   */
  public function testApplyDeclaredSetsShape(): void {
    $this->service->resolveRepo(
      'https://github.com/owner/repo',
      "apps:\n  - path: foo\n    software: Jupyter\n",
      ['stars' => 0, 'lastCommittedDate' => 0]
    );

    $this->assertArrayHasKey('field_repo_shape', $this->nodeFields);
    $this->assertSame('declared', $this->nodeFields['field_repo_shape']);
  }

  /**
   * applyInferred writes field_repo_shape='inferred'.
   *
   * Consumers of appverse_repo nodes branch on this field to
   * distinguish multi-app (declared via appverse.yml) from single-app
   * (inferred via manifest.yml) shapes.
   */
  public function testApplyInferredSetsShape(): void {
    $this->service->resolveRepo(
      'https://github.com/owner/repo',
      NULL,
      ['stars' => 0, 'lastCommittedDate' => 0]
    );

    $this->assertArrayHasKey('field_repo_shape', $this->nodeFields);
    $this->assertSame('inferred', $this->nodeFields['field_repo_shape']);
  }

  /**
   * applyDeclared writes field_repo_shape='declared' even when yaml is malformed.
   *
   * yaml was present at fetch time, so the shape is declared regardless of
   * parse outcome. NULL shape on a stale_invalid Repo would render
   * incorrectly in any consumer that branches on shape.
   */
  public function testApplyDeclaredSetsShapeOnMalformedYaml(): void {
    // Yaml syntax error: missing colon makes it unparseable.
    $this->service->resolveRepo(
      'https://github.com/owner/repo',
      "apps\n  not a mapping",
      ['stars' => 0, 'lastCommittedDate' => 0]
    );

    $this->assertArrayHasKey('field_repo_shape', $this->nodeFields);
    $this->assertSame('declared', $this->nodeFields['field_repo_shape']);
    // Validation should be stale_invalid (parse failure).
    $this->assertArrayHasKey('field_repo_validation_st', $this->nodeFields);
    $this->assertSame('stale_invalid', $this->nodeFields['field_repo_validation_st']);
  }

  /**
   * applyDeclaredApps deletes Apps whose subpath is no longer in apps[].
   *
   * Re-sync that drops an entry from apps[] removes the corresponding
   * App node — yaml is canonical, catalog mirrors yaml state.
   */
  public function testApplyDeclaredAppsDeletesAppsRemovedFromYml(): void {
    // Build a targeted service with a node storage that returns two
    // existing member App stubs: 'jupyter_example' (kept) and 'old_app'
    // (must be deleted). The new yaml declares only 'jupyter_example'.
    $keptApp = $this->createMock(NodeInterface::class);
    $keptApp->method('id')->willReturn(101);
    $keptSubpath = $this->buildSubpathFieldItem('jupyter_example');
    // Field item with getValue() for validation_er reads; ->value for st reads.
    $emptyValidationField = new class {
      public $value = NULL;

      public function getValue(): array {
        return [];
      }

    };
    $keptApp->method('get')->willReturnCallback(
      function (string $field_name) use ($keptSubpath, $emptyValidationField) {
        if ($field_name === 'field_appverse_app_subpath') {
          return $keptSubpath;
        }
        return $emptyValidationField;
      }
    );
    // kept App: delete() must NEVER be called.
    $keptApp->expects($this->never())->method('delete');
    // kept App: save() / set() called by applyDeclaredApp happy/rejected path.
    $keptApp->method('set')->willReturnSelf();
    $keptApp->method('setTitle')->willReturnSelf();
    $keptApp->method('save')->willReturn(1);
    $keptApp->method('hasField')->willReturn(FALSE);

    $oldApp = $this->createMock(NodeInterface::class);
    $oldApp->method('id')->willReturn(102);
    $oldSubpath = $this->buildSubpathFieldItem('old_app');
    // Return the same $emptyValidationField stub for fields other than subpath
    // so future code changes (e.g. reading title or validation_er for the
    // delete-log message) don't NPE the test.
    $oldApp->method('get')->willReturnCallback(
      function (string $field_name) use ($oldSubpath, $emptyValidationField) {
        return $field_name === 'field_appverse_app_subpath' ? $oldSubpath : $emptyValidationField;
      }
    );
    // old App: delete() MUST be called exactly once.
    $oldApp->expects($this->once())->method('delete');

    // Build a query that returns both nids; loadMultiple returns the stubs.
    $appsQuery = $this->createMock(QueryInterface::class);
    $appsQuery->method('condition')->willReturnSelf();
    $appsQuery->method('range')->willReturnSelf();
    $appsQuery->method('accessCheck')->willReturnSelf();
    $appsQuery->method('execute')->willReturn([101, 102]);

    $appsNodeStorage = $this->createMock(EntityStorageInterface::class);
    $appsNodeStorage->method('getQuery')->willReturn($appsQuery);
    $appsNodeStorage->method('loadMultiple')->willReturn([101 => $keptApp, 102 => $oldApp]);
    // resolveAppNode does ->load(reset(...)) — for the kept app it should
    // return the kept stub so applyDeclaredApp can use it.
    $appsNodeStorage->method('load')->willReturnCallback(
      function ($id) use ($keptApp) {
        return $id == 101 ? $keptApp : NULL;
      }
    );
    $appsNodeStorage->method('create')->willReturn($keptApp);

    // Term storage: empty matches.
    $termStorage = $this->createMock(EntityStorageInterface::class);
    $termQuery = $this->createMock(QueryInterface::class);
    $termQuery->method('condition')->willReturnSelf();
    $termQuery->method('range')->willReturnSelf();
    $termQuery->method('accessCheck')->willReturnSelf();
    $termQuery->method('execute')->willReturn([]);
    $termStorage->method('getQuery')->willReturn($termQuery);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnCallback(
      function (string $entity_type) use ($termStorage, $appsNodeStorage) {
        return $entity_type === 'taxonomy_term' ? $termStorage : $appsNodeStorage;
      }
    );

    $logger = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);
    $time = $this->createMock(\Drupal\Component\Datetime\TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(1234567890);

    // GitHubService: resolveSoftwareForApp returns a stub with nid match;
    // resolveTaxonomyTermsFromAppverseYml returns no resolved/unresolved.
    $githubService = $this->createMock(\Drupal\ood_software\Plugin\GitHubService::class);
    $githubService->method('resolveSoftwareForApp')
      ->willReturn(['resolvedNid' => 999, 'suggestion' => NULL]);
    $githubService->method('resolveTaxonomyTermsFromAppverseYml')
      ->willReturn(['resolved' => [], 'unresolved' => []]);

    $service = new RepoSyncService($entityTypeManager, $loggerFactory, $time, $githubService);

    // Parent Repo stub.
    $repo = $this->createMock(NodeInterface::class);
    $repo->method('id')->willReturn(50);

    $parsedRootYml = [
      'apps' => [
        ['path' => 'jupyter_example'],
      ],
    ];
    $subpathFiles = [
      'jupyter_example' => [
        'manifestYml' => "name: Jupyter Example\ndescription: desc\n",
        'appverseYml' => "name: Jupyter Example\ndescription: desc\napp_type: jupyter\nmaintainer:\n  name: Bob\n  support_url: https://example.org/support\n",
        'readme' => NULL,
      ],
    ];

    $service->applyDeclaredApps(
      $repo,
      $parsedRootYml,
      $subpathFiles,
      'https://github.com/owner/repo',
      ['organization' => 'OwnerOrg']
    );

    // The kept/deleted mock expectations above carry the contract; assert
    // here so the test isn't marked risky.
    $this->assertTrue(TRUE);
  }

  /**
   * applyDeclaredApps preserves existing member Apps when the new yaml's
   * apps[] is empty.
   *
   * Empty apps[] is treated as "no signal" (likely a transient parse
   * failure upstream), not "delete all" — see one-way-archive contract.
   * A stale_invalid yaml shouldn't silently empty the catalog.
   */
  public function testApplyDeclaredAppsPreservesAppsWhenYamlAppsEmpty(): void {
    // Build an existing App stub; assert ->delete() is NEVER called.
    $existingApp = $this->createMock(NodeInterface::class);
    $existingApp->method('id')->willReturn(101);
    $existingSubpath = $this->buildSubpathFieldItem('jupyter_example');
    $emptyValidationField = new class {
      public $value = NULL;

      public function getValue(): array {
        return [];
      }

    };
    $existingApp->method('get')->willReturnCallback(
      function (string $field_name) use ($existingSubpath, $emptyValidationField) {
        return $field_name === 'field_appverse_app_subpath' ? $existingSubpath : $emptyValidationField;
      }
    );
    // delete() MUST NEVER be called when apps[] is empty.
    $existingApp->expects($this->never())->method('delete');
    $existingApp->method('set')->willReturnSelf();
    $existingApp->method('setTitle')->willReturnSelf();
    $existingApp->method('save')->willReturn(1);
    $existingApp->method('hasField')->willReturn(FALSE);

    // Even if the service queried for existing apps, returning empty here
    // would still verify guard behavior; but to assert the guard short-
    // circuits BEFORE the query, we also wire loadMultiple to return our
    // stub (so a missing guard would attempt deletion and fail expectations).
    $appsQuery = $this->createMock(QueryInterface::class);
    $appsQuery->method('condition')->willReturnSelf();
    $appsQuery->method('range')->willReturnSelf();
    $appsQuery->method('accessCheck')->willReturnSelf();
    $appsQuery->method('execute')->willReturn([101]);

    $appsNodeStorage = $this->createMock(EntityStorageInterface::class);
    $appsNodeStorage->method('getQuery')->willReturn($appsQuery);
    $appsNodeStorage->method('loadMultiple')->willReturn([101 => $existingApp]);
    $appsNodeStorage->method('load')->willReturn(NULL);
    $appsNodeStorage->method('create')->willReturn($existingApp);

    $termStorage = $this->createMock(EntityStorageInterface::class);
    $termQuery = $this->createMock(QueryInterface::class);
    $termQuery->method('condition')->willReturnSelf();
    $termQuery->method('range')->willReturnSelf();
    $termQuery->method('accessCheck')->willReturnSelf();
    $termQuery->method('execute')->willReturn([]);
    $termStorage->method('getQuery')->willReturn($termQuery);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnCallback(
      function (string $entity_type) use ($termStorage, $appsNodeStorage) {
        return $entity_type === 'taxonomy_term' ? $termStorage : $appsNodeStorage;
      }
    );

    $logger = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);
    $time = $this->createMock(\Drupal\Component\Datetime\TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(1234567890);

    $githubService = $this->createMock(\Drupal\ood_software\Plugin\GitHubService::class);
    $githubService->method('resolveSoftwareForApp')
      ->willReturn(['resolvedNid' => 999, 'suggestion' => NULL]);
    $githubService->method('resolveTaxonomyTermsFromAppverseYml')
      ->willReturn(['resolved' => [], 'unresolved' => []]);

    $service = new RepoSyncService($entityTypeManager, $loggerFactory, $time, $githubService);

    $repo = $this->createMock(NodeInterface::class);
    $repo->method('id')->willReturn(50);

    // The new yaml declares NO apps. With the guard in place, the existing
    // App must be preserved (delete() never called).
    $parsedRootYml = [
      'apps' => [],
    ];
    $subpathFiles = [];

    $service->applyDeclaredApps(
      $repo,
      $parsedRootYml,
      $subpathFiles,
      'https://github.com/owner/repo',
      ['organization' => 'OwnerOrg']
    );

    // The ->never() expectation above carries the contract; assert here so
    // the test isn't marked risky.
    $this->assertTrue(TRUE);
  }

  /**
   * Helper: builds a mock field item list whose ->value returns a subpath.
   */
  protected function buildSubpathFieldItem(string $subpath): object {
    $item = new \stdClass();
    $item->value = $subpath;
    return $item;
  }

  /**
   * applyDeclared transitions Repo to archived when repo is archived.
   *
   * One-way: subsequent syncs that find the repo un-archived do NOT
   * auto-un-archive. Contributor re-submit is the recovery path.
   */
  public function testApplyDeclaredAutoArchivesWhenRepoIsArchived(): void {
    $this->service->resolveRepo(
      'https://github.com/owner/repo',
      "apps:\n  - path: foo\n",
      ['isArchived' => TRUE, 'stars' => 0, 'lastCommittedDate' => 0]
    );

    $this->assertArrayHasKey('moderation_state', $this->nodeFields);
    $this->assertSame('archived', $this->nodeFields['moderation_state']);
  }

  /**
   * applyInferred transitions Repo to archived when repo is archived.
   */
  public function testApplyInferredAutoArchivesWhenRepoIsArchived(): void {
    $this->service->resolveRepo(
      'https://github.com/owner/repo',
      NULL,
      ['isArchived' => TRUE, 'stars' => 0, 'lastCommittedDate' => 0]
    );

    $this->assertArrayHasKey('moderation_state', $this->nodeFields);
    $this->assertSame('archived', $this->nodeFields['moderation_state']);
  }

}
