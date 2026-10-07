<?php

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\ood_software\Service\ReviewPageData;

/**
 * Unit tests for ReviewPageData, the shaping behind the review page.
 *
 * Plain arrays in, plain arrays out: the form extracts them from the review
 * node and its paragraphs, the template renders what comes back. The rules
 * pinned here are the ones a reviewer would notice if wrong — which block a
 * finding lands in, which records count as findings, the order of severity
 * groups, and the "also flagged in" lookup across previous reviews.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Service\ReviewPageData
 */
class ReviewPageDataTest extends UnitTestCase {

  /**
   * A finding record for the tests.
   *
   * @return array<string, mixed>
   */
  private function finding(string $rule, string $severity = 'low', string $result = 'FAIL', string $id = ''): array {
    return [
      'rule' => $rule, 'severity' => $severity, 'result' => $result,
      'stable_id' => $id ?: 'id-' . $rule . '-' . $severity,
      'summary' => 'summary for ' . $rule, 'evidence' => 'file:1', 'defect_key' => 'file:tag', 'prose' => '',
    ];
  }

  /**
   * Every rule family has exactly one block; nothing is left homeless.
   *
   * @covers ::blockFor
   * @dataProvider blockProvider
   */
  public function testBlockFor(string $rule, string $expected): void {
    $this->assertSame($expected, ReviewPageData::blockFor($rule));
  }

  /**
   * @return array<string, array<int, mixed>>
   */
  public static function blockProvider(): array {
    return [
      'security' => ['OODT-03', 'security'],
      'docs threshold' => ['QUA-01', 'documentation'],
      'portability threshold' => ['QUA-02', 'portability'],
      'error handling is code quality' => ['QUA-03', 'code_quality'],
      'polish is code quality' => ['QUA-06', 'code_quality'],
      'structure' => ['STR-02', 'structure'],
      'maintenance' => ['MNT-01', 'maintenance'],
      'unknown families do not vanish' => ['XYZ-01', 'other'],
    ];
  }

  /**
   * Row-per-check reporting (appverse-review#46) puts PASS and NOT CHECKED
   * records in the findings array. They are not findings on the page.
   *
   * @covers ::isDefect
   */
  public function testOnlyFailAndWarnAreFindings(): void {
    $this->assertTrue(ReviewPageData::isDefect($this->finding('QUA-03', 'low', 'FAIL')));
    $this->assertTrue(ReviewPageData::isDefect($this->finding('QUA-03', 'low', 'WARN')));
    $this->assertTrue(ReviewPageData::isDefect($this->finding('QUA-03', 'low', '')), 'a record with no result is a finding, as it always was');
    $this->assertFalse(ReviewPageData::isDefect($this->finding('QUA-03', 'low', 'PASS')));
    $this->assertFalse(ReviewPageData::isDefect($this->finding('OODT-01', 'medium', 'NOT CHECKED')));
  }

  /**
   * Severity groups come worst first, only for severities present, each
   * with its findings and count; PASS rows are already gone.
   *
   * @covers ::groupBySeverity
   */
  public function testSeverityGroupsAreWorstFirstAndOnlyPresent(): void {
    $groups = ReviewPageData::groupBySeverity([
      $this->finding('OODT-08', 'low'),
      $this->finding('OODT-01', 'critical'),
      $this->finding('OODT-05', 'medium'),
      $this->finding('OODT-02', 'medium'),
      $this->finding('OODT-04', 'high', 'PASS'),
    ]);

    $this->assertSame(['critical', 'medium', 'low'], array_column($groups, 'severity'));
    $this->assertSame([1, 2, 1], array_column($groups, 'count'));
    $this->assertSame(['OODT-05', 'OODT-02'], array_column($groups[1]['findings'], 'rule'));
  }

  /**
   * @covers ::countLine
   */
  public function testCountLine(): void {
    $this->assertSame('3 findings · 2 High · 1 Medium', ReviewPageData::countLine([
      $this->finding('OODT-01', 'high'), $this->finding('OODT-02', 'high'), $this->finding('OODT-05', 'medium'),
    ]));
    $this->assertSame('1 finding · 1 Low', ReviewPageData::countLine([$this->finding('QUA-03', 'low')]));
    $this->assertSame('No findings', ReviewPageData::countLine([]));
    $this->assertSame('Nothing', ReviewPageData::countLine([$this->finding('QUA-03', 'low', 'PASS')], 'Nothing'), 'the empty text is the caller\'s');
  }

