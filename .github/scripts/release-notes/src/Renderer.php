<?php

/**
 * Renders release note items as the release body, and picks the bump.
 *
 * The format is a parse contract for the per-program summary: "## Section"
 * headings, and one "- [Program] text (D8-NNNN, #PR)" line per item.
 */
final class Renderer {

  public const SECTIONS = ['Features', 'Improvements', 'Fixes', 'Maintenance'];

  public const DEGRADED_BANNER = '> Jira could not be reached when these notes were written; some items are listed generically.';

  /**
   * @param list<array{section: string, program: string, text: string, key: ?string, prs: list<int>}> $items
   */
  public static function render(array $items, bool $jiraDegraded): string {
    $out = $jiraDegraded ? self::DEGRADED_BANNER . "\n\n" : '';
    if (!$items) {
      return $out . "No changes in this release.\n";
    }
    usort($items, fn ($a, $b) => [$a['program'], $a['text']] <=> [$b['program'], $b['text']]);
    $blocks = [];
    foreach (self::SECTIONS as $section) {
      $lines = '';
      foreach ($items as $item) {
        if ($item['section'] === $section) {
          $refs = array_map(fn ($n) => "#$n", $item['prs']);
          if ($item['key'] !== NULL) {
            array_unshift($refs, $item['key']);
          }
          $lines .= "- [{$item['program']}] {$item['text']} (" . implode(', ', $refs) . ")\n";
        }
      }
      if ($lines !== '') {
        $blocks[] = "## $section\n\n$lines";
      }
    }
    return $out . implode("\n", $blocks);
  }

  /**
   * @param list<array{section: string, program: string, text: string, key: ?string, prs: list<int>}> $items
   *
   * @return string
   *   'minor' when anything is a feature, otherwise 'patch'.
   */
  public static function bump(array $items): string {
    foreach ($items as $item) {
      if ($item['section'] === 'Features') {
        return 'minor';
      }
    }
    return 'patch';
  }

}
