<?php

/**
 * Picks the PRs a release carried to main.
 *
 * Released means merged to main. PRs merged straight to main count as they
 * are. md-dev work reaches main only through an "Md dev" PR (head md-dev), so
 * an md-dev PR counts when it merged after the previous Md dev merge and no
 * later than the latest one in this release. Merge times come from the API,
 * which survives md-dev being force-pushed.
 */
final class ReleaseWindow {

  /**
   * @param list<array<string, mixed>> $mainPrs
   *   PRs merged into main within the release window.
   * @param list<array<string, mixed>> $mdDevPrs
   *   PRs merged into md-dev after $previousAnchorAt.
   * @param string $previousAnchorAt
   *   Merge time of the last Md dev PR before this release.
   *
   * @return list<array<string, mixed>>
   *   The released PRs, deduplicated and sorted by merge time.
   */
  public static function select(array $mainPrs, array $mdDevPrs, string $previousAnchorAt): array {
    $released = [];
    $lastAnchor = NULL;
    foreach ($mainPrs as $pr) {
      if (($pr['head']['ref'] ?? '') === 'md-dev') {
        $at = strtotime($pr['merged_at']);
        $lastAnchor = max($lastAnchor ?? $at, $at);
        continue;
      }
      $released[$pr['number']] = $pr;
    }
    if ($lastAnchor !== NULL) {
      $after = strtotime($previousAnchorAt);
      foreach ($mdDevPrs as $pr) {
        $at = strtotime($pr['merged_at']);
        if ($at > $after && $at <= $lastAnchor) {
          $released[$pr['number']] = $pr;
        }
      }
    }
    $released = array_values($released);
    usort($released, fn ($a, $b) => strtotime($a['merged_at']) <=> strtotime($b['merged_at']));
    return $released;
  }

}