  /**
   * A finding carried over from earlier reviews of the same repo is marked
   * with those reviews' labels, newest first, by stable id — not by rule.
   *
   * @covers ::alsoFlaggedIn
   */
  public function testAlsoFlaggedInMatchesByStableId(): void {
    $previous = [
      ['label' => '2026-09-20 · a52c443', 'stable_ids' => ['abc', 'def']],
      ['label' => '2026-09-01 · 1111111', 'stable_ids' => ['abc']],
    ];
    $this->assertSame(['2026-09-20 · a52c443', '2026-09-01 · 1111111'], ReviewPageData::alsoFlaggedIn('abc', $previous));
    $this->assertSame(['2026-09-20 · a52c443'], ReviewPageData::alsoFlaggedIn('def', $previous));
    $this->assertSame([], ReviewPageData::alsoFlaggedIn('new', $previous));
    $this->assertSame([], ReviewPageData::alsoFlaggedIn('', $previous), 'a finding without an id never matches');
  }

  /**
   * A matched app's catalog checks, as rows in the gates' style (A4b).
   *
   * @covers ::catalogRows
   * @covers ::duplicateSuggestion
   */
  public function testCatalogRowsForAMatchedApp(): void {
    $checks = [
      'shape' => 'declared',
      'software' => ['status' => 'match', 'value' => 'HiGlass', 'entry' => 'HiGlass'],
      'app_type' => ['status' => 'known', 'value' => 'batch-connect-basic'],
      'implementation_tags' => ['declared' => ['gpu', 'modules'], 'known' => ['modules'], 'unknown' => ['gpu'], 'note' => NULL],
      'same_software_apps' => [],
    ];
    $rows = ReviewPageData::catalogRows($checks);
    $this->assertSame(['Software', 'App type', 'Implementation tags', 'Same software'], array_column($rows, 'check'));
    $this->assertSame(['PASS', 'PASS', 'WARN', 'PASS'], array_column($rows, 'result'));
    $this->assertSame('Not in the vocabulary: gpu (known: modules)', $rows[2]['text']);
    $this->assertSame(['outcome' => 'none', 'reason' => 'The catalog lists no other app for HiGlass.'], ReviewPageData::duplicateSuggestion($checks));
  }

  /**
   * Other repos' apps for the same software are listed as links, and nothing
   * is suggested: telling a different approach from a duplicate takes a
   * reviewer. The reviewed repo's own published apps are not others.
   *
   * @covers ::catalogRows
   * @covers ::duplicateSuggestion
   */
  public function testCatalogRowsListTheSameSoftwareApps(): void {
    $checks = [
      'shape' => 'declared',
      'software' => ['status' => 'match', 'value' => 'Abaqus', 'entry' => 'Abaqus'],
      'app_type' => ['status' => 'unknown', 'value' => 'desktop'],
      'implementation_tags' => ['declared' => [], 'known' => [], 'unknown' => [], 'note' => NULL],
      'same_software_apps' => [
        ['title' => 'Abaqus', 'github_url' => 'https://github.com/a/abaqus', 'subpath' => NULL, 'this_repo' => FALSE],
        ['title' => 'Abaqus CAE', 'github_url' => 'https://github.com/b/ood', 'subpath' => 'abaqus', 'this_repo' => FALSE],
        ['title' => 'Abaqus (ours)', 'github_url' => 'https://github.com/osc/bc_osc_abaqus', 'subpath' => NULL, 'this_repo' => TRUE],
      ],
    ];
    $rows = ReviewPageData::catalogRows($checks);
    $this->assertSame(['PASS', 'WARN', 'N/A', 'WARN'], array_column($rows, 'result'));
    $this->assertSame('2 published apps from other repos implement Abaqus', $rows[3]['text']);
    $this->assertSame(['Abaqus', 'Abaqus CAE'], array_column($rows[3]['links'], 'title'));
    $this->assertSame('https://github.com/b/ood/tree/HEAD/abaqus', $rows[3]['links'][1]['url']);
    $this->assertNull(ReviewPageData::duplicateSuggestion($checks));
  }

