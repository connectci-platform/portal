<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Unit\Form;

use Drupal\Core\Form\FormState;
use Drupal\ood_software\Form\ReviewPageForm;
use Drupal\Tests\UnitTestCase;

/**
 * A list's add form files the new finding under one of that list's sections.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Form\ReviewPageForm
 */
class ReviewPageAddFindingTest extends UnitTestCase {

  /**
   * The section guard, called as validateAddFinding() and addFinding() do.
   *
   * @param array<string, string> $input
   *   The add form's values.
   * @param string $listKey
   *   The list's key.
   * @param array<string, string> $sections
   *   The sections the list offers.
   *
   * @return array<string, mixed>
   *   The errors set.
   */
  private function errorsFor(array $input, string $listKey, array $sections): array {
    // The guard runs before anything touches the review, so the form needs
    // no services beyond translation.
    $form = (new \ReflectionClass(ReviewPageForm::class))->newInstanceWithoutConstructor();
    $form->setStringTranslation($this->getStringTranslationStub());
    $method = new \ReflectionMethod(ReviewPageForm::class, 'newFindingFromInput');
    $form_state = new FormState();
    $this->assertNull($method->invoke($form, $input, $listKey, $sections, $form_state));
    return array_map('strval', $form_state->getErrors());
  }

  /**
   * A section another list offers, but not this one, is refused.
   *
   * Drupal's illegal-choice check stops a value the select never offered, so
   * this covers a section the select allows that the list does not: the
   * repo's Structure, which it offers only while a repo gate stands there.
   *
   * @covers ::newFindingFromInput
   */
  public function testAddFindingRefusesSectionNotInList(): void {
    $errors = $this->errorsFor(
      ['section' => 'structure', 'rule' => 'STR-01', 'severity' => 'low', 'summary' => 'No LICENSE.'],
      'repo',
      ['maintenance' => 'Upkeep'],
    );
    $this->assertSame(['add_finding][repo][section' => 'Choose a section for the new finding.'], $errors);
  }

  /**
   * An empty section is refused the same way.
   *
   * @covers ::newFindingFromInput
   */
  public function testAddFindingRefusesNoSection(): void {
    $errors = $this->errorsFor(['rule' => 'QUA-03', 'severity' => 'low', 'summary' => 'x'], 'app-12', ReviewPageForm::addSections(['key' => 'app-12']));
    $this->assertSame(['add_finding][app-12][section' => 'Choose a section for the new finding.'], $errors);
  }

  /**
   * Where the finding is stored, from the list and the section.
   *
   * @covers ::addTarget
   * @covers ::addSections
   */
  public function testTargetsAndSections(): void {
    $this->assertSame('app:12:security', ReviewPageForm::addTarget('app-12', 'security'));
    $this->assertSame('repo:maintenance', ReviewPageForm::addTarget('repo', 'maintenance'));
    $this->assertSame(
      ['structure' => 'Structure', 'maintenance' => 'Upkeep'],
      ReviewPageForm::addSections([
        'key' => 'repo',
        'sections' => [
          ['key' => 'structure', 'first' => 0],
          ['key' => 'maintenance', 'first' => NULL],
        ],
      ]),
    );
  }

}
