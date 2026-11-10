<?php

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\ood_software\Service\ReviewPageData;
use Drupal\ood_software\Service\ReviewWorklist;
use Drupal\Tests\UnitTestCase;

/**
 * The reviewer's worklist: one ordered list per app, from the page array.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Service\ReviewWorklist
 */
class ReviewWorklistTest extends UnitTestCase {

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
    $row = $over + [
      'source' => 'ai',
      'pid' => 1,
      'rule' => 'QUA-03',
      'aspect' => 'quality',
      'severity' => 'medium',
      'dismissed' => FALSE,
      'override' => NULL,
      'original' => NULL,
      'result' => 'FAIL',
      'summary' => 'Tool summary',
      'evidence' => 'form.yml:4',
      'evidence_parts' => [],
      'defect_key' => '',
      'prose' => '',
    ];
    $row += ['tool_severity' => $row['severity']];
    // The stored value, as findingArray() reads it.
    $row += ['stored_result' => strtolower(str_replace(' ', '_', $row['result']))];
    return $row;
  }

  /**
   * A block as ReviewPageData::buildBlock() builds it, from finding rows.
   *
   * @param string $key
   *   The block's key.
   * @param array<int, array<string, mixed>> $rows
   *   Finding rows, in page order.
   * @param array<int, array<string, string>> $gates
   *   Gate pills (key, label, value).
   *
   * @return array<string, mixed>
   *   The block.
   */
  private static function block(string $key, array $rows, array $gates = []): array {
    return ReviewPageData::buildBlock($key, ReviewPageData::APP_BLOCKS[$key] ?? 'Upkeep', $rows, NULL, [], $gates);
  }

  /**
   * An app as buildPage() lists it, with every APP_BLOCKS block.
   *
   * @param int $pid
   *   The verdict's paragraph id.
   * @param string $name
   *   The app's name.
   * @param array<string, array<int, array<string, mixed>>> $rows
   *   Finding rows by block key.
   * @param array<int, array<string, string>> $gates
   *   The structure block's gate pills.
   * @param array<int, array<string, mixed>> $catalog
   *   Catalog rows.
   *
   * @return array<string, mixed>
   *   The app.
   */
  private static function app(int $pid, string $name, array $rows = [], array $gates = [], array $catalog = []): array {
    $blocks = [];
    foreach (array_keys(ReviewPageData::APP_BLOCKS) as $key) {
      $blocks[$key] = self::block($key, $rows[$key] ?? [], $key === 'structure' ? $gates : []);
    }
    return [
      'pid' => $pid,
      'app_id' => strtolower(str_replace(' ', '_', $name)),
      'name' => $name,
      'blocks' => $blocks,
      'catalog' => ['rows' => $catalog, 'suggestion' => NULL, 'backed' => TRUE],
    ];
  }

  /**
   * A review page.
   *
   * @param array<int, array<string, mixed>> $apps
   *   Apps as app() builds them.
   * @param array<string, array<int, array<string, mixed>>> $repoRows
   *   Repo-level finding rows by block key (maintenance included).
   * @param array<int, array<string, string>> $repoGates
   *   The repo gate pills.
   *
   * @return array<string, mixed>
   *   The page.
   */
  private static function page(array $apps, array $repoRows = [], array $repoGates = []): array {
    $repoBlocks = [];
    foreach (array_keys(ReviewPageData::APP_BLOCKS) as $key) {
      if (!empty($repoRows[$key])) {
        $repoBlocks[$key] = self::block($key, $repoRows[$key]);
      }
    }
    return [
      'apps' => $apps,
      'repo_blocks' => $repoBlocks,
      'maintenance' => self::block('maintenance', $repoRows['maintenance'] ?? []),
      'repo_gate_block' => ReviewPageData::gateSummary($repoGates),
    ];
  }

  /**
   * A gate pill.
   *
   * @return array<string, string>
   *   The pill.
   */
  private static function pill(string $key, string $value): array {
    return ['key' => $key, 'label' => ReviewPageData::GATE_LABELS[$key] ?? $key, 'value' => $value];
  }

  /**
   * The rule of each item's row.
   *
   * @param array<int, array<string, mixed>> $items
   *   Worklist items.
   *
   * @return array<int, mixed>
   *   The rules.
   */
  private static function rules(array $items): array {
    return array_map(static fn (array $i) => $i['row']['rule'] ?? $i['row']['key'] ?? $i['row']['check'] ?? NULL, $items);
  }

  /**
   * @covers ::build
   */
  public function testListShape(): void {
    $lists = ReviewWorklist::build(self::page([self::app(5, 'App A'), self::app(6, 'App B')]), []);
    $this->assertCount(3, $lists);
    $this->assertSame(
      ['key' => 'repo', 'title' => 'Repository', 'pid' => NULL, 'anchor' => 'maintenance', 'scope' => ''],
      array_intersect_key($lists[0], array_flip(['key', 'title', 'pid', 'anchor', 'scope'])),
    );
    $this->assertSame(
      ['key' => 'app-6', 'title' => 'App B', 'pid' => 6, 'anchor' => 'app-app_b', 'scope' => 'app_b'],
      array_intersect_key($lists[2], array_flip(['key', 'title', 'pid', 'anchor', 'scope'])),
    );
  }

  /**
   * @covers ::build
   */
  public function testBlockingFirstThenSeverityThenSection(): void {
    $page = self::page([self::app(5, 'App A', [
      'structure' => [self::row([
        'pid' => 3,
        'rule' => 'STR-01',
        'aspect' => 'structure',
        'severity' => 'low',
        'defect_key' => 'README.md:missing-readme',
      ]),
      ],
      'documentation' => [self::row([
        'pid' => 1,
        'rule' => 'QUA-01',
        'aspect' => 'documentation',
        'severity' => 'low',
        'result' => 'WARN',
      ]),
      ],
      'code_quality' => [self::row(['pid' => 2, 'rule' => 'QUA-03', 'severity' => 'critical'])],
    ]),
    ]);
    $items = ReviewWorklist::build($page, [])[1]['items'];
    $this->assertSame(['STR-01', 'QUA-03', 'QUA-01'], array_column(array_column($items, 'row'), 'rule'));
    $this->assertSame('request_changes', $items[0]['blocking']['decision']);
    $this->assertNull($items[1]['blocking']);
    $keys = array_flip(['kind', 'section', 'section_title', 'severity']);
    $this->assertSame(['finding', 'structure', 'Structure', 'low'], array_values(array_intersect_key($items[0], $keys)));
    $this->assertSame(['finding', 'code_quality', 'Code quality', 'critical'], array_values(array_intersect_key($items[1], $keys)));
  }

  /**
   * @covers ::build
   */
  public function testOverriddenSeverityDecidesOrder(): void {
    // The tool said High (a blocking security FAIL); the reviewer said Info.
    $page = self::page([self::app(5, 'App A', [
      'security' => [self::row([
        'pid' => 1,
        'rule' => 'OODT-01',
        'aspect' => 'security',
        'severity' => 'info',
        'tool_severity' => 'high',
      ]),
      ],
      'code_quality' => [self::row(['pid' => 2, 'rule' => 'QUA-03', 'severity' => 'medium'])],
    ]),
    ]);
    $items = ReviewWorklist::build($page, [])[1]['items'];
    $this->assertSame(['QUA-03', 'OODT-01'], array_column(array_column($items, 'row'), 'rule'));
    $this->assertSame('info', $items[1]['severity']);
    $this->assertNull($items[1]['blocking']);
  }

  /**
   * @covers ::build
   */
  public function testEveryAppFloorShowsAsReferenceInOtherApps(): void {
    $page = self::page([
      self::app(5, 'App A', [
        'security' => [self::row([
          'pid' => 77,
          'rule' => 'OODT-01',
          'aspect' => 'security',
          'severity' => 'high',
          'summary' => 'Runs user input',
        ]),
        ],
      ]),
      self::app(6, 'App B', ['code_quality' => [self::row(['pid' => 78, 'rule' => 'QUA-03', 'severity' => 'critical'])]]),
    ]);
    $lists = ReviewWorklist::build($page, []);
    $b = $lists[2]['items'];
    $this->assertSame('reference', $b[0]['kind']);
    $this->assertSame(77, $b[0]['row']['pid']);
    $this->assertSame('App A', $b[0]['row']['app_title']);
    $this->assertSame('request_changes', $b[0]['blocking']['decision']);
    $this->assertSame('security', $b[0]['section']);
    // The reference sorts as blocking, ahead of a critical non-blocking one.
    $this->assertSame('QUA-03', $b[1]['row']['rule']);
    $this->assertCount(2, $b);

    $a = $lists[1]['items'];
    $this->assertSame(['finding'], array_column($a, 'kind'));
    $this->assertStringContainsString('(applies to every app)', $a[0]['blocking']['reason']);
    $this->assertSame([], $lists[0]['items']);
  }

  /**
   * @covers ::build
   */
  public function testEveryAppBlockersFromOneListCollapseToOneReference(): void {
    $page = self::page([
      self::app(5, 'App A', [
        'security' => [
          self::row(['pid' => 77, 'rule' => 'OODT-01', 'aspect' => 'security', 'severity' => 'high']),
          self::row(['pid' => 79, 'rule' => 'OODT-02', 'aspect' => 'security', 'severity' => 'high']),
        ],
      ]),
      self::app(6, 'App B'),
      self::app(7, 'App C'),
    ]);
    $lists = ReviewWorklist::build($page, []);
    foreach ([2, 3] as $i) {
      $items = $lists[$i]['items'];
      $this->assertCount(1, $items);
      $this->assertSame('reference', $items[0]['kind']);
      $this->assertSame(2, $items[0]['row']['count']);
      $this->assertSame('App A', $items[0]['row']['source_title']);
      // Links to the first of them.
      $this->assertSame(77, $items[0]['row']['pid']);
      $this->assertSame('request_changes', $items[0]['blocking']['decision']);
    }
    // The single case is unchanged, and says so.
    $single = ReviewWorklist::build(self::page([
      self::app(5, 'App A', [
        'security' => [self::row(['pid' => 77, 'rule' => 'OODT-01', 'aspect' => 'security', 'severity' => 'high'])],
      ]),
      self::app(6, 'App B'),
    ]), []);
    $this->assertSame(1, $single[2]['items'][0]['row']['count']);
  }

  /**
   * @covers ::build
   */
  public function testRepoLevelEveryAppItemIsReferencedInEveryApp(): void {
    $page = self::page(
      [self::app(5, 'App A'), self::app(6, 'App B')],
      ['structure' => [self::row(['pid' => 90, 'rule' => 'STR-02', 'aspect' => 'structure', 'severity' => 'low'])]],
    );
    $lists = ReviewWorklist::build($page, []);
    $this->assertSame('finding', $lists[0]['items'][0]['kind']);
    $this->assertNotNull($lists[0]['items'][0]['blocking']);
    foreach ([1, 2] as $i) {
      $this->assertSame('reference', $lists[$i]['items'][0]['kind']);
      $this->assertSame(90, $lists[$i]['items'][0]['row']['pid']);
      $this->assertSame('Repository', $lists[$i]['items'][0]['row']['app_title']);
    }
  }

  /**
   * @covers ::build
   */
  public function testNoReferencesForSingleApp(): void {
    $page = self::page(
      [self::app(5, 'App A', [
        'security' => [self::row([
          'pid' => 77,
          'rule' => 'OODT-01',
          'aspect' => 'security',
          'severity' => 'high',
        ]),
        ],
      ]),
      ],
      ['structure' => [self::row(['pid' => 90, 'rule' => 'STR-02', 'aspect' => 'structure', 'severity' => 'low'])]],
    );
    $lists = ReviewWorklist::build($page, []);
    foreach ($lists as $list) {
      $this->assertNotContains('reference', array_column($list['items'], 'kind'));
    }
    $this->assertStringNotContainsString('every app', $lists[1]['items'][0]['blocking']['reason']);
  }

  /**
   * @covers ::build
   */
  public function testFailedRepoGateIsBlockingInRepoList(): void {
    $page = self::page(
      [self::app(5, 'App A')],
      ['code_quality' => [self::row(['pid' => 90, 'rule' => 'QUA-03', 'severity' => 'critical'])]],
      [self::pill('not_archived', 'pass'), self::pill('public', 'fail')],
    );
    $items = ReviewWorklist::build($page, ['not_archived' => 'pass', 'public' => 'fail'])[0]['items'];
    $this->assertCount(2, $items);
    $this->assertSame('gate', $items[0]['kind']);
    $this->assertSame('public', $items[0]['row']['key']);
    $this->assertSame('request_changes', $items[0]['blocking']['decision']);
    $this->assertStringContainsString('public', $items[0]['blocking']['reason']);
    $this->assertSame('high', $items[0]['severity']);
    $this->assertSame('QUA-03', $items[1]['row']['rule']);
  }

  /**
   * @covers ::build
   */
  public function testGateExplainedByFindingIsNotRepeated(): void {
    $finding = self::row([
      'pid' => 3,
      'rule' => 'STR-01',
      'aspect' => 'structure',
      'severity' => 'high',
      'defect_key' => 'LICENSE:missing-license',
    ]);
    $gates = [self::pill('license', 'fail')];
    $items = ReviewWorklist::build(self::page([self::app(5, 'App A', ['structure' => [$finding]], $gates)]), [])[1]['items'];
    $this->assertSame(['finding'], array_column($items, 'kind'));

    // Without the finding, the gate stands on its own.
    $items = ReviewWorklist::build(self::page([self::app(5, 'App A', [], $gates)]), [])[1]['items'];
    $this->assertSame(['gate'], array_column($items, 'kind'));
    // A dismissed finding is not listed, so it explains nothing.
    $finding['dismissed'] = TRUE;
    $items = ReviewWorklist::build(self::page([self::app(5, 'App A', ['structure' => [$finding]], $gates)]), [])[1]['items'];
    $this->assertSame(['gate'], array_column($items, 'kind'));
  }

  /**
   * @covers ::build
   */
  public function testUnexplainedGateSortsAsHighOrLow(): void {
    $catalog = [
      ['check' => 'Software', 'result' => 'PASS', 'text' => 'Matches'],
      ['check' => 'App type', 'result' => 'WARN', 'text' => 'x is not a known app type'],
      ['check' => 'Same software', 'result' => 'WARN', 'text' => '2 published apps', 'links' => []],
    ];
    $page = self::page([self::app(5, 'App A',
      ['code_quality' => [self::row(['pid' => 2, 'rule' => 'QUA-03', 'severity' => 'medium'])]],
      [self::pill('yaml_valid', 'warn'), self::pill('metadata', 'fail'), self::pill('license', 'pass')],
      $catalog,
      ),
    ]);
    $items = ReviewWorklist::build($page, [])[1]['items'];
    $this->assertSame(['gate', 'finding', 'gate', 'catalog'], array_column($items, 'kind'));
    $this->assertSame(['high', 'medium', 'low', 'low'], array_column($items, 'severity'));
    $this->assertSame(['metadata', 'QUA-03', 'yaml_valid', 'App type'], self::rules($items));
    // An app's own gate sets no floor; only the repo gates do.
    $this->assertSame([NULL, NULL, NULL, NULL], array_column($items, 'blocking'));
    $this->assertSame('structure', $items[3]['section']);
  }

  /**
   * @covers ::build
   */
  public function testSectionsListEverySectionWithItsFirstItem(): void {
    $page = self::page(
      [self::app(5, 'App A', [
        'security' => [self::row([
          'rule' => 'OODT-02',
          'aspect' => 'security',
          'severity' => 'low',
        ]),
        ],
      ]),
      ],
      ['code_quality' => [self::row(['pid' => 9, 'severity' => 'low'])]],
    );
    $lists = ReviewWorklist::build($page, []);
    $sections = array_column($lists[1]['sections'], 'first', 'key');
    $this->assertSame(array_keys(ReviewPageData::APP_BLOCKS), array_keys($sections));
    $this->assertSame(0, $sections['security']);
    $this->assertNull($sections['documentation']);

    $this->assertSame([['key' => 'code_quality', 'first' => 0], ['key' => 'maintenance', 'first' => NULL]], $lists[0]['sections']);
  }

  /**
   * @covers ::build
   */
  public function testPassedLines(): void {
    $gates = [
      self::pill('not_archived',
      'pass'),
      self::pill('public',
      'pass'),
      self::pill('license',
      'pass'),
      self::pill('metadata',
      'pass'),
    ];
    $page = self::page([self::app(5, 'App A', [
      'structure' => [
        self::row(['rule' => 'STR-01', 'result' => 'PASS']),
        self::row(['rule' => 'STR-02', 'result' => 'PASS']),
        self::row(['rule' => 'STR-04', 'result' => 'NOT CHECKED']),
        self::row(['rule' => 'STR-03', 'result' => 'PASS']),
      ],
      'security' => [self::row(['rule' => 'OODT-01', 'result' => 'PASS'])],
    ]),
    ], [], $gates);
    $lists = ReviewWorklist::build($page, []);
    $this->assertSame('Repo: 4 of 4 passed', $lists[0]['passed'][0]['line']);
    $this->assertCount(4, $lists[0]['passed'][0]['rows']);
    $this->assertCount(1, $lists[0]['passed']);
    $this->assertSame('Structure: 3 passed · 1 not checked', $lists[1]['passed'][0]['line']);
    $this->assertSame('Structure', $lists[1]['passed'][0]['section_title']);
    $this->assertSame(['STR-01', 'STR-02', 'STR-04', 'STR-03'], array_column($lists[1]['passed'][0]['rows'], 'rule'));
    $this->assertSame('Security: 1 passed', $lists[1]['passed'][1]['line']);
    $this->assertCount(2, $lists[1]['passed']);
    $this->assertSame([], $lists[1]['items']);
  }

  /**
   * @covers ::build
   */
  public function testGateAndCatalogPassesJoinStructurePassed(): void {
    $page = self::page([self::app(5, 'App A', [], [self::pill('license', 'pass'), self::pill('metadata', 'fail')], [
      ['check' => 'Software', 'result' => 'PASS', 'text' => 'Matches'],
      ['check' => 'App type', 'result' => 'N/A', 'text' => 'Not applicable'],
      ['check' => 'Same software', 'result' => 'PASS', 'text' => 'No other'],
    ]),
    ], [], [self::pill('public', 'pass'), self::pill('not_archived', 'fail')]);
    $lists = ReviewWorklist::build($page, ['public' => 'pass', 'not_archived' => 'fail']);
    $this->assertSame('Repo: 1 of 2 passed', $lists[0]['passed'][0]['line']);
    $this->assertSame('Structure: 3 passed', $lists[1]['passed'][0]['line']);
    $this->assertCount(3, $lists[1]['passed'][0]['rows']);
  }

  /**
   * @covers ::build
   */
  public function testNotCheckedGatesJoinPassedAsNotChecked(): void {
    $page = self::page(
      [self::app(5, 'App A', [], [self::pill('license', 'pass'), self::pill('metadata', 'not_checked')])],
      [],
      [self::pill('public', 'pass'), self::pill('not_archived', 'not_checked')],
    );
    $lists = ReviewWorklist::build($page, ['public' => 'pass', 'not_archived' => 'not_checked']);
    // Neither is a worklist item.
    $this->assertSame([], $lists[0]['items']);
    $this->assertSame([], $lists[1]['items']);
    $this->assertSame('Repo: 1 of 2 passed · 1 not checked', $lists[0]['passed'][0]['line']);
    $this->assertSame(['pass', 'not_checked'], array_column($lists[0]['passed'][0]['rows'], 'value'));
    $this->assertSame('Structure: 1 passed · 1 not checked', $lists[1]['passed'][0]['line']);
    $byValue = array_column($lists[1]['passed'][0]['rows'], NULL, 'value');
    $this->assertSame('NOT CHECKED', $byValue['not_checked']['result']);
    $this->assertArrayHasKey('pass', $byValue);
  }

  /**
   * @covers ::build
   */
  public function testItemsSayWhetherTheyBlock(): void {
    $page = self::page([
      self::app(5, 'App A', [
        'security' => [self::row(['pid' => 77, 'rule' => 'OODT-01', 'aspect' => 'security', 'severity' => 'high'])],
        'code_quality' => [self::row(['pid' => 78, 'rule' => 'QUA-03', 'severity' => 'low'])],
      ]),
      self::app(6, 'App B'),
    ]);
    $lists = ReviewWorklist::build($page, []);
    $this->assertSame([TRUE, FALSE], array_column($lists[1]['items'], 'blocks'));
    // A reference always blocks.
    $this->assertSame([TRUE], array_column($lists[2]['items'], 'blocks'));
  }

  /**
   * @covers ::build
   */
  public function testDismissedLeaveItemsAndJoinDismissed(): void {
    $page = self::page([self::app(5, 'App A', [
      'security' => [self::row([
        'pid' => 4,
        'rule' => 'OODT-01',
        'aspect' => 'security',
        'severity' => 'critical',
        'dismissed' => TRUE,
      ]),
      ],
      'code_quality' => [self::row(['pid' => 5, 'dismissed' => TRUE])],
    ]),
    ]);
    $list = ReviewWorklist::build($page, [])[1];
    $this->assertSame([], $list['items']);
    $this->assertSame([4, 5], array_column($list['dismissed'], 'pid'));
    $this->assertSame(['security', 'code_quality'], array_column($list['dismissed'], 'section'));
  }

  /**
   * @covers ::build
   */
  public function testDefaultSection(): void {
    $page = self::page([
      self::app(5, 'App A', [
        'structure' => [self::row(['rule' => 'STR-07', 'aspect' => 'structure', 'severity' => 'high'])],
        'security' => [self::row(['rule' => 'OODT-02', 'aspect' => 'security', 'severity' => 'low'])],
      ]),
      self::app(6, 'App B'),
    ]);
    $lists = ReviewWorklist::build($page, []);
    $this->assertSame('security', $lists[1]['default_section']);
    $this->assertSame('structure', $lists[2]['default_section']);
    $this->assertSame('maintenance', $lists[0]['default_section']);
  }

}
