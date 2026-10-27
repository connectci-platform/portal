<?php

namespace Drupal\ood_software\Plugin\Block;

/**
 * Provides a 'Tips & Tricks Calls' Block.
 *
 * @Block(
 *   id = "tips_tricks_calls_block",
 *   admin_label = @Translation("Tips & Tricks Calls"),
 *   category = @Translation("Custom"),
 * )
 */
class TipsTricksCallsBlock extends BadgePeopleBlockBase {

  /**
   * {@inheritdoc}
   */
  protected function badgeName(): string {
    return 'Tips & Tricks';
  }

  /**
   * {@inheritdoc}
   */
  protected function themeHook(): string {
    return 'tips_tricks_calls_block';
  }

}
