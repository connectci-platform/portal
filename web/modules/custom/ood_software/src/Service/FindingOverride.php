<?php

namespace Drupal\ood_software\Service;

use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * A reviewer's change to an automated finding: a new severity, or a
 * dismissal, always with a reason (A1 in the 2026-10-07 guidelines alignment
 * plan).
 *
 * The tool's own values (field_rvf_severity, field_rvf_result) are never
 * written; the change sits beside them on the finding, so the record keeps
 * what the tool said and a later re-review can read both. Nothing carries
 * into the next round: a new review seeds fresh findings with no overrides.
 *
 * Everything that reads a finding uses the effective severity, and treats a
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
   * Choosing the tool's own severity is no change; a dismissal makes the
   * severity moot; with no change the reason is dropped, so undoing a change
   * clears it.
   *
   * @param array<string, mixed> $input
   *   severity ('' for the tool's), dismissed, reason.
   *
   * @return array{severity: ?string, dismissed: bool, reason: string}
   */
  public static function normalize(array $input, string $toolSeverity): array {
    $dismissed = !empty($input['dismissed']);
    $severity = strtolower(trim((string) ($input['severity'] ?? '')));
    $severity = !$dismissed && in_array($severity, self::SEVERITIES, TRUE) && $severity !== strtolower($toolSeverity) ? $severity : NULL;
    $reason = $dismissed || $severity !== NULL ? trim((string) ($input['reason'] ?? '')) : '';
    return ['severity' => $severity, 'dismissed' => $dismissed, 'reason' => $reason];
  }

  /**
   * Why a change cannot be saved, or NULL.
   *
   * @param array{severity: ?string, dismissed: bool, reason: string} $change
   */
  public static function error(array $change): ?string {
    return ($change['dismissed'] || $change['severity'] !== NULL) && $change['reason'] === ''
      ? 'Give a reason for changing an automated finding.'
      : NULL;
  }

  /**
   * The change stored on a finding, in normalize()'s shape.
   *
   * @return array{severity: ?string, dismissed: bool, reason: string}
   */
  public static function stored(FieldableEntityInterface $finding): array {
    $value = fn (string $field) => $finding->hasField($field) ? $finding->get($field)->value : NULL;
    $severity = (string) ($value('field_rvf_override_severity') ?? '');
    return [
      'severity' => $severity !== '' ? $severity : NULL,
      'dismissed' => (bool) $value('field_rvf_dismissed'),
      'reason' => trim((string) ($value('field_rvf_override_reason') ?? '')),
    ];
  }

  /**
   * The revision log line for going from one change to another, or NULL.
   *
   * @param array{severity: ?string, dismissed: bool, reason: string} $from
   * @param array{severity: ?string, dismissed: bool, reason: string} $to
   */
  public static function describe(string $rule, string $toolSeverity, array $from, array $to): ?string {
    if ($from === $to) {
      return NULL;
    }
    if ($to['dismissed']) {
      return $from['dismissed'] ? sprintf('changed the reason on %s', $rule) : sprintf('dismissed %s', $rule);
    }
    if ($to['severity'] !== NULL) {
      return $from['severity'] === $to['severity'] && !$from['dismissed']
        ? sprintf('changed the reason on %s', $rule)
        : sprintf('changed %s from %s to %s', $rule, ucfirst($toolSeverity), ucfirst($to['severity']));
    }
    return sprintf('undid the change to %s', $rule);
  }

  /**
   * Stores a change on a finding; the caller saves it.
   *
   * @param array{severity: ?string, dismissed: bool, reason: string} $change
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
    $changed = $change['dismissed'] || $change['severity'] !== NULL;
    $finding->set('field_rvf_override_severity', $change['severity']);
    $finding->set('field_rvf_dismissed', $change['dismissed']);
    $finding->set('field_rvf_override_reason', $changed ? $change['reason'] : NULL);
    $finding->set('field_rvf_override_by', $changed ? $uid : NULL);
    $finding->set('field_rvf_override_at', $changed ? $time : NULL);
    return $line;
  }

}
