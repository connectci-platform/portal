<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeSend.php';

/**
 * Lists merged PRs from the GitHub REST API.
 */
class GitHubClientTest extends TestCase {

  private static function pr(int $n, ?string $mergedAt, string $updatedAt, string $head = 'md-1'): array {
    return ['number' => $n, 'merged_at' => $mergedAt, 'updated_at' => $updatedAt, 'head' => ['ref' => $head], 'base' => ['ref' => 'main'], 'title' => "#$n", 'body' => '', 'labels' => []];
  }

  private static function page(array $prs): array {
    return ['status' => 200, 'body' => json_encode($prs)];
  }

  public function testStopsPagingPastWindow(): void {
    $prs = [];
    for ($i = 0; $i < 99; $i++) {
      $prs[] = self::pr(1000 + $i, '2026-10-23T10:00:00Z', '2026-10-23T10:00:00Z');
    }
    $prs[] = self::pr(999, '2026-10-01T10:00:00Z', '2026-10-01T10:00:00Z');
    $send = new FakeSend([self::page($prs), self::page($prs)]);
    $client = new GitHubClient(new Http($send), 'connectci-platform/portal', 'token');
    $client->mergedInto('main', '2026-10-22T00:00:00Z', '2026-10-24T00:00:00Z');
    $this->assertCount(1, $send->requests);
    $this->assertStringContainsString('/repos/connectci-platform/portal/pulls?state=closed&base=main&sort=updated&direction=desc&per_page=100&page=1', $send->requests[0]['url']);
  }

  public function testFiltersByMergedAt(): void {
    $send = new FakeSend([self::page([
      self::pr(1, NULL, '2026-10-23T10:00:00Z'),
      self::pr(2, '2026-10-25T10:00:00Z', '2026-10-25T10:00:00Z'),
      self::pr(3, '2026-10-23T10:00:00Z', '2026-10-23T10:00:00Z'),
      self::pr(4, '2026-10-21T10:00:00Z', '2026-10-23T11:00:00Z'),
    ])]);
    $client = new GitHubClient(new Http($send), 'connectci-platform/portal', 'token');
    $numbers = array_map(fn ($pr) => $pr['number'], $client->mergedInto('main', '2026-10-22T00:00:00Z', '2026-10-24T00:00:00Z'));
    $this->assertSame([3], $numbers);
  }

  public function testLastMdDevMerge(): void {
    $send = new FakeSend([self::page([
      self::pr(2290, '2026-10-23T20:00:00Z', '2026-10-23T20:00:00Z', 'md-dev'),
      self::pr(2269, '2026-10-16T12:00:00Z', '2026-10-16T12:00:00Z', 'md-dev'),
      self::pr(2259, '2026-10-14T12:00:00Z', '2026-10-14T12:00:00Z', 'md-dev'),
    ])]);
    $client = new GitHubClient(new Http($send), 'connectci-platform/portal', 'token');
    $this->assertSame('2026-10-16T12:00:00Z', $client->lastMdDevMergeAtOrBefore('2026-10-22T00:00:00Z'));
    $this->assertStringContainsString('head=connectci-platform%3Amd-dev', $send->requests[0]['url']);
  }

  public function testLastMdDevMergeNone(): void {
    $client = new GitHubClient(new Http(new FakeSend([self::page([])])), 'connectci-platform/portal', 'token');
    $this->assertSame('1970-01-01T00:00:00Z', $client->lastMdDevMergeAtOrBefore('2026-10-22T00:00:00Z'));
  }

  public function testThrowsOnHttpError(): void {
    $client = new GitHubClient(new Http(new FakeSend([['status' => 500, 'body' => 'oops']])), 'connectci-platform/portal', 'token');
    $this->expectException(RuntimeException::class);
    $client->mergedInto('main', '2026-10-22T00:00:00Z', '2026-10-24T00:00:00Z');
  }

}
