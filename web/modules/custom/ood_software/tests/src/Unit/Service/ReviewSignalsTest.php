<?php

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\ood_software\Service\ReviewSignals;

/**
 * Unit tests for ReviewSignals, the review data the public cache carries.
 *
 * The cache file is public and read by the CDN-hosted catalog, so the rules
 * pinned here are the ones with public consequences: which verdict belongs
 * to which app, when a review counts as out of date, and exactly what the
 * emitted object contains — enum keys and resolved links, no draft data, no
 * display words.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Service\ReviewSignals
 */
class ReviewSignalsTest extends UnitTestCase {

  private function verdict(?int $appRef, string $appId, array $levels = []): array {
    $axes = [];
    foreach (['security', 'portability', 'documentation'] as $axis) {
      $axes[$axis] = $levels[$axis] ?? ['level' => 'solid', 'summary' => $axis . ' fine', 'anchor' => '#' . $axis];
    }
    return ['app_ref' => $appRef, 'app_id' => $appId, 'axes' => $axes];
  }

  /**
   * @covers ::pickVerdict
   */
  public function testVerdictIsPickedByAppNodeFirst(): void {
    $verdicts = [$this->verdict(11, 'apps/a'), $this->verdict(12, 'apps/b')];
    $this->assertSame('apps/b', ReviewSignals::pickVerdict($verdicts, 12, 'apps/b')['app_id']);
    $this->assertSame('apps/b', ReviewSignals::pickVerdict($verdicts, 12, 'something-else')['app_id'], 'the node reference wins over the subpath');
  }

  /**
   * The seeder leaves app_ref empty when the catalog's app nodes have no
   * subpath (#4); the app_id is the subpath the tool used, so it is the
   * fallback join, and "root" is the id of a repo's only app.
   *
   * @covers ::pickVerdict
   */
  public function testVerdictFallsBackToSubpathThenRoot(): void {
    $verdicts = [$this->verdict(NULL, 'jupyter_example'), $this->verdict(NULL, 'rstudio_example')];
    $this->assertSame('rstudio_example', ReviewSignals::pickVerdict($verdicts, 99, 'rstudio_example')['app_id']);
    $this->assertNull(ReviewSignals::pickVerdict($verdicts, 99, ''), 'an app with no subpath does not match a subpath verdict');

    $single = [$this->verdict(NULL, 'root')];
    $this->assertSame('root', ReviewSignals::pickVerdict($single, 99, '')['app_id'], 'an empty subpath is the root app');
    $this->assertNull(ReviewSignals::pickVerdict($single, 99, 'apps/x'));
    $this->assertNull(ReviewSignals::pickVerdict([], 99, ''));
  }

  /**
   * @covers ::isOutOfDate
   */
  public function testOutOfDateWhenTheRepoMovedAfterTheReview(): void {
    $this->assertFalse(ReviewSignals::isOutOfDate(1000, 900));
    $this->assertFalse(ReviewSignals::isOutOfDate(1000, 1000));
    $this->assertTrue(ReviewSignals::isOutOfDate(1000, 1001));
    $this->assertFalse(ReviewSignals::isOutOfDate(1000, NULL), 'no commit date is no evidence');
    $this->assertFalse(ReviewSignals::isOutOfDate(0, 1000), 'no review date is no evidence');
  }

  /**
   * The emitted object: enum keys as stored, one-line summaries, anchors
   * resolved against the HTML report, upkeep from the repo-level fields,
   * the review page URL. No labels, nothing about drafts.
   *
   * @covers ::shape
   */
  public function testShapeEmitsEnumKeysAndResolvedLinks(): void {
    $review = [
      'reviewed_at' => 1790000000, 'sha' => 'a52c443663757694b45b0af0d85297be485ba05d',
      'url' => '/appverse/review/12334', 'report_html' => 'https://x.test/files/r.html',
      'upkeep' => ['level' => 'some_notes', 'summary' => 'Brand-new repo', 'anchor' => '#upkeep'],
    ];
    $verdict = $this->verdict(12329, 'jupyter_example', [
      'documentation' => ['level' => 'needs_attention', 'summary' => 'No install section', 'anchor' => '#documentation'],
      'security' => ['level' => 'solid', 'summary' => 'No security findings', 'anchor' => ''],
    ]);

    $out = ReviewSignals::shape($review, $verdict, 1790000500);

    $this->assertSame(['reviewedAt', 'sha7', 'url', 'outOfDate', 'security', 'portability', 'documentation', 'upkeep'], array_keys($out));
    $this->assertSame('a52c443', $out['sha7']);
    $this->assertTrue($out['outOfDate']);
    $this->assertSame(['level' => 'needs_attention', 'summary' => 'No install section', 'anchor' => 'https://x.test/files/r.html#documentation'], $out['documentation']);
    $this->assertSame('', $out['security']['anchor'], 'no fragment, no link');
    $this->assertSame('https://x.test/files/r.html#upkeep', $out['upkeep']['anchor']);
    $this->assertSame('some_notes', $out['upkeep']['level']);
    $this->assertStringNotContainsString('Some notes', json_encode($out), 'display words stay out of the cache');
  }

  /**
   * @covers ::shape
   */
  public function testShapeWithoutAVerdictIsNull(): void {
    $this->assertNull(ReviewSignals::shape(['reviewed_at' => 1, 'sha' => 'abc', 'url' => '/r', 'report_html' => '', 'upkeep' => []], NULL, NULL));
  }

}