  /**
   * Without a Software entry there is nothing to compare by.
   *
   * @covers ::catalogRows
   * @covers ::duplicateSuggestion
   */
  public function testCatalogRowsWithoutASoftwareEntry(): void {
    $inferred = ['shape' => 'inferred', 'software' => ['status' => 'inferred'], 'app_type' => ['status' => 'inferred'],
      'implementation_tags' => ['declared' => [], 'known' => [], 'unknown' => [], 'note' => NULL], 'same_software_apps' => []];
    $this->assertSame(['N/A', 'N/A', 'N/A', 'N/A'], array_column(ReviewPageData::catalogRows($inferred), 'result'));
    $this->assertNull(ReviewPageData::duplicateSuggestion($inferred));
    $missing = ['software' => ['status' => 'no_match', 'value' => 'Higlas', 'closest' => 'HiGlass']] + $inferred;
    $missing['shape'] = 'declared';
    $rows = ReviewPageData::catalogRows($missing);
    $this->assertSame('WARN', $rows[0]['result']);
    $this->assertSame('Higlas has no Software entry; the closest is HiGlass', $rows[0]['text']);
    $this->assertTrue($rows[0]['add_software']);
    $this->assertSame([], ReviewPageData::catalogRows([]), 'No checks, no rows: the report text stands.');
  }

  /**
   * The report's rationale placeholder is left out; the rest is kept.
   *
   * @covers ::catalogChecks
   */
  public function testCatalogChecksDropTheRationalePlaceholder(): void {
    $md = "Read from the catalog.\n\n- Duplicate check — No published app implements `HiGlass`.\n  - **Duplicate-check rationale:** _<reviewer fills in — the outcome and why>_\n- `software` — matches.";
    $this->assertSame("Read from the catalog.\n\n- Duplicate check — No published app implements `HiGlass`.\n- `software` — matches.", ReviewPageData::catalogChecks($md));
  }

  /**
   * @covers ::gatePills
   */
  public function testGatePillsKeepTheToolsOrderAndVocabulary(): void {
    $pills = ReviewPageData::gatePills(['metadata' => 'fail', 'yaml_valid' => 'pass', 'structure' => 'not_checked', 'references' => 'warn']);
    $this->assertSame(['metadata', 'yaml_valid', 'structure', 'references'], array_column($pills, 'key'));
    $this->assertSame(['fail', 'pass', 'not_checked', 'warn'], array_column($pills, 'value'));
    $this->assertSame('YAML valid', $pills[1]['label']);
    $this->assertSame([], ReviewPageData::gatePills([]));
    // Schema 1.4: template syntax (STR-05/06) is its own gate, not layout.
    $pills = ReviewPageData::gatePills(['structure' => 'pass', 'template_syntax' => 'fail']);
    $this->assertSame(['Standard layout', 'Template syntax'], array_column($pills, 'label'));
  }

  /**
   * Repo-level findings are not only maintenance: in a monorepo the tool files
   * repo-wide structure and quality findings under "root" and the assembler
   * keeps them at repo level (appverse-review#43). The page must show them,
   * sorted into the same blocks as an app's, without signal levels.
   *
   * @covers ::buildRepo
   */
  public function testBuildRepoKeepsNonMaintenanceRepoFindings(): void {
    $repo = ReviewPageData::buildRepo([
      $this->finding('MNT-03', 'info'),
      $this->finding('STR-04', 'medium'),
      $this->finding('QUA-06', 'low'),
      $this->finding('MNT-02', 'info', 'PASS'),
    ], ['level' => 'some_notes', 'summary' => 'Active', 'anchor' => '#upkeep', 'note' => '']);

    $this->assertSame('1 finding · 1 Info', $repo['maintenance']['count_line']);
    $this->assertSame('some_notes', $repo['maintenance']['level']);
    $this->assertSame(['structure', 'code_quality'], array_keys($repo['blocks']), 'only blocks with findings, in the fixed order');
    $this->assertSame(['STR-04'], array_column($repo['blocks']['structure']['groups'][0]['findings'], 'rule'));
    $this->assertNull($repo['blocks']['structure']['level']);
    $this->assertSame(3, $repo['total'], 'three findings; the PASS row is not one');
  }

