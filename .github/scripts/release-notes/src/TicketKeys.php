<?php

/**
 * Finds the D8 ticket a PR belongs to.
 *
 * Branch names (md-NNNN) are the most reliable carrier, so they win over the
 * title (#NNNN or D8-NNNN) and the Jira link in the PR body.
 */
final class TicketKeys {

  /**
   * @param array<string, mixed> $pr
   *   A GitHub pull request: head.ref, title, body.
   */
  public static function fromPr(array $pr): ?string {
    if (preg_match('/^md-(\d+)\b/', $pr['head']['ref'] ?? '', $m)) {
      return 'D8-' . $m[1];
    }
    if (preg_match('/\bD8-(\d+)\b|(?<!\w)#(\d+)\b/', $pr['title'] ?? '', $m)) {
      return 'D8-' . ($m[1] !== '' ? $m[1] : $m[2]);
    }
    if (preg_match('#/browse/D8-(\d+)\b#', $pr['body'] ?? '', $m)) {
      return 'D8-' . $m[1];
    }
    return NULL;
  }

}
