<?php

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\ConditionInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ood_software\Service\AppverseCacheService;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for AppverseCacheService's parent-Repo cascade filter.
 *
 * The full generate() path is too entangled with file_system, logo media
 * resolution, and node field accessors to mock cleanly here. Instead we
 * test the cascade filter's query-construction in isolation by exercising
 * the protected applyRepoCascadeFilter() helper directly via
 * reflection. Cypress E2E covers end-to-end behavior.
 *
 * The unit under test is a query builder, not a node mutator, so the test
 * shape is different from RepoSyncServiceTest: tests record method
 * calls on mock query/condition objects rather than asserting against
 * persisted entity state.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Service\AppverseCacheService
 */
class AppverseCacheServiceTest extends UnitTestCase {

  /**
   * Build an AppverseCacheService with a node storage that returns the given
   * NIDs from any Repo query (status=0).
   *
   * @param int[] $unpublishedRepoIds
   *   NIDs the unpublished-Repo lookup should return.
   *
   * @return \Drupal\ood_software\Service\AppverseCacheService
   */
  protected function buildService(array $unpublishedRepoIds): AppverseCacheService {
    // Repo query: records nothing, just returns the configured NIDs.
    $repoQuery = $this->createMock(QueryInterface::class);
    $repoQuery->method('condition')->willReturnSelf();
    $repoQuery->method('accessCheck')->willReturnSelf();
    $repoQuery->method('execute')->willReturn($unpublishedRepoIds);

    // Storage::getQuery() returns the repo query (the cascade helper
    // is the only thing being exercised so we don't need to disambiguate).
    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $nodeStorage->method('getQuery')->willReturn($repoQuery);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($nodeStorage);

    $fileUrlGenerator = $this->createMock(FileUrlGeneratorInterface::class);
    $fileSystem = $this->createMock(FileSystemInterface::class);

    $logger = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    return new AppverseCacheService($entityTypeManager, $fileUrlGenerator, $fileSystem, $loggerFactory);
  }

  /**
   * Build an OR-group mock that records every notExists() / condition() call.
   *
   * @param array $capturedOrGroupCalls
   *   Out-parameter: each call lands here as ['method' => ..., 'args' => [...]].
   *
   * @return \Drupal\Core\Entity\Query\ConditionInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected function buildOrGroupMock(array &$capturedOrGroupCalls): MockObject {
    $orGroup = $this->createMock(ConditionInterface::class);
    $orGroup->method('notExists')->willReturnCallback(function (string $field) use (&$capturedOrGroupCalls, $orGroup) {
      $capturedOrGroupCalls[] = ['method' => 'notExists', 'args' => [$field]];
      return $orGroup;
    });
    $orGroup->method('condition')->willReturnCallback(function (...$args) use (&$capturedOrGroupCalls, $orGroup) {
      $capturedOrGroupCalls[] = ['method' => 'condition', 'args' => $args];
      return $orGroup;
    });
    return $orGroup;
  }

  /**
   * Build an app-query mock that records condition() calls and returns the
   * given OR group from orConditionGroup().
   */
  protected function buildAppQuery(array &$capturedConditions, ConditionInterface $orGroup): QueryInterface {
    $appQuery = $this->createMock(QueryInterface::class);
    $appQuery->method('condition')->willReturnCallback(function (...$args) use (&$capturedConditions, $appQuery) {
      $capturedConditions[] = $args;
      return $appQuery;
    });
    $appQuery->method('orConditionGroup')->willReturn($orGroup);
    return $appQuery;
  }

  /**
   * Invoke a protected method on the service under test.
   */
  protected function invokeProtected(AppverseCacheService $service, string $method, array $args) {
    $ref = new \ReflectionMethod($service, $method);
    $ref->setAccessible(TRUE);
    return $ref->invokeArgs($service, $args);
  }

  /**
   * With no unpublished Repos, the cascade filter is a no-op.
   *
   * @covers ::applyRepoCascadeFilter
   */
  public function testNoUnpublishedReposLeavesQueryUntouched(): void {
    $capturedConditions = [];
    $capturedOrGroupCalls = [];
    $service = $this->buildService([]);

    $orGroup = $this->buildOrGroupMock($capturedOrGroupCalls);
    $appQuery = $this->buildAppQuery($capturedConditions, $orGroup);

    $this->invokeProtected($service, 'applyRepoCascadeFilter', [$appQuery]);

    $this->assertSame([], $capturedConditions, 'No condition() should be added when there are no unpublished Repos.');
    $this->assertSame([], $capturedOrGroupCalls, 'OR group should not be configured when there are no unpublished Repos.');
  }

  /**
   * With unpublished Repos, the cascade adds an OR group:
   *   (no parent reference) OR (parent NOT IN unpublished list).
   *
   * Legacy apps with NULL field_appverse_repo (orphan branch) stay
   * visible; apps with a parent reference are subject to the NOT IN filter.
   *
   * @covers ::applyRepoCascadeFilter
   */
  public function testUnpublishedReposAddOrFilter(): void {
    $unpublishedNids = [42, 99];
    $capturedConditions = [];
    $capturedOrGroupCalls = [];
    $service = $this->buildService($unpublishedNids);

    $orGroup = $this->buildOrGroupMock($capturedOrGroupCalls);
    $appQuery = $this->buildAppQuery($capturedConditions, $orGroup);

    $this->invokeProtected($service, 'applyRepoCascadeFilter', [$appQuery]);

    // Orphan branch: notExists on field_appverse_repo.
    $notExistsCalls = array_filter($capturedOrGroupCalls, fn($c) => $c['method'] === 'notExists');
    $this->assertCount(1, $notExistsCalls);
    $notExists = array_values($notExistsCalls)[0];
    $this->assertSame(['field_appverse_repo'], $notExists['args']);

    // Published-parent branch: condition(field, [42,99], 'NOT IN').
    $conditionCalls = array_values(array_filter($capturedOrGroupCalls, fn($c) => $c['method'] === 'condition'));
    $this->assertCount(1, $conditionCalls);
    $this->assertSame('field_appverse_repo', $conditionCalls[0]['args'][0]);
    $this->assertSame($unpublishedNids, $conditionCalls[0]['args'][1]);
    $this->assertSame('NOT IN', $conditionCalls[0]['args'][2]);

    // And the OR group itself is attached to the outer app query.
    $this->assertCount(1, $capturedConditions, 'Exactly one outer condition() call (the OR group) should be added.');
    $this->assertSame($orGroup, $capturedConditions[0][0]);
  }

}
