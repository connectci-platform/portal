<?php

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\ood_software\Service\ContributorReviewView;
use Drupal\Tests\UnitTestCase;

/**
 * The contributor's view of a review page array.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Service\ContributorReviewView
 */
class ContributorReviewViewTest extends UnitTestCase {

  /**
   * A finding row as ReviewPageForm::findingArray() builds it.
   *
   * @param array<string, mixed> $over
   *   Keys to replace.
   *
   * @return array<string, mixed>
   *   The row.
   */
  private static function row(array $over = []): array {
    $url = 'https://github.com/o/r/blob/abc/form.yml#L4';
    return $over + [
      'source' => 'ai',
      'pid' => 1,
      'rule' => 'STR-01',
      'severity' => 'medium',
      'tool_severity' => 'medium',
      'dismissed' => FALSE,
      'override' => NULL,
      'original' => NULL,
      'result' => 'FAIL',
      'summary' => 'Tool summary',
      'evidence' => 'form.yml:4',
      'evidence_parts' => [['text' => 'form.yml:4', 'url' => $url]],
      'prose' => '',
    ];
  }

  /**
   * A block as ReviewPageData::buildBlock() builds it, from finding rows.
   *
   * @param string $title
   *   The block's title.
   * @param array<int, array<string, mixed>> $rows
   *   Finding rows.
   * @param array<int, array<string, string>> $gates
   *   Gate pills (key, label, value) for the block's gate_summary.
   *
   * @return array<string, mixed>
   *   The block.
   */
  private static function block(string $title, array $rows, array $gates = []): array {
    $groups = [];
    $checked = [];
    $dismissed = [];
    foreach ($rows as $row) {
      if (!empty($row['dismissed'])) {
        $dismissed[] = $row;
      }
      elseif (in_array($row['result'], ['FAIL', 'WARN'], TRUE)) {
        $groups[$row['severity']][] = $row;
      }
      else {
        $checked[] = $row;
      }
    }
    $block = [
      'title' => $title,
      'groups' => array_map(
        static fn ($sev, $f) => ['severity' => $sev, 'count' => count($f), 'findings' => $f],
        array_keys($groups),
        $groups,
      ),
      'checked' => ['rows' => $checked, 'counts' => []],
      'dismissed' => $dismissed,
    ];
    if ($gates) {
      $block['gate_summary'] = self::gateSummary($gates);
    }
    return $block;
  }

  /**
   * Gate pills as ReviewPageData::gateSummary() groups them.
   *
   * @param array<int, array<string, string>> $pills
   *   Pills with key, label and value.
   *
   * @return array<string, mixed>
   *   The summary.
   */
  private static function gateSummary(array $pills): array {
    return [
      'total' => count($pills),
      'passes' => array_values(array_filter($pills, static fn ($p) => $p['value'] === 'pass')),
      'others' => array_values(array_filter($pills, static fn ($p) => $p['value'] !== 'pass')),
    ];
  }

  /**
   * A gate pill.
   *
   * @return array<string, string>
   *   The pill.
   */
  private static function pill(string $label, string $value): array {
    return ['key' => strtolower($label), 'label' => $label, 'value' => $value];
  }

  /**
   * A review page with the given apps' blocks.
   *
   * @param array<string, mixed> $blocks
   *   Blocks keyed by block key, for one app.
   * @param array<string, mixed> $over
   *   Page keys to replace.
   *
   * @return array<string, mixed>
   *   The page.
   */
  private static function page(array $blocks, array $over = []): array {
    return $over + [
      'reviewed_at' => '5 November 2026',
      'apps' => [['name' => 'My App', 'blocks' => $blocks]],
      'repo_blocks' => [],
      'repo_gate_block' => ['passes' => [], 'others' => []],
      'maintenance' => [],
      'decision' => ['sent' => TRUE, 'label' => 'Changes requested'],
      'response' => 'Please fix these.',
      'history' => [],
      'superseded_by' => NULL,
      'withdrawn' => NULL,
    ];
  }

