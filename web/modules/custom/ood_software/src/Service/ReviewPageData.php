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
   * Never "safe". The block holds the reviewer's findings as well as the
   * tool's, so the line describes the review rather than crediting the tool
   * alone, in the catalog chip's words.
   */
  const SECURITY_NONE = 'No findings to review';

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
    // Schema 1.4 (STR-05/06). Earlier artifacts counted a shell syntax error
    // under the layout gate.
    'template_syntax' => 'Template syntax',
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
   *
   * @param array<mixed> $input
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
   * Evidence as text and links: every "path:line" in it links to GitHub.
   *
   * A finding often cites several places ("a.erb:19,22; b.erb:2"), and only
   * the first used to link. A "path:line" counts at the start, as
   * evidenceLink() reads it, or after a space, comma, semicolon or bracket
   * when its path has a "/" or "." in it, so a time like "10:30" stays text.
   *
   * @return array<int, array{text: string, url?: string}>
   *   The evidence in order; the parts' text joined is the evidence.
   */
  public static function evidenceParts(string $evidence, string $repoUrl, string $sha): array {
    $parts = [];
    $at = 0;
    preg_match_all('#(?:^|(?<=[\s;,(]))[A-Za-z0-9._~/-]*[A-Za-z0-9_~-]\.?[A-Za-z0-9._~-]*:\d+(?:-\d+)?(?:,\d+)*#', $evidence, $matches, PREG_OFFSET_CAPTURE);
    foreach ($matches[0] as [$ref, $offset]) {
      $path = substr($ref, 0, (int) strpos($ref, ':'));
      if ($offset > 0 && strpbrk($path, '/.') === FALSE) {
        continue;
      }
      $link = self::evidenceLink($ref, $repoUrl, $sha);
      if ($link === NULL) {
        continue;
      }
      if ($offset > $at) {
        $parts[] = ['text' => substr($evidence, $at, $offset - $at)];
      }
      $parts[] = ['text' => $link['text'], 'url' => $link['url']];
      $at = $offset + strlen($link['text']);
    }
    if ($at < strlen($evidence)) {
      $parts[] = ['text' => substr($evidence, $at)];
    }
    return $parts;
  }

  /**
   * Whether a finding record asserts a defect.
   *
   * FAIL and WARN do; PASS confirms a check and NOT CHECKED reports a skipped
   * one (row-per-check reporting since appverse-review#46). A record with no
   * result counts as a finding, as it always has.
   *
   * @param array<mixed> $finding
   */
  public static function isDefect(array $finding): bool {
    // A finding the reviewer dismissed is not one (A1, FindingOverride).
    if (!empty($finding['dismissed'])) {
      return FALSE;
    }
    $result = strtoupper(trim((string) ($finding['result'] ?? '')));
    return $result === '' || $result === 'FAIL' || $result === 'WARN';
  }

  /**
   * Findings grouped by severity, worst first, only severities present.
   *
   * @param array<mixed> $findings
   *
   * @return array<int, array{severity: string, count: int, findings: array<mixed>}>
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
   *
   * @param array<mixed> $findings
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
   * @param array<int, array<string, mixed>> $reviews
   *   The repo's reviews, oldest first.
   *
   * @return array{position: int, total: int, is_rerun: bool, older: ?array<string, mixed>, newer: ?array<string, mixed>, newest: ?array<string, mixed>, others: array<int, array<string, mixed>>}|null
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
      // This review is of the same commit as the one before it.
      'is_rerun' => (bool) ($reviews[$index]['is_rerun'] ?? FALSE),
      'older' => $index > 0 ? $reviews[$index - 1] : NULL,
      'newer' => $index < $last ? $reviews[$index + 1] : NULL,
      'newest' => $index < $last ? $reviews[$last] : NULL,
      // The others, newest first, as one line each rather than a prev/next
      // pair (appverse-planning#54).
      'others' => array_values(array_reverse(array_filter(
        $reviews,
        static fn (array $r): bool => (int) $r['nid'] !== $currentNid
      ))),
    ];
  }

  /**
   * Labels of the previous reviews that carried this stable id, in the
   * order given (newest first).
   *
   * @param array<int, array{label: string, stable_ids: array<int, string>}> $previous
   *
   * @return array<mixed>
   */
  public static function alsoFlaggedIn(string $stableId, array $previous): array {
    if ($stableId === '') {
      return [];
    }
    $labels = [];
    foreach ($previous as $review) {
      if (in_array($stableId, $review['stable_ids'], TRUE)) {
        $labels[] = $review['label'];
      }
    }
    return $labels;
  }

  /**
   * Gate criteria as pills, in the tool's order and vocabulary.
   *
   * @return array<int, array{key: string, label: string, value: string}>
   *
   * @param array<mixed> $criteria
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
   * How a set of gate pills reads as one line.
   *
   * A row of pills gave a pass the same weight as a failure, and the passes
   * are the common case: four of them say only "nothing to see here" while
   * taking a full line to say it. So the passes collapse to a count, and
   * anything that is not a pass stays out in full, which is what a reviewer
   * is looking for (appverse-planning#54).
   *
   * @param array<int, array{key: string, label: string, value: string}> $pills
   *
   * @return array{total: int, passed: int, all_passed: bool, passes: array<int, array{key: string, label: string, value: string}>, others: array<int, array{key: string, label: string, value: string}>}
   */
  /**
   * How this round compares with the one before it.
   *
   * Repeating "Also flagged in <date>" on every carried-over row is noise;
   * what a reviewer wants is what moved (appverse-planning#54). Both sides are
   * whole-review sets of stable ids, which is the level they are stored at: a
   * finding that moved between blocks has not been resolved, and counting per
   * block would say it had.
   *
   * @param array<int, string> $currentIds
   *   Every stable id in this review.
   * @param array<mixed> $previous
   *   Previous reviews, newest first, as alsoFlaggedIn() expects them.
   *
   * @return array{has_previous: bool, new: int, resolved: int}
   */
  public static function roundDelta(array $currentIds, array $previous): array {
    if ($previous === []) {
      return ['has_previous' => FALSE, 'new' => 0, 'resolved' => 0];
    }
    $current = array_values(array_unique(array_filter($currentIds)));
    $last = array_values(array_unique(array_filter($previous[0]['stable_ids'] ?? [])));
    return [
      'has_previous' => TRUE,
      'new' => count(array_diff($current, $last)),
      'resolved' => count(array_diff($last, $current)),
    ];
  }

  /**
   * The gate pills as one line: the passes counted, anything else listed.
   *
   * @param array<int, array{key: string, label: string, value: string}> $pills
   *   As gatePills() builds them.
   *
   * @return array<string, mixed>
   *   The summary the template reads.
   */
  public static function gateSummary(array $pills): array {
    $passes = array_values(array_filter($pills, static fn (array $p): bool => $p['value'] === 'pass'));
    $others = array_values(array_filter($pills, static fn (array $p): bool => $p['value'] !== 'pass'));
    return [
      'total' => count($pills),
      'passed' => count($passes),
      'all_passed' => $pills !== [] && $others === [],
      'passes' => $passes,
      'others' => $others,
    ];
  }

  /**
   * One block: title, level (or NULL), summary, note, count line, groups.
   *
   * @param array<mixed> $findings
   * @param array<mixed> $gates
   * @param array<mixed>|null $level
   * @param array<mixed> $previous
   * @return array<mixed>
   */
  public static function buildBlock(string $key, string $title, array $findings, ?array $level, array $previous, array $gates = []): array {
    // What the last round said, for marking what has changed since. $previous
    // is newest first, so [0] is the round before this one.
    $lastRoundIds = $previous[0]['stable_ids'] ?? [];
    $groups = self::groupBySeverity($findings);
    foreach ($groups as &$group) {
      foreach ($group['findings'] as &$finding) {
        $finding['also_flagged_in'] = self::alsoFlaggedIn((string) ($finding['stable_id'] ?? ''), $previous);
        // New since the last round. Repeating "Also flagged in <date>" on
        // every row that carried over is noise; what a reviewer wants to know
        // is what changed (appverse-planning#54). A finding with no stable id
        // cannot be matched across rounds, so it is not called new.
        $stableId = (string) ($finding['stable_id'] ?? '');
        $finding['is_new'] = $previous !== []
          && $stableId !== ''
          && !in_array($stableId, $lastRoundIds, TRUE);
      }
      unset($finding);
    }
    unset($group);
    // PASS and NOT CHECKED rows: not findings, but a reviewer checks that a
    // cleared candidate really is clear. Counts by result, worst-first order.
    $checkedRows = array_values(array_filter($findings, fn ($f) => !self::isDefect($f) && empty($f['dismissed'])));
    $checkedCounts = [];
    foreach ($checkedRows as $row) {
      $result = strtoupper(trim((string) $row['result']));
      $checkedCounts[$result] = ($checkedCounts[$result] ?? 0) + 1;
    }
    return [
      'key' => $key,
      'checked' => ['rows' => $checkedRows, 'counts' => $checkedCounts],
      // The tool's findings the reviewer dismissed, listed on their own with
      // the reason: shown to the reviewer and the contributor, not publicly.
      'dismissed' => array_values(array_filter($findings, fn ($f) => !empty($f['dismissed']))),
      'title' => $title,
      'level' => $level['level'] ?? NULL,
      // The automated review's own rating, kept when a reviewer overrides it.
      'tool_level' => $level['tool_level'] ?? NULL,
      'summary' => $level['summary'] ?? '',
      'anchor' => $level['anchor'] ?? '',
      'note' => $level['note'] ?? '',
      'gates' => $gates,
      // The same count-and-hold-out treatment the repo gates use, so a block
      // does not show two kinds of gate two different ways
      // (appverse-planning#53).
      'gate_summary' => self::gateSummary($gates),
      'count_line' => self::countLine($findings, $key === 'security' ? self::SECURITY_NONE : 'No findings'),
      'groups' => $groups,
    ];
  }

  /**
   * The per-app section: five blocks in order, findings sorted into them.
   *
   * @param array<string, mixed> $app
   *   app_id, name, criteria (array), conclusion, levels (portability /
   *   documentation => level, summary, anchor, note), findings.
   * @param array<mixed> $previous
   *   Previous reviews as alsoFlaggedIn() expects them.
   *
   * @return array<mixed>
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
   * @return array{maintenance: array<mixed>, blocks: array<mixed>, total: int}
   *
   * @param array<mixed> $findings
   * @param array<mixed>|null $level
   * @param array<mixed> $previous
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
      // A repo-level block appears when it holds anything: findings, or only
      // checked rows (PASS / NOT CHECKED) a reviewer may want to confirm.
      if ($byBlock[$key] !== []) {
        $blocks[$key] = self::buildBlock($key, $title, $byBlock[$key], NULL, $previous);
      }
    }
    // Shown as Upkeep, the word the catalog and the report use; the key and
    // the page anchor stay "maintenance", which the catalog links to.
    $maintenance = self::buildBlock('maintenance', 'Upkeep', $mnt, $level, $previous);
    $total = array_sum(array_column($maintenance['groups'], 'count'));
    foreach ($blocks as $block) {
      $total += array_sum(array_column($block['groups'], 'count'));
    }
    return ['maintenance' => $maintenance, 'blocks' => $blocks, 'total' => $total];
  }

}
