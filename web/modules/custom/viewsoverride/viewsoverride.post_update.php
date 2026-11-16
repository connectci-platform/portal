<?php

/**
 * @file
 * Post update functions for ViewsOverride.
 */

/**
 * Rebuild every stored real name after realname 2.0.1 deleted them.
 *
 * Realname's update 10001 empties the realname table so names regenerate,
 * but it regenerates a name only when that account is next loaded. Views that
 * read the table directly (member lists, people with expertise, the
 * coordinator autocomplete) show no name until then, so every account is
 * loaded here, which stores its name again.
 *
 * @param array<string, mixed> $sandbox
 *   The batch sandbox.
 *
 * @return string
 *   Progress for the update log.
 */
function viewsoverride_post_update_rebuild_realnames(array &$sandbox): string {
  if (!\Drupal::moduleHandler()->moduleExists('realname')) {
    $sandbox['#finished'] = 1;
    return 'Realname is not enabled.';
  }

  $storage = \Drupal::entityTypeManager()->getStorage('user');
  if (!isset($sandbox['ids'])) {
    $sandbox['ids'] = array_values($storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', 0, '>')
      ->sort('uid')
      ->execute());
    $sandbox['done'] = 0;
  }

  $chunk = array_slice($sandbox['ids'], $sandbox['done'], 500);
  if ($chunk) {
    // Loading an account runs realname_user_load(), which generates and stores
    // any name missing from the table.
    $storage->loadMultiple($chunk);
    $storage->resetCache($chunk);
    drupal_static_reset('realname_load_multiple');
    $sandbox['done'] += count($chunk);
  }

  $total = count($sandbox['ids']);
  $sandbox['#finished'] = $total ? min(1, $sandbox['done'] / $total) : 1;
  return "Rebuilt real names for {$sandbox['done']} of $total accounts.";
}
