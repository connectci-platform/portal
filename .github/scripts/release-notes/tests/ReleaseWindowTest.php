<?php

use PHPUnit\Framework\TestCase;

/**
 * Picks the PRs a release carried to main.
 */
class ReleaseWindowTest extends TestCase {

  private static function pr(int $n, string $mergedAt, string $head, string $base): array {
    return [
      'number' => $n,
      'merged_at' => $mergedAt,
      'head' => ['ref' => $head],
      'base' => ['ref' => $base],
      'title' => "#$n",
      'body' => '',
      'labels' => [],
    ];
  }

  private static function numbers(array $prs): array {
    return array_map(fn ($pr) => $pr['number'], $prs);
  }

  public function testDirectMainPrsIncluded(): void {
    $main = [self::pr(2283, '2026-10-23T19:03:00Z', 'drupal/flag', 'main')];
    $this->assertSame([2283], self::numbers(ReleaseWindow::select($main, [], '2026-10-22T00:00:00Z')));
  }

  public function testMdDevPrsCarriedByAnchor(): void {
    $main = [self::pr(2290, '2026-10-23T20:00:00Z', 'md-dev', 'main')];
    $mdDev = [
      self::pr(2249, '2026-10-23T18:11:00Z', 'md-2832', 'md-dev'),
      self::pr(2278, '2026-10-23T21:00:00Z', 'md-2878', 'md-dev'),
    ];
    $this->assertSame([2249], self::numbers(ReleaseWindow::select($main, $mdDev, '2026-10-20T00:00:00Z')));
  }

  public function testMdDevPrBeforePreviousAnchorExcluded(): void {
    $main = [self::pr(2290, '2026-10-23T20:00:00Z', 'md-dev', 'main')];
    $mdDev = [self::pr(2240, '2026-10-19T00:00:00Z', 'md-2800', 'md-dev')];
    $this->assertSame([], self::numbers(ReleaseWindow::select($main, $mdDev, '2026-10-20T00:00:00Z')));
  }

  public function testNoMdDevAnchorMeansNoMdDevPrs(): void {
    $main = [
      self::pr(2285, '2026-10-23T18:52:00Z', 'drupal/views_bulk_operations', 'main'),
      self::pr(2286, '2026-10-23T18:52:30Z', 'drupal/openid_connect', 'main'),
    ];
    $mdDev = [self::pr(2281, '2026-10-23T14:59:00Z', 'gh-concurrency', 'md-dev')];
    $this->assertSame([2285, 2286], self::numbers(ReleaseWindow::select($main, $mdDev, '2026-10-20T00:00:00Z')));
  }

  public function testMdDevAnchorItselfExcluded(): void {
    $main = [
      self::pr(2269, '2026-10-21T00:00:00Z', 'md-dev', 'main'),
      self::pr(2290, '2026-10-23T20:00:00Z', 'md-dev', 'main'),
    ];
    $mdDev = [
      self::pr(2260, '2026-10-20T12:00:00Z', 'md-2860', 'md-dev'),
      self::pr(2281, '2026-10-23T14:59:00Z', 'gh-concurrency', 'md-dev'),
    ];
    $result = self::numbers(ReleaseWindow::select($main, $mdDev, '2026-10-20T00:00:00Z'));
    $this->assertSame([2260, 2281], $result);
    $this->assertNotContains(2269, $result);
    $this->assertNotContains(2290, $result);
  }

}
