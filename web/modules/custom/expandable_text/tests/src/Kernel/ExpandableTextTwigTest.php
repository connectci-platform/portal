<?php

namespace Drupal\Tests\expandable_text\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * @group expandable_text
 */
class ExpandableTextTwigTest extends KernelTestBase {

  protected static $modules = ['system', 'expandable_text'];

  public function testTwigFunctionRendersComponent(): void {
    $build = [
      '#type' => 'inline_template',
      '#template' => '{{ expandable_text(content, 3) }}',
      '#context' => ['content' => ['#markup' => '<p>alpha</p>']],
    ];
    $html = (string) \Drupal::service('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('class="expandable-text"', $html);
    $this->assertStringContainsString('data-lines="3"', $html);
    $this->assertStringContainsString('<p>alpha</p>', $html);
  }

  /**
   * The rows argument surfaces as data-rows for the row-boundary clamp.
   */
  public function testRowsVariableEmitsDataRows(): void {
    $build = [
      '#type' => 'inline_template',
      '#template' => '{{ expandable_text(content, 3, 5) }}',
      '#context' => ['content' => ['#markup' => '<p>alpha</p>']],
    ];
    $html = (string) \Drupal::service('renderer')->renderInIsolation($build);
    // Both attributes ride along: data-rows drives the row clamp, data-lines
    // stays as the fallback for content that turns out to hold no table rows.
    $this->assertStringContainsString('data-rows="5"', $html);
    $this->assertStringContainsString('data-lines="3"', $html);
  }

  /**
   * Callers that ask for no rows get no attribute, so line mode stays default.
   */
  public function testRowsOmittedWhenNotRequested(): void {
    $build = [
      '#type' => 'inline_template',
      '#template' => '{{ expandable_text(content, 3) }}',
      '#context' => ['content' => ['#markup' => '<p>alpha</p>']],
    ];
    $html = (string) \Drupal::service('renderer')->renderInIsolation($build);
    $this->assertStringNotContainsString('data-rows', $html);
  }

  public function testThemeHookAttachesLibraryViaPreprocess(): void {
    // The library must attach for BOTH entry points; assert on the #theme
    // render array directly (the bio consumer's path), which exercises the
    // preprocess-based attach rather than the Twig function.
    $build = [
      '#theme' => 'expandable_text',
      '#content' => ['#markup' => 'x'],
      '#lines' => 4,
    ];
    \Drupal::service('renderer')->renderInIsolation($build);
    $this->assertContains(
      'expandable_text/expandable_text',
      $build['#attached']['library'] ?? []
    );
  }
}
