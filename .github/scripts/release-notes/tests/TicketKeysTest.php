<?php

use PHPUnit\Framework\TestCase;

/**
 * Finds the D8 ticket key a PR belongs to.
 */
class TicketKeysTest extends TestCase {

  /**
   * @dataProvider prProvider
   */
  public function testFromPr(array $pr, ?string $expected): void {
    $this->assertSame($expected, TicketKeys::fromPr($pr));
  }

  public static function prProvider(): array {
    return [
      'branch' => [['head' => ['ref' => 'md-2878'], 'title' => '#2878', 'body' => ''], 'D8-2878'],
      'branch wins over title' => [['head' => ['ref' => 'md-1957'], 'title' => '#2843', 'body' => ''], 'D8-1957'],
      'composer bump' => [['head' => ['ref' => 'drupal/flag'], 'title' => '[Composer] drupal/flag #nobuild', 'body' => ''], NULL],
      'non-numeric key' => [['head' => ['ref' => 'gh-concurrency'], 'title' => '#concurrency', 'body' => 'https://cyberteamportal.atlassian.net/browse/D8-concurrency'], NULL],
      'key in title' => [['head' => ['ref' => 'fix-x'], 'title' => 'Fix D8-2912 import', 'body' => NULL], 'D8-2912'],
      'browse link in issue link section' => [['head' => ['ref' => 'fix-y'], 'title' => 'Fix import', 'body' => "## Describe context\n\nText.\n\n## Issue link\n\nhttps://cyberteamportal.atlassian.net/browse/D8-2900\n\n## Any other related PRs?\n\n- none"], 'D8-2900'],
      'browse link only in related PRs is ignored' => [['head' => ['ref' => 'fix-z'], 'title' => 'Fix import', 'body' => "## Issue link\n\nNone\n\n## Any other related PRs?\n\n- https://cyberteamportal.atlassian.net/browse/D8-2908"], NULL],
      'browse link with no template sections is ignored' => [['head' => ['ref' => 'fix-w'], 'title' => 'Fix import', 'body' => 'See https://cyberteamportal.atlassian.net/browse/D8-2908'], NULL],
    ];
  }

}
