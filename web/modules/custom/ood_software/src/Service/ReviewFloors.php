<?php

namespace Drupal\ood_software\Service;

use Drupal\node\NodeInterface;

/**
 * The mildest decision each app may get, from the review's findings.
 *
 * The portal's copy of appverse-review's check-decisions.py, so the decision
 * panel never offers a choice the review's own checks would fail
 * (review-rubric.md, "Repo shapes" and "Decision rubric"; appverse-planning#30):
 *
 * - A security FAIL (aspect "security", or an OODT rule) at High or Critical
 *   sets the floor for every app, wherever in the repo it was found:
 *   installing any one app clones the whole repo.
 * - A structure gate FAIL (an STR rule, whichever aspect filed it) sets
 *   Request changes at any severity: every Structure row is a gate, a
 *   missing gate criterion is Request changes, and a gate failure can always
 *   be fixed, so it is never Reject. An STR note tagged "other" is not a gate
 *   row and floors only at High or Critical. Both apply to their own app, or
 *   to every app when the finding is repo-level.
 * - Inactivity (MNT-01) sets no floor: it is the Inactive upkeep level, and
 *   whether an inactive repo is abandoned (a Reject) is the reviewer's call.
 * - A failed not_archived or public repo gate sets Request changes for every
 *   app.
 *
 * High is at least Request changes and Critical is Reject. Documentation,
 * portability and code-quality findings never set a floor. A finding with no
 * stored result (seeded before results were) counts as a FAIL, as it does on
 * the review page, but only at High or Critical: most such rows are passes
 * at Info, so a gate needs a stored FAIL to set its any-severity floor.
 */
final class ReviewFloors {

  const SEVERITY_FLOOR = ['high' => 'request_changes', 'critical' => 'reject'];

  const REPO_GATES = ['not_archived', 'public'];

  /**
   * Each app's floor.
   *
   * @param array<int, array<string, mixed>> $repoFindings
   *   Repo-level findings, each with rule, aspect, severity, result, evidence.
   * @param array<string, array<int, array<string, mixed>>> $appFindings
   *   App key => that app's findings, in the same shape.
   * @param array<string, string> $repoCriteria
   *   The repo gates, gate => pass / fail.
   *
   * @return array<string, array{decision: string, reason: string}>
   *   App key => its floor and why. An app with no floor is absent.
   */
  public static function floors(array $repoFindings, array $appFindings, array $repoCriteria): array {
    $everyApp = NULL;
    $own = [];
    $multi = count($appFindings) > 1;
    $raise = static function (?array &$current, string $decision, string $reason): void {
      if ($current === NULL || self::rank($decision) > self::rank($current['decision'])) {
        $current = ['decision' => $decision, 'reason' => $reason];
      }
    };

    $sources = [[NULL, $repoFindings]];
    foreach ($appFindings as $app => $findings) {
      $sources[] = [$app, $findings];
    }
    foreach ($sources as [$app, $findings]) {
      foreach ($findings as $f) {
        $result = strtoupper(trim((string) ($f['result'] ?? '')));
        // A finding the reviewer dismissed sets no floor (A1).
        if (($result !== '' && $result !== 'FAIL') || !empty($f['dismissed'])) {
          continue;
        }
        $severity = strtolower(trim((string) ($f['severity'] ?? '')));
        $decision = self::SEVERITY_FLOOR[$severity] ?? NULL;
        $rule = strtoupper(trim((string) ($f['rule'] ?? '')));
        $gate = self::isGate($f);
        // A gate row the security aspect filed (a shellcheck code that maps
        // to STR-04, say) is still a gate, so its floor matches its pill.
        $security = !$gate && (strtolower(trim((string) ($f['aspect'] ?? ''))) === 'security' || str_starts_with($rule, 'OODT'));
        if (!$security && !str_starts_with($rule, 'STR')) {
          continue;
        }
        // A gate: Request changes when it failed (a row seeded before results
        // were stored still needs High to count), never Reject.
        if ($gate) {
          $decision = $decision !== NULL || $result === 'FAIL' ? 'request_changes' : NULL;
        }
        if ($decision === NULL) {
          continue;
        }
        $repoWide = $security || $app === NULL;
        // Evidence is markdown (backticked values); the reason is plain text.
        $evidence = str_replace('`', '', (string) ($f['evidence'] ?? ''));
        $reason = sprintf('%s %s FAIL at %s%s',
          $f['rule'] ?? '?', ucfirst($severity), $evidence !== '' ? $evidence : 'the repo',
          $repoWide && $multi ? ' (applies to every app)' : '');
        if ($repoWide) {
          $raise($everyApp, $decision, $reason);
        }
        else {
          $own[$app] ??= NULL;
          $raise($own[$app], $decision, $reason);
        }
      }
    }
    foreach (self::REPO_GATES as $gate) {
      if (strtolower((string) ($repoCriteria[$gate] ?? '')) === 'fail') {
        $raise($everyApp, 'request_changes', sprintf('the %s repo gate failed', str_replace('_', ' ', $gate)));
      }
    }

    $floors = [];
    foreach (array_keys($appFindings) as $app) {
      $floor = NULL;
      foreach ([$everyApp, $own[$app] ?? NULL] as $candidate) {
        if ($candidate !== NULL) {
          $raise($floor, $candidate['decision'], $candidate['reason']);
        }
      }
      if ($floor !== NULL) {
        $floors[$app] = $floor;
      }
    }
    return $floors;
  }

