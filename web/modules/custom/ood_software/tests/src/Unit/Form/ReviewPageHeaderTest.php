<?php

namespace Drupal\Tests\ood_software\Unit\Form;

use Drupal\Tests\UnitTestCase;
use Drupal\ood_software\Form\ReviewPageForm;

/**
 * The reviewer header's derived facts.
 *
 * A maintainer worth naming, and a model that changed since the last review.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Form\ReviewPageForm
 */
class ReviewPageHeaderTest extends UnitTestCase {

  /**
   * @covers ::maintainerDiffers
   */
  public function testMaintainerDiffers(): void {
    $c = ['name' => 'Christian Bustelo', 'url' => '/community-persona/5'];
    $url = 'https://github.com/Christian-bustelo/OOD_EESSI_APP';
    $this->assertFalse(ReviewPageForm::maintainerDiffers('', $c, $url));
    $this->assertFalse(ReviewPageForm::maintainerDiffers('christian-bustelo', $c, $url));
    $this->assertFalse(ReviewPageForm::maintainerDiffers('Christian Bustelo', $c, $url));
    $this->assertTrue(ReviewPageForm::maintainerDiffers('OSC', $c, $url));
    $this->assertTrue(ReviewPageForm::maintainerDiffers('OSC', NULL, $url));
  }

  /**
   * @covers ::modelChanged
   */
  public function testModelChanged(): void {
    $this->assertFalse(ReviewPageForm::modelChanged('{"model":"claude-sonnet-5-5"}', '{"model":"claude-sonnet-5-5"}'));
    $this->assertTrue(ReviewPageForm::modelChanged('{"model":"claude-sonnet-5-5"}', '{"model":"qwen/qwen3"}'));
    $this->assertFalse(ReviewPageForm::modelChanged('{"model":"claude-sonnet-5-5"}', NULL));
  }

}
