<?php

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\ood_software\Service\ReleaseWatcher;

/**
 * When a new GitHub release starts a review.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Service\ReleaseWatcher
 */
class ReleaseWatcherTest extends UnitTestCase {

  /**
   * @covers ::decide
   * @dataProvider cases
   */
  public function testDecide(?string $stored, ?string $latest, ?string $store, bool $review): void {
    $this->assertSame(['store' => $store, 'review' => $review], ReleaseWatcher::decide($stored, $latest));
  }

  public static function cases(): array {
    $none = ReleaseWatcher::NO_RELEASE;
    return [
      // The first check records a baseline and reviews nothing.
      'first check, a release' => [NULL, 'v1.2', 'v1.2', FALSE],
      'first check, empty field' => ['', 'v1.2', 'v1.2', FALSE],
      'first check, no release' => [NULL, NULL, $none, FALSE],
      'same release' => ['v1.2', 'v1.2', NULL, FALSE],
      'still no release' => [$none, NULL, NULL, FALSE],
      'a new release' => ['v1.2', 'v1.3', 'v1.3', TRUE],
      // A repo's first release, after a check that saw none, is news.
      'a first release' => [$none, 'v1.0', 'v1.0', TRUE],
      // A deleted release is recorded, not reviewed.
      'the release went away' => ['v1.2', NULL, $none, FALSE],
    ];
  }

}
