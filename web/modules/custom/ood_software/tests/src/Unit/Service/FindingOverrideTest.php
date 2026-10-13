<?php

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\ood_software\Service\FindingOverride;
use Drupal\Tests\UnitTestCase;

/**
 * A reviewer's change to an automated finding (A1 in the 2026-10-07
 * guidelines alignment plan): a new severity, a dismissal, or their wording.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Service\FindingOverride
 */
class FindingOverrideTest extends UnitTestCase {

  const TOOL = ['severity' => 'high', 'summary' => 'Binds to all interfaces.', 'evidence' => 'script.sh.erb:9'];

  const NONE = ['severity' => NULL, 'dismissed' => FALSE, 'summary' => NULL, 'evidence' => NULL];

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
   * @covers ::isChanged
   */
  public function testNormalize(): void {
    $this->assertSame(self::NONE, FindingOverride::normalize([], self::TOOL));
    $this->assertSame(self::NONE, FindingOverride::normalize(self::TOOL, self::TOOL), "The tool's own values are no change.");
    $this->assertSame(self::NONE, FindingOverride::normalize(['summary' => '  ', 'evidence' => ''], self::TOOL), "An emptied field means the tool's.");
    $this->assertFalse(FindingOverride::isChanged(self::NONE));
    $this->assertSame(array_replace(self::NONE, ['severity' => 'low']), FindingOverride::normalize(['severity' => 'low'] + self::TOOL, self::TOOL));
    $this->assertSame(array_replace(self::NONE, ['dismissed' => TRUE]), FindingOverride::normalize(['severity' => 'low', 'dismissed' => '1'], self::TOOL), 'A dismissal makes the severity moot.');
    $this->assertSame(array_replace(self::NONE, ['summary' => 'Binds to all interfaces inside its own container.']),
      FindingOverride::normalize(['summary' => ' Binds to all interfaces inside its own container. '] + self::TOOL, self::TOOL));
  }

  /**
   * A new severity or a dismissal needs the note as its reason; a wording
   * change does not.
   *
   * @covers ::needsNote
   */
  public function testANoteIsTheReason(): void {
    $this->assertFalse(FindingOverride::needsNote(self::NONE, ''));
    $this->assertTrue(FindingOverride::needsNote(array_replace(self::NONE, ['severity' => 'low']), ''));
    $this->assertTrue(FindingOverride::needsNote(array_replace(self::NONE, ['dismissed' => TRUE]), '  '));
    $this->assertFalse(FindingOverride::needsNote(array_replace(self::NONE, ['dismissed' => TRUE]), 'Test fixture.'));
    $this->assertFalse(FindingOverride::needsNote(array_replace(self::NONE, ['summary' => 'Clearer.']), ''));
  }

  /**
   * The revision log line for a change, or NULL when nothing changed.
   *
   * @covers ::describe
   */
  public function testDescribe(): void {
    $low = array_replace(self::NONE, ['severity' => 'low']);
    $gone = array_replace(self::NONE, ['dismissed' => TRUE]);
    $reworded = array_replace(self::NONE, ['summary' => 'Clearer.']);
    $this->assertNull(FindingOverride::describe('OODT-02', 'high', self::NONE, self::NONE));
    $this->assertSame('changed OODT-02 from High to Low', FindingOverride::describe('OODT-02', 'high', self::NONE, $low));
    $this->assertSame('dismissed OODT-02', FindingOverride::describe('OODT-02', 'high', $low, $gone));
    $this->assertSame('undid the change to OODT-02', FindingOverride::describe('OODT-02', 'high', $gone, self::NONE));
    $this->assertSame('edited the wording of OODT-02', FindingOverride::describe('OODT-02', 'high', self::NONE, $reworded));
    $this->assertSame('changed OODT-02 from High to Low, edited the wording of OODT-02', FindingOverride::describe('OODT-02', 'high', self::NONE, array_replace(self::NONE, ['severity' => 'low', 'summary' => 'Clearer.'])));
    $this->assertSame("restored OODT-02", FindingOverride::describe('OODT-02', 'high', array_replace(self::NONE, ['dismissed' => TRUE, 'summary' => 'Clearer.']), $reworded));
  }

}
