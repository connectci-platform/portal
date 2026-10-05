<?php

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
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
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for AppverseReviewService::dispatchForNode().
 *
 * The one place an on-demand review is started for a repo node: it must send
 * the correlation id the poll loop will look for, and record the same
 * timestamp on the node so the id can be recomputed later. Mocks follow
 * RepoSyncServiceTest: the node records set() calls, storage hands back a
 * fresh copy, the HTTP client captures the request.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Service\AppverseReviewService
 */
class AppverseReviewServiceDispatchTest extends UnitTestCase {

  const NID = 12319;
  const NOW = 1790000000;
  const REPO_URL = 'https://github.com/Sweet-and-Fizzy/appverse-example-monorepo';

  /** @var array<int, array{0: string, 1: mixed}> */
  protected array $freshSets = [];
  protected int $freshSaves = 0;
  protected ?array $postOptions = NULL;

  protected function makeService(int $httpStatus, ?AccountInterface $user = NULL): AppverseReviewService {
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn($httpStatus);
    // Guzzle 7's ClientInterface does not declare post(); it is a trait
    // method on the concrete Client, so that is what has to be mocked.
    $http = $this->createMock(Client::class);
    $http->method('post')->willReturnCallback(function (string $url, array $options) use ($response) {
      $this->postOptions = $options + ['url' => $url];
      return $response;
    });

    $key = $this->createMock(KeyInterface::class);
    $key->method('getKeyValue')->willReturn('ghp_test');
    $keys = $this->createMock(KeyRepositoryInterface::class);
    $keys->method('getKey')->willReturn($key);

    $logger = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    // The fresh copy recordDispatch() loads and mutates.
    $fresh = $this->createMock(NodeInterface::class);
    $fresh->method('hasField')->willReturn(TRUE);
    $fresh->method('set')->willReturnCallback(function (string $field, $value) use ($fresh) {
      $this->freshSets[] = [$field, $value];
      return $fresh;
    });
    $fresh->method('save')->willReturnCallback(function () {
      $this->freshSaves++;
      return 2;
    });
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadUnchanged')->with(self::NID)->willReturn($fresh);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('node')->willReturn($storage);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(self::NOW);

    return new AppverseReviewService(
      $http, $keys, $loggerFactory, $etm, $time,
      $this->createMock(FileSystemInterface::class),
      $this->createMock(AppverseReviewSeeder::class),
      $user,
    );
  }

  protected function makeRepoNode(string $url = self::REPO_URL): NodeInterface {
    $item = $this->createMock(FieldItemInterface::class);
    $item->method('getValue')->willReturn(['uri' => $url]);
    $list = $this->createMock(FieldItemListInterface::class);
    $list->method('isEmpty')->willReturn($url === '');
    $list->method('first')->willReturn($item);
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(self::NID);
    $node->method('bundle')->willReturn('appverse_repo');
    $node->method('hasField')->willReturn(TRUE);
    $node->method('get')->with('field_repo_url')->willReturn($list);
    return $node;
  }

  /**
   * @covers ::dispatchForNode
   */
  public function testDispatchSendsTheCorrelationIdAndRecordsTheSameTimestamp(): void {
    $service = $this->makeService(204);

    $this->assertTrue($service->dispatchForNode($this->makeRepoNode()));

    $inputs = $this->postOptions['json']['inputs'];
    $this->assertSame('Sweet-and-Fizzy/appverse-example-monorepo', $inputs['target_repo']);
    $this->assertSame(AppverseReviewService::correlationId(self::NID, self::NOW), $inputs['correlation_id']);
    // Not a live environment in a unit test, so the dispatch is a dry-run.
    $this->assertSame('dry-run', $inputs['review_aspects']);
    $this->assertSame('sonnet', $inputs['model']);

    $this->assertContains(['field_review_dispatched_at', self::NOW], $this->freshSets);
    $this->assertContains(['field_review_status', 'pending'], $this->freshSets);
    $this->assertSame(1, $this->freshSaves);
  }

  /**
   * The dispatch sends the model the ood_software.review_model setting names
   * for this environment; a value the workflow does not accept falls back to
   * sonnet rather than failing every dispatch.
   *
   * @covers ::dispatchForNode
   * @covers ::reviewModel
   */
  public function testDispatchSendsTheConfiguredModel(): void {
    try {
      new Settings(['ood_software.review_model' => 'qwen']);
      $this->assertTrue($this->makeService(204)->dispatchForNode($this->makeRepoNode()));
      $this->assertSame('qwen', $this->postOptions['json']['inputs']['model']);

      new Settings(['ood_software.review_model' => 'gpt-9']);
      $this->assertTrue($this->makeService(204)->dispatchForNode($this->makeRepoNode()));
      $this->assertSame('sonnet', $this->postOptions['json']['inputs']['model']);
    }
    finally {
      new Settings([]);
    }
  }

  /**
   * The repo records who started the run, so the imported review is
   * authored by them rather than the anonymous cron user; an anonymous
   * starter records no one.
   *
   * @covers ::dispatchForNode
   */
  public function testDispatchRecordsWhoStartedTheReview(): void {
    $user = $this->createMock(AccountInterface::class);
    $user->method('isAuthenticated')->willReturn(TRUE);
    $user->method('id')->willReturn(42);
    $this->assertTrue($this->makeService(204, $user)->dispatchForNode($this->makeRepoNode()));
    $this->assertContains(['field_review_dispatched_by', 42], $this->freshSets);

    $this->freshSets = [];
    $anonymous = $this->createMock(AccountInterface::class);
    $anonymous->method('isAuthenticated')->willReturn(FALSE);
    $this->assertTrue($this->makeService(204, $anonymous)->dispatchForNode($this->makeRepoNode()));
    $this->assertContains(['field_review_dispatched_by', NULL], $this->freshSets);
  }

  /**
   * A failed dispatch must not mark the node pending: the poll loop would
   * wait two hours for a run that was never created.
   *
   * @covers ::dispatchForNode
   */
  public function testFailedDispatchLeavesTheNodeUntouched(): void {
    $service = $this->makeService(500);

    $this->assertFalse($service->dispatchForNode($this->makeRepoNode()));

    $this->assertSame([], $this->freshSets);
    $this->assertSame(0, $this->freshSaves);
  }

  /**
   * @covers ::dispatchForNode
   */
  public function testNodeWithoutAGitHubUrlIsNotDispatched(): void {
    $service = $this->makeService(204);

    $this->assertFalse($service->dispatchForNode($this->makeRepoNode('')));

    $this->assertNull($this->postOptions);
    $this->assertSame(0, $this->freshSaves);
  }

}
