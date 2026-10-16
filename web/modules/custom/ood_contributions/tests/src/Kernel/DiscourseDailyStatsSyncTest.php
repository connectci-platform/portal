<?php

namespace Drupal\Tests\ood_contributions\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\ood_contributions\Kernel\Traits\DiscourseTestTrait;

/**
 * Covers the Discourse daily post sync.
 *
 * @group ood_contributions
 */
class DiscourseDailyStatsSyncTest extends KernelTestBase {

  use DiscourseTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'options', 'datetime',
    'link', 'taxonomy', 'path', 'path_alias', 'content_moderation', 'workflows',
    'key', 'flag', 'file', 'ood_software', 'ood_contributions',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('ood_contributions', ['ood_disc_daily_posts']);
    $this->setUpDiscourseClock();
  }

  /**
   * A run imports new posts and stores the correct per-day counts.
   */
  public function testImportsNewPosts(): void {
    // 15:00Z is 11:00 New York, the same date.
    $this->addPost('2026-10-14T15:00:00Z');
    $this->addPost('2026-10-14T16:00:00Z');
    $this->addPost('2026-10-15T15:00:00Z');
    $this->addPost('2026-10-16T13:00:00Z');

    $result = $this->statsService()->updateDailyPostStats();
    $this->assertTrue($result['success'], $result['message']);

    $counts = $this->storedCounts();
    $this->assertSame(2, $counts['2026-10-14']);
    $this->assertSame(1, $counts['2026-10-15']);
    $this->assertSame(1, $counts['2026-10-16']);
    $this->assertSame(0, $counts['2026-10-13']);
    // The first run covers 365 days back through today, no further.
    $this->assertArrayNotHasKey('2026-10-17', $counts);
    $this->assertCount(366, $counts);
  }

  /**
   * Regression: a second run the same day updates today's count.
   *
   * Before the fix the cursor was MAX(post_date) + 1 day, so once today had a
   * row, every later run that day was "Already up to date".
   */
  public function testSecondRunSameDayUpdatesToday(): void {
    $this->addPost('2026-10-15T15:00:00Z');
    $service = $this->statsService();
    $this->assertTrue($service->updateDailyPostStats()['success']);
    $this->assertSame(0, $this->storedCounts()['2026-10-16']);

    // More posts arrive later the same day.
    $this->addPost('2026-10-16T14:00:00Z');
    $this->addPost('2026-10-16T17:00:00Z');
    $this->now += 3 * 3600;
    $result = $this->statsService()->updateDailyPostStats();

    $this->assertTrue($result['success'], $result['message']);
    $this->assertNotSame('Already up to date', $result['message']);
    $counts = $this->storedCounts();
    $this->assertSame(2, $counts['2026-10-16']);
    $this->assertSame(1, $counts['2026-10-15']);
  }

  /**
   * Later runs re-fetch only REFETCH_DAYS before the latest stored date.
   */
  public function testIncrementalWindowIsTrailing(): void {
    $this->seedCount('2026-10-10', 7);
    $this->seedCount('2026-10-13', 5);
    $this->seedCount('2026-10-14', 5);
    $this->addPost('2026-10-14T15:00:00Z');

    $this->assertTrue($this->statsService()->updateDailyPostStats()['success']);
    $counts = $this->storedCounts();
    // Oct 14 is the latest stored date; the window starts two days earlier.
    $this->assertSame(1, $counts['2026-10-14']);
    $this->assertSame(0, $counts['2026-10-13']);
    $this->assertSame(0, $counts['2026-10-12']);
    // Older rows are untouched.
    $this->assertSame(7, $counts['2026-10-10']);
    $this->assertArrayHasKey('2026-10-16', $counts);
  }

  /**
   * A post at 03:30Z is counted on the previous New York date.
   */
  public function testEveningPostCountsOnPreviousNewYorkDate(): void {
    // 03:30Z on Oct 16 is 23:30 EDT on Oct 15.
    $this->addPost('2026-10-16T03:30:00Z');
    // 04:30Z on Oct 16 is 00:30 EDT on Oct 16.
    $this->addPost('2026-10-16T04:30:00Z');

    $this->assertTrue($this->statsService()->updateDailyPostStats()['success']);
    $counts = $this->storedCounts();
    $this->assertSame(1, $counts['2026-10-15']);
    $this->assertSame(1, $counts['2026-10-16']);
  }

  /**
   * The $since argument overrides the start date and re-writes older rows.
   */
  public function testSinceOverridesStartDate(): void {
    $this->seedCount('2026-10-01', 99);
    $this->seedCount('2026-10-14', 99);
    $this->addPost('2026-10-02T15:00:00Z');
    $this->addPost('2026-10-14T15:00:00Z');

    // Without $since only the trailing window is touched.
    $this->assertTrue($this->statsService()->updateDailyPostStats()['success']);
    $this->assertSame(99, $this->storedCounts()['2026-10-01']);
    $this->assertSame(1, $this->storedCounts()['2026-10-14']);

    $this->assertTrue($this->statsService()->updateDailyPostStats('2026-10-01')['success']);
    $counts = $this->storedCounts();
    $this->assertSame(0, $counts['2026-10-01']);
    $this->assertSame(1, $counts['2026-10-02']);
  }

  /**
   * A malformed or impossible $since is rejected and nothing is fetched.
   *
   * @dataProvider invalidSinceProvider
   */
  public function testInvalidSinceIsRejected(string $since): void {
    $result = $this->statsService()->updateDailyPostStats($since);
    $this->assertFalse($result['success']);
    $this->assertSame([], $this->storedCounts());
    $this->assertSame([], $this->requested);
  }

  /**
   * Data provider for invalid dates.
   *
   * @return array<string, array<string>>
   *   Candidate strings.
   */
  public static function invalidSinceProvider(): array {
    return [
      'impossible date' => ['2026-02-30'],
      'wrong format' => ['10/01/2026'],
      'trailing text' => ['2026-10-01 garbage'],
      'no zero padding' => ['2026-1-5'],
      'empty' => [''],
    ];
  }

}
