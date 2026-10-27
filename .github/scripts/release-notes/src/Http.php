<?php

/**
 * A minimal JSON HTTP client over an injectable transport.
 *
 * The transport is a callable (method, url, headers, body) returning
 * ['status' => int, 'body' => string]; the default uses curl. Tests pass a
 * fake so nothing reaches the network.
 */
final class Http {

  /** @var callable(string, string, array<string, string>, ?string): array{status: int, body: string} */
  private $send;

  public function __construct(?callable $send = NULL) {
    $this->send = $send ?? self::curl(...);
  }

  /**
   * @param array<string, string> $headers
   * @param array<string, mixed>|null $body
   *   Sent as JSON when given.
   *
   * @return array<mixed>
   *   The decoded JSON response.
   *
   * @throws \RuntimeException
   *   On a transport error, a non-2xx status or invalid JSON.
   */
  public function json(string $method, string $url, array $headers, ?array $body = NULL): array {
    $headers += ['Accept' => 'application/json'];
    $payload = NULL;
    if ($body !== NULL) {
      $headers += ['Content-Type' => 'application/json'];
      $payload = json_encode($body, JSON_THROW_ON_ERROR);
    }
    $response = ($this->send)($method, $url, $headers, $payload);
    if ($response['status'] < 200 || $response['status'] >= 300) {
      throw new \RuntimeException("$method $url returned HTTP {$response['status']}");
    }
    try {
      $data = json_decode($response['body'], TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw new \RuntimeException("$method $url returned invalid JSON", 0, $e);
    }
    return is_array($data) ? $data : [];
  }

  /**
   * @param array<string, string> $headers
   *
   * @return array{status: int, body: string}
   */
  private static function curl(string $method, string $url, array $headers, ?string $body): array {
    $ch = curl_init($url);
    $lines = [];
    foreach ($headers as $name => $value) {
      $lines[] = "$name: $value";
    }
    curl_setopt_array($ch, [
      CURLOPT_CUSTOMREQUEST => $method,
      CURLOPT_HTTPHEADER => $lines,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_USERAGENT => 'connectci-release-notes',
    ]);
    if ($body !== NULL) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $result = curl_exec($ch);
    if ($result === FALSE) {
      throw new \RuntimeException("$method $url failed: " . curl_error($ch));
    }
    return ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => (string) $result];
  }

}
