<?php

use PHPUnit\Framework\TestCase;

/**
 * Maps a ticket's sprints to the program it was built for.
 */
class ProgramMapperTest extends TestCase {

  private function mapper(): ProgramMapper {
    return ProgramMapper::fromFile(__DIR__ . '/../../../release-programs.json');
  }

  private static function sprint(string $name, ?string $start): array {
    return ['name' => $name, 'startDate' => $start];
  }

  /**
   * @dataProvider sprintProvider
   */
  public function testProgramFor(array $sprints, string $expected): void {
    $this->assertSame($expected, $this->mapper()->programFor($sprints));
  }

  public static function sprintProvider(): array {
    return [
      'access' => [[self::sprint('ACCESS Oct-Nov', '2026-10-11T00:00:00Z')], 'ACCESS'],
      'accessibility is not access' => [[self::sprint('Accessibility', '2026-09-08T00:00:00Z')], 'Connect.CI'],
      'latest program sprint wins, list order is not date order' => [[
        self::sprint('April 26', '2026-04-26T00:00:00Z'),
        self::sprint('OOD Hub Sept', '2026-09-08T00:00:00Z'),
        self::sprint('ACCESS Sept', '2026-09-08T00:00:00Z'),
        self::sprint('Testing', NULL),
        self::sprint('OOD Hub Oct-Nov', '2026-10-11T00:00:00Z'),
      ], 'OOD'],
      'only date and testing sprints' => [[self::sprint('Jan 17', '2025-01-03T00:00:00Z'), self::sprint('Testing', NULL)], 'Connect.CI'],
      'community hub' => [[self::sprint('Community Hub August', '2026-08-07T00:00:00Z')], 'OOD'],
      'champions caps' => [[self::sprint('CHAMPIONS MAY-JUN', '2026-05-21T00:00:00Z')], 'Champions'],
      'campus champions' => [[self::sprint('Campus Champions Refresh', '2025-06-01T00:00:00Z')], 'Champions'],
      'cnct' => [[self::sprint('CNCT Sept', '2026-09-08T00:00:00Z')], 'Connect.CI'],
      'ccmnet' => [[self::sprint('CCMNet MAR-APR', '2026-03-19T00:00:00Z')], 'CCMNet'],
      'no sprints' => [[], 'Connect.CI'],
    ];
  }

}