  /**
   * The decisions at or above a floor, mildest first.
   *
   * @return array<int, string>
   */
  public static function choices(?string $floor): array {
    $rank = $floor === NULL ? 0 : max(0, self::rank($floor));
    return array_slice(ReviewProgress::DECISIONS, $rank);
  }

  /**
   * One message per app whose decision is milder than its floor.
   *
   * @param array<string, string|null> $appDecisions
   *   App key => its decision; an undecided app is skipped (it is reported as
   *   undecided elsewhere).
   * @param array<string, array{decision: string, reason: string}> $floors
   *   From floors().
   * @param array<string, string> $names
   *   App key => the name to show; the key is shown when absent.
   *
   * @return array<int, string>
   */
  public static function problems(array $appDecisions, array $floors, array $names = []): array {
    $problems = [];
    foreach ($appDecisions as $app => $decision) {
      if (!isset($floors[$app]) || !in_array($decision, ReviewProgress::DECISIONS, TRUE)) {
        continue;
      }
      if (self::rank($decision) < self::rank($floors[$app]['decision'])) {
        $problems[] = sprintf('%s cannot be %s: %s needs at least %s.',
          $names[$app] ?? $app,
          ReviewProgress::decisionLabel($decision),
          $floors[$app]['reason'],
          ReviewProgress::decisionLabel($floors[$app]['decision']));
      }
    }
    return $problems;
  }

  /**
   * The floors of a stored review, keyed by verdict paragraph id.
   *
   * @return array<string, array{decision: string, reason: string}>
   */
  public static function forReview(NodeInterface $review): array {
    $read = static fn (array $paragraphs): array => array_map(static fn ($p) => [
      'rule' => (string) ($p->get('field_rvf_rule')->value ?? ''),
      'aspect' => (string) ($p->get('field_rvf_aspect')->value ?? ''),
      // The reviewer's severity when they changed it (FindingOverride).
      'severity' => FindingOverride::effectiveSeverity($p->get('field_rvf_severity')->value, $p->hasField('field_rvf_override_severity') ? $p->get('field_rvf_override_severity')->value : NULL),
      'dismissed' => $p->hasField('field_rvf_dismissed') && (bool) $p->get('field_rvf_dismissed')->value,
      'result' => $p->hasField('field_rvf_result') ? (string) ($p->get('field_rvf_result')->value ?? '') : '',
      'evidence' => (string) ($p->get('field_rvf_evidence')->value ?? ''),
      'defect_key' => (string) ($p->get('field_rvf_defect_key')->value ?? ''),
    ], $paragraphs);
    $apps = [];
    foreach ($review->get('field_arv_verdicts')->referencedEntities() as $verdict) {
      $apps[(string) $verdict->id()] = $read($verdict->get('field_rvv_findings')->referencedEntities());
    }
    return self::floors(
      $read($review->get('field_arv_repo_findings')->referencedEntities()),
      $apps,
      json_decode((string) ($review->get('field_arv_repo_criteria')->value ?? ''), TRUE) ?: [],
    );
  }

  /**
   * Whether a finding is a Structure gate row, as appverse-review's
   * report_parse.is_gate_finding() decides it: an STR rule, whichever aspect
   * filed it, unless its defect_key tag is "other", the structure skill's
   * note for anything else worth a look.
   *
   * @param array<string, mixed> $finding
   */
  public static function isGate(array $finding): bool {
    $rule = strtoupper(trim((string) ($finding['rule'] ?? '')));
    $key = (string) ($finding['defect_key'] ?? '');
    $tag = str_contains($key, ':') ? explode(':', $key, 2)[1] : $key;
    return str_starts_with($rule, 'STR') && explode(':', $tag, 2)[0] !== 'other';
  }

  protected static function rank(string $decision): int {
    $rank = array_search($decision, ReviewProgress::DECISIONS, TRUE);
    return $rank === FALSE ? -1 : $rank;
  }

}
