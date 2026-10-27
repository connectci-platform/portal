<?php

/**
 * Lists merged PRs from the GitHub REST API.
 */
final class GitHubClient {

  public function __construct(
    private Http $http,
    private string $repo,
    private string $token,
  ) {}

  /**
   * PRs merged into $base with merge time in (after, until].
   *
   * Pages closed PRs newest-updated first and stops once a page reaches PRs
   * last updated at or before $after: a PR's merge time is never later than
   * its last update.
   *
   * @return list<array<string, mixed>>
   */
  public function mergedInto(string $base, string $after, string $until): array {
    $from = strtotime($after);
    $to = strtotime($until);
    $merged = [];
    foreach ($this->pages(['base' => $base]) as $page) {
      foreach ($page as $pr) {
        $at = $pr['merged_at'] !== NULL ? strtotime($pr['merged_at']) : NULL;
        if ($at !== NULL && $at > $from && $at <= $to) {
          $merged[] = $pr;
        }
      }
      if (count($page) < 100 || strtotime(end($page)['updated_at']) <= $from) {
        break;
      }
    }
    return $merged;
  }

  /**
   * Merge time of the latest md-dev to main PR at or before $at.
   *
   * @return string
   *   ISO time, or '1970-01-01T00:00:00Z' when there is none.
   */
  public function lastMdDevMergeAtOrBefore(string $at): string {
    $limit = strtotime($at);
    $owner = explode('/', $this->repo)[0];
    $best = NULL;
    foreach ($this->pages(['base' => 'main', 'head' => "$owner:md-dev"]) as $page) {
      foreach ($page as $pr) {
        if ($pr['merged_at'] !== NULL && strtotime($pr['merged_at']) <= $limit && ($best === NULL || strtotime($pr['merged_at']) > strtotime($best))) {
          $best = $pr['merged_at'];
        }
      }
      if (count($page) < 100 || ($best !== NULL && strtotime(end($page)['updated_at']) < strtotime($best))) {
        break;
      }
    }
    return $best ?? '1970-01-01T00:00:00Z';
  }

  /**
   * The time the PR that produced commit $sha was merged.
   *
   * A merge commit's own date is when GitHub built the test merge, often 20
   * minutes or more before the PR's merged_at, so it can't bound a window
   * that's compared against merged_at.
   *
   * @return string
   *   ISO time, or $fallback when no merged PR has $sha as its merge commit.
   */
  public function mergeTimeOf(string $sha, string $fallback): string {
    $prs = $this->http->json('GET', "https://api.github.com/repos/{$this->repo}/commits/$sha/pulls", $this->headers());
    foreach ($prs as $pr) {
      if (($pr['merge_commit_sha'] ?? NULL) === $sha && $pr['merged_at'] !== NULL) {
        return $pr['merged_at'];
      }
    }
    return $fallback;
  }

  /**
   * @return array<string, string>
   */
  private function headers(): array {
    return [
      'Authorization' => "Bearer {$this->token}",
      'Accept' => 'application/vnd.github+json',
      'X-GitHub-Api-Version' => '2022-11-28',
    ];
  }

  /**
   * @param array<string, string> $filters
   *
   * @return \Generator<list<array<string, mixed>>>
   */
  private function pages(array $filters): \Generator {
    for ($page = 1;; $page++) {
      $query = http_build_query(['state' => 'closed'] + $filters + ['sort' => 'updated', 'direction' => 'desc', 'per_page' => 100, 'page' => $page]);
      $prs = $this->http->json('GET', "https://api.github.com/repos/{$this->repo}/pulls?$query", $this->headers());
      if (!$prs) {
        return;
      }
      yield array_values($prs);
    }
  }

}
