<?php

/**
 * @file
 * Deploy hooks for the Open OnDemand general module.
 */

use Drupal\block_content\BlockContentInterface;
use Drupal\node\NodeInterface;

/**
 * D8-2634: Clean up the markup of the people intro and Contributor Wall copy.
 *
 * Strips the wrapper divs and heading from the people intro block and gives
 * the Contributor Wall intro paragraph the page-intro class. Each update is
 * skipped, without saving, when the entity or the expected markup is missing.
 */
function ood_general_deploy_d82634_intro_text(): string {
  $logger = \Drupal::logger('ood_general');
  $repository = \Drupal::service('entity.repository');
  $summary = [];

  // Update 1: people intro block.
  $prefix = '<div class="container mt-4"><div class="row"><div class="d-flex mx-3"><div class="mr-4"><h2>Welcome to the Community,</h2>';
  $suffix = '</div></div></div></div>';
  $block = $repository->loadEntityByUuid('block_content', 'dcb991f7-62ee-4462-8f31-65001f68de0e');
  $message = 'People intro block not found; skipped.';
  if ($block instanceof BlockContentInterface && $block->hasField('body')) {
    $message = 'People intro block did not have the expected markup; skipped.';
    $body = trim((string) $block->get('body')->value);
    if (str_starts_with($body, $prefix) && str_ends_with($body, $suffix)) {
      $inner = trim(substr($body, strlen($prefix), strlen($body) - strlen($prefix) - strlen($suffix)));
      if (str_starts_with($inner, '<p')) {
        $block->get('body')->value = $inner;
        $block->save();
        $message = 'People intro block markup cleaned up.';
      }
    }
  }
  $logger->notice('D8-2634: @message', ['@message' => $message]);
  $summary[] = $message;

  // Update 2: Contributor Wall node.
  $old_tag = '<p class="font-weight-bold">';
  $node = $repository->loadEntityByUuid('node', 'a220e430-334a-4484-9ae9-0f7b52dc8d3c');
  $message = 'Contributor Wall node not found; skipped.';
  if ($node instanceof NodeInterface && $node->hasField('body')) {
    $message = 'Contributor Wall node did not have the expected markup; skipped.';
    $body = (string) $node->get('body')->value;
    $position = strpos($body, $old_tag);
    if ($position !== FALSE) {
      $node->get('body')->value = substr_replace($body, '<p class="page-intro">', $position, strlen($old_tag));
      $node->setNewRevision(TRUE);
      $node->setRevisionLogMessage('D8-2634: Use the page-intro class on the intro paragraph.');
      $node->setRevisionCreationTime(\Drupal::time()->getRequestTime());
      $node->save();
      $message = 'Contributor Wall node intro paragraph updated.';
    }
  }
  $logger->notice('D8-2634: @message', ['@message' => $message]);
  $summary[] = $message;

  return implode(' ', $summary);
}
