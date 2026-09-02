<?php

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ood_software\Service\AppverseCacheService;

/**
 * @group ood_software
 * @coversDefaultClass \Drupal\ood_software\Service\AppverseCacheService
 */
class AppverseCacheInvalidationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Minimal set: these tests exercise only the dirty-flag/flush semantics and
   * subscriber registration, NOT cache file output. If you later add an
   * assertion that calls generate() and inspects appverse-data.json, you'll
   * need the fuller module set used by SyncInferredMemberAppTest (field, text,
   * taxonomy, file, media, content_moderation, workflows, etc.).
   */
  protected static $modules = ['ood_software', 'node', 'user', 'system', 'key'];

  /**
   * The cache service under test.
   */
  protected AppverseCacheService $cache;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->cache = $this->container->get('ood_software.appverse_cache');
  }

  /**
   * flushIfDirty() is a no-op (returns FALSE) when nothing marked it dirty.
   *
   * @covers ::flushIfDirty
   */
  public function testFlushIsNoOpWhenClean(): void {
    $this->assertFalse($this->cache->flushIfDirty(), 'A clean cache should not flush.');
  }

  /**
   * Build a partial mock that stubs only generate() to a fixed result.
   *
   * Lets the flag-semantics tests run deterministically without the full
   * appverse field/bundle config that real generation needs. The real
   * markDirty()/flushIfDirty() bodies run against the stubbed generate().
   */
  protected function cacheWithGenerateResult(bool $result): AppverseCacheService {
    $mock = $this->getMockBuilder(AppverseCacheService::class)
      ->setConstructorArgs([
        $this->container->get('entity_type.manager'),
        $this->container->get('file_url_generator'),
        $this->container->get('file_system'),
        $this->container->get('logger.factory'),
      ])
      ->onlyMethods(['generate'])
      ->getMock();
    $mock->method('generate')->willReturn($result);
    return $mock;
  }

  /**
   * markDirty() then flushIfDirty() runs exactly one (successful) regeneration.
   *
   * @covers ::markDirty
   * @covers ::flushIfDirty
   */
  public function testMarkThenFlushRunsOnce(): void {
    $cache = $this->cacheWithGenerateResult(TRUE);
    $cache->expects($this->once())->method('generate');
    $cache->markDirty();
    $this->assertTrue($cache->flushIfDirty(), 'First flush after markDirty should regenerate and succeed.');
    // Flag is consumed: a second flush is a no-op (generate not called again).
    $this->assertFalse($cache->flushIfDirty(), 'Second flush without a new mark should be a no-op.');
  }

  /**
   * Multiple markDirty() calls still collapse to a single flush.
   *
   * @covers ::markDirty
   * @covers ::flushIfDirty
   */
  public function testMultipleMarksCollapseToOneFlush(): void {
    $cache = $this->cacheWithGenerateResult(TRUE);
    $cache->expects($this->once())->method('generate');
    $cache->markDirty();
    $cache->markDirty();
    $cache->markDirty();
    $this->assertTrue($cache->flushIfDirty(), 'Flush runs once for N marks.');
    $this->assertFalse($cache->flushIfDirty(), 'No second flush — N marks collapsed to one.');
  }

  /**
   * A failed generate() leaves the flag set so the next flush retries.
   *
   * @covers ::flushIfDirty
   */
  public function testFailedGenerateKeepsDirtyForRetry(): void {
    $cache = $this->cacheWithGenerateResult(FALSE);
    // Two flush attempts: each should call generate() because the first
    // failure re-set the flag.
    $cache->expects($this->exactly(2))->method('generate');
    $cache->markDirty();
    $this->assertFalse($cache->flushIfDirty(), 'A failed regeneration returns FALSE.');
    $this->assertFalse($cache->flushIfDirty(), 'Flag stayed set, so the retry attempts generate() again.');
  }

  /**
   * The terminate subscriber is registered and wired to the cache service.
   */
  public function testFlushSubscriberRegistered(): void {
    $this->assertTrue(
      $this->container->has('ood_software.appverse_cache_flush'),
      'The kernel.terminate flush subscriber should be registered.'
    );
  }

}
