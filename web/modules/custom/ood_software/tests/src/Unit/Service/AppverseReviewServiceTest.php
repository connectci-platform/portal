<?php

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\ood_software\Service\AppverseReviewService;

/**
 * Unit tests for the pure pieces of the review poll loop.
 *
 * pollForResults() itself talks to GitHub, entity storage, and the seeder,
 * and the appverse_review bundle config lives in the site's sync directory,
 * so the loop is exercised end to end in ddev rather than here. What can be
 * pinned down without Drupal: the mapping from the artifact's recommendation
 * vocabulary to the repo node's field enum, and the extraction of the review
 * files from a workflow-run artifact zip.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Service\AppverseReviewService
 */
class AppverseReviewServiceTest extends UnitTestCase {

  /**
   * The artifact says accept / accept_with_suggestions / request_changes /
   * reject (assemble-artifact.py's snake_case decisions); the repo node's
   * field_review_recommendation allows accepted / accepted_with_suggestions /
   * changes_requested / rejected. Writing the artifact value straight in
   * fails validation, so every artifact decision must map to a field value.
   *
   * @covers ::mapRecommendation
   * @dataProvider recommendationProvider
   */
  public function testMapRecommendation(?string $decision, ?string $expected): void {
    $this->assertSame($expected, AppverseReviewService::mapRecommendation($decision));
  }

  public static function recommendationProvider(): array {
    return [
      'accept' => ['accept', 'accepted'],
      'accept with suggestions' => ['accept_with_suggestions', 'accepted_with_suggestions'],
      'request changes' => ['request_changes', 'changes_requested'],
      'reject' => ['reject', 'rejected'],
      'already a field value passes through' => ['changes_requested', 'changes_requested'],
      'case and whitespace are tolerated' => ['  Accept  ', 'accepted'],
      'unknown maps to nothing, not to a guess' => ['maybe', NULL],
      'empty maps to nothing' => ['', NULL],
      'null maps to nothing' => [NULL, NULL],
    ];
  }

  /**
   * The run's artifact zip holds the report files under a nested path
   * (review-<slug>/appverse-review/appverse-review/<file>, as GitHub packs
   * them). The loop needs the artifact JSON plus the three reports, written
   * to a directory the seeder can read, by basename, and nothing else.
   *
   * @covers ::extractReviewFiles
   */
  public function testExtractReviewFilesPicksTheReviewFilesByBasename(): void {
    $dir = $this->makeTempDir();
    $zip = $this->buildZip([
      'review-o-r/appverse-review/appverse-review/review-o-r.artifact.json' => '{"schema_version":"1.1"}',
      'review-o-r/appverse-review/appverse-review/review-o-r.md' => '# report',
      'review-o-r/appverse-review/appverse-review/review-o-r.pdf' => '%PDF-1.4 fake',
      'review-o-r/appverse-review/appverse-review/review-o-r.html' => '<html></html>',
      'review-o-r/appverse-review/appverse-review/review-o-r.findings.json' => '[]',
      'review-o-r/appverse-review/appverse-review/review-o-r.meta.json' => '{}',
      'review-o-r/_temp/claude-execution-output.json' => '[]',
    ]);

    $files = AppverseReviewService::extractReviewFiles($zip, $dir);

    $this->assertSame(['artifact', 'md', 'pdf', 'html'], array_keys($files));
    $this->assertSame($dir . '/review-o-r.artifact.json', $files['artifact']);
    $this->assertSame('{"schema_version":"1.1"}', file_get_contents($files['artifact']));
    $this->assertSame('%PDF-1.4 fake', file_get_contents($files['pdf']));
    // Nothing but the four review files lands in the directory: findings.json,
    // meta.json, and the execution log stay in the zip.
    $this->assertSame(
      ['review-o-r.artifact.json', 'review-o-r.html', 'review-o-r.md', 'review-o-r.pdf'],
      $this->listDir($dir),
    );
  }

