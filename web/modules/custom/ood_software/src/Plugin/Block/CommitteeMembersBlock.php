<?php

namespace Drupal\ood_software\Plugin\Block;

/**
 * Provides a 'Committee Members' Block.
 *
 * @Block(
 *   id = "committee_members_block",
 *   admin_label = @Translation("Committee Members"),
 *   category = @Translation("Custom"),
 * )
 */
class CommitteeMembersBlock extends BadgePeopleBlockBase {

  /**
   * {@inheritdoc}
   */
  protected function badgeName(): string {
    return 'Committee member';
  }

  /**
   * {@inheritdoc}
   */
  protected function themeHook(): string {
    return 'committee_members_block';
  }

}
