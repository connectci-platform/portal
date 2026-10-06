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

  /**
   * @param array<mixed> $levels
   * @return array<mixed>
   */
  private function verdict(?int $appRef, string $appId, array $levels = []): array {
    $axes = [];
    foreach (['portability', 'documentation'] as $axis) {
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
   * The emitted object: enum keys as stored, one-line summaries, links into
   * the public review page (the app's section, or the repository section for
   * upkeep), upkeep from the repo-level fields. No labels, nothing about
   * drafts, and no HTML report (appverse-planning#42).
   *
   * @covers ::shape
   */
  public function testShapeEmitsEnumKeysAndResolvedLinks(): void {
    $review = [
      'reviewed_at' => 1790000000, 'sha' => 'a52c443663757694b45b0af0d85297be485ba05d',
      'url' => '/appverse/review/12334',
      'upkeep' => ['level' => 'some_notes', 'summary' => 'Brand-new repo', 'anchor' => '#upkeep'],
    ];
    $verdict = $this->verdict(12329, 'jupyter_example', [
      'documentation' => ['level' => 'needs_attention', 'summary' => 'No install section', 'anchor' => '#documentation'],
      'portability' => ['level' => 'solid', 'summary' => 'All site values in form.yml', 'anchor' => ''],
    ]);
    // A verdict loaded from older data may still carry a security axis.
    $verdict['axes']['security'] = ['level' => 'solid', 'summary' => 'No security findings', 'anchor' => '#security'];

    $out = ReviewSignals::shape($review, $verdict, 1790000500);

    $this->assertSame(['reviewedAt', 'sha7', 'url', 'outOfDate', 'security', 'portability', 'documentation', 'upkeep'], array_keys($out));
    $this->assertSame(['count', 'anchor'], array_keys($out['security']), 'security is a count, never a level (schema 1.2)');
    $this->assertSame('a52c443', $out['sha7']);
    $this->assertTrue($out['outOfDate']);
    $this->assertSame(['level' => 'needs_attention', 'summary' => 'No install section', 'anchor' => '/appverse/review/12334#app-jupyter_example'], $out['documentation']);
    $this->assertSame('/appverse/review/12334#app-jupyter_example', $out['portability']['anchor'], 'both app axes go to the app section');
    $this->assertSame('/appverse/review/12334#maintenance', $out['upkeep']['anchor']);
    $this->assertSame('some_notes', $out['upkeep']['level']);
    $this->assertStringNotContainsString('Some notes', json_encode($out), 'display words stay out of the cache');
  }

  /**
   * @covers ::shape
   */
  public function testShapeWithoutAVerdictIsNull(): void {
    $this->assertNull(ReviewSignals::shape(['reviewed_at' => 1, 'sha' => 'abc', 'url' => '/r', 'upkeep' => []], NULL, NULL));
  }

  /**
   * A single-app repo's verdict has no app id; its section is app-root, as
   * on the review page.
   *
   * @covers ::shape
   */
  public function testASingleAppLinksToTheRootSection(): void {
    $out = ReviewSignals::shape(['reviewed_at' => 1, 'sha' => 'abc', 'url' => '/appverse/review/7', 'upkeep' => []], $this->verdict(5, '', []), NULL);
    $this->assertSame('/appverse/review/7#app-root', $out['documentation']['anchor']);
  }

  /**
   * Without a review URL there is nothing to link to.
   *
   * @covers ::shape
   */
  public function testNoUrlMeansNoLinks(): void {
    $out = ReviewSignals::shape(['reviewed_at' => 1, 'sha' => 'abc', 'url' => '', 'upkeep' => []], $this->verdict(5, 'x', []), NULL);
    $this->assertSame('', $out['portability']['anchor']);
    $this->assertSame('', $out['upkeep']['anchor']);
  }

  /**
   * The security count is the app's findings plus the repo-level ones, which
   * apply to every app, and links to the app's section.
   *
   * @covers ::shape
   */
  public function testSecurityCountAddsRepoLevelFindings(): void {
    $verdict = $this->verdict(5, 'apps/notebook') + ['security' => 2];
    $out = ReviewSignals::shape(['reviewed_at' => 1, 'sha' => 'abc', 'url' => '/appverse/review/9', 'repo_security' => 1, 'upkeep' => []], $verdict, NULL);
    $this->assertSame(['count' => 3, 'anchor' => '/appverse/review/9#app-apps/notebook'], $out['security']);

    $none = ReviewSignals::shape(['reviewed_at' => 1, 'sha' => 'abc', 'url' => '/r', 'upkeep' => []], $this->verdict(5, 'x'), NULL);
    $this->assertSame(0, $none['security']['count'], 'no findings, a zero the card shows as "No findings"');
  }

  /**
   * Counts what the review page lists under Security: the tool's OODT
   * findings and the reviewer's own security findings, failed or warned.
   *
   * @covers ::securityCount
   */
  public function testSecurityCountFollowsTheReviewPage(): void {
    $f = fn (string $rule, string $result, string $source = 'ai', ?string $aspect = NULL, ?string $category = NULL): array => [
      'source' => $source, 'rule' => $rule, 'result' => $result, 'aspect' => $aspect, 'category' => $category,
    ];
    $this->assertSame(3, ReviewSignals::securityCount([
      $f('OODT-02', 'fail'),
      $f('OODT-05', 'warn'),
      // A reviewer's finding filed under security, whatever its rule text.
      $f('Exposed port', 'fail', 'reviewer', 'security', 'security'),
      // Not counted: a passed check, a skipped one, other blocks.
      $f('OODT-01', 'pass'),
      $f('OODT-01', 'not checked'),
      $f('QUA-02', 'fail'),
      $f('STR-01', 'fail'),
    ]));
    // A tool finding is placed by its rule, not its aspect field.
    $this->assertSame(0, ReviewSignals::securityCount([$f('QUA-03', 'fail', 'ai', 'security', 'security')]));
  }

}
