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
      'browse link in body' => [['head' => ['ref' => 'fix-y'], 'title' => 'Fix import', 'body' => 'Issue link https://cyberteamportal.atlassian.net/browse/D8-2900'], 'D8-2900'],
    ];
  }

}
