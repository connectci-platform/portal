<?php

namespace Drupal\ood_software\Service;

/**
 * The reviewer's worklist: one ordered list for the repo, then one per app.
 *
 * Takes the "page" array ReviewPageForm::buildPage() builds, as
 * ContributorReviewView does for the contributor, and lays out what the
 * reviewer has to look at: what blocks a decision first, then the rest by
 * severity, then by section, then in page order. Passes, checks not run and
 * dismissed findings are held out of the list, for the folds below it.
 *
 * An every-app blocker (a security FAIL at High, a repo-level gate FAIL) is
 * listed once, where it lives, and as a reference row in each other app's
 * list, so it can be edited in one place only.
 */
final class ReviewWorklist {

  /**
   * The lists.
   *
   * @param array<mixed> $page
   *   As ReviewPageForm::buildPage() returns it.
   * @param array<string, string> $repoCriteria
   *   The repo gates, gate => pass / fail (field_arv_repo_criteria).
   *
   * @return array<int, array<string, mixed>>
   *   The repo list, then one list per app in page order, each with key,
   *   title, pid, anchor, scope, items, sections, passed, dismissed and
   *   default_section.
   */
  public static function build(array $page, array $repoCriteria): array {
    $apps = array_values((array) ($page['apps'] ?? []));
    $multi = count($apps) > 1;

    // Every listed finding row on the page, for the repo gates: a single
    // app's root findings sit in its own blocks.
    $allRows = [];

    // The repo list's sections: its blocks, then Upkeep.
    $repoBlocks = [];
    foreach ((array) ($page['repo_blocks'] ?? []) as $key => $block) {
      $repoBlocks[(string) $key] = (array) $block;
    }
    $repoBlocks['maintenance'] = (array) ($page['maintenance'] ?? []) + ['title' => 'Upkeep'];
    $repo = self::collect($repoBlocks, TRUE, $multi);
    $allRows = array_merge($allRows, $repo['rows']);

    $appLists = [];
    foreach ($apps as $app) {
      $blocks = [];
      foreach (array_keys(ReviewPageData::APP_BLOCKS) as $key) {
        $blocks[$key] = (array) ($app['blocks'][$key] ?? []) + ['title' => ReviewPageData::APP_BLOCKS[$key]];
      }
      $collected = self::collect($blocks, FALSE, $multi);
      $allRows = array_merge($allRows, $collected['rows']);
      // The app's catalog checks: a WARN is a worklist item, except "Same
      // software", which the duplicate check covers; a PASS is a passed
      // check in Structure.
      foreach ((array) ($app['catalog']['rows'] ?? []) as $row) {
        $result = strtoupper((string) ($row['result'] ?? ''));
        if ($result === 'WARN' && ($row['check'] ?? '') !== 'Same software') {
          $collected['items'][] = self::item('catalog', 'structure', 'Catalog checks', 'low', NULL, (array) $row);
        }
        elseif ($result === 'PASS') {
          $collected['passed']['structure'][] = (array) $row;
        }
      }
      // A duplicate check with apps to compare, or nothing to compare by,
      // waits in the list until the reviewer records it.
      if (self::duplicateIsItem($app)) {
        $basis = (array) $app['duplicate']['basis'];
        $collected['items'][] = self::item('duplicate', 'structure', 'Duplicate check', 'high', NULL, [
          'pid' => $app['pid'] ?? NULL,
          'line' => (string) ($basis['line'] ?? ''),
          'apps' => (array) ($basis['apps'] ?? []),
          'mode' => (string) ($basis['mode'] ?? ''),
        ]);
      }
      $appLists[] = ['app' => $app, 'blocks' => $blocks] + $collected;
    }

    // The repo gates sit in the repo list, under Structure.
    $gateFloors = array_column(ReviewFloors::repoGateFloors($repoCriteria), NULL, 'gate');
    $repoGates = (array) ($page['repo_gate_block'] ?? []);
    $gateItems = [];
    foreach (self::gateItems((array) ($repoGates['others'] ?? []), $allRows, 'Structure') as $item) {
      $floor = $gateFloors[$item['row']['key'] ?? ''] ?? NULL;
      if ($floor !== NULL && strtolower((string) ($item['row']['value'] ?? '')) === 'fail') {
        $item['blocking'] = ['decision' => $floor['decision'], 'reason' => $floor['reason']];
      }
      $gateItems[] = $item;
    }
    $repo['items'] = array_merge($gateItems, $repo['items']);

    // Every-app blockers, as reference rows in each other app's list: one
    // row per blocker when a list has one, else one row for all of that
    // list's, linking to the most severe, so a monorepo does not repeat a
    // dozen rows in every app.
    if ($multi) {
      $sources = [['Repository', NULL, $repo['every_app']]];
      foreach ($appLists as $i => $list) {
        $sources[] = [(string) ($list['app']['name'] ?? ''), $i, $list['every_app']];
      }
      foreach ($sources as [$title, $from, $everyApp]) {
        if ($everyApp === []) {
          continue;
        }
        // Stable, so ties keep page order.
        usort($everyApp, static fn (array $a, array $b): int => self::severityRank($a['severity']) <=> self::severityRank($b['severity']));
        $item = $everyApp[0];
        $reference = self::item('reference', $item['section'], $item['section_title'], $item['severity'], $item['blocking'], [
          'pid' => $item['row']['pid'] ?? NULL,
          'app_title' => $title,
          'source_title' => $title,
          'count' => count($everyApp),
          'rule' => (string) ($item['row']['rule'] ?? ''),
          'summary' => (string) ($item['row']['summary'] ?? ''),
        ]);
        foreach (array_keys($appLists) as $i) {
          if ($i !== $from) {
            $appLists[$i]['items'][] = $reference;
          }
        }
      }
    }

    $repoGatePassed = NULL;
    $gatePasses = array_values((array) ($repoGates['passes'] ?? []));
    $gateNotChecked = [];
    foreach ((array) ($repoGates['others'] ?? []) as $pill) {
      if (self::isNotChecked((array) $pill)) {
        $gateNotChecked[] = self::notChecked((array) $pill);
      }
    }
    if ($gatePasses !== [] || $gateNotChecked !== []) {
      $line = sprintf('Repo: %d of %d passed', count($gatePasses), (int) ($repoGates['total'] ?? count($gatePasses)));
      if ($gateNotChecked !== []) {
        $line .= sprintf(' · %d not checked', count($gateNotChecked));
      }
      $repoGatePassed = [
        'section_title' => 'Structure',
        'line' => $line,
        'rows' => array_merge($gatePasses, $gateNotChecked),
      ];
    }

    $lists = [];
    $repoSections = array_keys($repoBlocks);
    // A repo gate item needs its section in the list, for its anchor and the
    // add form's default.
    if ($gateItems !== [] && !in_array('structure', $repoSections, TRUE)) {
      array_unshift($repoSections, 'structure');
    }
    $lists[] = self::finish([
      'key' => 'repo',
      'title' => 'Repository',
      'pid' => NULL,
      'anchor' => 'maintenance',
      'scope' => '',
    ], $repo, $repoBlocks, $repoSections, 'maintenance', $repoGatePassed);
    foreach ($appLists as $list) {
      $app = $list['app'];
      $lists[] = self::finish([
        'key' => 'app-' . ($app['pid'] ?? ''),
        'title' => (string) ($app['name'] ?? ''),
        'pid' => $app['pid'] ?? NULL,
        'anchor' => 'app-' . ($app['app_id'] ?? ''),
        'scope' => (string) ($app['app_id'] ?? ''),
      ], $list, $list['blocks'], array_keys(ReviewPageData::APP_BLOCKS), 'structure', NULL);
    }
    return $lists;
  }

