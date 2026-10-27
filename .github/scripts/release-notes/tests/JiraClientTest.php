<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeSend.php';

/**
 * Reads ticket fields from Jira Cloud.
 */
class JiraClientTest extends TestCase {

  private static function ok(string $body): array {
    return ['status' => 200, 'body' => $body];
  }

  public function testChunksKeysBy100(): void {
    $keys = array_map(fn ($n) => "D8-$n", range(1, 150));
    $send = new FakeSend([self::ok('{"issues":[]}'), self::ok('{"issues":[]}')]);
    (new JiraClient(new Http($send), 'https://cyberteamportal.atlassian.net', 'a@b.c', 't'))->fetch($keys);
    $this->assertCount(2, $send->requests);
    $this->assertSame('POST', $send->requests[0]['method']);
    $this->assertSame('https://cyberteamportal.atlassian.net/rest/api/3/search/jql', $send->requests[0]['url']);
    $first = json_decode($send->requests[0]['body'], TRUE);
    $this->assertSame(100, count(explode(',', trim(substr($first['jql'], strlen('key in (')), ')'))));
    $this->assertSame(['summary', 'issuetype', 'labels', 'customfield_10020', 'parent'], $first['fields']);
    $this->assertSame('Basic ' . base64_encode('a@b.c:t'), $send->requests[0]['headers']['Authorization']);
  }

  public function testShapesFields(): void {
    $send = new FakeSend([self::ok(file_get_contents(__DIR__ . '/fixtures/jira-raw.json'))]);
    $tickets = (new JiraClient(new Http($send), 'https://cyberteamportal.atlassian.net', 'a@b.c', 't'))->fetch(['D8-2878', 'D8-2930']);
    $this->assertSame([
      'summary' => 'Classroom Story: unable to add images when editing a story',
      'type' => 'Bug',
      'labels' => [],
      'sprints' => [['name' => 'OOD Hub Oct-Nov', 'startDate' => '2026-10-11T00:00:00.000Z']],
      'parent' => NULL,
    ], $tickets['D8-2878']);
    $this->assertSame('D8-2843', $tickets['D8-2930']['parent']);
    $this->assertSame([], $tickets['D8-2930']['sprints']);
    $this->assertSame(['security'], $tickets['D8-2930']['labels']);
  }

  public function testNoKeysNoRequest(): void {
    $send = new FakeSend([]);
    $this->assertSame([], (new JiraClient(new Http($send), 'https://x', 'a', 'b'))->fetch([]));
    $this->assertCount(0, $send->requests);
  }

  public function testThrowsOnHttpError(): void {
    $send = new FakeSend([['status' => 401, 'body' => 'Unauthorized']]);
    $this->expectException(RuntimeException::class);
    (new JiraClient(new Http($send), 'https://cyberteamportal.atlassian.net', 'a@b.c', 't'))->fetch(['D8-1']);
  }

  public function testUnknownKeyDoesNotSinkTheChunk(): void {
    $one = file_get_contents(__DIR__ . '/fixtures/jira-raw.json');
    $send = new FakeSend([
      ['status' => 400, 'body' => '{"errorMessages":["An issue with key \'D8-999999\' does not exist for field \'key\'."]}'],
      self::ok($one),
      ['status' => 400, 'body' => '{"errorMessages":["does not exist"]}'],
    ]);
    $tickets = (new JiraClient(new Http($send), 'https://cyberteamportal.atlassian.net', 'a@b.c', 't'))->fetch(['D8-2878', 'D8-999999']);
    $this->assertArrayHasKey('D8-2878', $tickets);
    $this->assertArrayNotHasKey('D8-999999', $tickets);
    $this->assertCount(3, $send->requests);
    $this->assertStringContainsString('key in (D8-2878)', json_decode($send->requests[1]['body'], TRUE)['jql']);
  }

  public function testNon400StillThrows(): void {
    $send = new FakeSend([['status' => 500, 'body' => 'down']]);
    $this->expectException(RuntimeException::class);
    (new JiraClient(new Http($send), 'https://cyberteamportal.atlassian.net', 'a@b.c', 't'))->fetch(['D8-1', 'D8-2']);
  }

}
