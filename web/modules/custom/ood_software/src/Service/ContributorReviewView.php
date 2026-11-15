<?php

namespace Drupal\ood_software\Service;

/**
 * What the repo owner's page of a review adds to the public summary.
 *
 * The owner sees the public summary's layout (D8-3091) with a band above it.
 * build() says what the band shows: the state, the decision and response once
 * sent, and whether the review is closed. Before the decision is sent,
 * ReviewPageForm builds the page from the tool's report alone, so none of the
 * reviewer's work in progress shows: no overrides, rewording, dismissals,
 * added findings, ratings, conclusions or summary.
 *
 * withGateRows() puts back what the summary leaves out but the owner needs: a
 * failing or warning gate that no listed finding explains.
 */
final class ContributorReviewView {

  /**
   * The band's state for one review.
   *
   * @param array<mixed> $page
   *   As ReviewPageForm::buildPage() returns it.
   * @param bool $sent
   *   Whether the reviewer has sent the decision.
   *
   * @return array<string, mixed>
   *   state (waiting, precheck or decided), decision, response and closed
   *   (superseded, withdrawn or NULL).
   */
  public static function build(array $page, bool $sent): array {
    $closed = !empty($page['superseded_by']) ? 'superseded' : (!empty($page['withdrawn']) ? 'withdrawn' : NULL);
    $view = [
      'state' => 'waiting',
      'decision' => NULL,
      'response' => '',
      'closed' => $closed,
    ];
    // No report yet, so nothing to show, whatever the reviewer has entered.
    if (empty($page['reviewed_at'])) {
      return $view;
    }
    $view['state'] = $sent ? 'decided' : 'precheck';
    if ($sent) {
      $view['decision'] = $page['decision'] ?? NULL;
      $view['response'] = (string) ($page['response'] ?? '');
    }
    return $view;
  }

  /**
   * The page with each unexplained failing or warning gate as a finding.
   *
   * The public summary lists findings only. A gate is the tool's tally of
   * finding rows, so one whose row is listed needs nothing more; one set by a
   * check the report does not list would otherwise leave the owner with a
   * failed check they cannot see. Such a gate joins its block's findings,
   * worded as a sentence; a repo-wide gate joins the first app's Structure,
   * or the repository's when there is no app.
   *
   * @param array<mixed> $page
   *   As ReviewPageForm::buildPage() returns it, already limited to what the
   *   owner may see.
   *
   * @return array<mixed>
   *   The page, with gate rows in the blocks' groups.
   */
  public static function withGateRows(array $page): array {
    // A repo gate can be explained by a finding in any section (a single
    // app's root findings sit in its own blocks).
    // Dismissed rows are read too: a gate whose row the reviewer dismissed is
    // accounted for (see gateExplained()).
    $allRows = [];
    foreach ((array) ($page['apps'] ?? []) as $app) {
      foreach ((array) ($app['blocks'] ?? []) as $block) {
        $allRows = array_merge($allRows, self::blockRows((array) $block, FALSE));
      }
    }
    foreach ((array) ($page['repo_blocks'] ?? []) as $block) {
      $allRows = array_merge($allRows, self::blockRows((array) $block, FALSE));
    }
    if (!empty($page['maintenance'])) {
      $allRows = array_merge($allRows, self::blockRows((array) $page['maintenance'], FALSE));
    }

    foreach ((array) ($page['apps'] ?? []) as $a => $app) {
      foreach ((array) ($app['blocks'] ?? []) as $key => $block) {
        $rows = self::gateRows((array) ($block['gate_summary']['others'] ?? []), self::blockRows((array) $block, FALSE), TRUE);
        if ($rows !== []) {
          $page['apps'][$a]['blocks'][$key] = self::withRows((array) $block, $rows);
        }
      }
    }
    $repoRows = self::gateRows((array) ($page['repo_gate_block']['others'] ?? []), $allRows, TRUE);
    if ($repoRows !== []) {
      foreach ((array) ($page['apps'] ?? []) as $a => $app) {
        if (isset($app['blocks']['structure'])) {
          $page['apps'][$a]['blocks']['structure'] = self::withRows((array) $app['blocks']['structure'], $repoRows);
          return $page;
        }
      }
      // No app to file them under: a repo the tool found no apps in, which is
      // when a repo gate (YAML valid, layout) is most likely the reason. The
      // template shows a repo block with findings under Repository.
      $page['repo_blocks']['structure'] = isset($page['repo_blocks']['structure'])
        ? self::withRows((array) $page['repo_blocks']['structure'], $repoRows)
        : ReviewPageData::buildBlock('structure', 'Structure', $repoRows, NULL, []);
    }
    return $page;
  }

  /**
   * A block with extra finding rows grouped in by severity.
   *
   * @param array<mixed> $block
   *   A block from the page.
   * @param array<int, array<mixed>> $rows
   *   Rows shaped like a finding row.
   *
   * @return array<mixed>
   *   The block.
   */
  private static function withRows(array $block, array $rows): array {
    $findings = [];
    foreach ((array) ($block['groups'] ?? []) as $group) {
      $findings = array_merge($findings, (array) ($group['findings'] ?? []));
    }
    $findings = array_merge($findings, $rows);
    $block['groups'] = ReviewPageData::groupBySeverity($findings);
    // A block with no rating summary shows the count line, which must agree
    // with the findings below it.
    $none = ($block['key'] ?? '') === 'security' ? ReviewPageData::SECURITY_NONE : 'No findings';
    $block['count_line'] = ReviewPageData::countLine($findings, $none);
    return $block;
  }

