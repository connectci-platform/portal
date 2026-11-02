<?php

/**
 * @file
 * Sets an Appverse repo's AI report run status for Cypress.
 *
 * Usage (project root is mounted at /var/www/html in DDEV):
 * @code
 * ddev drush php:script \
 *   /var/www/html/tests/cypress/cypress/fixtures/scripts/appverse-review-status.php \
 *   -- 123 pending
 * @endcode
 *
 * Positional arguments after "--":
 *   1. nid: The appverse_repo node id.
 *   2. status: pending, in_progress, complete or failed.
 *
 * pending and in_progress also stamp the dispatch time with now, which is
 * what makes the run count as in flight. Refuses to run outside DDEV.
 */

use Drupal\node\Entity\Node;

if (getenv('IS_DDEV_PROJECT') !== 'true') {
  fwrite(STDERR, "appverse-review-status: refusing to run outside DDEV.\n");
  exit(1);
}

$extra = $extra ?? [];
$nid = (string) ($extra[0] ?? '');
$status = (string) ($extra[1] ?? '');
if (!preg_match('/^\d+$/', $nid) || !in_array($status, ['pending', 'in_progress', 'complete', 'failed'], TRUE)) {
  fwrite(STDERR, "appverse-review-status: usage: <nid> <pending|in_progress|complete|failed>\n");
  exit(1);
}

$repo = Node::load((int) $nid);
if (!$repo || $repo->bundle() !== 'appverse_repo') {
  fwrite(STDERR, "appverse-review-status: node $nid is not an appverse_repo.\n");
  exit(1);
}

\Drupal::service('ood_software.in_place_saver')->updateRevisions($repo, function ($revision) use ($status): void {
  $revision->set('field_review_status', $status);
  if (in_array($status, ['pending', 'in_progress'], TRUE)) {
    $revision->set('field_review_dispatched_at', \Drupal::time()->getCurrentTime());
  }
});
\Drupal::entityTypeManager()->getStorage('node')->resetCache([(int) $nid]);

echo "APPVERSE_REVIEW_STATUS:ok\n";
