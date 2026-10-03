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