  /**
   * Whether the contributor sees a finding row.
   *
   * @param array<mixed> $row
   *   A finding row from the page.
   * @param bool $sent
   *   Whether the decision has been sent.
   */
  private static function visible(array $row, bool $sent): bool {
    // A reviewer's finding is theirs until sent; a dismissal stands once sent.
    if (($row['source'] ?? '') === 'reviewer') {
      return $sent;
    }
    return !$sent || empty($row['dismissed']);
  }

  /**
   * A block's defect rows the contributor may be shown, in page order.
   *
   * Dismissals are the reviewer's until sent; the tool's report still has
   * the finding, so before sending they are listed too.
   *
   * @param array<mixed> $block
   *   A block from the page.
   * @param bool $sent
   *   Whether the decision has been sent.
   *
   * @return array<int, array<mixed>>
   *   The rows.
   */
  private static function blockRows(array $block, bool $sent): array {
    $rows = [];
    foreach ((array) ($block['groups'] ?? []) as $group) {
      $rows = array_merge($rows, (array) ($group['findings'] ?? []));
    }
    $dismissed = $sent ? [] : (array) ($block['dismissed'] ?? []);
    return array_values(array_filter(array_merge($rows, $dismissed), static fn ($row): bool => self::isDefect((array) $row)));
  }

  /**
   * Failing and warning gate pills as finding rows.
   *
   * A gate is only the tool's tally of finding rows, so a gate whose rows the
   * contributor already sees is left out: the finding says what is wrong and
   * where, and the gate's label alone said neither. A gate with no such row
   * (a gate set by a check the report does not list) stays, worded as a
   * sentence rather than a bare label.
   *
   * A pill has no severity of its own: a fail reads as high and a warn as
   * low, unless the row carries one. Other values (a pass, a skipped check)
   * are not findings.
   *
   * @param array<mixed> $pills
   *   Gate pills (key, label, value) from a gate summary's "others".
   * @param array<int, array<mixed>> $rows
   *   The defect rows that may explain the gates.
   * @param bool $sent
   *   Whether the decision has been sent.
   *
   * @return array<int, array<string, mixed>>
   *   Rows shaped like a finding row.
   */
  private static function gateRows(array $pills, array $rows, bool $sent): array {
    $out = [];
    foreach ($pills as $pill) {
      $value = strtolower((string) ($pill['value'] ?? ''));
      if (!in_array($value, ['fail', 'warn'], TRUE)) {
        continue;
      }
      if (self::gateExplained((string) ($pill['key'] ?? ''), $value, $rows, $sent, TRUE)) {
        continue;
      }
      $label = (string) ($pill['label'] ?? '');
      $severity = (string) ($pill['severity'] ?? '') ?: ($value === 'fail' ? 'high' : 'low');
      $out[] = [
        'source' => 'ai',
        'rule' => '',
        'severity' => $severity,
        'tool_severity' => $severity,
        'summary' => (string) ($pill['summary'] ?? sprintf($value === 'fail' ? 'The "%s" check failed.' : 'The "%s" check raised a warning.', $label)),
        'evidence' => (string) ($pill['evidence'] ?? ''),
        'evidence_parts' => (array) ($pill['evidence_parts'] ?? []),
        'dismissed' => FALSE,
        'original' => NULL,
        'prose' => '',
      ];
    }
    return $out;
  }

  /**
   * Whether a row the contributor sees accounts for the gate.
   *
   * The tool sets a gate to the worst result among its rows (every row of the
   * rule, other: notes included), so a failed gate is accounted for only by a
   * visible FAIL row: a WARN row left after the FAIL was dismissed would
   * otherwise stand in for it at its own, lower severity.
   *
   * @param string $gate
   *   The gate's key, as ReviewPageData::GATE_RULES names it.
   * @param string $value
   *   The gate's value, fail or warn.
   * @param array<int, array<mixed>> $rows
   *   Defect rows.
   * @param bool $sent
   *   Whether the decision has been sent.
   * @param bool $dismissedAccounts
   *   Whether a row the reviewer dismissed accounts for the gate: on the
   *   owner's page it does, since the reviewer looked at that failure and
   *   cleared it, and a bare "check failed" row would contradict them.
   */
  public static function gateExplained(string $gate, string $value, array $rows, bool $sent, bool $dismissedAccounts = FALSE): bool {
    [$rules, $tags] = ReviewPageData::GATE_RULES[$gate] ?? [[], NULL];
    $results = $value === 'fail' ? ['FAIL'] : ['FAIL', 'WARN'];
    foreach ($rows as $row) {
      $rule = strtoupper(trim((string) ($row['rule'] ?? '')));
      // A row with no result is a FAIL, as on the reviewer page.
      $result = strtoupper(trim((string) ($row['result'] ?? ''))) ?: 'FAIL';
      // Only a tool finding is dismissed (a reviewer's own is deleted instead);
      // the source check keeps it that way should that change.
      $dismissed = !empty($row['dismissed']) && ($row['source'] ?? '') !== 'reviewer';
      $accounts = self::visible((array) $row, $sent) || ($dismissedAccounts && $dismissed);
      if (!in_array($rule, $rules, TRUE) || !in_array($result, $results, TRUE) || !$accounts) {
        continue;
      }
      $key = (string) ($row['defect_key'] ?? '');
      $tag = explode(':', str_contains($key, ':') ? explode(':', $key, 2)[1] : $key, 2)[0];
      if ($tags === NULL || in_array($tag, $tags, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether the row asserts a defect, ignoring dismissal.
   *
   * @param array<mixed> $row
   *   A finding row from the page.
   *
   * @return bool
   *   TRUE for a FAIL, a WARN or a row with no result.
   */
  private static function isDefect(array $row): bool {
    $result = strtoupper(trim((string) ($row['result'] ?? '')));
    return $result === '' || $result === 'FAIL' || $result === 'WARN';
  }

}
