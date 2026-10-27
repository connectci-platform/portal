<?php

/**
 * Collects one program's release note lines over a date range.
 *
 * Reads the format Renderer writes: "## Section" headings and
 * "- [Program] ..." lines. Releases from before that format (the old git log
 * notes) have no section headings, so they contribute nothing.
 */
final class ProgramSummary {

  /**
   * @param list<array<string, mixed>> $releases
   *   GitHub release objects: tag_name, published_at, body.
   * @param string $from
   *   First day, YYYY-MM-DD, inclusive (UTC).
   * @param string $to
   *   Last day, YYYY-MM-DD, inclusive (UTC).
   */
  public static function summarize(array $releases, string $program, string $from, string $to): string {
    $prefix = "- [$program] ";
    $bySection = array_fill_keys(Renderer::SECTIONS, []);
    foreach ($releases as $release) {
      $day = substr((string) ($release['published_at'] ?? ''), 0, 10);
      if ($day < $from || $day > $to) {
        continue;
      }
      $section = NULL;
      foreach (preg_split('/\R/', (string) ($release['body'] ?? '')) as $line) {
        if (preg_match('/^## (.+?)\s*$/', $line, $m)) {
          $section = in_array($m[1], Renderer::SECTIONS, TRUE) ? $m[1] : NULL;
        }
        elseif ($section !== NULL && str_starts_with($line, $prefix)) {
          $bySection[$section][] = "$line — {$release['tag_name']}";
        }
      }
    }
    $blocks = [];
    foreach ($bySection as $section => $lines) {
      if ($lines) {
        $blocks[] = "## $section\n\n" . implode("\n", $lines) . "\n";
      }
    }
    if (!$blocks) {
      return "No $program changes between $from and $to.";
    }
    return "# $program changes, $from to $to\n\n" . implode("\n", $blocks);
  }

}
