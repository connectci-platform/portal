<?php

/**
 * A fake HTTP transport for Http: records requests, answers from a queue.
 */
final class FakeSend {

  /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
  public array $requests = [];

  /** @param list<array{status: int, body: string}> $responses */
  public function __construct(private array $responses) {}

  /** @return array{status: int, body: string} */
  public function __invoke(string $method, string $url, array $headers, ?string $body): array {
    $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
    return array_shift($this->responses) ?? ['status' => 200, 'body' => '[]'];
  }

}
