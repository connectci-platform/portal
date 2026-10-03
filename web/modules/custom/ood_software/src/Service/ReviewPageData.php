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
   *
   * Security is findings only (artifact schema 1.2): the tool asserts no
   * security level, so there is none to show or override.
   */
  const LEVEL_BLOCKS = ['portability', 'documentation'];

  /**
   * The Security block's count line when it has no findings.
   *
   * The honest claim, never "safe" (appverse-review's rubric wording).
   */
  const SECURITY_NONE = 'No tool-detectable issues in the checked tiers';

  const SEVERITY_ORDER = ['critical', 'high', 'medium', 'low', 'info'];

  /**
   * The rule codes a reviewer may pick for a finding added to each block,
   * from appverse-review's references/finding-codes.md. blockFor() files
   * each code under the same block.
   */
  const BLOCK_RULES = [
    'structure' => [
      'STR-01' => 'Missing or insufficient required file',
      'STR-02' => 'Missing or invalid required metadata field',
      'STR-03' => 'YAML parse error',
      'STR-04' => 'Broken reference',
      'STR-05' => 'Unbalanced or malformed ERB tags',
      'STR-06' => 'Shell script syntax error',
      'STR-07' => 'Non-standard app layout',
      'STR-08' => 'Passenger dependency manifest missing or inconsistent',
    ],
    'security' => [
      'OODT-01' => 'Shell injection / arbitrary code execution',
      'OODT-02' => 'Credential exposure',
      'OODT-03' => 'Unauthorized access',
      'OODT-04' => 'Data exfiltration',
      'OODT-05' => 'Network exposure',
      'OODT-06' => 'Container security',
      'OODT-07' => 'Persistence',
      'OODT-08' => 'Insecure configuration',
    ],
    'portability' => [
      'QUA-02' => 'Portability below threshold',
    ],
    'documentation' => [
      'QUA-01' => 'Documentation below threshold',
    ],
    'code_quality' => [
      'QUA-03' => 'Missing error handling',
      'QUA-04' => 'Dead code',
      'QUA-05' => 'Copy-paste artifact',
      'QUA-06' => 'Correctness defect',
      'QUA-07' => 'Missing input validation',
      'QUA-08' => 'Magic number or undocumented literal',
      'QUA-09' => 'Large duplicated code block',
      'QUA-10' => 'ERB template does not handle a missing or nil value',
    ],
    'maintenance' => [
      'MNT-01' => 'Activity below threshold',
      'MNT-02' => 'No tagged releases',
      'MNT-03' => 'No CHANGELOG',
      'MNT-04' => 'No CI configuration',
      'MNT-05' => 'Single contributor with no recent activity',
      'MNT-06' => 'Open issues with no response',
    ],
  ];

  /**
   * How a reviewer finding records the block it was added in, using the
   * paragraph's aspect and category fields (the seeder's vocabulary), so a
   * finding with an "Other" rule code stays where the reviewer put it.
   */
  const BLOCK_FIELDS = [
    'structure' => ['aspect' => 'structure', 'category' => NULL],
    'security' => ['aspect' => 'security', 'category' => 'security'],
    'portability' => ['aspect' => 'quality', 'category' => 'portability'],
    'documentation' => ['aspect' => 'quality', 'category' => 'documentation'],
    'code_quality' => ['aspect' => 'quality', 'category' => NULL],
    'maintenance' => ['aspect' => 'maintenance', 'category' => 'maintenance'],
  ];

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
   * The block a reviewer finding was added in, from its aspect and category
   * (the inverse of BLOCK_FIELDS); NULL when they name no block.
   */
  public static function blockFromFields(?string $aspect, ?string $category): ?string {
    foreach (self::BLOCK_FIELDS as $block => $fields) {
      if ($fields['aspect'] === $aspect && $fields['category'] === ($category ?: NULL)) {
        return $block;
      }
    }
    return NULL;
  }

  /**
   * The paragraph field values for a finding a reviewer adds or edits.
   *
   * Fills what the automated review would: the anchor file and line parsed
   * from evidence written as "path:line" (or "path:line-line"), a defect key
   * on that file, and the aspect/category that keep it in $block. Throws
   * \InvalidArgumentException for an unknown block, an unknown severity, or
   * an empty summary or rule.
   *
   * @return array{rule: string, severity: string, summary: string, evidence: string, anchor: string, line: ?int, defect_key: string, aspect: string, category: ?string, app_id: string, stable_id: string}
   */
  public static function reviewerFinding(array $input, string $block, string $appId, string $stableId): array {
    if (!isset(self::BLOCK_FIELDS[$block])) {
      throw new \InvalidArgumentException("Unknown block: $block");
    }
    $rule = strtoupper(trim((string) ($input['rule'] ?? '')));
    $severity = strtolower(trim((string) ($input['severity'] ?? '')));
    $summary = trim((string) ($input['summary'] ?? ''));
    $evidence = trim((string) ($input['evidence'] ?? ''));
    if ($rule === '' || $summary === '') {
      throw new \InvalidArgumentException('A finding needs a rule and a summary.');
    }
    if (!in_array($severity, self::SEVERITY_ORDER, TRUE)) {
      throw new \InvalidArgumentException("Unknown severity: $severity");
    }
    $anchor = '';
    $line = NULL;
    if (preg_match('/^([^\s:]+):(\d+)(?:-\d+)?\b/', $evidence, $m)) {
      $anchor = $m[1];
      $line = (int) $m[2];
    }
    elseif (preg_match('/^([^\s:]+\.[A-Za-z0-9]+)\b/', $evidence, $m)) {
      // A file named without a line ("form.yml has no …").
      $anchor = $m[1];
    }
    return [
      'rule' => $rule,
      'severity' => $severity,
      'summary' => $summary,
      'evidence' => $evidence,
      'anchor' => $anchor,
      'line' => $line,
      'defect_key' => ($anchor !== '' ? $anchor : 'root') . ':reviewer-finding',
      'aspect' => self::BLOCK_FIELDS[$block]['aspect'],
      'category' => self::BLOCK_FIELDS[$block]['category'],
      'app_id' => $appId,
      'stable_id' => $stableId,
    ];
  }

  /**
   * A GitHub link for the "path:line" an evidence string starts with.
   *
   * Only a path with a line number is linked: the line proves the file
   * exists at the reviewed commit, while a bare path is often the "file
   * absent" case and would link to a 404. "path:3", "path:3-5" (a range)
   * and "path:10,17" (first line) are understood. NULL for a non-GitHub
   * repo, no commit, or evidence that does not start with path:line.
   *
   * @return array{url: string, text: string, rest: string}|null
   *   The URL, the linked "path:line" text, and the rest of the evidence.
   */
  public static function evidenceLink(string $evidence, string $repoUrl, string $sha): ?array {
    if ($sha === '' || !preg_match('#^https?://(?:www\.)?github\.com/([^/\s]+)/([^/\s]+?)(?:\.git)?/?$#i', trim($repoUrl), $repo)) {
      return NULL;
    }
    if (!preg_match('#^([A-Za-z0-9._~/-]*[A-Za-z0-9_~-]\.?[A-Za-z0-9._~-]*):(\d+)(?:-(\d+))?(?:,\d+)*#', $evidence, $m)) {
      return NULL;
    }
    $path = ltrim($m[1], '/');
    if ($path === '' || str_contains($path, '..')) {
      return NULL;
    }
    $anchor = '#L' . $m[2] . (isset($m[3]) && $m[3] !== '' ? '-L' . $m[3] : '');
    $segments = implode('/', array_map('rawurlencode', explode('/', $path)));
    return [
      'url' => sprintf('https://github.com/%s/%s/blob/%s/%s%s', $repo[1], $repo[2], rawurlencode($sha), $segments, $anchor),
      'text' => $m[0],
      'rest' => substr($evidence, strlen($m[0])),
    ];
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
   * "3 findings · 2 High · 1 Medium", or $none when there are none.
   */
  public static function countLine(array $findings, string $none = 'No findings'): string {
    $groups = self::groupBySeverity($findings);
    $total = array_sum(array_column($groups, 'count'));
    if ($total === 0) {
      return $none;
    }
    $parts = [sprintf('%d %s', $total, $total === 1 ? 'finding' : 'findings')];
    foreach ($groups as $group) {
      $parts[] = sprintf('%d %s', $group['count'], ucfirst($group['severity']));
    }
    return implode(' · ', $parts);
  }

  /**
   * Where a review sits among the reviews of its repo.
   *
   * $reviews is the repo's reviews the viewer may see, oldest first, each
   * with at least an 'nid'. Returns the 1-based position, the total, the
   * neighbours either side, and 'newest' when the current review is not the
   * newest one (it is superseded); NULL when $currentNid is not in the list.
   *
   * @return array{position: int, total: int, older: ?array, newer: ?array, newest: ?array}|null
   */
  public static function historyPosition(array $reviews, int $currentNid): ?array {
    $reviews = array_values($reviews);
    $index = NULL;
    foreach ($reviews as $i => $review) {
      if ((int) $review['nid'] === $currentNid) {
        $index = $i;
        break;
      }
    }
    if ($index === NULL) {
      return NULL;
    }
    $last = count($reviews) - 1;
    return [
      'position' => $index + 1,
      'total' => count($reviews),
      'older' => $index > 0 ? $reviews[$index - 1] : NULL,
      'newer' => $index < $last ? $reviews[$index + 1] : NULL,
      'newest' => $index < $last ? $reviews[$last] : NULL,
    ];
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
      // The automated review's own rating, kept when a reviewer overrides it.
      'tool_level' => $level['tool_level'] ?? NULL,
      'summary' => $level['summary'] ?? '',
      'anchor' => $level['anchor'] ?? '',
      'note' => $level['note'] ?? '',
      'gates' => $gates,
      'count_line' => self::countLine($findings, $key === 'security' ? self::SECURITY_NONE : 'No findings'),
      'groups' => $groups,
    ];
  }

  /**
   * The per-app section: five blocks in order, findings sorted into them.
   *
   * @param array $app
   *   app_id, name, criteria (array), conclusion, levels (portability /
   *   documentation => level, summary, anchor, note), findings.
   * @param array $previous
   *   Previous reviews as alsoFlaggedIn() expects them.
   */
  public static function buildApp(array $app, array $previous = []): array {
    $byBlock = array_fill_keys(array_keys(self::APP_BLOCKS), []);
    foreach ($app['findings'] ?? [] as $finding) {
      // A reviewer finding names its block; others go by rule code.
      $block = $finding['block'] ?? self::blockFor((string) ($finding['rule'] ?? ''));
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
      $block = $finding['block'] ?? self::blockFor((string) ($finding['rule'] ?? ''));
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
