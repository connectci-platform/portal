<?php

/**
 * @file
 * Seeds one Appverse Collection (appverse_repo) plus member apps for Cypress.
 *
 * JSON:API is read-only (D8-2832), so the Cypress specs can no longer create
 * fixtures through POST/PATCH/DELETE. This script does it with the entity API
 * instead. It is a checked-in file rather than PHP piped over stdin because
 * the old stdin approach returned empty output intermittently under full-suite
 * load.
 *
 * Usage (project root is mounted at /var/www/html in DDEV):
 * @code
 * ddev drush php:script \
 *   /var/www/html/tests/cypress/cypress/fixtures/scripts/appverse-seed.php \
 *   -- 'Hub Render Collection' published 2 published
 * @endcode
 *
 * Positional arguments after "--":
 *   1. title: Collection title (required, 1-120 chars, [A-Za-z0-9 _().:-]).
 *   2. state: Collection moderation state (required).
 *   3. memberApps: Number of member apps to create (0-10, default 0).
 *   4. memberAppState: Moderation state for member apps (default draft).
 *
 * Re-running with the same title first purges the previous fixture, so the
 * script is idempotent and safe to retry.
 *
 * On success the only stdout line is:
 * APPVERSE_SEED_RESULT:{"nid":123,"uuid":"..."}
 * On invalid input or a missing lookup it writes to stderr and exits non-zero.
 * It refuses to run outside DDEV, since it deletes nodes by title.
 */

use Drupal\node\Entity\Node;

/**
 * Prints an error to stderr and stops with a non-zero exit code.
 */
function appverse_seed_fail(string $message): void {
  fwrite(STDERR, "appverse-seed: $message\n");
  exit(1);
}

/**
 * Loads exactly the first entity matching properties, or fails loudly.
 */
function appverse_seed_lookup(string $entity_type, array $properties, string $label): object {
  $entities = \Drupal::entityTypeManager()->getStorage($entity_type)->loadByProperties($properties);
  $entity = reset($entities);
  if (!$entity) {
    appverse_seed_fail("could not find $label.");
  }
  return $entity;
}

// The script deletes nodes by title, so only run it inside a DDEV container
// (local and CI), never against a Pantheon environment.
if (getenv('IS_DDEV_PROJECT') !== 'true') {
  appverse_seed_fail('refusing to run outside DDEV (IS_DDEV_PROJECT is not "true").');
}

$allowed_states = [
  'draft',
  'ready_for_review',
  'needs_adjustment',
  'published',
  'archived',
  'declined',
];

$extra = $extra ?? [];
$title = (string) ($extra[0] ?? '');
$state = (string) ($extra[1] ?? '');
$member_apps_arg = (string) ($extra[2] ?? '0');
$member_app_state = (string) ($extra[3] ?? 'draft');

// Validate strictly. Nothing below interpolates these into code or SQL; they
// only reach the entity API as values.
if ($title === '' || strlen($title) > 120 || !preg_match('/^[A-Za-z0-9 _().:-]+$/', $title)) {
  appverse_seed_fail('invalid title (1-120 chars of A-Z a-z 0-9 space _ ( ) . : -).');
}
if (!in_array($state, $allowed_states, TRUE)) {
  appverse_seed_fail('invalid state "' . $state . '"; allowed: ' . implode(', ', $allowed_states));
}
if (!in_array($member_app_state, $allowed_states, TRUE)) {
  appverse_seed_fail('invalid memberAppState "' . $member_app_state . '"; allowed: ' . implode(', ', $allowed_states));
}
if (!preg_match('/^\d{1,2}$/', $member_apps_arg) || (int) $member_apps_arg > 10) {
  appverse_seed_fail('invalid memberApps "' . $member_apps_arg . '"; expected an integer 0-10.');
}
$member_apps = (int) $member_apps_arg;

$fixture_repo_url = 'https://github.com/Sweet-and-Fizzy/appverse-example-monorepo';

// Resolve every lookup before changing anything, so a missing fixture fails
// without leaving a half-purged state.
$contributor = user_load_by_name('authenticated_test_user');
if (!$contributor) {
  appverse_seed_fail('contributor user authenticated_test_user not found.');
}
$app_type = NULL;
$license = NULL;
$domain = NULL;
if ($member_apps > 0) {
  $app_type = appverse_seed_lookup('taxonomy_term', ['vid' => 'appverse_app_type', 'name' => 'batch-connect-VNC'], 'app_type term batch-connect-VNC');
  $license = appverse_seed_lookup('taxonomy_term', ['vid' => 'appverse_license', 'name' => 'Open-Source License'], 'license term Open-Source License');
  $domain = appverse_seed_lookup('domain', ['id' => 'openondemand_cyberinfrastructure_org'], 'domain openondemand_cyberinfrastructure_org');
}

$storage = \Drupal::entityTypeManager()->getStorage('node');

// Purge prior fixtures: repos with this exact title, their member apps, and
// orphan "<title> App " apps left by a partial previous run.
$repo_ids = $storage->getQuery()->accessCheck(FALSE)
  ->condition('type', 'appverse_repo')
  ->condition('title', $title)
  ->execute();
$app_ids = [];
if ($repo_ids) {
  $app_ids = $storage->getQuery()->accessCheck(FALSE)
    ->condition('type', 'appverse_app')
    ->condition('field_appverse_repo', array_values($repo_ids), 'IN')
    ->execute();
}
$orphan_ids = $storage->getQuery()->accessCheck(FALSE)
  ->condition('type', 'appverse_app')
  ->condition('title', \Drupal::database()->escapeLike($title . ' App ') . '%', 'LIKE')
  ->execute();
$app_ids = array_unique(array_merge(array_values($app_ids), array_values($orphan_ids)));
$storage->delete($storage->loadMultiple($app_ids));
$storage->delete($storage->loadMultiple($repo_ids));

$repo = Node::create([
  'type' => 'appverse_repo',
  'title' => $title,
  'uid' => $contributor->id(),
  'moderation_state' => $state,
  'field_repo_url' => ['uri' => $fixture_repo_url],
  'field_repo_description' => 'Cypress fixture Collection for the Appverse maintenance hub tests.',
  'field_repo_validation_st' => 'valid',
]);
$repo->save();

for ($n = 1; $n <= $member_apps; $n++) {
  Node::create([
    'type' => 'appverse_app',
    'title' => $title . ' App ' . $n,
    'uid' => $contributor->id(),
    'moderation_state' => $member_app_state,
    'field_appverse_repo' => $repo->id(),
    'field_appverse_app_type' => $app_type->id(),
    'field_license' => $license->id(),
    'field_domain_access' => [$domain->id()],
  ])->save();
}

echo 'APPVERSE_SEED_RESULT:' . json_encode(['nid' => (int) $repo->id(), 'uuid' => $repo->uuid()]) . "\n";
