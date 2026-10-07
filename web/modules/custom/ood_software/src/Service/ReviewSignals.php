<?php

namespace Drupal\ood_software\Service;

/**
 * The review data the public catalog cache carries per app.
 *
 * Pure functions over arrays, so the rules with public consequences are
 * unit-testable: which verdict belongs to which app, when a review counts as
 * out of date, and exactly what is emitted. The emitted object carries the
 * stored enum keys and resolved links, never display words (those live in
 * the catalog front end, one constant) and never anything from a Draft
 * review (AppverseCacheService only passes Published ones in).
 */
final class ReviewSignals {

  /**
   * The per-app axes the cache emits.
   *
   * No security axis: the public display shows no security level (artifact
   * schema 1.2).
   */
  const AXES = ['portability', 'documentation'];

  /**
   * The verdict for an app: by app node first, then by the subpath the tool
   * used as app_id, where an empty subpath is the "root" app.
   *
   * @param array<int, array{app_ref: ?int, app_id: string, axes: array<string, mixed>}> $verdicts
   * @return array<mixed>
   */
  public static function pickVerdict(array $verdicts, int $appNid, string $subpath): ?array {
    foreach ($verdicts as $verdict) {
      if (($verdict['app_ref'] ?? NULL) !== NULL && (int) $verdict['app_ref'] === $appNid) {
        return $verdict;
      }
    }
    $wanted = trim($subpath, '/');
    $wanted = $wanted === '' ? 'root' : $wanted;
    foreach ($verdicts as $verdict) {
      if (trim($verdict['app_id'], '/') === $wanted) {
        return $verdict;
      }
    }
    return NULL;
  }

  /**
   * How many of a list of findings are security findings to review.
   *
   * The review page's rule (ReviewPageForm::findingArray()): a reviewer's
   * finding sits in the block it was filed under, the tool's by its rule
   * code, so security means an OODT rule; it is one to review when it failed
   * or warned (ReviewPageData::isDefect()).
   *
   * Pure.
   *
   * @param array<int, array<string, mixed>> $findings
   *   Each with source, rule, aspect, category and result.
   */
  public static function securityCount(array $findings): int {
    $n = 0;
    foreach ($findings as $finding) {
      $filed = ($finding['source'] ?? '') === 'reviewer'
        ? ReviewPageData::blockFromFields($finding['aspect'] ?? NULL, $finding['category'] ?? NULL)
        : NULL;
      $block = $filed ?? ReviewPageData::blockFor((string) ($finding['rule'] ?? ''));
      if ($block === 'security' && ReviewPageData::isDefect($finding)) {
        $n++;
      }
    }
    return $n;
  }

  /**
   * A review is out of date when the repo has a commit after it.
   */
  public static function isOutOfDate(int $reviewedAt, ?int $lastCommit): bool {
    if ($reviewedAt <= 0 || $lastCommit === NULL) {
      return FALSE;
    }
    return $lastCommit > $reviewedAt;
  }

  /**
   * The per-app review object for the cache, or NULL without a verdict.
   *
   * Each axis links into the public review page: the app's section for
   * portability and documentation, the repository section for upkeep. The
   * page carries matching ids (`app-<app id>`, `maintenance`). The HTML
   * report it used to link to is no longer imported (appverse-planning#42).
   *
   * @param array<string, mixed> $review
   *   reviewed_at (int), sha, url (the review page), upkeep
   *   (level/summary).
   * @param array<string, mixed>|null $verdict
   *   As pickVerdict() returns it.
   * @param int|null $lastCommit
   *   The repo's last commit time.
   *
   * @return array<string, mixed>|null
   *   The review object, or NULL without a verdict.
   */
  public static function shape(array $review, ?array $verdict, ?int $lastCommit): ?array {
    if ($verdict === NULL) {
      return NULL;
    }
    $url = (string) ($review['url'] ?? '');
    $appId = trim((string) ($verdict['app_id'] ?? ''), '/');
    $appSection = 'app-' . ($appId === '' ? 'root' : $appId);
    $axis = function (array $a, string $section) use ($url): array {
      return [
        'level' => $a['level'] ?? NULL,
        'summary' => (string) ($a['summary'] ?? ''),
        'anchor' => $url !== '' ? $url . '#' . $section : '',
      ];
    };
    $out = [
      'reviewedAt' => (int) ($review['reviewed_at'] ?? 0),
      'sha7' => substr((string) ($review['sha'] ?? ''), 0, 7),
      'url' => $url,
      'outOfDate' => self::isOutOfDate((int) ($review['reviewed_at'] ?? 0), $lastCommit),
    ];
    // Security has no level, only the findings to review: the app's own plus
    // the repo-level ones, which apply to every app. The count is what the
    // review page lists, so the two always agree.
    $out['security'] = [
      'count' => (int) ($verdict['security'] ?? 0) + (int) ($review['repo_security'] ?? 0),
      'anchor' => $url !== '' ? $url . '#' . $appSection : '',
    ];
    foreach (self::AXES as $name) {
      $out[$name] = $axis($verdict['axes'][$name] ?? [], $appSection);
    }
    // A monorepo app's own upkeep when the review scored it, linked to the
    // app's section; otherwise the repo's (appverse-planning#58).
    $out['upkeep'] = !empty($verdict['upkeep']['level'])
      ? $axis($verdict['upkeep'], $appSection)
      : $axis($review['upkeep'] ?? [], 'maintenance');
    return $out;
  }
}
