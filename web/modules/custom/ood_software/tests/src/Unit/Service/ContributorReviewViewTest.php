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
   * The summaries of a block's findings, in the order the page lists them.
   *
   * @param array<string, mixed> $block
   *   A block.
   *
   * @return array<int, string>
   *   The summaries.
   */
  private static function summaries(array $block): array {
    $out = [];
    foreach ($block['groups'] as $group) {
      foreach ($group['findings'] as $finding) {
        $out[] = $finding['summary'];
      }
    }
    return $out;
  }

  /**
   * Before the decision is sent: the precheck state, no decision or response.
   *
   * @covers ::build
   */
  public function testPrecheckHasNoDecisionOrResponse(): void {
    $page = self::page(['structure' => self::block('Structure', [self::row()])]);
    $view = ContributorReviewView::build($page, FALSE);
    $this->assertSame('precheck', $view['state']);
    $this->assertNull($view['decision']);
    $this->assertSame('', $view['response']);
  }

  /**
   * Once sent: the decision and the response.
   *
   * @covers ::build
   */
  public function testDecidedCarriesDecisionAndResponse(): void {
    $page = self::page(['structure' => self::block('Structure', [self::row()])]);
    $view = ContributorReviewView::build($page, TRUE);
    $this->assertSame('decided', $view['state']);
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
    $view = ContributorReviewView::build($page, TRUE);
    $this->assertSame('waiting', $view['state']);
    $this->assertNull($view['decision']);
  }

  /**
   * A failing or warning gate no finding explains joins its block.
   *
   * @covers ::withGateRows
   */
  public function testUnexplainedGatesJoinTheirBlock(): void {
    $gates = [self::pill('Layout', 'fail'), self::pill('Docs', 'warn')];
    $page = self::page(['structure' => self::block('Structure', [self::row(['summary' => 'Medium one'])], $gates)]);
    $page['repo_gate_block']['others'] = [self::pill('YAML valid', 'fail')];
    $block = ContributorReviewView::withGateRows($page)['apps'][0]['blocks']['structure'];
    // Grouped by severity: a failed gate reads as high, a warning as low.
    $this->assertSame([
      'The "Layout" check failed.',
      'The "YAML valid" check failed.',
      'Medium one',
      'The "Docs" check raised a warning.',
    ], self::summaries($block));
    $this->assertSame(['high', 'medium', 'low'], array_column($block['groups'], 'severity'));
    $this->assertSame([2, 1, 1], array_column($block['groups'], 'count'));
    // The block's count line agrees with the findings listed under it.
    $this->assertSame('4 findings · 2 High · 1 Medium · 1 Low', $block['count_line']);
  }

  /**
   * With no app, an unexplained repo gate goes under the repository.
   *
   * A repo the tool found no apps in (appverse.yml does not parse) is when a
   * repo gate is most likely the whole story.
   *
   * @covers ::withGateRows
   */
  public function testRepoGateWithNoAppsGoesUnderRepository(): void {
    $page = self::page([], ['apps' => []]);
    $page['repo_gate_block']['others'] = [self::pill('YAML valid', 'fail')];
    $out = ContributorReviewView::withGateRows($page);
    $this->assertSame(['The "YAML valid" check failed.'], self::summaries($out['repo_blocks']['structure']));
    $this->assertSame('1 finding · 1 High', $out['repo_blocks']['structure']['count_line']);

    // An existing repo Structure block gains the row.
    $page['repo_blocks']['structure'] = self::block('Structure', [self::row(['summary' => 'Repo finding'])]);
    $out = ContributorReviewView::withGateRows($page);
    $this->assertSame(['The "YAML valid" check failed.', 'Repo finding'], self::summaries($out['repo_blocks']['structure']));
  }

  /**
   * A page with every gate explained comes back as it was.
   *
   * @covers ::withGateRows
   */
  public function testNothingToAddLeavesThePage(): void {
    $page = self::page(['structure' => self::block('Structure', [self::row()], [self::pill('Files', 'pass')])]);
    $this->assertSame($page, ContributorReviewView::withGateRows($page));
  }

  /**
   * A gate whose finding the contributor sees is not repeated as a bare row.
   *
   * Review 12415: structure and references warned, and the STR-07 and STR-04
   * rows behind them were already listed, so the two gate rows said nothing.
   *
   * @covers ::withGateRows
   */
  public function testGateExplainedByItsFindingIsNotRepeated(): void {
    $layout = self::row([
      'rule' => 'STR-07',
      'result' => 'WARN',
      'severity' => 'low',
      'tool_severity' => 'low',
      'defect_key' => 'form.yml.erb:other:missing-pre-render-script',
      'summary' => 'pre_render_script.sh is not in the repo',
    ]);
    $gates = [
      ['key' => 'structure', 'label' => 'Standard layout', 'value' => 'warn'],
      ['key' => 'references', 'label' => 'References resolve', 'value' => 'warn'],
    ];
    $page = self::page(['structure' => self::block('Structure', [$layout], $gates)]);
    $this->assertSame([
      'pre_render_script.sh is not in the repo',
      'The "References resolve" check raised a warning.',
    ], self::summaries(ContributorReviewView::withGateRows($page)['apps'][0]['blocks']['structure']));
  }

  /**
   * STR-01 sets two repo gates; each is explained only by its own tag.
   *
   * @covers ::withGateRows
   */
  public function testRepoGateExplainedOnlyByItsOwnTag(): void {
    $license = self::row(['rule' => 'STR-01', 'defect_key' => 'LICENSE:missing-license', 'summary' => 'No LICENSE file']);
    $page = self::page(['structure' => self::block('Structure', [$license])]);
    $page['repo_gate_block']['others'] = [
      ['key' => 'license', 'label' => 'License', 'value' => 'fail'],
      ['key' => 'readme_substantive', 'label' => 'README substantive', 'value' => 'fail'],
    ];
    $this->assertSame([
      'The "README substantive" check failed.',
      'No LICENSE file',
    ], self::summaries(ContributorReviewView::withGateRows($page)['apps'][0]['blocks']['structure']));

    // In a monorepo a repo gate is explained by its row in any app's block,
    // and an unexplained one goes to the first app's Structure.
    $page['apps'][] = ['name' => 'Other App', 'blocks' => ['structure' => self::block('Structure', [])]];
    $page['apps'] = array_reverse($page['apps']);
    $out = ContributorReviewView::withGateRows($page);
    $this->assertSame(['The "README substantive" check failed.'], self::summaries($out['apps'][0]['blocks']['structure']));
    $this->assertSame(['No LICENSE file'], self::summaries($out['apps'][1]['blocks']['structure']));
  }

  /**
   * A failed gate is accounted for by a FAIL row of its rule, or its dismissal.
   *
   * A WARN row alone must not stand in for a failed gate at its own low
   * severity. A FAIL row the reviewer dismissed does account for it: they
   * looked at that failure and cleared it, so a bare "check failed" row
   * would contradict them.
   *
   * @covers ::withGateRows
   */
  public function testFailedGateNeedsFailRowOrItsDismissal(): void {
    $fail = self::row([
      'rule' => 'STR-07',
      'defect_key' => 'apps/x:missing-entry-point',
      'severity' => 'high',
      'tool_severity' => 'high',
      'summary' => 'No entry point',
    ]);
    $note = self::row([
      'rule' => 'STR-07',
      'result' => 'WARN',
      'severity' => 'low',
      'tool_severity' => 'low',
      'defect_key' => 'form.yml:other:odd-layout',
      'summary' => 'Odd layout',
    ]);
    $gates = [['key' => 'structure', 'label' => 'Standard layout', 'value' => 'fail']];

    $structure = static fn (array $rows): array => self::summaries(
      ContributorReviewView::withGateRows(self::page(['structure' => self::block('Structure', $rows, $gates)]))['apps'][0]['blocks']['structure'],
    );
    $this->assertSame(['No entry point', 'Odd layout'], $structure([$fail, $note]));
    // Only the WARN row: the failure itself is unexplained.
    $this->assertSame(['The "Standard layout" check failed.', 'Odd layout'], $structure([$note]));
    // The FAIL row dismissed: no bare row, and the dismissed finding stays off
    // the page as it does for visitors.
    $this->assertSame(['Odd layout'], $structure([['dismissed' => TRUE] + $fail, $note]));
  }

  /**
   * The template syntax gate is explained by STR-05 or STR-06.
   *
   * @covers ::withGateRows
   */
  public function testTemplateSyntaxGate(): void {
    $syntax = self::row([
      'rule' => 'STR-06',
      'defect_key' => 'template/script.sh.erb:bash-syntax-error',
      'summary' => 'bash -n fails',
    ]);
    $gates = [['key' => 'template_syntax', 'label' => 'Template syntax', 'value' => 'fail']];
    $page = self::page(['structure' => self::block('Structure', [$syntax], $gates)]);
    $this->assertSame(['bash -n fails'], self::summaries(ContributorReviewView::withGateRows($page)['apps'][0]['blocks']['structure']));
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
