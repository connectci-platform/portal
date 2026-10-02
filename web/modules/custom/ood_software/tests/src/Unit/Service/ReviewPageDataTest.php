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
   * @covers ::gatePills
   */
  public function testGatePillsKeepTheToolsOrderAndVocabulary(): void {
    $pills = ReviewPageData::gatePills(['metadata' => 'fail', 'yaml_valid' => 'pass', 'structure' => 'not_checked', 'references' => 'warn']);
    $this->assertSame(['metadata', 'yaml_valid', 'structure', 'references'], array_column($pills, 'key'));
    $this->assertSame(['fail', 'pass', 'not_checked', 'warn'], array_column($pills, 'value'));
    $this->assertSame('YAML valid', $pills[1]['label']);
    $this->assertSame([], ReviewPageData::gatePills([]));
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

    $only = ReviewPageData::historyPosition([['nid' => 10]], 10);
    $this->assertSame(['position' => 1, 'total' => 1, 'older' => NULL, 'newer' => NULL, 'newest' => NULL], $only);
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
   */
  public function testReviewerFindingRejectsIncompleteInput(array $input, string $block): void {
    $this->expectException(\InvalidArgumentException::class);
    ReviewPageData::reviewerFinding($input, $block, 'root', 'm');
  }

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

}
