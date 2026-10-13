<?php

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Service\AppverseReviewService;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * When a moderation change starts an AI run.
 *
 * Only entering ready_for_review from another state starts one, never while
 * a run is pending, and never a save that stays in ready_for_review: the app
 * updater, Re-sync and a contributor's edit save repos awaiting review, and
 * each used to start a run that superseded the review being curated.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Service\AppverseReviewService
 */
class AppverseReviewServiceOnTransitionTest extends UnitTestCase {

  /**
   * How many runs the service started.
   */
  protected int $dispatched = 0;

  /**
   * @covers ::onTransition
   * @dataProvider transitions
   */
  public function testOnTransition(?string $previous, string $now, string $status, int $dispatchedAgo, int $expected): void {
    $this->service()->onTransition($this->repo($now, $status, $dispatchedAgo), $previous);
    $this->assertSame($expected, $this->dispatched);
  }

  /**
   * Previous state, new state, run status, seconds since the last dispatch,
   * runs started.
   *
   * @return array<string, array<int, mixed>>
   */
  public static function transitions(): array {
    return [
      'submitted from draft' => ['draft', 'ready_for_review', '', 0, 1],
      're-submitted after changes' => ['needs_adjustment', 'ready_for_review', 'complete', 3600, 1],
      'a save that stays awaiting review' => ['ready_for_review', 'ready_for_review', 'complete', 3600, 0],
      'not while a run is pending' => ['needs_adjustment', 'ready_for_review', 'pending', 3600, 0],
      'not while a run is in progress' => ['draft', 'ready_for_review', 'in_progress', 3600, 0],
      'not within the debounce' => ['draft', 'ready_for_review', '', 60, 0],
      'leaving review' => ['ready_for_review', 'needs_adjustment', '', 0, 0],
      'unknown previous state' => [NULL, 'ready_for_review', '', 0, 0],
    ];
  }

  /**
   * The service, with the dispatch counted instead of sent.
   */
  protected function service(): AppverseReviewService {
    $service = $this->getMockBuilder(AppverseReviewService::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['dispatchForNode'])
      ->getMock();
    $service->method('dispatchForNode')->willReturnCallback(function (): bool {
      $this->dispatched++;
      return TRUE;
    });
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(100000);
    foreach (['logger' => $this->createMock(LoggerInterface::class), 'time' => $time] as $name => $value) {
      $property = new \ReflectionProperty(AppverseReviewService::class, $name);
      $property->setValue($service, $value);
    }
    return $service;
  }

  /**
   * A repo in a moderation state, with a run status and a last dispatch.
   */
  protected function repo(string $state, string $status, int $dispatchedAgo): NodeInterface {
    $field = function ($value): FieldItemListInterface {
      $list = $this->createMock(FieldItemListInterface::class);
      $list->method('__get')->with('value')->willReturn($value);
      // `->value ?? ''` asks __isset first, as Drupal's field lists answer.
      $list->method('__isset')->with('value')->willReturn($value !== NULL);
      $list->method('isEmpty')->willReturn($value === NULL || $value === '');
      return $list;
    };
    $fields = [
      'moderation_state' => $field($state),
      'field_review_status' => $field($status),
      'field_review_dispatched_at' => $field($dispatchedAgo > 0 ? 100000 - $dispatchedAgo : NULL),
    ];
    $repo = $this->createMock(NodeInterface::class);
    $repo->method('bundle')->willReturn('appverse_repo');
    $repo->method('id')->willReturn(7);
    $repo->method('hasField')->willReturn(TRUE);
    $repo->method('get')->willReturnCallback(fn (string $name) => $fields[$name]);
    return $repo;
  }

}
