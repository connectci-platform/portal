<?php

namespace Drupal\expandable_text;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Provides the expandable_text() Twig function.
 */
class TwigExtension extends AbstractExtension {

  /**
   * {@inheritdoc}
   */
  public function getFunctions(): array {
    return [
      new TwigFunction('expandable_text', [$this, 'build'], ['is_safe' => ['html']]),
    ];
  }

  /**
   * Builds the expandable_text render array.
   *
   * @param mixed $content
   *   Already-rendered body: a render array or a safe markup string.
   * @param int $lines
   *   Collapse target in rendered lines.
   *
   * @return array<string, mixed>
   *   A render array for the expandable_text theme hook.
   */
  public function build($content, int $lines = 4): array {
    // The library is attached by expandable_text_preprocess_expandable_text()
    // on every render of the theme hook, so it is NOT added here — one source
    // of truth, and consumers reaching the hook directly get it too.
    return [
      '#theme' => 'expandable_text',
      '#content' => is_array($content) ? $content : ['#markup' => $content],
      '#lines' => $lines,
    ];
  }

}
