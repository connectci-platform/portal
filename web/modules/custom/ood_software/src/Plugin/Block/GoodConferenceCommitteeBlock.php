<?php

namespace Drupal\ood_software\Plugin\Block;

/**
 * Provides a 'GOOD Conference Committee' Block.
 *
 * @Block(
 *   id = "good_conference_committee_block",
 *   admin_label = @Translation("GOOD Conference Committee"),
 *   category = @Translation("Custom"),
 * )
 */
class GoodConferenceCommitteeBlock extends BadgePeopleBlockBase {

  /**
   * {@inheritdoc}
   */
  protected function badgeName(): string {
    return 'GOOD Committee';
  }

  /**
   * {@inheritdoc}
   */
  protected function themeHook(): string {
    return 'good_conference_committee_block';
  }

}
