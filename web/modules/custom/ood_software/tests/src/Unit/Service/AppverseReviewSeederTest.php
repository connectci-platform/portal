<?php

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\ood_software\Service\AppverseReviewSeeder;

/**
 * Pure helpers of the review seeder.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Service\AppverseReviewSeeder
 */
class AppverseReviewSeederTest extends UnitTestCase {

  /**
   * The draft feedback pre-fills the response to the contributor: the
   * section's prose only, without its heading, the following sections, or
   * the feedback-covers comment the report's checker reads.
   *
   * @covers ::extractDraftFeedback
   */
  public function testExtractDraftFeedbackTakesTheSectionProseOnly(): void {
    $md = "# Appverse Review: x\n\n## Overall recommendation\n\n**Reject.**\n\n"
      . "## Draft feedback — edit before sending.\n\nThank you for submitting.\n\n**Required:**\n\nAdd a LICENSE.\n\n\n\n"
      . "<!-- feedback-covers: LICENSE:no-license,\n  README.md:docs-minimal -->\n"
      . "## Appendix\n\nNot feedback.\n";

    $this->assertSame("Thank you for submitting.\n\n**Required:**\n\nAdd a LICENSE.", AppverseReviewSeeder::extractDraftFeedback($md));
  }

  /**
   * Submitter mode names the same section "Fix before submitting"; it runs to
   * the end of the report when it is the last section.
   *
   * @covers ::extractDraftFeedback
   */
  public function testExtractDraftFeedbackReadsTheSubmitterHeading(): void {
    $md = "## Catalog checks\n\n- none\n\n## Fix before submitting\n\n1. Add a LICENSE.\n";

    $this->assertSame('1. Add a LICENSE.', AppverseReviewSeeder::extractDraftFeedback($md));
  }

  /**
   * The gate table's rows, in the report's wording, for the reviewer's
   * Step 1: "—" rules become '', the header and separator are skipped.
   *
   * @covers ::gateRows
   */
  public function testGateRowsReadsTheRepoLevelTable(): void {
    $md = "## Repo-level gate criteria\n\n| Rule | Result | Evidence |\n|---|---|---|\n"
      . "| — | PASS | Repository public and accessible (clone succeeded) |\n"
      . "| STR-01 | fail | LICENSE — no LICENSE file found |\n\n## Upkeep\n\n| Signal | Value |\n|---|---|\n| CI | none |\n";

    $this->assertSame([
      ['rule' => '', 'result' => 'PASS', 'evidence' => 'Repository public and accessible (clone succeeded)'],
      ['rule' => 'STR-01', 'result' => 'FAIL', 'evidence' => 'LICENSE — no LICENSE file found'],
    ], AppverseReviewSeeder::gateRows($md));
    $this->assertSame([], AppverseReviewSeeder::gateRows("## Upkeep\n\n| a | b | c |\n"));
  }

  /**
   * The Catalog checks section keeps its markdown (nested bullets, emphasis)
   * for the page to render.
   *
   * @covers ::extractSection
   */
  public function testExtractSectionKeepsTheCatalogChecksMarkdown(): void {
    $md = "## Catalog checks\n\n- Duplicate check — pages 1–2 read.\n  - **Rationale:** _example apps_\n- `software` matches — PASS.\n\n## Overall recommendation\n\nReject.\n";

    $this->assertSame("- Duplicate check — pages 1–2 read.\n  - **Rationale:** _example apps_\n- `software` matches — PASS.", AppverseReviewSeeder::extractSection($md, 'Catalog checks'));
  }

  /**
   * A report without the section (a dry-run placeholder, an older report)
   * pre-fills nothing.
   *
   * @covers ::extractDraftFeedback
   */
  public function testExtractDraftFeedbackWithoutTheSectionIsEmpty(): void {
    $this->assertSame('', AppverseReviewSeeder::extractDraftFeedback("# Review\n\n## Overall recommendation\n\nAccept.\n"));
    $this->assertSame('', AppverseReviewSeeder::extractDraftFeedback(''));
  }

}
