<?php

/**
 * Finds the D8 ticket a PR belongs to.
 *
 * Branch names (md-NNNN) are the most reliable carrier, so they win over the
 * title (#NNNN or D8-NNNN) and a Jira link in the PR body's Issue link
 * section.
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
    // Only the template's "## Issue link" section names the PR's own ticket;
    // links elsewhere (related PRs, context) point at other tickets.
    if (preg_match('/^##\s*Issue link\s*$(.*?)(?=^##\s|\z)/ims', $pr['body'] ?? '', $section)
      && preg_match('#/browse/D8-(\d+)\b#', $section[1], $m)) {
      return 'D8-' . $m[1];
    }
    return NULL;
  }

}
