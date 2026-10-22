<?php

namespace Drupal\Tests\ood_contributions\Kernel\Traits;

use Drupal\ood_contributions\Service\DiscourseApiService;
use Drupal\ood_contributions\Service\DiscourseContributionService;
use Drupal\ood_contributions\Service\DiscourseStatsService;
use Drupal\ood_contributions\Service\DiscourseStorageService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Shared helpers: a routed fake Discourse over a Guzzle handler.
 *
 * The site timezone is America/New_York and "now" is fixed at
 * 2026-10-16 15:00 New York time (EDT, UTC-4), so results never depend on
 * when the test runs.
 */
trait DiscourseTestTrait {

  /**
   * The module list shared by the Discourse kernel tests.
   *
   * @var string[]
   */
  protected static $discourseModules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'options', 'datetime',
    'link', 'taxonomy', 'path', 'path_alias', 'content_moderation', 'workflows',
    'key', 'flag', 'file', 'ood_software', 'ood_contributions',
  ];

  /**
   * Fake posts for /posts.json, each ['id' => int, 'created_at' => string].
   *
   * @var array<int, array<string, mixed>>
   */
  protected array $posts = [];

  /**
   * The value of about.stats.posts_30_days, or NULL to omit.
   */
  protected ?int $posts30Days = NULL;

  /**
   * Fake /u/{name}.json bodies keyed by username.
   *
   * @var array<string, array<string, mixed>>
   */
  protected array $userJson = [];

  /**
   * Fake /u/{name}/summary.json bodies keyed by username.
   *
   * @var array<string, array<string, mixed>>
   */
  protected array $summaryJson = [];

  /**
   * When TRUE, every request fails with HTTP 500.
   */
  protected bool $httpFails = FALSE;

  /**
   * Request paths seen by the fake, in order.
   *
   * @var string[]
   */
  protected array $requested = [];

  /**
   * Fixed current time.
   */
  protected int $now = 0;

  /**
   * Sets the site timezone and fixed clock. Call from setUp().
   */
  protected function setUpDiscourseClock(): void {
    $this->config('system.date')->set('timezone.default', 'America/New_York')->save();
    $this->now = (new \DateTimeImmutable('2026-10-16 15:00:00', new \DateTimeZone('America/New_York')))->getTimestamp();
  }

  /**
   * Builds a Guzzle client that answers from the fake Discourse state.
   */
  protected function fakeHttpClient(): Client {
    $stack = HandlerStack::create(function (RequestInterface $request, array $options) {
      $path = $request->getUri()->getPath();
      parse_str($request->getUri()->getQuery(), $query);
      $this->requested[] = $path;

      if ($this->httpFails) {
        return Create::rejectionFor(new ServerException('Server error', $request, new Response(500)));
      }
      return Create::promiseFor(new Response(200, [], json_encode($this->route($path, $query))));
    });
    return new Client(['handler' => $stack, 'http_errors' => TRUE]);
  }

  /**
   * Routes a fake request to a response body.
   *
   * @param string $path
   *   The URL path.
   * @param array<string, mixed> $query
   *   The query parameters.
   *
   * @return array<string, mixed>
   *   The JSON body.
   */
  protected function route(string $path, array $query): array {
    if ($path === '/posts.json') {
      $before = isset($query['before']) ? (int) $query['before'] : PHP_INT_MAX;
      $page = array_values(array_filter($this->posts, fn($p) => $p['id'] < $before));
      usort($page, fn($a, $b) => $b['id'] <=> $a['id']);
      return ['latest_posts' => array_slice($page, 0, 50)];
    }
    if ($path === '/about.json') {
      $stats = $this->posts30Days === NULL ? [] : ['posts_30_days' => $this->posts30Days];
      return ['about' => ['stats' => $stats]];
    }
    if (preg_match('#^/u/([^/]+)/summary\.json$#', $path, $m)) {
      return $this->summaryJson[urldecode($m[1])] ?? [];
    }
    if (preg_match('#^/u/([^/]+)\.json$#', $path, $m)) {
      return $this->userJson[urldecode($m[1])] ?? [];
    }
    return [];
  }

  /**
   * Adds a fake post.
   *
   * @param string $created_at
   *   ISO 8601 UTC timestamp, as Discourse returns it.
   */
  protected function addPost(string $created_at): void {
    $this->posts[] = ['id' => count($this->posts) + 1, 'created_at' => $created_at];
  }

  /**
   * Builds the fixed-clock time service.
   */
  protected function fakeTime(): TimeInterface {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturnCallback(fn() => $this->now);
    return $time;
  }

  /**
   * Builds the API service over the fake client.
   */
  protected function apiService(): DiscourseApiService {
    return new DiscourseApiService(
      $this->fakeHttpClient(),
      $this->container->get('logger.factory'),
      $this->container->get('config.factory'),
    );
  }

  /**
   * Builds the stats service over the fake client and fixed clock.
   */
  protected function statsService(): DiscourseStatsService {
    return new DiscourseStatsService(
      $this->container->get('database'),
      $this->apiService(),
      $this->container->get('entity_type.manager'),
      $this->container->get('logger.factory'),
      $this->container->get('config.factory'),
      $this->fakeTime(),
    );
  }

  /**
   * Builds the contribution service over the fake client.
   */
  protected function contributionService(): DiscourseContributionService {
    return new DiscourseContributionService(
      $this->container->get('entity_type.manager'),
      $this->apiService(),
      new DiscourseStorageService($this->container->get('database'), $this->container->get('logger.factory')),
      $this->container->get('logger.factory'),
    );
  }

  /**
   * Reads stored daily counts keyed by date.
   *
   * @return array<string, int>
   *   Counts keyed by Y-m-d.
   */
  protected function storedCounts(): array {
    $rows = $this->container->get('database')->select('ood_disc_daily_posts', 'd')
      ->fields('d', ['post_date', 'post_count'])
      ->orderBy('post_date')
      ->execute()
      ->fetchAllKeyed();
    return array_map('intval', $rows);
  }

  /**
   * Writes a stored daily count directly.
   */
  protected function seedCount(string $date, int $count): void {
    $this->container->get('database')->merge('ood_disc_daily_posts')
      ->key('post_date', $date)
      ->fields(['post_count' => $count, 'last_updated' => 1])
      ->execute();
  }

}