  /**
   * Whether an app's duplicate check is a worklist item.
   *
   * It is while nothing is recorded and the check has apps to compare
   * (compare), or a declared name with no entry (misspelled), or nothing to
   * go on (unknown). The item then carries the control, and the slot under
   * the app's ratings shows nothing.
   *
   * @param array<mixed> $app
   *   An app as ReviewPageForm::buildPage() lists it.
   */
  public static function duplicateIsItem(array $app): bool {
    return empty($app['duplicate']['outcome'])
      && in_array($app['duplicate']['basis']['mode'] ?? NULL, ['compare', 'misspelled', 'unknown'], TRUE);
  }

  /**
   * One list's items, passes and dismissals, from its blocks.
   *
   * @param array<string, array<mixed>> $blocks
   *   Block key => block, in section order.
   * @param bool $repoLevel
   *   Whether these are the repo's blocks.
   * @param bool $multi
   *   Whether the review has more than one app.
   *
   * @return array{items: array<int, array<string, mixed>>, rows: array<int, array<mixed>>, every_app: array<int, array<string, mixed>>, passed: array<string, array<int, array<mixed>>>, dismissed: array<int, array<mixed>>}
   *   Items in page order; the listed finding rows; the every-app blocking
   *   items; passed rows by section; dismissed rows.
   */
  private static function collect(array $blocks, bool $repoLevel, bool $multi): array {
    $out = ['items' => [], 'rows' => [], 'every_app' => [], 'passed' => [], 'dismissed' => []];
    $gatePills = [];
    foreach ($blocks as $key => $block) {
      $title = (string) ($block['title'] ?? $key);
      foreach ((array) ($block['gate_summary']['others'] ?? []) as $pill) {
        if (self::isNotChecked((array) $pill)) {
          $out['passed'][$key][] = self::notChecked((array) $pill);
        }
        else {
          $gatePills[] = [$key, $title, (array) $pill];
        }
      }
      foreach ((array) ($block['gate_summary']['passes'] ?? []) as $pill) {
        $out['passed'][$key][] = (array) $pill;
      }
      foreach ((array) ($block['groups'] ?? []) as $group) {
        foreach ((array) ($group['findings'] ?? []) as $row) {
          $row = (array) $row;
          $floor = ReviewFloors::findingFloor([
            'rule' => $row['rule'] ?? '',
            'aspect' => $row['aspect'] ?? '',
            'severity' => $row['severity'] ?? '',
            'result' => $row['stored_result'] ?? '',
            'evidence' => $row['evidence'] ?? '',
            'defect_key' => $row['defect_key'] ?? '',
            'dismissed' => !empty($row['dismissed']),
          ], $repoLevel, $multi);
          $item = self::item('finding', $key, $title, strtolower((string) ($row['severity'] ?? '')),
            $floor === NULL ? NULL : ['decision' => $floor['decision'], 'reason' => $floor['reason']], $row);
          $out['items'][] = $item;
          $out['rows'][] = $row;
          if ($floor !== NULL && $floor['every_app']) {
            $out['every_app'][] = $item;
          }
        }
      }
      foreach ((array) ($block['checked']['rows'] ?? []) as $row) {
        $out['passed'][$key][] = (array) $row;
      }
      // A dismissed row keeps its section, for the page's data-section.
      foreach ((array) ($block['dismissed'] ?? []) as $row) {
        $out['dismissed'][] = (array) $row + ['section' => (string) $key];
      }
    }
    // A block's gate stands only when no listed finding accounts for it. The
    // gate items go ahead of the findings, as the pills sat above them.
    $gates = [];
    foreach ($gatePills as [$key, $title, $pill]) {
      foreach (self::gateItems([$pill], $out['rows'], $title) as $item) {
        $item['section'] = $key;
        $gates[] = $item;
      }
    }
    $out['items'] = array_merge($gates, $out['items']);
    return $out;
  }

