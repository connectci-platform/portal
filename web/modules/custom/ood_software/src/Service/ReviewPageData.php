<?php

namespace Drupal\ood_software\Service;

/**
 * Shapes a review's data for the review page.
 *
 * Pure functions over plain arrays: the form extracts arrays from the review
 * node and its paragraphs, this class sorts them into the page's blocks, and
 * the template renders the result. Keeping Drupal out of here is what makes
 * the rules unit-testable — which block a finding belongs to, which records
 * count as findings, how severity groups order, how "also flagged in" is
 * decided.
 */
final class ReviewPageData {

  /**
   * Per-app blocks in display order. Maintenance is repo-level and separate.
   */
  const APP_BLOCKS = [
    'structure' => 'Structure',
    'security' => 'Security',
    'portability' => 'Portability',
    'documentation' => 'Documentation',
    'code_quality' => 'Code quality',
  ];

  /**
   * Blocks that carry a public signal level; the others only hold findings.
   */
  const LEVEL_BLOCKS = ['security', 'portability', 'documentation'];

  const SEVERITY_ORDER = ['critical', 'high', 'medium', 'low', 'info'];

  const GATE_LABELS = [
    'metadata' => 'Metadata',
    'yaml_valid' => 'YAML valid',
    'structure' => 'Standard layout',
    'references' => 'References resolve',
    'license' => 'License',
    'readme_substantive' => 'README substantive',
    'not_archived' => 'Not archived',
    'public' => 'Public',
  ];

  /**
   * The block a finding belongs to, from its rule code.
   */
  public static function blockFor(string $rule): string {
    $rule = strtoupper(trim($rule));
    if (str_starts_with($rule, 'OODT-')) {
      return 'security';
    }
    if ($rule === 'QUA-01') {
      return 'documentation';
    }
    if ($rule === 'QUA-02') {
      return 'portability';
    }
    if (str_starts_with($rule, 'QUA-')) {
      return 'code_quality';
    }
    if (str_starts_with($rule, 'STR-')) {
      return 'structure';
    }
    if (str_starts_with($rule, 'MNT-')) {
      return 'maintenance';
    }
    return 'other';
  }

  /**
   * Whether a finding record asserts a defect.
   *
   * FAIL and WARN do; PASS confirms a check and NOT CHECKED reports a skipped
   * one (row-per-check reporting since appverse-review#46). A record with no
   * result counts as a finding, as it always has.
   */
  public static function isDefect(array $finding): bool {
    $result = strtoupper(trim((string) ($finding['result'] ?? '')));
    return $result === '' || $result === 'FAIL' || $result === 'WARN';
  }

  /**
   * Findings grouped by severity, worst first, only severities present.
   *
   * @return array<int, array{severity: string, count: int, findings: array}>
   */
  public static function groupBySeverity(array $findings): array {
    $buckets = [];
    foreach ($findings as $finding) {
      if (!self::isDefect($finding)) {
        continue;
      }
      $severity = strtolower(trim((string) ($finding['severity'] ?? 'info'))) ?: 'info';
      $buckets[$severity][] = $finding;
    }
    $order = self::SEVERITY_ORDER;
    foreach (array_keys($buckets) as $severity) {
      if (!in_array($severity, $order, TRUE)) {
        $order[] = $severity;
      }
    }
    $groups = [];
    foreach ($order as $severity) {
      if (isset($buckets[$severity])) {
        $groups[] = ['severity' => $severity, 'count' => count($buckets[$severity]), 'findings' => $buckets[$severity]];
      }
    }
    return $groups;
  }

  /**
   * "3 findings · 2 High · 1 Medium", or "No findings".
   */
  public static function countLine(array $findings): string {
    $groups = self::groupBySeverity($findings);
    $total = array_sum(array_column($groups, 'count'));
    if ($total === 0) {
      return 'No findings';
    }
    $parts = [sprintf('%d %s', $total, $total === 1 ? 'finding' : 'findings')];
    foreach ($groups as $group) {
      $parts[] = sprintf('%d %s', $group['count'], ucfirst($group['severity']));
    }
    return implode(' · ', $parts);
  }

  /**
   * Labels of the previous reviews that carried this stable id, in the
   * order given (newest first).
   *
   * @param array<int, array{label: string, stable_ids: array}> $previous
   */
  public static function alsoFlaggedIn(string $stableId, array $previous): array {
    if ($stableId === '') {
      return [];
    }
    $labels = [];
    foreach ($previous as $review) {
      if (in_array($stableId, $review['stable_ids'] ?? [], TRUE)) {
        $labels[] = $review['label'];
      }
    }
    return $labels;
  }

