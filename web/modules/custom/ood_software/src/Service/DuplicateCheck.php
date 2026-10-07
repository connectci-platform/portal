<?php

namespace Drupal\ood_software\Service;

/**
 * The reviewer's duplicate check, per app (A4 in the 2026-10-07 guidelines
 * alignment plan; the Reviewer Process's Step 1 "Duplicate check").
 *
 * The review lists the published apps that implement the same software; the
 * decision about them is the reviewer's. Accepting an app needs the check
 * recorded, since the rubric makes any Accept conditional on it. The outcome
 * does not restrict the decision: a reviewer may accept an app they marked
 * a duplicate, with a warning, and choose Request changes or Reject for one
 * that is.
 *
 * Pure.
 */
final class DuplicateCheck {

  /**
   * The outcomes, in the Process doc's words.
   */
  const OUTCOMES = [
    'none' => 'No other app for this software',
    'distinct' => 'Same software, a meaningfully different approach',
    'duplicate' => 'Duplicate of an existing app',
  ];

  /**
   * Outcomes that need a rationale: they are the catalog's precedent, or the
   * reason an app is not accepted.
   */
  const NEEDS_NOTE = ['distinct', 'duplicate'];

  const ACCEPTING = ['accept', 'accept_with_suggestions'];

  /**
   * Whether an outcome needs a rationale it does not have.
   */
  public static function needsNote(?string $outcome, string $note): bool {
    return in_array($outcome, self::NEEDS_NOTE, TRUE) && trim($note) === '';
  }

  /**
   * What stops a send: an app being accepted with no duplicate check.
   *
   * @param array<int|string, string|null> $appDecisions
   *   App key => its decision.
   * @param array<int|string, string|null> $outcomes
   *   App key => its duplicate-check outcome, or NULL.
   * @param array<int|string, string> $names
   *   App key => the name to show; the key when absent.
   *
   * @return array<int, string>
   */
  public static function problems(array $appDecisions, array $outcomes, array $names = []): array {
    $problems = [];
    foreach ($appDecisions as $app => $decision) {
      if (in_array($decision, self::ACCEPTING, TRUE) && !isset(self::OUTCOMES[$outcomes[$app] ?? ''])) {
        $problems[] = sprintf('Record the duplicate check for %s before accepting it.', $names[$app] ?? $app);
      }
    }
    return $problems;
  }

  /**
   * What a reviewer is told but may send anyway: accepting an app they
   * marked a duplicate.
   *
   * @param array<int|string, string|null> $appDecisions
   * @param array<int|string, string|null> $outcomes
   * @param array<int|string, string> $names
   *
   * @return array<int, string>
   */
  public static function warnings(array $appDecisions, array $outcomes, array $names = []): array {
    $warnings = [];
    foreach ($appDecisions as $app => $decision) {
      if (in_array($decision, self::ACCEPTING, TRUE) && ($outcomes[$app] ?? NULL) === 'duplicate') {
        $warnings[] = sprintf('You marked %s as a duplicate of an existing app but are accepting it.', $names[$app] ?? $app);
      }
    }
    return $warnings;
  }

}
