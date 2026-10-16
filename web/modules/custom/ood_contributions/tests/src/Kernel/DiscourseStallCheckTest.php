<?php

namespace Drupal\Tests\ood_contributions\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\ood_contributions\Kernel\Traits\DiscourseTestTrait;

/**
 * Covers DiscourseStatsService::checkStall().
 *
 * @group ood_contributions
 */
class DiscourseStallCheckTest extends KernelTestBase {

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
   * Spreads a stored total over recent days.
   */
  protected function seedStoredTotal(int $total): void {
    $this->seedCount('2026-09-25', 0);
    if ($total > 0) {
      $this->seedCount('2026-10-10', intdiv($total, 2));
      $this->seedCount('2026-10-15', $total - intdiv($total, 2));
    }
    // Outside the 30-day window; must not be counted.
    $this->seedCount('2026-08-01', 500);
  }

  /**
   * Discourse reports 82 and nothing is stored: stall.
   */
  public function testZeroStoredIsStall(): void {
    $this->posts30Days = 82;
    $this->seedStoredTotal(0);
    $message = $this->statsService()->checkStall();
    $this->assertNotNull($message);
    $this->assertStringContainsString('82', $message);
  }

  /**
   * The normal ratio (about 0.6) is fine.
   */
  public function testNormalRatioIsFine(): void {
    $this->posts30Days = 82;
    $this->seedStoredTotal(48);
    $this->assertNull($this->statsService()->checkStall());
  }

  /**
   * A ratio under 0.25 is a stall.
   */
  public function testLowRatioIsStall(): void {
    $this->posts30Days = 82;
    $this->seedStoredTotal(15);
    $this->assertNotNull($this->statsService()->checkStall());
  }

  /**
   * Below the minimum of 5 Discourse posts nothing is judged.
   */
  public function testQuietSiteIsFine(): void {
    $this->posts30Days = 3;
    $this->seedStoredTotal(0);
    $this->assertNull($this->statsService()->checkStall());
  }

  /**
   * Boundary: exactly 5 with nothing stored is a stall, 0.25 is not.
   */
  public function testBoundaries(): void {
    $this->posts30Days = 5;
    $this->seedStoredTotal(0);
    $this->assertNotNull($this->statsService()->checkStall());

    $this->posts30Days = 100;
    $this->seedStoredTotal(25);
    $this->assertNull($this->statsService()->checkStall());
  }

  /**
   * An unreachable or malformed /about.json is skipped, not alerted.
   */
  public function testUnavailableAboutJsonReturnsNull(): void {
    $this->seedStoredTotal(0);
    $this->posts30Days = NULL;
    $this->assertNull($this->statsService()->checkStall());

    $this->httpFails = TRUE;
    $this->assertNull($this->statsService()->checkStall());
  }

}
