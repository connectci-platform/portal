<?php

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\ood_software\Service\FindingOverride;
use Drupal\Tests\UnitTestCase;

/**
 * A reviewer's change to an automated finding (A1 in the 2026-10-07
 * guidelines alignment plan): a new severity or a dismissal, with a reason.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Service\FindingOverride
 */
class FindingOverrideTest extends UnitTestCase {

  /**
   * @covers ::effectiveSeverity
   */
  public function testEffectiveSeverity(): void {
    $this->assertSame('high', FindingOverride::effectiveSeverity('high', NULL));
    $this->assertSame('high', FindingOverride::effectiveSeverity('high', ''));
    $this->assertSame('low', FindingOverride::effectiveSeverity('high', 'low'));
    $this->assertSame('critical', FindingOverride::effectiveSeverity('low', 'critical'), 'Raising is allowed.');
    $this->assertSame('high', FindingOverride::effectiveSeverity('high', 'bogus'), 'An unknown value falls back to the tool.');
  }

  /**
   * Only the tool's findings that are findings: FAIL, WARN, or a row seeded
   * before results were stored.
   *
   * @covers ::applies
   */
  public function testApplies(): void {
    $this->assertTrue(FindingOverride::applies(['source' => 'ai', 'result' => 'FAIL']));
    $this->assertTrue(FindingOverride::applies(['source' => 'ai', 'result' => 'WARN']));
    $this->assertTrue(FindingOverride::applies(['source' => 'ai', 'result' => '']));
    $this->assertFalse(FindingOverride::applies(['source' => 'ai', 'result' => 'PASS']));
    $this->assertFalse(FindingOverride::applies(['source' => 'ai', 'result' => 'NOT CHECKED']));
    $this->assertFalse(FindingOverride::applies(['source' => 'reviewer', 'result' => 'FAIL']), 'A reviewer edits their own finding directly.');
  }

  /**
   * @covers ::normalize
   */
  public function testNormalize(): void {
    $none = ['severity' => NULL, 'dismissed' => FALSE, 'reason' => ''];
    $this->assertSame($none, FindingOverride::normalize([], 'high'));
    $this->assertSame($none, FindingOverride::normalize(['severity' => 'high', 'reason' => 'x'], 'high'), "Choosing the tool's own severity is no change, and a reason alone is dropped.");
    $this->assertSame(['severity' => 'low', 'dismissed' => FALSE, 'reason' => 'Only reachable by admins.'], FindingOverride::normalize(['severity' => 'low', 'reason' => ' Only reachable by admins. '], 'high'));
    $this->assertSame(['severity' => NULL, 'dismissed' => TRUE, 'reason' => 'False positive.'], FindingOverride::normalize(['severity' => 'low', 'dismissed' => '1', 'reason' => 'False positive.'], 'high'), 'A dismissal makes the severity moot.');
  }

  /**
   * @covers ::error
   */
  public function testAChangeNeedsAReason(): void {
    $this->assertNull(FindingOverride::error(['severity' => NULL, 'dismissed' => FALSE, 'reason' => '']));
    $this->assertNotNull(FindingOverride::error(['severity' => 'low', 'dismissed' => FALSE, 'reason' => '']));
    $this->assertNotNull(FindingOverride::error(['severity' => NULL, 'dismissed' => TRUE, 'reason' => '']));
    $this->assertNull(FindingOverride::error(['severity' => NULL, 'dismissed' => TRUE, 'reason' => 'Test fixture.']));
  }

  /**
   * The revision log line for a change, or NULL when nothing changed.
   *
   * @covers ::describe
   */
  public function testDescribe(): void {
    $none = ['severity' => NULL, 'dismissed' => FALSE, 'reason' => ''];
    $low = ['severity' => 'low', 'dismissed' => FALSE, 'reason' => 'r'];
    $gone = ['severity' => NULL, 'dismissed' => TRUE, 'reason' => 'r'];
    $this->assertNull(FindingOverride::describe('OODT-02', 'high', $none, $none));
    $this->assertSame('changed OODT-02 from High to Low', FindingOverride::describe('OODT-02', 'high', $none, $low));
    $this->assertSame('dismissed OODT-02', FindingOverride::describe('OODT-02', 'high', $low, $gone));
    $this->assertSame('undid the change to OODT-02', FindingOverride::describe('OODT-02', 'high', $gone, $none));
    $this->assertSame('changed the reason on OODT-02', FindingOverride::describe('OODT-02', 'high', $low, ['reason' => 'new'] + $low));
  }

}
