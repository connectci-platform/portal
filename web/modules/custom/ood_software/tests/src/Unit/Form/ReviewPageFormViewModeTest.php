<?php

namespace Drupal\Tests\ood_software\Unit\Form;

use Drupal\Tests\UnitTestCase;
use Drupal\ood_software\Form\ReviewPageForm;

/**
 * Who gets which view of a review.
 *
 * The public summary leaves out the evidence, the response written to the
 * contributor and the internal notes, so the rule deciding who sees the full
 * page is the privacy boundary of the review page.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Form\ReviewPageForm
 */
class ReviewPageFormViewModeTest extends UnitTestCase {

  /**
   * @covers ::viewModeFor
   * @dataProvider viewModes
   */
  public function testViewModeFor(bool $isReviewer, bool $isContributor, ?string $requested, string $expected): void {
    $this->assertSame($expected, ReviewPageForm::viewModeFor($isReviewer, $isContributor, $requested));
  }

  public static function viewModes(): array {
    return [
      'reviewer edits' => [TRUE, FALSE, NULL, ReviewPageForm::MODE_EDIT],
      'reviewer previews the public view' => [TRUE, FALSE, 'public', ReviewPageForm::MODE_PUBLIC],
      'reviewer who owns the repo still edits' => [TRUE, TRUE, NULL, ReviewPageForm::MODE_EDIT],
      'reviewer with an unknown view value edits' => [TRUE, FALSE, 'bogus', ReviewPageForm::MODE_EDIT],
      'contributor sees the full page read-only' => [FALSE, TRUE, NULL, ReviewPageForm::MODE_CONTRIBUTOR],
      // ?view=public is a reviewer's preview; anyone else asking for another
      // view cannot widen what they see.
      'contributor asking for public still gets their page' => [FALSE, TRUE, 'public', ReviewPageForm::MODE_CONTRIBUTOR],
      'visitor gets the summary' => [FALSE, FALSE, NULL, ReviewPageForm::MODE_PUBLIC],
      'visitor cannot ask for the edit view' => [FALSE, FALSE, 'edit', ReviewPageForm::MODE_PUBLIC],
      'visitor cannot ask for the contributor view' => [FALSE, FALSE, 'contributor', ReviewPageForm::MODE_PUBLIC],
    ];
  }

}
