<?php

/**
 * Reads ticket fields from Jira Cloud.
 */
final class JiraClient {

  private const FIELDS = ['summary', 'issuetype', 'labels', 'customfield_10020', 'parent'];

  public function __construct(
    private Http $http,
    private string $site,
    private string $email,
    private string $token,
  ) {}

  /**
   * @param list<string> $keys
   *   Ticket keys like 'D8-2878'. Fetched 100 at a time.
   *
   * @return array<string, array{summary: string, type: string, labels: list<string>, sprints: list<array{name: string, startDate: ?string}>, parent: ?string}>
   *
   * @throws \RuntimeException
   *   When Jira can't be reached or refuses the request.
   */
  public function fetch(array $keys): array {
    $tickets = [];
    foreach (array_chunk(array_values(array_unique($keys)), 100) as $chunk) {
      try {
        $issues = $this->search($chunk);
      }
      catch (\RuntimeException $e) {
        // Jira answers 400 for the whole query when any key doesn't exist or
        // isn't visible. Retry one key at a time so one bad key only costs
        // its own item; a key that still 400s is left out.
        if (!str_contains($e->getMessage(), 'HTTP 400')) {
          throw $e;
        }
        $issues = [];
        foreach ($chunk as $key) {
          try {
            $issues = [...$issues, ...$this->search([$key])];
          }
          catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'HTTP 400')) {
              throw $e;
            }
          }
        }
      }
      foreach ($issues as $issue) {
        $f = $issue['fields'];
        $tickets[$issue['key']] = [
          'summary' => (string) $f['summary'],
          'type' => (string) ($f['issuetype']['name'] ?? ''),
          'labels' => array_values($f['labels'] ?? []),
          'sprints' => array_map(fn ($s) => ['name' => (string) $s['name'], 'startDate' => $s['startDate'] ?? NULL], $f['customfield_10020'] ?? []),
          'parent' => $f['parent']['key'] ?? NULL,
        ];
      }
    }
    return $tickets;
  }

  /**
   * @param list<string> $keys
   *
   * @return list<array<string, mixed>>
   */
  private function search(array $keys): array {
    $data = $this->http->json('POST', rtrim($this->site, '/') . '/rest/api/3/search/jql', [
      'Authorization' => 'Basic ' . base64_encode("{$this->email}:{$this->token}"),
    ], [
      'jql' => 'key in (' . implode(',', $keys) . ')',
      'fields' => self::FIELDS,
      'maxResults' => 100,
    ]);
    return array_values($data['issues'] ?? []);
  }

}