  /**
   * Failing and warning gate pills no listed finding explains, as items.
   *
   * @param array<mixed> $pills
   *   Gate pills (key, label, value, and maybe severity).
   * @param array<int, array<mixed>> $rows
   *   The listed finding rows.
   * @param string $title
   *   The section's title.
   *
   * @return array<int, array<string, mixed>>
   *   Gate items, in section "structure" until the caller says otherwise.
   */
  private static function gateItems(array $pills, array $rows, string $title): array {
    $items = [];
    foreach ($pills as $pill) {
      $pill = (array) $pill;
      $value = strtolower((string) ($pill['value'] ?? ''));
      if (!in_array($value, ['fail', 'warn'], TRUE)
        || ContributorReviewView::gateExplained((string) ($pill['key'] ?? ''), $value, $rows, FALSE)) {
        continue;
      }
      // A pill has no severity of its own unless it carries one.
      $severity = strtolower((string) ($pill['severity'] ?? '')) ?: ($value === 'fail' ? 'high' : 'low');
      $items[] = self::item('gate', 'structure', $title, $severity, NULL, $pill);
    }
    return $items;
  }

  /**
   * One worklist item.
   *
   * @param string $kind
   *   Finding, gate, catalog, duplicate or reference.
   * @param string $section
   *   The section key.
   * @param string $title
   *   The section's title.
   * @param string $severity
   *   The severity it sorts by.
   * @param array{decision: string, reason: string}|null $blocking
   *   The floor it sets, or NULL.
   * @param array<mixed> $row
   *   The row the item shows.
   *
   * @return array<string, mixed>
   *   The item.
   */
  private static function item(string $kind, string $section, string $title, string $severity, ?array $blocking, array $row): array {
    return [
      'kind' => $kind,
      'section' => $section,
      'section_title' => $title,
      'severity' => $severity,
      'blocking' => $blocking,
      'row' => $row,
    ];
  }