  /**
   * PASS and NOT CHECKED rows are not findings: they stay out of the groups
   * and the count line, and are listed apart so a reviewer can confirm a
   * cleared check.
   *
   * @covers ::buildBlock
   */
  public function testBuildBlockListsCheckedRowsApart(): void {
    $block = ReviewPageData::buildBlock('code_quality', 'Code quality', [
      $this->finding('QUA-05', 'low', 'WARN'),
      $this->finding('QUA-07', 'info', 'PASS'),
      $this->finding('QUA-03', 'info', 'NOT CHECKED'),
      $this->finding('QUA-04', 'info', 'NOT CHECKED'),
    ], NULL, []);

    $this->assertSame('1 finding · 1 Low', $block['count_line']);
    $this->assertSame(['QUA-07', 'QUA-03', 'QUA-04'], array_column($block['checked']['rows'], 'rule'));
    $this->assertSame(['PASS' => 1, 'NOT CHECKED' => 2], $block['checked']['counts']);
  }

  /**
   * A finding the reviewer dismissed leaves the groups, the count line and
   * the checked rows, and is listed on its own; a re-rated one is grouped
   * by the severity the reviewer gave it (A1).
   *
   * @covers ::buildBlock
   * @covers ::isDefect
   */
  public function testBuildBlockListsDismissedFindingsApart(): void {
    $dismissed = ['dismissed' => TRUE] + $this->finding('OODT-05', 'high', 'FAIL');
    $this->assertFalse(ReviewPageData::isDefect($dismissed));
    $block = ReviewPageData::buildBlock('security', 'Security', [
      $dismissed,
      $this->finding('OODT-02', 'low', 'FAIL'),
      $this->finding('OODT-01', 'info', 'PASS'),
    ], NULL, []);

    $this->assertSame('1 finding · 1 Low', $block['count_line']);
    $this->assertSame(['OODT-05'], array_column($block['dismissed'], 'rule'));
    $this->assertSame(['OODT-01'], array_column($block['checked']['rows'], 'rule'), 'a dismissal is not a passed check');
    $this->assertSame([], ReviewPageData::buildBlock('security', 'Security', [], NULL, [])['dismissed']);
  }

  /**
   * A repo-level block with only checked rows still appears, so a cleared
   * repo-wide check can be confirmed; it adds nothing to the total.
   *
   * @covers ::buildRepo
   */
  public function testBuildRepoKeepsABlockOfOnlyCheckedRows(): void {
    $repo = ReviewPageData::buildRepo([$this->finding('STR-01', 'info', 'PASS')], NULL);

    $this->assertSame(['structure'], array_keys($repo['blocks']));
    $this->assertSame('No findings', $repo['blocks']['structure']['count_line']);
    $this->assertSame(['PASS' => 1], $repo['blocks']['structure']['checked']['counts']);
    $this->assertSame(0, $repo['total']);
  }

  /**
   * The per-app assembly: findings sorted into the five blocks in the fixed
   * order, each block carrying its level, count line, severity groups, and
   * the "also flagged" marks; PASS rows excluded everywhere.
   *
   * @covers ::buildApp
   */
  public function testBuildAppSortsFindingsIntoBlocksInOrder(): void {
    $app = ReviewPageData::buildApp([
      'app_id' => 'jupyter_example',
      'name' => 'Jupyter (Example)',
      'criteria' => ['metadata' => 'fail', 'yaml_valid' => 'pass'],
      'conclusion' => NULL,
      'levels' => [
        // Stale data from a 1.1 review; schema 1.2 has no security level.
        'security' => ['level' => 'solid', 'summary' => 'No security findings', 'anchor' => '#security', 'note' => ''],
        'portability' => ['level' => 'some_notes', 'summary' => 'Cluster hardcoded', 'anchor' => '#portability', 'note' => 'Fine for a reference app.'],
        'documentation' => ['level' => 'needs_attention', 'summary' => 'No install section', 'anchor' => '#documentation', 'note' => ''],
      ],
      'findings' => [
        $this->finding('STR-02', 'high', 'FAIL', 'sid-str'),
        $this->finding('QUA-02', 'low', 'WARN', 'sid-port'),
        $this->finding('QUA-03', 'low', 'PASS', 'sid-pass'),
        $this->finding('QUA-05', 'low', 'WARN', 'sid-cq'),
      ],
    ], [['label' => '2026-09-20 · a52c443', 'stable_ids' => ['sid-port']]]);

    $this->assertSame(['structure', 'security', 'portability', 'documentation', 'code_quality'], array_keys($app['blocks']));
    $this->assertSame('Structure', $app['blocks']['structure']['title']);
    $this->assertSame(['metadata', 'yaml_valid'], array_column($app['blocks']['structure']['gates'], 'key'));
    $this->assertSame('1 finding · 1 High', $app['blocks']['structure']['count_line']);
    $this->assertSame('some_notes', $app['blocks']['portability']['level']);
    $this->assertSame('Fine for a reference app.', $app['blocks']['portability']['note']);
    $this->assertSame(['2026-09-20 · a52c443'], $app['blocks']['portability']['groups'][0]['findings'][0]['also_flagged_in']);
    // Security is findings only: no level, and the empty state makes the
    // honest claim rather than "No findings" (never "safe").
    $this->assertNull($app['blocks']['security']['level'], 'no security level, even from stale data');
    $this->assertSame('', $app['blocks']['security']['summary']);
    $this->assertSame(ReviewPageData::SECURITY_NONE, $app['blocks']['security']['count_line']);
    $this->assertSame([], $app['blocks']['security']['groups']);
    // The PASS QUA-03 row is not in code_quality; the WARN QUA-05 is.
    $this->assertSame('1 finding · 1 Low', $app['blocks']['code_quality']['count_line']);
    $this->assertSame(['QUA-05'], array_column($app['blocks']['code_quality']['groups'][0]['findings'], 'rule'));
    // Code quality has no level of its own: it feeds the decision, not a signal.
    $this->assertNull($app['blocks']['code_quality']['level']);
  }