  /**
   * A tool finding the reviewer has reworded, re-rated and dismissed.
   *
   * @covers ::build
   */
  public function testPrecheckUsesToolValuesAndHidesReviewerWork(): void {
    $edited = self::row([
      'pid' => 1,
      'severity' => 'low',
      'dismissed' => TRUE,
      'override' => ['severity' => 'low', 'edited' => TRUE],
      'original' => ['summary' => 'Tool summary', 'evidence' => 'form.yml:4'],
      'summary' => 'Reviewer wording',
      'prose' => 'Note to contributor',
    ]);
    $added = self::row([
      'pid' => 2,
      'source' => 'reviewer',
      'summary' => 'Added',
      'severity' => 'high',
      'tool_severity' => '',
    ]);
    $page = self::page(['structure' => self::block('Structure', [$edited, $added])]);
    $view = ContributorReviewView::build($page, FALSE);
    $this->assertSame('precheck', $view['state']);
    $this->assertCount(1, $view['findings']);
    $this->assertSame('medium', $view['findings'][0]['severity']);
    $this->assertSame('Tool summary', $view['findings'][0]['summary']);
    $this->assertSame('tool', $view['findings'][0]['source']);
    $this->assertSame('', $view['findings'][0]['note']);
    $this->assertSame('My App', $view['findings'][0]['app']);
    $this->assertSame('Structure', $view['findings'][0]['section']);
    $this->assertSame('form.yml', $view['findings'][0]['file']);
    $this->assertSame(4, $view['findings'][0]['line']);
    $this->assertNull($view['decision']);
    $this->assertSame('', $view['response']);
  }

  /**
   * Once sent, effective values apply and dismissed findings are gone.
   *
   * @covers ::build
   */
  public function testDecidedUsesEffectiveValuesAndDropsDismissed(): void {
    $edited = self::row([
      'pid' => 1,
      'severity' => 'low',
      'original' => ['summary' => 'Tool summary', 'evidence' => 'form.yml:4'],
      'summary' => 'Reviewer wording',
      'prose' => 'Please change this.',
    ]);
    $dismissed = self::row(['pid' => 2, 'dismissed' => TRUE, 'summary' => 'Gone']);
    $added = self::row([
      'pid' => 3,
      'source' => 'reviewer',
      'summary' => 'Added',
      'severity' => 'high',
      'tool_severity' => '',
      'prose' => 'Because.',
    ]);
    $page = self::page(['structure' => self::block('Structure', [$edited, $dismissed, $added])]);
    $view = ContributorReviewView::build($page, TRUE);
    $this->assertSame('decided', $view['state']);
    $this->assertCount(2, $view['findings']);
    $this->assertSame('Added', $view['findings'][0]['summary']);
    $this->assertSame('reviewer', $view['findings'][0]['source']);
    $this->assertSame('high', $view['findings'][0]['severity']);
    $this->assertSame('Because.', $view['findings'][0]['note']);
    $this->assertSame('Reviewer wording', $view['findings'][1]['summary']);
    $this->assertSame('low', $view['findings'][1]['severity']);
    $this->assertSame('Please change this.', $view['findings'][1]['note']);
    $this->assertSame($page['decision'], $view['decision']);
    $this->assertSame('Please fix these.', $view['response']);
  }

  /**
   * No report yet means nothing to show.
   *
   * @covers ::build
   */
  public function testWaitingBeforeReportLands(): void {
    $page = self::page(['structure' => self::block('Structure', [self::row()])]);
    $page['reviewed_at'] = '';
    $view = ContributorReviewView::build($page, FALSE);
    $this->assertSame('waiting', $view['state']);
    $this->assertSame([], $view['findings']);
    $this->assertSame([], $view['passed']);
  }

  /**
   * Most severe first, then the page's own order.
   *
   * @covers ::build
   */
  public function testOrderingBySeverityThenSection(): void {
    $high = self::row(['pid' => 1, 'severity' => 'high', 'tool_severity' => 'high', 'summary' => 'H']);
    $low = self::row(['pid' => 2, 'severity' => 'low', 'tool_severity' => 'low', 'summary' => 'L']);
    $crit = self::row(['pid' => 3, 'severity' => 'critical', 'tool_severity' => 'critical', 'summary' => 'C']);
    $low2 = self::row(['pid' => 4, 'severity' => 'low', 'tool_severity' => 'low', 'summary' => 'L2']);
    $page = self::page([
      'structure' => self::block('Structure', [$high]),
      'security' => self::block('Security', [$low]),
      'code_quality' => self::block('Code quality', [$crit, $low2]),
    ]);
    $summaries = array_column(ContributorReviewView::build($page, FALSE)['findings'], 'summary');
    $this->assertSame(['C', 'H', 'L', 'L2'], $summaries);
  }