  /**
   * Gate criteria as pills, in the tool's order and vocabulary.
   *
   * @return array<int, array{key: string, label: string, value: string}>
   */
  public static function gatePills(array $criteria): array {
    $pills = [];
    foreach ($criteria as $key => $value) {
      $pills[] = [
        'key' => (string) $key,
        'label' => self::GATE_LABELS[$key] ?? ucfirst(str_replace('_', ' ', (string) $key)),
        'value' => (string) $value,
      ];
    }
    return $pills;
  }

  /**
   * One block: title, level (or NULL), summary, note, count line, groups.
   */
  public static function buildBlock(string $key, string $title, array $findings, ?array $level, array $previous, array $gates = []): array {
    $groups = self::groupBySeverity($findings);
    foreach ($groups as &$group) {
      foreach ($group['findings'] as &$finding) {
        $finding['also_flagged_in'] = self::alsoFlaggedIn((string) ($finding['stable_id'] ?? ''), $previous);
      }
      unset($finding);
    }
    unset($group);
    return [
      'key' => $key,
      'title' => $title,
      'level' => $level['level'] ?? NULL,
      'summary' => $level['summary'] ?? '',
      'anchor' => $level['anchor'] ?? '',
      'note' => $level['note'] ?? '',
      'gates' => $gates,
      'count_line' => self::countLine($findings),
      'groups' => $groups,
    ];
  }

  /**
   * The per-app section: five blocks in order, findings sorted into them.
   *
   * @param array $app
   *   app_id, name, criteria (array), conclusion, levels (security /
   *   portability / documentation => level, summary, anchor, note), findings.
   * @param array $previous
   *   Previous reviews as alsoFlaggedIn() expects them.
   */
  public static function buildApp(array $app, array $previous = []): array {
    $byBlock = array_fill_keys(array_keys(self::APP_BLOCKS), []);
    foreach ($app['findings'] ?? [] as $finding) {
      $block = self::blockFor((string) ($finding['rule'] ?? ''));
      if (!isset($byBlock[$block])) {
        $block = 'code_quality';
      }
      $byBlock[$block][] = $finding;
    }
    $blocks = [];
    foreach (self::APP_BLOCKS as $key => $title) {
      $level = in_array($key, self::LEVEL_BLOCKS, TRUE) ? ($app['levels'][$key] ?? NULL) : NULL;
      $gates = $key === 'structure' ? self::gatePills($app['criteria'] ?? []) : [];
      $blocks[$key] = self::buildBlock($key, $title, $byBlock[$key], $level, $previous, $gates);
    }
    return [
      'app_id' => $app['app_id'] ?? 'root',
      'name' => $app['name'] ?? ($app['app_id'] ?? 'App'),
      'conclusion' => $app['conclusion'] ?? NULL,
      'blocks' => $blocks,
    ];
  }

  /**
   * The repo-level section: the Maintenance block plus any other repo-wide
   * findings sorted into the app blocks (findings only, no levels).
   *
   * In a monorepo the tool files repo-wide structure and quality findings
   * under "root" and the assembler keeps them at repo level; they must not
   * fall between the per-app sections and Maintenance.
   *
   * @return array{maintenance: array, blocks: array, total: int}
   */
  public static function buildRepo(array $findings, ?array $level, array $previous = []): array {
    $mnt = [];
    $byBlock = array_fill_keys(array_keys(self::APP_BLOCKS), []);
    foreach ($findings as $finding) {
      $block = self::blockFor((string) ($finding['rule'] ?? ''));
      if ($block === 'maintenance') {
        $mnt[] = $finding;
      }
      else {
        $byBlock[isset($byBlock[$block]) ? $block : 'code_quality'][] = $finding;
      }
    }
    $blocks = [];
    foreach (self::APP_BLOCKS as $key => $title) {
      $defects = array_filter($byBlock[$key], [self::class, 'isDefect']);
      if ($defects !== []) {
        $blocks[$key] = self::buildBlock($key, $title, array_values($defects), NULL, $previous);
      }
    }
    $maintenance = self::buildBlock('maintenance', 'Maintenance', $mnt, $level, $previous);
    $total = array_sum(array_column($maintenance['groups'], 'count'));
    foreach ($blocks as $block) {
      $total += array_sum(array_column($block['groups'], 'count'));
    }
    return ['maintenance' => $maintenance, 'blocks' => $blocks, 'total' => $total];
  }

}
