<?php

namespace Drupal\Tests\ood_software\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ood_software\Form\ReviewPageForm;

/**
 * The model shown on the review header, read from the run metadata.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Form\ReviewPageForm
 */
class ModelLabelTest extends UnitTestCase {

  /**
   * Checks the label and full id for each run metadata case.
   *
   * @param array{label: string, full: string}|null $expected
   *   The expected label and full id, or NULL when no model is recorded.
   *
   * @covers ::modelLabel
   * @dataProvider runMeta
   */
  public function testModelLabel(?string $runMetaJson, ?array $expected): void {
    $this->assertSame($expected, ReviewPageForm::modelLabel($runMetaJson));
  }

  /**
   * Run metadata JSON and the label it should produce.
   *
   * @return array<string, array{0: ?string, 1: array{label: string, full: string}|null}>
   *   Cases keyed by description.
   */
  public static function runMeta(): array {
    return [
      'provider path keeps the last segment as the label' => [
        '{"model":"ccs/Qwen/Qwen3.6-35B-A3B-FP8"}',
        ['label' => 'Qwen3.6-35B-A3B-FP8', 'full' => 'ccs/Qwen/Qwen3.6-35B-A3B-FP8'],
      ],
      'bare model name is its own label' => [
        '{"model":"sonnet"}',
        ['label' => 'sonnet', 'full' => 'sonnet'],
      ],
      'empty model is no model' => ['{"model":""}', NULL],
      'missing model is no model' => ['{}', NULL],
      'empty string is no model' => ['', NULL],
      'null is no model' => [NULL, NULL],
      'invalid JSON is no model' => ['not json', NULL],
    ];
  }

}
