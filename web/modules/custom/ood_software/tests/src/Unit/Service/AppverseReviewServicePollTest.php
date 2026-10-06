<?php

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ood_software\Service\AppverseReviewSeeder;
use Drupal\ood_software\Service\AppverseReviewService;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\AbstractLogger;

/**
 * Unit tests for AppverseReviewService::pollForResults().
 *
 * The cron poll must keep polling a review it has already seen running (an
 * in_progress node) until the run completes, and must log what it did with
 * every review it polled, so a cron run can be read back from the log.
 * Completed runs here conclude in failure, so the loop is exercised end to
 * end without the artifact download or the seeder.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Service\AppverseReviewService
 */
class AppverseReviewServicePollTest extends UnitTestCase {

  const NID = 12319;
  const DISPATCHED_AT = 1790000000;
  const NOW = self::DISPATCHED_AT + 600;

  /** @var array<int, array<int, mixed>> */
  protected array $queryConditions = [];
  /** @var array<int, array{0: string, 1: mixed}> */
  protected array $freshSets = [];
  /** @var string[] */
  protected array $httpGets = [];
  /** @var array<int, array{0: string, 1: string}> */
  protected array $logs = [];

  /**
   * @param string|null $status
   *   The node's field_review_status, or NULL for no pending node at all.
   * @param array<string, array<int, array<string, mixed>>> $runsByStatus
   *   Workflow runs the API returns, keyed by the status filter.
   */
  protected function makeService(?string $status, array $runsByStatus = []): AppverseReviewService {
    $http = $this->createMock(Client::class);
    $http->method('request')->willReturnCallback(function (string $method, string $url) use ($runsByStatus) {
      $this->httpGets[] = $url;
      parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
      $stream = $this->createMock(StreamInterface::class);
      $stream->method('getContents')->willReturn(json_encode([
        'workflow_runs' => $runsByStatus[$query['status']] ?? [],
      ]));
      $response = $this->createMock(ResponseInterface::class);
      $response->method('getBody')->willReturn($stream);
      return $response;
    });

    $key = $this->createMock(KeyInterface::class);
    $key->method('getKeyValue')->willReturn('ghp_test');
    $keys = $this->createMock(KeyRepositoryInterface::class);
    $keys->method('getKey')->willReturn($key);

    // Collects each log line into $this->logs as [level, message].
    $logger = new class(function (string $level, string $message): void {
      $this->logs[] = [$level, $message];
    }) extends AbstractLogger {

      public function __construct(private \Closure $sink) {}

      /**
       * {@inheritdoc}
       *
       * @param array<string, mixed> $context
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        ($this->sink)((string) $level, strtr((string) $message, $context));
      }

    };
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    $query = $this->createMock(QueryInterface::class);
    // Field, value, operator; the mock also passes the defaulted $langcode.
    $query->method('condition')->willReturnCallback(function (...$args) use ($query) {
      $this->queryConditions[] = array_slice($args, 0, 3);
      return $query;
    });
    $query->method('accessCheck')->willReturn($query);
    $query->method('execute')->willReturn($status === NULL ? [] : [self::NID => self::NID]);

    // The fresh copy updateNodeReviewStatus() loads and mutates.
    $fresh = $this->createMock(NodeInterface::class);
    $fresh->method('hasField')->willReturn(TRUE);
    $fresh->method('set')->willReturnCallback(function (string $field, $value) use ($fresh) {
      $this->freshSets[] = [$field, $value];
      return $fresh;
    });

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn($status === NULL ? [] : [self::NID => $this->makeRepoNode($status)]);
    $storage->method('loadUnchanged')->with(self::NID)->willReturn($fresh);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('node')->willReturn($storage);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(self::NOW);

    return new AppverseReviewService(
      $http, $keys, $loggerFactory, $etm, $time,
      $this->createMock(FileSystemInterface::class),
      $this->createMock(AppverseReviewSeeder::class),
    );
  }

  protected function makeRepoNode(string $status): NodeInterface {
    $fields = [
      'field_review_status' => (object) ['value' => $status],
      'field_review_dispatched_at' => (object) ['value' => (string) self::DISPATCHED_AT],
    ];
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(self::NID);
    $node->method('hasField')->willReturn(TRUE);
    $node->method('get')->willReturnCallback(fn (string $field) => $fields[$field]);
    return $node;
  }

  /**
   * A run as the workflow-runs API returns it, titled for this node.
   *
   * @return array<string, mixed>
   *   The run.
   */
  protected function workflowRun(int $id, ?string $conclusion = NULL): array {
    $correlationId = AppverseReviewService::correlationId(self::NID, self::DISPATCHED_AT);
    return [
      'id' => $id,
      'display_title' => 'Review o/r · all · ' . $correlationId,
      'created_at' => gmdate('Y-m-d\TH:i:s\Z', self::DISPATCHED_AT + 5),
      'conclusion' => $conclusion,
    ];
  }

  /**
   * Messages logged at a level.
   *
   * @return string[]
   */
  protected function logged(string $level): array {
    return array_values(array_map(
      fn ($entry) => $entry[1],
      array_filter($this->logs, fn ($entry) => $entry[0] === $level),
    ));
  }

  /**
   * The loop moves a node to in_progress when it first sees the run running;
   * the next pass must still find that node and resolve it when the run
   * completes, or it stays in_progress forever.
   *
   * @covers ::pollForResults
   * @covers ::getPendingReviewNodes
   */
  public function testInProgressReviewIsStillPolledUntilItsRunCompletes(): void {
    $service = $this->makeService('in_progress', ['completed' => [$this->workflowRun(555, 'failure')]]);

    $service->pollForResults();

    $this->assertContains(['field_review_status', ['pending', 'in_progress'], 'IN'], $this->queryConditions);
    $this->assertContains(['field_review_status', 'error'], $this->freshSets);
    $this->assertContains(['field_review_run_id', 555], $this->freshSets);
    $info = $this->logged('info');
    $this->assertContains('Review poll: node 12319 matched completed run 555 (failure), dispatched 10 min ago.', $info);
    $this->assertContains('Review poll: 1 review(s) to poll against 1 completed and 0 running run(s): 1 completed.', $info);
  }

  /**
   * @covers ::pollForResults
   */
  public function testRunningReviewIsLoggedAndMarkedInProgress(): void {
    $service = $this->makeService('pending', ['in_progress' => [$this->workflowRun(556)]]);

    $service->pollForResults();

    $this->assertContains(['field_review_status', 'in_progress'], $this->freshSets);
    $info = $this->logged('info');
    $this->assertContains('Review poll: node 12319 run 556 is still running, dispatched 10 min ago.', $info);
    $this->assertContains('Review poll: 1 review(s) to poll against 0 completed and 1 running run(s): 1 running.', $info);
  }

  /**
   * A review with no matching run yet used to pass through silently.
   *
   * @covers ::pollForResults
   */
  public function testWaitingReviewIsLoggedWithTheIdItLookedFor(): void {
    $service = $this->makeService('pending');

    $service->pollForResults();

    $this->assertSame([], $this->freshSets);
    $info = $this->logged('info');
    $this->assertContains('Review poll: node 12319 has no completed or running run titled portal-12319-1790000000 yet, dispatched 10 min ago.', $info);
    $this->assertContains('Review poll: 1 review(s) to poll against 0 completed and 0 running run(s): 1 waiting.', $info);
  }

  /**
   * The poll runs every five minutes; with nothing to poll it must not fill
   * dblog at info level, and must not call GitHub.
   *
   * @covers ::pollForResults
   */
  public function testNothingToPollLogsAtDebugOnly(): void {
    $service = $this->makeService(NULL);

    $service->pollForResults();

    $this->assertSame([], $this->httpGets);
    $this->assertSame(['debug'], array_values(array_unique(array_column($this->logs, 0))));
    $this->assertSame(['Review poll: no pending or in-progress reviews.'], $this->logged('debug'));
  }

}