  /**
   * A middle review knows both neighbours and that it is superseded.
   *
   * @covers ::historyPosition
   */
  public function testHistoryPositionOfAMiddleReview(): void {
    $reviews = [['nid' => 10], ['nid' => 20], ['nid' => 30]];

    $h = ReviewPageData::historyPosition($reviews, 20);

    $this->assertSame(2, $h['position']);
    $this->assertSame(3, $h['total']);
    $this->assertSame(10, $h['older']['nid']);
    $this->assertSame(30, $h['newer']['nid']);
    $this->assertSame(30, $h['newest']['nid'], 'superseded: points at the newest, not just the next');
  }

  /**
   * The newest review has no newer neighbour and is not superseded; the
   * oldest has no older one.
   *
   * @covers ::historyPosition
   */
  public function testHistoryPositionAtTheEnds(): void {
    $reviews = [['nid' => 10], ['nid' => 20], ['nid' => 30]];

    $newest = ReviewPageData::historyPosition($reviews, 30);
    $this->assertNull($newest['newer']);
    $this->assertNull($newest['newest'], 'the newest review is not superseded');
    $this->assertSame(20, $newest['older']['nid']);

    $oldest = ReviewPageData::historyPosition($reviews, 10);
    $this->assertNull($oldest['older']);
    $this->assertSame(30, $oldest['newest']['nid']);

    // The neighbours, not the whole array: asserting every key made this fail
    // whenever one was added, which says nothing about the ends.
    $only = ReviewPageData::historyPosition([['nid' => 10]], 10);
    $this->assertSame(1, $only['position']);
    $this->assertSame(1, $only['total']);
    $this->assertNull($only['older']);
    $this->assertNull($only['newer']);
    $this->assertNull($only['newest']);
  }

  /**
   * Evidence that starts with path:line links to that line at the reviewed
   * commit; the rest of the evidence stays as text.
   *
   * @covers ::evidenceLink
   * @dataProvider evidenceLinks
   */
  public function testEvidenceLink(string $evidence, ?string $url, ?string $text): void {
    $link = ReviewPageData::evidenceLink($evidence, 'https://github.com/mkonda/appverse-example-monorepo', 'a52c443deadbeef');
    $this->assertSame($url, $link['url'] ?? NULL);
    $this->assertSame($text, $link['text'] ?? NULL);
    if ($link !== NULL) {
      $this->assertSame($evidence, $link['text'] . $link['rest'], 'text + rest is the whole evidence');
    }
  }