  /**
   * Equal severities follow the page: apps in order, repo blocks, Upkeep.
   *
   * @covers ::build
   */
  public function testTiesFollowPageOrderAcrossApps(): void {
    $row = static fn (string $s): array => self::row(['pid' => crc32($s), 'summary' => $s]);
    $page = self::page(['code_quality' => self::block('Code quality', [$row('A1')])]);
    $page['apps'][] = ['name' => 'Second', 'blocks' => ['structure' => self::block('Structure', [$row('B1')])]];
    $page['repo_blocks'] = ['structure' => self::block('Structure', [$row('R1')])];
    $page['maintenance'] = self::block('Upkeep', [$row('M1')]);
    $summaries = array_column(ContributorReviewView::build($page, FALSE)['findings'], 'summary');
    $this->assertSame(['A1', 'B1', 'R1', 'M1'], $summaries);
  }

  /**
   * Passed checks and passed gates, repo-level and per app.
   *
   * @covers ::build
   */
  public function testPassedChecksAndGates(): void {
    $pass = self::row(['pid' => 1, 'result' => 'PASS', 'summary' => 'Has a manifest']);
    $skipped = self::row(['pid' => 2, 'result' => 'NOT CHECKED', 'summary' => 'Skipped']);
    $page = self::page([
      'structure' => self::block('Structure', [], [self::pill('Files', 'pass'), self::pill('Layout', 'fail')]),
      'security' => self::block('Security', [$pass, $skipped]),
    ]);
    $page['repo_gate_block']['passes'] = [self::pill('Metadata', 'pass')];
    $view = ContributorReviewView::build($page, FALSE);
    $this->assertSame([
      ['section' => 'Structure', 'label' => 'Files'],
      ['section' => 'Security', 'label' => 'Has a manifest'],
      ['section' => 'Structure', 'label' => 'Metadata'],
    ], $view['passed']);
  }

  /**
   * A failing or warning gate reaches the contributor as a finding.
   *
   * @covers ::build
   */
  public function testFailingGatesAreFindings(): void {
    $page = self::page([
      'structure' => self::block('Structure', [], [self::pill('Layout', 'fail'), self::pill('Docs', 'warn')]),
    ]);
    $page['repo_gate_block']['others'] = [self::pill('YAML valid', 'fail')];
    foreach ([FALSE, TRUE] as $sent) {
      $findings = ContributorReviewView::build($page, $sent)['findings'];
      $this->assertSame(['YAML valid', 'Layout', 'Docs'], array_column($findings, 'summary'));
      $this->assertSame(['high', 'high', 'low'], array_column($findings, 'severity'));
      $this->assertSame(['', 'My App', 'My App'], array_column($findings, 'app'));
      $this->assertSame(['Structure', 'Structure', 'Structure'], array_column($findings, 'section'));
      $this->assertSame(['tool', 'tool', 'tool'], array_column($findings, 'source'));
      $this->assertSame(['', '', ''], array_column($findings, 'note'));
    }
    $page['repo_gate_block']['others'][0]['severity'] = 'critical';
    $findings = ContributorReviewView::build($page, FALSE)['findings'];
    $this->assertSame('critical', $findings[0]['severity']);
  }

  /**
   * Superseded and withdrawn reviews are closed.
   *
   * @covers ::build
   */
  public function testSupersededAndWithdrawn(): void {
    $page = self::page([]);
    $this->assertNull(ContributorReviewView::build($page, FALSE)['closed']);
    $page['withdrawn'] = '3 November 2026 at 10:00';
    $this->assertSame('withdrawn', ContributorReviewView::build($page, FALSE)['closed']);
    $page['superseded_by'] = ['nid' => 9];
    $this->assertSame('superseded', ContributorReviewView::build($page, FALSE)['closed']);
  }

}
