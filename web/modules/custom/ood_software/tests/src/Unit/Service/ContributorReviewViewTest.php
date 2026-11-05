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
   *
   * @return array<string, mixed>
   */
  private static function row(array $over = []): array {
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
      'evidence_parts' => [['text' => 'form.yml:4', 'url' => 'https://github.com/o/r/blob/abc/form.yml#L4']],
      'prose' => '',
    ];
  }

  /**
   * A block as ReviewPageData::buildBlock() builds it, from finding rows.
   *
   * @param array<int, array<string, mixed>> $rows
   *
   * @return array<string, mixed>
   */
  private static function block(string $title, array $rows): array {
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
    return [
      'title' => $title,
      'groups' => array_map(static fn ($sev, $f) => ['severity' => $sev, 'count' => count($f), 'findings' => $f], array_keys($groups), $groups),
      'checked' => ['rows' => $checked, 'counts' => []],
      'dismissed' => $dismissed,
    ];
  }

  /**
   * @param array<string, mixed> $blocks
   *   Blocks keyed by block key, for one app.
   * @param array<string, mixed> $over
   *   Page keys to replace.
   *
   * @return array<string, mixed>
   */
  private static function page(array $blocks, array $over = []): array {
    return $over + [
      'reviewed_at' => '5 November 2026',
      'apps' => [['name' => 'My App', 'blocks' => $blocks]],
      'repo_blocks' => [],
      'repo_gate_block' => ['passes' => []],
      'maintenance' => [],
      'decision' => ['sent' => TRUE, 'label' => 'Changes requested'],
      'response' => 'Please fix these.',
      'history' => [],
      'superseded_by' => NULL,
      'withdrawn' => NULL,
    ];
  }

  /**
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
    $added = self::row(['pid' => 2, 'source' => 'reviewer', 'summary' => 'Added', 'severity' => 'high', 'tool_severity' => '']);
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
    $added = self::row(['pid' => 3, 'source' => 'reviewer', 'summary' => 'Added', 'severity' => 'high', 'tool_severity' => '', 'prose' => 'Because.']);
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
   * @covers ::build
   */
  public function testOrderingBySeverityThenSection(): void {
    $page = self::page([
      'structure' => self::block('Structure', [self::row(['pid' => 1, 'severity' => 'high', 'tool_severity' => 'high', 'summary' => 'H'])]),
      'security' => self::block('Security', [self::row(['pid' => 2, 'severity' => 'low', 'tool_severity' => 'low', 'summary' => 'L'])]),
      'code_quality' => self::block('Code quality', [
        self::row(['pid' => 3, 'severity' => 'critical', 'tool_severity' => 'critical', 'summary' => 'C']),
        self::row(['pid' => 4, 'severity' => 'low', 'tool_severity' => 'low', 'summary' => 'L2']),
      ]),
    ]);
    $summaries = array_column(ContributorReviewView::build($page, FALSE)['findings'], 'summary');
    $this->assertSame(['C', 'H', 'L', 'L2'], $summaries);
  }

  /**
   * @covers ::build
   */
  public function testPassedChecksAndGates(): void {
    $pass = self::row(['pid' => 1, 'result' => 'PASS', 'summary' => 'Has a manifest']);
    $skipped = self::row(['pid' => 2, 'result' => 'NOT CHECKED', 'summary' => 'Skipped']);
    $page = self::page(['security' => self::block('Security', [$pass, $skipped])]);
    $page['repo_gate_block']['passes'] = [['key' => 'metadata', 'label' => 'Metadata', 'value' => 'pass']];
    $view = ContributorReviewView::build($page, FALSE);
    $this->assertSame([], $view['findings']);
    $this->assertSame([
      ['section' => 'Security', 'label' => 'Has a manifest'],
      ['section' => 'Structure', 'label' => 'Metadata'],
    ], $view['passed']);
  }

  /**
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
