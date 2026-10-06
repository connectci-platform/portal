<?php

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Service\AppverseReviewService;
use Drupal\ood_software\Service\ReleaseWatcher;
use Drupal\Tests\UnitTestCase;

/**
 * The release watcher's pure rules (appverse-planning#34, #45).
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Service\ReleaseWatcher
 */
class ReleaseWatcherTest extends UnitTestCase {

  const T1 = 1790000000;
  const T2 = 1790100000;
  const T3 = 1790200000;

  /**
   * @covers ::decide
   * @covers ::parse
   * @dataProvider cases
   *
   * @param array{tag: string, at: int}|null $latest
   *   The newest release or tag.
   */
  public function testDecide(?string $stored, ?array $latest, int $coveredUntil, ?string $store, bool $review): void {
    $this->assertSame(['store' => $store, 'review' => $review], ReleaseWatcher::decide($stored, $latest, $coveredUntil));
  }

  /**
   * @return array<string, array<int, mixed>>
   *   Cases for testDecide().
   */
  public static function cases(): array {
    $none = ReleaseWatcher::NO_RELEASE;
    $v12 = ['tag' => 'v1.2', 'at' => self::T1];
    $v13 = ['tag' => 'v1.3', 'at' => self::T2];
    return [
      // The first check records a baseline and reviews nothing.
      'first check, a release' => [NULL, $v12, 0, 'v1.2|' . self::T1, FALSE],
      'first check, empty field' => ['', $v12, 0, 'v1.2|' . self::T1, FALSE],
      'first check, nothing' => [NULL, NULL, 0, $none, FALSE],
      'same release' => ['v1.2|' . self::T1, $v12, 0, NULL, FALSE],
      'still nothing' => [$none, NULL, 0, NULL, FALSE],
      'newer code' => ['v1.2|' . self::T1, $v13, 0, 'v1.3|' . self::T2, TRUE],
      // A repo's first release or tag, after a check that saw none, is news.
      'a first release' => [$none, $v12, 0, 'v1.2|' . self::T1, TRUE],
      // Deleted: nothing to review, and what was seen is kept, so the same
      // release coming back is not news.
      'the release went away' => ['v1.2|' . self::T1, NULL, 0, NULL, FALSE],
      'the same release came back' => ['v1.2|' . self::T1, $v12, 0, NULL, FALSE],
      // The newest was deleted and an older one is now "latest".
      'an older tag' => ['v1.3|' . self::T2, $v12, 0, NULL, FALSE],
      // Newer than what was seen, but the last review already ran after it,
      // as when a contributor tagged their fix and re-submitted.
      'already reviewed' => ['v1.2|' . self::T1, $v13, self::T3, 'v1.3|' . self::T2, FALSE],
      // Stored as a bare tag before commit times were.
      'old format, same tag' => ['v1.2', $v12, 0, 'v1.2|' . self::T1, FALSE],
      'old format, newer tag' => ['v1.2', $v13, 0, 'v1.3|' . self::T2, TRUE],
    ];
  }

  /**
   * @covers ::parse
   */
  public function testParse(): void {
    $this->assertNull(ReleaseWatcher::parse(NULL));
    $this->assertSame(['tag' => NULL, 'at' => 0], ReleaseWatcher::parse(ReleaseWatcher::NO_RELEASE));
    $this->assertSame(['tag' => 'v1|2', 'at' => 5], ReleaseWatcher::parse('v1|2|5'), 'only the last | separates the time');
    $this->assertSame(['tag' => 'release-x', 'at' => 0], ReleaseWatcher::parse('release-x'));
  }

  /**
   * A release wins over a tag; a repo without releases uses its newest tag,
   * annotated or not.
   *
   * @covers \Drupal\ood_software\Service\AppverseReviewService::newestTag
   */
  public function testNewestTag(): void {
    $release = ['latestRelease' => ['tagName' => 'v2.0', 'tagCommit' => ['committedDate' => '2026-10-01T12:00:00Z']],
      'refs' => ['nodes' => [['name' => 'v2.1', 'target' => ['committedDate' => '2026-10-02T12:00:00Z']]]]];
    $this->assertSame(['tag' => 'v2.0', 'at' => strtotime('2026-10-01T12:00:00Z')], AppverseReviewService::newestTag($release));

    $lightweight = ['latestRelease' => NULL, 'refs' => ['nodes' => [['name' => 'v0.3', 'target' => ['committedDate' => '2026-09-01T00:00:00Z']]]]];
    $this->assertSame(['tag' => 'v0.3', 'at' => strtotime('2026-09-01T00:00:00Z')], AppverseReviewService::newestTag($lightweight));

    $annotated = ['latestRelease' => NULL, 'refs' => ['nodes' => [['name' => 'v0.4', 'target' => ['target' => ['committedDate' => '2026-09-02T00:00:00Z']]]]]];
    $this->assertSame(['tag' => 'v0.4', 'at' => strtotime('2026-09-02T00:00:00Z')], AppverseReviewService::newestTag($annotated));

    $this->assertNull(AppverseReviewService::newestTag(['latestRelease' => NULL, 'refs' => ['nodes' => []]]));
  }

  /**
   * @covers ::isDecided
   */
  public function testIsDecided(): void {
    $this->assertFalse(ReleaseWatcher::isDecided($this->review(FALSE, FALSE, FALSE)), 'draft, nothing sent');
    $this->assertTrue(ReleaseWatcher::isDecided($this->review(TRUE, FALSE, FALSE)), 'decision sent');
    $this->assertTrue(ReleaseWatcher::isDecided($this->review(FALSE, TRUE, FALSE)), 'withdrawn');
    $this->assertTrue(ReleaseWatcher::isDecided($this->review(FALSE, FALSE, TRUE)), 'published before decisions were recorded');
  }

  /**
   * A review mock with the given decision, withdrawal and published state.
   */
  protected function review(bool $sent, bool $withdrawn, bool $published): NodeInterface {
    $field = function (bool $set): FieldItemListInterface {
      $list = $this->createMock(FieldItemListInterface::class);
      $list->method('isEmpty')->willReturn(!$set);
      return $list;
    };
    $review = $this->createMock(NodeInterface::class);
    $review->method('hasField')->willReturn(TRUE);
    $review->method('get')->willReturnMap([
      ['field_arv_decision_sent_at', $field($sent)],
      ['field_arv_withdrawn_at', $field($withdrawn)],
    ]);
    $review->method('isPublished')->willReturn($published);
    return $review;
  }

}
