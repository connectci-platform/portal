<?php

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\ood_software\Service\DuplicateCheck;
use Drupal\Tests\UnitTestCase;

/**
 * The reviewer's duplicate check gates an Accept without restricting it.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Service\DuplicateCheck
 */
class DuplicateCheckTest extends UnitTestCase {

  /**
   * @covers ::needsNote
   */
  public function testARationaleForPrecedentAndDuplicates(): void {
    $this->assertFalse(DuplicateCheck::needsNote('none', ''));
    $this->assertTrue(DuplicateCheck::needsNote('distinct', ' '));
    $this->assertTrue(DuplicateCheck::needsNote('duplicate', ''));
    $this->assertFalse(DuplicateCheck::needsNote('duplicate', 'Same app as OSC/bc_osc_abaqus with site config.'));
    $this->assertFalse(DuplicateCheck::needsNote(NULL, ''));
    // "No other app" stands on the catalog only when a Software entry
    // matched; otherwise the reviewer compared by name and says how (A4b).
    $this->assertTrue(DuplicateCheck::needsNote('none', '', FALSE));
    $this->assertFalse(DuplicateCheck::needsNote('none', 'Compared by name: no other SAS app.', FALSE));
  }

  /**
   * @covers ::problems
   */
  public function testAnAcceptNeedsTheCheckRecorded(): void {
    $names = ['1' => 'Abaqus', '2' => 'SAS'];
    $this->assertSame(['Record the duplicate check for Abaqus before accepting it.'],
      DuplicateCheck::problems(['1' => 'accept', '2' => 'request_changes'], [], $names));
    $this->assertSame(['Record the duplicate check for SAS before accepting it.'],
      DuplicateCheck::problems(['1' => 'accept', '2' => 'accept_with_suggestions'], ['1' => 'none', '2' => 'bogus'], $names));
    $this->assertSame([], DuplicateCheck::problems(['1' => 'reject', '2' => 'request_changes'], [], $names), 'Only an Accept needs it.');
    $this->assertSame([], DuplicateCheck::problems(['1' => 'accept'], ['1' => 'duplicate'], $names), 'The outcome does not restrict the decision.');
  }

  /**
   * @covers ::warnings
   */
  public function testAcceptingADuplicateIsAWarning(): void {
    $this->assertSame(['You marked Abaqus as a duplicate of an existing app but are accepting it.'],
      DuplicateCheck::warnings(['1' => 'accept_with_suggestions'], ['1' => 'duplicate'], ['1' => 'Abaqus']));
    $this->assertSame([], DuplicateCheck::warnings(['1' => 'reject'], ['1' => 'duplicate']));
    $this->assertSame([], DuplicateCheck::warnings(['1' => 'accept'], ['1' => 'distinct']));
  }

}
