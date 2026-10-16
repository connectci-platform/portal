<?php

namespace Drupal\ood_software\Plugin\Block;

/**
 * Provides a 'Appverse App Reviewers' Block.
 *
 * @Block(
 *   id = "app_reviewers_block",
 *   admin_label = @Translation("Appverse App Reviewers"),
 *   category = @Translation("Custom"),
 * )
 */
class AppReviewersBlock extends BadgePeopleBlockBase {

  /**
   * {@inheritdoc}
   */
  protected function badgeName(): string {
    return 'Appverse App Reviewer';
  }

  /**
   * {@inheritdoc}
   */
  protected function themeHook(): string {
    return 'app_reviewers_block';
  }

}