  /**
   * @return array<string, array<int, string|null>>
   */
  public static function evidenceLinks(): array {
    $base = 'https://github.com/mkonda/appverse-example-monorepo/blob/a52c443deadbeef/';
    return [
      'file and line' => ['jupyter_example/form.yml:3 — `cluster: x`', $base . 'jupyter_example/form.yml#L3', 'jupyter_example/form.yml:3'],
      'line range' => ['appverse.yml:43-44 — `shared_paths`', $base . 'appverse.yml#L43-L44', 'appverse.yml:43-44'],
      'several lines link the first' => ['jupyter_example/form.yml:10,17 — min/max', $base . 'jupyter_example/form.yml#L10', 'jupyter_example/form.yml:10,17'],
      'erb file' => ['template/script.sh.erb:40', $base . 'template/script.sh.erb#L40', 'template/script.sh.erb:40'],
      'absent directory is not linked' => ['jupyter_example/template/ — directory absent from repo', NULL, NULL],
      'bare file is not linked' => ['README.md — no Prerequisites section', NULL, NULL],
      'no path is not linked' => ['No CHANGELOG, CHANGES, or HISTORY file found at repo root', NULL, NULL],
      'parent paths are not linked' => ['../etc/passwd:1', NULL, NULL],
    ];
  }

  /**
   * Every path:line in the evidence links, not only the first.
   *
   * @covers ::evidenceParts
   */
  public function testEvidencePartsLinkEveryPlace(): void {
    $evidence = 'app/views/layouts/application.html.erb:19,22,23; app/views/cluster_status/index.html.erb:2; _job_queue.erb:191 at 10:30';
    $parts = ReviewPageData::evidenceParts($evidence, 'https://github.com/o/r', 'abc');
    $this->assertSame($evidence, implode('', array_column($parts, 'text')), 'the parts are the whole evidence');
    $this->assertSame([
      'https://github.com/o/r/blob/abc/app/views/layouts/application.html.erb#L19',
      'https://github.com/o/r/blob/abc/app/views/cluster_status/index.html.erb#L2',
      'https://github.com/o/r/blob/abc/_job_queue.erb#L191',
    ], array_values(array_filter(array_column($parts, 'url'))));
    // Without GitHub links the evidence is one plain part.
    $this->assertSame([['text' => 'f.yml:1; g.yml:2']], ReviewPageData::evidenceParts('f.yml:1; g.yml:2', 'https://gitlab.com/o/r', 'abc'));
    $this->assertSame([], ReviewPageData::evidenceParts('', 'https://github.com/o/r', 'abc'));
  }

  /**
   * Only GitHub repos with a known commit get links.
   *
   * @covers ::evidenceLink
   */
  public function testEvidenceLinkNeedsAGitHubRepoAndACommit(): void {
    $this->assertSame('https://github.com/o/r/blob/abc/f.yml#L1', ReviewPageData::evidenceLink('f.yml:1', 'https://github.com/o/r.git', 'abc')['url']);
    $this->assertNull(ReviewPageData::evidenceLink('f.yml:1', 'https://gitlab.com/o/r', 'abc'));
    $this->assertNull(ReviewPageData::evidenceLink('f.yml:1', 'https://github.com/o/r', ''));
  }

  /**
   * A block keeps the automated rating beside the current level, so the page
   * can say what the tool rated after a reviewer overrides it.
   *
   * @covers ::buildBlock
   */
  public function testBuildBlockKeepsTheToolLevel(): void {
    $block = ReviewPageData::buildBlock('portability', 'Portability', [], ['level' => 'needs_attention', 'tool_level' => 'some_notes'], []);
    $this->assertSame(['needs_attention', 'some_notes'], [$block['level'], $block['tool_level']]);

    $none = ReviewPageData::buildBlock('code_quality', 'Code quality', [], NULL, []);
    $this->assertNull($none['tool_level']);
  }

