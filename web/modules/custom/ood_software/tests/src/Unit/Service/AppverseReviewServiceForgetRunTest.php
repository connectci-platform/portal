<?php

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Service\AppverseReviewSeeder;
use Drupal\ood_software\Service\AppverseReviewService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for AppverseReviewService::forgetRun() and ::cancelRun().
 *
 * Withdrawing a repo forgets its review run (appverse-planning#46): the run
 * is cancelled on GitHub when its id is known, and the run fields, the
 * dispatch time included, are cleared so a re-submit is not debounced.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Service\AppverseReviewService
 */
class AppverseReviewServiceForgetRunTest extends UnitTestCase {

  /**
   * URLs the HTTP client was asked to POST.
   *
   * @var array<int, string>
   */
  protected array $posted = [];

  /**
   * The field => value pairs set on the repo.
   *
   * @var array<string, mixed>
   */
  protected array $sets = [];

  /**
   * Builds the service with an HTTP client that answers $status to a POST.
   */
  protected function makeService(int $status = 202): AppverseReviewService {
    $http = $this->createMock(Client::class);
    $http->method('request')->willReturnCallback(function (string $method, string $url) use ($status) {
      $this->posted[] = $url;
      if ($status >= 400) {
        throw new ClientException('Conflict', new Request('POST', $url), new Response($status));
      }
      return new Response($status);
    });
    $key = $this->createMock(KeyInterface::class);
    $key->method('getKeyValue')->willReturn('ghp_test');
    $keys = $this->createMock(KeyRepositoryInterface::class);
    $keys->method('getKey')->willReturn($key);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerInterface::class));

    return new AppverseReviewService(
      $http, $keys, $loggerFactory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(FileSystemInterface::class),
      $this->createMock(AppverseReviewSeeder::class),
    );
  }

  /**
   * A repo whose run id field holds $runId, recording what is set on it.
   */
  protected function makeRepo(string $runId): NodeInterface {
    $runField = $this->createMock(FieldItemListInterface::class);
    $runField->method('getString')->willReturn($runId);
    $repo = $this->createMock(NodeInterface::class);
    $repo->method('hasField')->willReturn(TRUE);
    $repo->method('get')->with('field_review_run_id')->willReturn($runField);
    $repo->method('set')->willReturnCallback(function (string $field, $value) use ($repo) {
      $this->sets[$field] = $value;
      return $repo;
    });
    return $repo;
  }

  /**
   * The run fields a withdraw clears.
   */
  protected function assertRunFieldsCleared(): void {
    foreach (['field_review_status', 'field_review_run_id', 'field_review_recommendation', 'field_review_dispatched_at'] as $field) {
      $this->assertArrayHasKey($field, $this->sets, "$field is cleared.");
      $this->assertNull($this->sets[$field], "$field is cleared.");
    }
  }

  /**
   * @covers ::forgetRun
   * @covers ::cancelRun
   */
  public function testForgetRunCancelsAKnownRunAndClearsTheFields(): void {
    $this->assertTrue($this->makeService(202)->forgetRun($this->makeRepo('4242')));
    $this->assertSame(['https://api.github.com/repos/Sweet-and-Fizzy/appverse-review/actions/runs/4242/cancel'], $this->posted);
    $this->assertRunFieldsCleared();
  }

  /**
   * @covers ::forgetRun
   */
  public function testForgetRunWithoutARunIdOnlyClearsTheFields(): void {
    $this->assertFalse($this->makeService()->forgetRun($this->makeRepo('')));
    $this->assertSame([], $this->posted);
    $this->assertRunFieldsCleared();
  }

  /**
   * @covers ::forgetRun
   * @covers ::cancelRun
   */
  public function testARunThatAlreadyFinishedStillClearsTheFields(): void {
    $this->assertFalse($this->makeService(409)->forgetRun($this->makeRepo('4242')));
    $this->assertCount(1, $this->posted);
    $this->assertRunFieldsCleared();
  }

}