  /**
   * A list, sorted, with its sections, passes and default section.
   *
   * @param array<string, mixed> $list
   *   The list's key, title, pid, anchor and scope.
   * @param array<string, mixed> $collected
   *   From collect(), with any gate, catalog and reference items added.
   * @param array<string, array<mixed>> $blocks
   *   Block key => block.
   * @param array<int, string> $sections
   *   The list's section keys, in section order.
   * @param string $fallback
   *   The default section of an empty list.
   * @param array<string, mixed>|null $repoGatePassed
   *   The repo gates' passed entry, for the repo list.
   *
   * @return array<string, mixed>
   *   The list.
   */
  private static function finish(array $list, array $collected, array $blocks, array $sections, string $fallback, ?array $repoGatePassed): array {
    $items = (array) $collected['items'];
    $order = array_flip(array_merge(array_keys(ReviewPageData::APP_BLOCKS), ['maintenance']));
    // PHP's sort is stable, so ties keep page order.
    usort($items, static function (array $a, array $b) use ($order): int {
      return [self::blocks($a) ? 0 : 1, self::severityRank($a['severity']), $order[$a['section']] ?? count($order)]
        <=> [self::blocks($b) ? 0 : 1, self::severityRank($b['severity']), $order[$b['section']] ?? count($order)];
    });
    // Whether each item goes under "Blocking", for the page.
    foreach ($items as &$item) {
      $item['blocks'] = self::blocks($item);
    }
    unset($item);

    $sectionList = [];
    foreach ($sections as $key) {
      $first = NULL;
      foreach ($items as $i => $item) {
        if ($item['section'] === $key) {
          $first = $i;
          break;
        }
      }
      $sectionList[] = ['key' => $key, 'first' => $first];
    }

    $passed = $repoGatePassed !== NULL ? [$repoGatePassed] : [];
    foreach ($sections as $key) {
      $rows = $collected['passed'][$key] ?? [];
      if ($rows === []) {
        continue;
      }
      $notChecked = count(array_filter($rows, static fn (array $r): bool => strtoupper(trim((string) ($r['result'] ?? ''))) === 'NOT CHECKED'));
      $title = (string) ($blocks[$key]['title'] ?? ReviewPageData::APP_BLOCKS[$key] ?? $key);
      $line = sprintf('%s: %d passed', $title, count($rows) - $notChecked);
      if ($notChecked > 0) {
        $line .= sprintf(' · %d not checked', $notChecked);
      }
      $passed[] = ['section_title' => $title, 'line' => $line, 'rows' => $rows];
    }

    return $list + [
      'items' => $items,
      'sections' => $sectionList,
      'passed' => $passed,
      'dismissed' => (array) $collected['dismissed'],
      'default_section' => $items !== [] ? (string) end($items)['section'] : $fallback,
    ];
  }

  /**
   * Whether a gate pill was neither passed, failed nor warned about.
   *
   * An older review's not_checked, say. It is listed as not checked.
   *
   * @param array<mixed> $pill
   *   A gate pill.
   */
  private static function isNotChecked(array $pill): bool {
    return !in_array(strtolower((string) ($pill['value'] ?? '')), ['pass', 'fail', 'warn'], TRUE);
  }

  /**
   * A gate pill as a passed-checks row that was not checked.
   *
   * @param array<mixed> $pill
   *   A gate pill.
   *
   * @return array<mixed>
   *   The row.
   */
  private static function notChecked(array $pill): array {
    return ['result' => 'NOT CHECKED'] + $pill;
  }

  /**
   * Whether an item blocks a decision; a reference always does.
   *
   * @param array<string, mixed> $item
   *   A worklist item.
   */
  private static function blocks(array $item): bool {
    return $item['blocking'] !== NULL || $item['kind'] === 'reference';
  }

  /**
   * Position of a severity, most severe first; unknown ones last.
   */
  private static function severityRank(string $severity): int {
    $rank = array_search($severity, ReviewPageData::SEVERITY_ORDER, TRUE);
    return $rank === FALSE ? count(ReviewPageData::SEVERITY_ORDER) : $rank;
  }

}