  /**
   * A zip with no artifact JSON (a dry-run, or an older workflow) must not
   * produce an 'artifact' key, so the caller can tell "nothing to seed" from
   * "seed this".
   *
   * @covers ::extractReviewFiles
   */
  public function testExtractReviewFilesWithoutArtifactHasNoArtifactKey(): void {
    $dir = $this->makeTempDir();
    $zip = $this->buildZip([
      'review-o-r/appverse-review/appverse-review/review-o-r.md' => '# dry run',
      'review-o-r/appverse-review/appverse-review/review-o-r.pdf' => '%PDF',
    ]);

    $files = AppverseReviewService::extractReviewFiles($zip, $dir);

    $this->assertArrayNotHasKey('artifact', $files);
    $this->assertSame(['md', 'pdf'], array_keys($files));
  }

  /**
   * Bytes that are not a zip archive give an empty result, not an exception:
   * the caller logs and marks the review as error.
   *
   * @covers ::extractReviewFiles
   */
  public function testExtractReviewFilesRejectsNonZipBytes(): void {
    $dir = $this->makeTempDir();

    $this->assertSame([], AppverseReviewService::extractReviewFiles('not a zip', $dir));
    $this->assertSame([], $this->listDir($dir));
  }

  /**
   * The workflow-runs API returns no dispatch inputs, so the loop cannot
   * find its run by target_repo. It sends an id it can recompute from the
   * node (nid + dispatch time), the workflow echoes it in the run title,
   * and the loop matches on that.
   *
   * @covers ::correlationId
   */
  public function testCorrelationIdIsRecomputableFromTheNode(): void {
    $this->assertSame('portal-12319-1790000000', AppverseReviewService::correlationId(12319, 1790000000));
  }

  /**
   * @covers ::runMatches
   * @dataProvider runMatchesProvider
   */
  public function testRunMatches(array $run, string $id, bool $expected): void {
    $this->assertSame($expected, AppverseReviewService::runMatches($run, $id));
  }

  public static function runMatchesProvider(): array {
    $title = fn(string $t) => ['display_title' => $t];
    return [
      'the run this node dispatched' => [$title('Review o/r · all · portal-12319-1790000000'), 'portal-12319-1790000000', TRUE],
      'a later dispatch for the same node is a different id' => [$title('Review o/r · all · portal-12319-1790000600'), 'portal-12319-1790000000', FALSE],
      'a superstring id does not match (token boundary)' => [$title('Review o/r · all · portal-12319-17900000001'), 'portal-12319-1790000000', FALSE],
      'a hand dispatch has no id' => [$title('Review o/r · all · '), 'portal-12319-1790000000', FALSE],
      'an old-style run has the default title' => [$title('AppVerse App Review'), 'portal-12319-1790000000', FALSE],
      'an empty id never matches anything' => [$title('Review o/r · all · '), '', FALSE],
      'a run without a title' => [['id' => 1], 'portal-12319-1790000000', FALSE],
    ];
  }

  /**
   * The aspects the run was dispatched with come from the same title; a
   * dry-run produces no artifact by design and must not read as an error.
   *
   * @covers ::runAspects
   * @dataProvider runAspectsProvider
   */
  public function testRunAspects(array $run, ?string $expected): void {
    $this->assertSame($expected, AppverseReviewService::runAspects($run));
  }

  public static function runAspectsProvider(): array {
    return [
      'dry-run' => [['display_title' => 'Review o/r · dry-run · portal-1-2'], 'dry-run'],
      'all' => [['display_title' => 'Review Sweet-and-Fizzy/appverse-example-monorepo · all · portal-12319-1790000000'], 'all'],
      'single aspect' => [['display_title' => 'Review o/r · security · '], 'security'],
      'old-style title' => [['display_title' => 'AppVerse App Review'], NULL],
      'no title' => [[], NULL],
    ];
  }

  /**
   * Builds a zip archive in memory-ish (via a temp file) and returns its bytes.
   */
  private function buildZip(array $entries): string {
    $path = tempnam(sys_get_temp_dir(), 'arv-zip-');
    $zip = new \ZipArchive();
    $zip->open($path, \ZipArchive::OVERWRITE);
    foreach ($entries as $name => $content) {
      $zip->addFromString($name, $content);
    }
    $zip->close();
    $bytes = file_get_contents($path);
    unlink($path);
    return $bytes;
  }

  private function makeTempDir(): string {
    $dir = sys_get_temp_dir() . '/arv-test-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700);
    return $dir;
  }

  private function listDir(string $dir): array {
    $names = array_values(array_diff(scandir($dir), ['.', '..']));
    sort($names);
    return $names;
  }

}