  /**
   * A reviewer's finding gets what the automated review fills: the anchor
   * file and line from "path:line" evidence, a defect key on that file, and
   * the aspect/category of the block it was added in.
   *
   * @covers ::reviewerFinding
   */
  public function testReviewerFindingParsesEvidenceLikeTheTool(): void {
    $f = ReviewPageData::reviewerFinding(
      ['rule' => 'qua-02', 'severity' => 'Medium', 'summary' => ' Cluster is hardcoded ', 'evidence' => 'jupyter_example/form.yml:3-5 — `cluster: x`'],
      'portability', 'jupyter_example', 'manual-0a1b2c3d',
    );

    $this->assertSame('QUA-02', $f['rule']);
    $this->assertSame('medium', $f['severity']);
    $this->assertSame('Cluster is hardcoded', $f['summary']);
    $this->assertSame('jupyter_example/form.yml', $f['anchor']);
    $this->assertSame(3, $f['line']);
    $this->assertSame('jupyter_example/form.yml:reviewer-finding', $f['defect_key']);
    $this->assertSame(['quality', 'portability'], [$f['aspect'], $f['category']]);
    $this->assertSame('jupyter_example', $f['app_id']);
    $this->assertSame('manual-0a1b2c3d', $f['stable_id']);

    // A file without a line still anchors; prose without a file anchors to root.
    $this->assertSame('README.md', ReviewPageData::reviewerFinding(['rule' => 'QUA-01', 'severity' => 'low', 'summary' => 's', 'evidence' => 'README.md has no install steps'], 'documentation', 'root', 'm')['anchor']);
    $noFile = ReviewPageData::reviewerFinding(['rule' => 'MNT-02', 'severity' => 'info', 'summary' => 's', 'evidence' => 'no tags on GitHub'], 'maintenance', 'root', 'm');
    $this->assertSame(['', NULL, 'root:reviewer-finding'], [$noFile['anchor'], $noFile['line'], $noFile['defect_key']]);
  }

  /**
   * @covers ::reviewerFinding
   * @dataProvider invalidReviewerFindings
   * @param array<string, mixed> $input
   */
  public function testReviewerFindingRejectsIncompleteInput(array $input, string $block): void {
    $this->expectException(\InvalidArgumentException::class);
    ReviewPageData::reviewerFinding($input, $block, 'root', 'm');
  }

  /**
   * @return array<string, array<int, mixed>>
   */
  public static function invalidReviewerFindings(): array {
    $ok = ['rule' => 'QUA-03', 'severity' => 'low', 'summary' => 'Missing set -e'];
    return [
      'no summary' => [['summary' => '  '] + $ok, 'code_quality'],
      'no rule' => [['rule' => ''] + $ok, 'code_quality'],
      'unknown severity' => [['severity' => 'urgent'] + $ok, 'code_quality'],
      'unknown block' => [$ok, 'other'],
    ];
  }

  /**
   * Every block's aspect/category pair maps back to that block, and a
   * reviewer finding with an "Other" rule code stays in the block it was
   * added in rather than falling into Code quality.
   *
   * @covers ::blockFromFields
   * @covers ::buildApp
   */
  public function testReviewerFindingStaysInItsBlock(): void {
    foreach (ReviewPageData::BLOCK_FIELDS as $block => $fields) {
      $this->assertSame($block, ReviewPageData::blockFromFields($fields['aspect'], $fields['category']));
    }
    $this->assertNull(ReviewPageData::blockFromFields(NULL, NULL));

    $app = ReviewPageData::buildApp(['app_id' => 'root', 'findings' => [
      ['rule' => 'CUSTOM-1', 'severity' => 'low', 'summary' => 'Site path in submit.yml.erb', 'evidence' => '', 'stable_id' => 'm1', 'block' => 'portability'],
    ]]);
    $this->assertSame('1 finding · 1 Low', $app['blocks']['portability']['count_line']);
    $this->assertSame('No findings', $app['blocks']['code_quality']['count_line']);
  }

  /**
   * Each rule a reviewer can pick under a block is filed under that block by
   * blockFor(), so a reviewer finding and a tool finding with the same code
   * land together.
   *
   * @covers ::blockFor
   */
  public function testBlockRulesAgreeWithBlockFor(): void {
    foreach (ReviewPageData::BLOCK_RULES as $block => $rules) {
      foreach (array_keys($rules) as $rule) {
        $this->assertSame($block, ReviewPageData::blockFor($rule), $rule);
      }
    }
  }

  /**
   * A review the list does not contain (the viewer may not see it, or the
   * list is stale) has no position rather than a wrong one.
   *
   * @covers ::historyPosition
   */
  public function testHistoryPositionOfAReviewNotInTheList(): void {
    $this->assertNull(ReviewPageData::historyPosition([['nid' => 10]], 99));
    $this->assertNull(ReviewPageData::historyPosition([], 10));
  }

  /**
   * All four gates pass: a count, and nothing held out.
   *
   * @covers ::gateSummary
   */
  public function testGateSummaryWhenEverythingPasses(): void {
    $summary = ReviewPageData::gateSummary(ReviewPageData::gatePills([
      'license' => 'pass',
      'readme_substantive' => 'pass',
      'not_archived' => 'pass',
      'public' => 'pass',
    ]));

    $this->assertSame(4, $summary['total']);
    $this->assertSame(4, $summary['passed']);
    $this->assertTrue($summary['all_passed']);
    $this->assertSame([], $summary['others']);
    $this->assertCount(4, $summary['passes']);
  }

