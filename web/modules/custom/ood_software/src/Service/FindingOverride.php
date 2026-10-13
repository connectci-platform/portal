<?php

namespace Drupal\ood_software\Service;

use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * A reviewer's change to an automated finding: a new severity, a dismissal,
 * or their own wording of its summary and evidence (A1 in the 2026-10-07
 * guidelines alignment plan).
 *
 * The tool's own values are never written; the change sits beside them on
 * the finding, so the record keeps what the tool said and a later re-review
 * can read both. Nothing carries into the next round: a new review seeds
 * fresh findings with no changes.
 *
 * The reason for a new severity or a dismissal is the finding's note to the
 * contributor (field_rvf_reviewer_prose), which is then required. A wording
 * change needs no reason: the tool's text stays on the record beside it.
 *
 * Everything that reads a finding uses the effective values, and treats a
 * dismissed finding as no finding: the review page's counts and groups, the
 * decision floors and the catalog's security count.
 */
final class FindingOverride {

  const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];

  /**
   * The severity everything else reads: the reviewer's when they set a valid
   * one, else the tool's.
   */
  public static function effectiveSeverity(?string $tool, ?string $override): string {
    $override = strtolower(trim((string) $override));
    return in_array($override, self::SEVERITIES, TRUE) ? $override : strtolower(trim((string) $tool));
  }

  /**
   * Whether a finding takes a reviewer's change: the tool's, and a finding
   * (FAIL, WARN, or a row seeded before results were stored). A reviewer's
   * own finding is edited directly.
   *
   * @param array<string, mixed> $finding
   *   With source and result, as ReviewPageForm::findingArray() gives them.
   */
  public static function applies(array $finding): bool {
    $result = strtoupper(trim((string) ($finding['result'] ?? '')));
    return ($finding['source'] ?? 'ai') !== 'reviewer' && in_array($result, ['', 'FAIL', 'WARN'], TRUE);
  }

  /**
   * The change a form submission asks for, in one shape.
   *
   * A value equal to the tool's is no change, so putting the tool's back
   * undoes it; a dismissal makes the severity moot; an emptied text field
   * also means the tool's.
   *
   * @param array<string, mixed> $input
   *   severity ('' for the tool's), dismissed, summary, evidence.
   * @param array{severity: string, summary: string, evidence: string} $tool
   *   The tool's values.
   *
   * @return array{severity: ?string, dismissed: bool, summary: ?string, evidence: ?string}
   */
  public static function normalize(array $input, array $tool): array {
    $dismissed = !empty($input['dismissed']);
    $severity = strtolower(trim((string) ($input['severity'] ?? '')));
    $text = static function (string $key) use ($input, $tool): ?string {
      $value = trim((string) ($input[$key] ?? ''));
      return $value === '' || $value === trim($tool[$key]) ? NULL : $value;
    };
    return [
      'severity' => !$dismissed && in_array($severity, self::SEVERITIES, TRUE) && $severity !== strtolower($tool['severity']) ? $severity : NULL,
      'dismissed' => $dismissed,
      'summary' => $text('summary'),
      'evidence' => $text('evidence'),
    ];
  }

  /**
   * Whether a change needs a note, the reason, and has none.
   *
   * @param array{severity: ?string, dismissed: bool, summary: ?string, evidence: ?string} $change
   */
  public static function needsNote(array $change, string $note): bool {
    return ($change['dismissed'] || $change['severity'] !== NULL) && trim($note) === '';
  }

  /**
   * Whether a change changes anything.
   *
   * @param array{severity: ?string, dismissed: bool, summary: ?string, evidence: ?string} $change
   */
  public static function isChanged(array $change): bool {
    return $change['dismissed'] || $change['severity'] !== NULL || $change['summary'] !== NULL || $change['evidence'] !== NULL;
  }

  /**
   * The change stored on a finding, in normalize()'s shape.
   *
   * @return array{severity: ?string, dismissed: bool, summary: ?string, evidence: ?string}
   */
  public static function stored(FieldableEntityInterface $finding): array {
    $value = static function (string $field) use ($finding): ?string {
      $v = $finding->hasField($field) ? trim((string) ($finding->get($field)->value ?? '')) : '';
      return $v !== '' ? $v : NULL;
    };
    return [
      'severity' => $value('field_rvf_override_severity'),
      'dismissed' => $finding->hasField('field_rvf_dismissed') && (bool) $finding->get('field_rvf_dismissed')->value,
      'summary' => $value('field_rvf_override_summary'),
      'evidence' => $value('field_rvf_override_evidence'),
    ];
  }

  /**
   * The revision log line for going from one change to another, or NULL.
   *
   * @param array{severity: ?string, dismissed: bool, summary: ?string, evidence: ?string} $from
   * @param array{severity: ?string, dismissed: bool, summary: ?string, evidence: ?string} $to
   */
  public static function describe(string $rule, string $toolSeverity, array $from, array $to): ?string {
    if ($from === $to) {
      return NULL;
    }
    if (!self::isChanged($to)) {
      return sprintf('undid the change to %s', $rule);
    }
    $parts = [];
    if ($to['dismissed'] && !$from['dismissed']) {
      $parts[] = sprintf('dismissed %s', $rule);
    }
    elseif (!$to['dismissed'] && $from['dismissed']) {
      $parts[] = sprintf('restored %s', $rule);
    }
    if ($to['severity'] !== $from['severity'] && !$to['dismissed']) {
      $parts[] = $to['severity'] !== NULL
        ? sprintf('changed %s from %s to %s', $rule, ucfirst($toolSeverity), ucfirst($to['severity']))
        : sprintf("put %s back to the review's severity", $rule);
    }
    if ($to['summary'] !== $from['summary'] || $to['evidence'] !== $from['evidence']) {
      $parts[] = sprintf('edited the wording of %s', $rule);
    }
    return $parts !== [] ? implode(', ', $parts) : NULL;
  }

  /**
   * Stores a change on a finding; the caller saves it.
   *
   * @param array{severity: ?string, dismissed: bool, summary: ?string, evidence: ?string} $change
   *
   * @return string|null
   *   The revision log line, or NULL when nothing changed.
   */
  public static function apply(FieldableEntityInterface $finding, array $change, int $uid, int $time): ?string {
    $line = self::describe(
      (string) ($finding->get('field_rvf_rule')->value ?? ''),
      (string) ($finding->get('field_rvf_severity')->value ?? ''),
      self::stored($finding),
      $change,
    );
    if ($line === NULL) {
      return NULL;
    }
    $changed = self::isChanged($change);
    $finding->set('field_rvf_override_severity', $change['severity']);
    $finding->set('field_rvf_dismissed', $change['dismissed']);
    $finding->set('field_rvf_override_summary', $change['summary']);
    $finding->set('field_rvf_override_evidence', $change['evidence']);
    $finding->set('field_rvf_override_by', $changed ? $uid : NULL);
    $finding->set('field_rvf_override_at', $changed ? $time : NULL);
    return $line;
  }

}
