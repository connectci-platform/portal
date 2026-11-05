<?php

namespace Drupal\ood_software\Service;

/**
 * What a contributor may read of a review (Task 2 of the review page work).
 *
 * Takes the "page" array ReviewPageForm::buildPage() builds for the reviewer
 * and returns the part a contributor sees. Before the decision is sent the
 * contributor sees only the tool's own report, with none of the reviewer's
 * work in progress: no overrides, rewording, dismissals, added findings or
 * response. Once it is sent they see the decision as the reviewer left it.
 */
final class ContributorReviewView {

  /**
   * Blocks in the order sections are listed, for breaking severity ties.
   */
  private const SECTION_ORDER = ['structure', 'security', 'portability', 'documentation', 'code_quality', 'maintenance'];

  /**
   * The contributor's view of one review.
   *
   * @param array<mixed> $page
   *   As ReviewPageForm::buildPage() returns it.
   * @param bool $sent
   *   Whether the reviewer has sent the decision.
   *
   * @return array<string, mixed>
   *   state (waiting, precheck or decided), findings, passed, decision,
   *   response and closed (superseded, withdrawn or NULL).
   */
  public static function build(array $page, bool $sent): array {
    $closed = !empty($page['superseded_by']) ? 'superseded' : (!empty($page['withdrawn']) ? 'withdrawn' : NULL);
    $view = [
      'state' => 'waiting',
      'findings' => [],
      'passed' => [],
      'decision' => NULL,
      'response' => '',
      'closed' => $closed,
    ];
    // No report yet, so nothing to show, whatever the reviewer has entered.
    if (empty($page['reviewed_at'])) {
      return $view;
    }
    $view['state'] = $sent ? 'decided' : 'precheck';
    if ($sent) {
      $view['decision'] = $page['decision'] ?? NULL;
      $view['response'] = (string) ($page['response'] ?? '');
    }

    $sections = [];
    foreach ((array) ($page['apps'] ?? []) as $app) {
      foreach ((array) ($app['blocks'] ?? []) as $key => $block) {
        $sections[] = [(string) ($app['name'] ?? ''), (string) $key, (array) $block];
      }
    }
    foreach ((array) ($page['repo_blocks'] ?? []) as $key => $block) {
      $sections[] = ['', (string) $key, (array) $block];
    }
    if (!empty($page['maintenance'])) {
      $sections[] = ['', 'maintenance', (array) $page['maintenance']];
    }

    $findings = [];
    foreach ($sections as [$app, $key, $block]) {
      $title = (string) ($block['title'] ?? $key);
      $rows = [];
      foreach ((array) ($block['groups'] ?? []) as $group) {
        $rows = array_merge($rows, (array) ($group['findings'] ?? []));
      }
      $checked = (array) ($block['checked']['rows'] ?? []);
      // Dismissals are the reviewer's until sent; the tool's report still
      // has the finding.
      $dismissed = $sent ? [] : (array) ($block['dismissed'] ?? []);
      foreach (array_merge($rows, $dismissed) as $row) {
        if (!self::isDefect((array) $row)) {
          continue;
        }
        $item = self::finding((array) $row, $app, $title, $sent);
        if ($item !== NULL) {
          $item['_order'] = array_search($key, self::SECTION_ORDER, TRUE);
          $findings[] = $item;
        }
      }
      foreach (array_merge($checked, $dismissed) as $row) {
        $isPass = strtoupper(trim((string) ($row['result'] ?? ''))) === 'PASS';
        if ($isPass && ($sent || ($row['source'] ?? '') !== 'reviewer')) {
          $label = self::toolOrEffective((array) $row, 'summary', $sent);
          $view['passed'][] = ['section' => $title, 'label' => $label];
        }
      }
    }
    // Gates the repo passed read as passed checks too.
    foreach ((array) ($page['repo_gate_block']['passes'] ?? []) as $pill) {
      $view['passed'][] = ['section' => 'Structure', 'label' => (string) ($pill['label'] ?? '')];
    }

    // PHP's sort is stable, so equal severity and section keep page order.
    usort($findings, static function (array $a, array $b): int {
      return [self::severityRank($a['severity']), $a['_order']]
        <=> [self::severityRank($b['severity']), $b['_order']];
    });
    $view['findings'] = array_map(static function (array $f): array {
      unset($f['_order']);
      return $f;
    }, $findings);
    return $view;
  }

  /**
   * One finding row as the contributor sees it, or NULL if they do not.
   *
   * @param array<mixed> $row
   *   A finding row from the page.
   * @param string $app
   *   The app's name, empty for a repo-level finding.
   * @param string $section
   *   The block's title.
   * @param bool $sent
   *   Whether the decision has been sent.
   *
   * @return array<string, mixed>|null
   *   The finding, or NULL when the contributor does not see it.
   */
  private static function finding(array $row, string $app, string $section, bool $sent): ?array {
    $reviewer = ($row['source'] ?? '') === 'reviewer';
    if ($reviewer && !$sent) {
      return NULL;
    }
    if ($sent && !empty($row['dismissed'])) {
      return NULL;
    }
    $severity = $sent ? ($row['severity'] ?? '') : ($row['tool_severity'] ?? $row['severity'] ?? '');
    $evidence = self::toolOrEffective($row, 'evidence', $sent);
    // The link is built from the evidence the page shows; a tool original the
    // reviewer reworded has none.
    $url = NULL;
    if ($sent || empty($row['original'])) {
      foreach ((array) ($row['evidence_parts'] ?? []) as $part) {
        if (!empty($part['url'])) {
          $url = (string) $part['url'];
          break;
        }
      }
    }
    $file = NULL;
    $line = NULL;
    if (preg_match('#^([^\s:,;()]+):(\d+)#', $evidence, $m)) {
      $file = $m[1];
      $line = (int) $m[2];
    }
    return [
      'app' => $app,
      'section' => $section,
      'severity' => strtolower((string) $severity),
      'source' => $reviewer ? 'reviewer' : 'tool',
      'summary' => self::toolOrEffective($row, 'summary', $sent),
      'file' => $file,
      'line' => $line,
      'url' => $url,
      'note' => $sent ? (string) ($row['prose'] ?? '') : '',
    ];
  }

  /**
   * A row's wording: the tool's before the decision is sent, else effective.
   *
   * The page keeps the tool's wording in "original" only when the reviewer
   * changed it; otherwise the effective value is the tool's.
   *
   * @param array<mixed> $row
   *   A finding row from the page.
   * @param string $key
   *   The row key to read, summary or evidence.
   * @param bool $sent
   *   Whether the decision has been sent.
   *
   * @return string
   *   The wording to show.
   */
  private static function toolOrEffective(array $row, string $key, bool $sent): string {
    if (!$sent && isset($row['original'][$key])) {
      return (string) $row['original'][$key];
    }
    return (string) ($row[$key] ?? '');
  }

  /**
   * Whether the row asserts a defect, ignoring dismissal.
   *
   * @param array<mixed> $row
   *   A finding row from the page.
   *
   * @return bool
   *   TRUE for a FAIL, a WARN or a row with no result.
   */
  private static function isDefect(array $row): bool {
    $result = strtoupper(trim((string) ($row['result'] ?? '')));
    return $result === '' || $result === 'FAIL' || $result === 'WARN';
  }

  /**
   * Position of a severity, most severe first; unknown ones last.
   */
  private static function severityRank(string $severity): int {
    $rank = array_search($severity, ReviewPageData::SEVERITY_ORDER, TRUE);
    return $rank === FALSE ? count(ReviewPageData::SEVERITY_ORDER) : $rank;
  }

}