  /**
   * Anything that is not a pass stays out in full, whatever it is called.
   *
   * fail, warn and not_checked all mean the reviewer has something to look
   * at, so none of them collapse into the count.
   *
   * @covers ::gateSummary
   */
  public function testGateSummaryHoldsOutEverythingThatIsNotAPass(): void {
    $summary = ReviewPageData::gateSummary(ReviewPageData::gatePills([
      'license' => 'pass',
      'readme_substantive' => 'fail',
      'not_archived' => 'warn',
      'public' => 'not_checked',
    ]));

    $this->assertSame(4, $summary['total']);
    $this->assertSame(1, $summary['passed']);
    $this->assertFalse($summary['all_passed']);
    $this->assertSame(
      ['readme_substantive', 'not_archived', 'public'],
      array_column($summary['others'], 'key'),
      'The three non-passes are held out, in the tool\'s order.'
    );
  }

  /**
   * The others are every review but this one, newest first.
   *
   * @covers ::historyPosition
   */
  public function testHistoryPositionListsTheOtherReviewsNewestFirst(): void {
    $history = ReviewPageData::historyPosition([
      ['nid' => 1, 'is_rerun' => FALSE],
      ['nid' => 2, 'is_rerun' => TRUE],
      ['nid' => 3, 'is_rerun' => FALSE],
    ], 2);

    $this->assertSame(2, $history['position']);
    $this->assertSame(3, $history['total']);
    $this->assertTrue($history['is_rerun'], 'Review 2 is a rerun of review 1.');
    $this->assertSame([3, 1], array_column($history['others'], 'nid'), 'Newest first, this one left out.');
  }

  /**
   * The only review of a repo has no others to list.
   *
   * @covers ::historyPosition
   */
  public function testHistoryPositionOfTheOnlyReview(): void {
    $history = ReviewPageData::historyPosition([['nid' => 7]], 7);

    $this->assertSame([], $history['others']);
    $this->assertFalse($history['is_rerun'], 'A first review cannot be a rerun.');
  }

  /**
   * A first review has nothing to compare against.
   *
   * @covers ::roundDelta
   */
  public function testRoundDeltaOfAFirstReview(): void {
    $delta = ReviewPageData::roundDelta(['STR-01', 'STR-02'], []);

    $this->assertFalse($delta['has_previous']);
    $this->assertSame(0, $delta['new'], 'Nothing is new when there is no round to be new since.');
    $this->assertSame(0, $delta['resolved']);
  }

  /**
   * What this round added, and what the last round raised that is gone.
   *
   * @covers ::roundDelta
   */
  public function testRoundDeltaCountsBothDirections(): void {
    $delta = ReviewPageData::roundDelta(
      ['STR-01', 'STR-09', 'STR-10'],
      [['stable_ids' => ['STR-01', 'STR-02', 'STR-03']]]
    );

    $this->assertTrue($delta['has_previous']);
    $this->assertSame(2, $delta['new'], 'STR-09 and STR-10 are new.');
    $this->assertSame(2, $delta['resolved'], 'STR-02 and STR-03 are gone.');
  }

  /**
   * Only the round immediately before counts, not every earlier one.
   *
   * @covers ::roundDelta
   */
  public function testRoundDeltaComparesWithTheRoundBefore(): void {
    $delta = ReviewPageData::roundDelta(
      ['STR-01'],
      [
        ['stable_ids' => ['STR-01', 'STR-02']],
        ['stable_ids' => ['STR-07', 'STR-08']],
      ]
    );

    $this->assertSame(0, $delta['new'], 'STR-01 was in the round before, so it is not new.');
    $this->assertSame(1, $delta['resolved'], 'Only STR-02 went; the older round is not consulted.');
  }

  /**
   * A review with no stored criteria has not passed anything.
   *
   * @covers ::gateSummary
   */
  public function testGateSummaryOfNoGatesIsNotAPass(): void {
    $summary = ReviewPageData::gateSummary([]);

    $this->assertSame(0, $summary['total']);
    $this->assertFalse($summary['all_passed'], 'Nothing to pass is not the same as passing.');
  }

}
