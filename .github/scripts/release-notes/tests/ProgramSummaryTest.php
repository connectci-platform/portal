<?php

use PHPUnit\Framework\TestCase;

/**
 * Collects one program's lines from release bodies over a date range.
 */
class ProgramSummaryTest extends TestCase {

  private static function releases(): array {
    return json_decode(file_get_contents(__DIR__ . '/fixtures/releases.json'), TRUE);
  }

  public function testFiltersByProgramAndDate(): void {
    $out = ProgramSummary::summarize(self::releases(), 'ACCESS', '2026-10-25', '2026-10-31');
    $this->assertStringStartsWith("# ACCESS changes, 2026-10-25 to 2026-10-31\n", $out);
    $this->assertStringContainsString("- [ACCESS] Refinement to Alert in Event editing (D8-2843, #2288) — 6.9.0\n", $out);
    $this->assertStringNotContainsString('[OOD]', $out);
    $this->assertStringNotContainsString('PA Science', $out);
  }

  public function testKeepsSections(): void {
    $out = ProgramSummary::summarize(self::releases(), 'ACCESS', '2026-10-01', '2026-10-31');
    $this->assertMatchesRegularExpression('/## Features\n\n- \[ACCESS\] Refinement.*\n\n## Fixes\n\n- \[ACCESS\] Security hardening \(#2300\) — 6\.9\.0\n/s', $out);
  }

  public function testDateRangeIsInclusive(): void {
    $out = ProgramSummary::summarize(self::releases(), 'Connect.CI', '2026-10-24', '2026-10-24');
    $this->assertStringContainsString('Allow PA Science Manager to delete projects (D8-2892, #2267) — 6.8.35', $out);
  }

  public function testSkipsOldFormat(): void {
    $out = ProgramSummary::summarize(self::releases(), 'ACCESS', '2026-10-22', '2026-10-23');
    $this->assertSame('No ACCESS changes between 2026-10-22 and 2026-10-23.', $out);
  }

  public function testNoMatches(): void {
    $this->assertSame('No CCMNet changes between 2026-10-01 and 2026-10-31.', ProgramSummary::summarize(self::releases(), 'CCMNet', '2026-10-01', '2026-10-31'));
  }

}
