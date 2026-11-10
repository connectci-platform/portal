<?php

/**
 * @file
 * Gives a seeded Appverse review one verdict per app, with findings, for Cypress.
 *
 * Run after appverse-seed.php with its "review" extra and member apps (one for
 * "single", two for "multi").
 *
 * Usage (project root is mounted at /var/www/html in DDEV):
 * @code
 * ddev drush php:script \
 *   /var/www/html/tests/cypress/cypress/fixtures/scripts/appverse-review-findings.php \
 *   -- 456 single
 * @endcode
 *
 * Positional arguments after "--":
 *   1. nid: The appverse_review node id.
 *   2. shape: "single" or "multi".
 *
 * Shapes:
 *   - single: one app. OODT-01 high FAIL (security), QUA-01 low WARN
 *     (documentation) and QUA-03 medium WARN (code quality), plus QUA-04 low WARN (code quality)
 *     already dismissed by the reviewer, and STR-02 a PASS. OODT-01 is the
 *     only finding that sets a floor, so Accept is possible once it is lowered.
 *     The app is assigned a new Software entry, "<repo title> Software", that
 *     no other app implements, so its duplicate check is clear.
 *   - multi: two apps. OODT-01 high FAIL (security) in app 1, a floor for
 *     every app. App 2 is assigned "<repo title> Software", which a published
 *     app ("<repo title> Other App") under another published repo ("<repo
 *     title> Other") also implements, so its duplicate check compares.
 *
 * Every verdict carries the catalog checks of an inferred repo
 * (field_rvv_catalog), as the review tool sends them, so the page treats the
 * review as one with catalog data rather than a legacy one.
 *
 * The Software entry and the other repo are purged by title before they are
 * made again, so a rerun does not collide with itself.
 *
 * On success the only stdout line is:
 * APPVERSE_REVIEW_FINDINGS:{"apps":[<verdict pid>,...],"findings":{"OODT-01":<pid>,...},"software":"<title>","other_app":{"nid":<nid>,"title":"<title>","url":"<path>"}|null}
 * Refuses to run outside DDEV.
 */

use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;

if (getenv('IS_DDEV_PROJECT') !== 'true') {
  fwrite(STDERR, "appverse-review-findings: refusing to run outside DDEV.\n");
  exit(1);
}

$extra = $extra ?? [];
$nid = (string) ($extra[0] ?? '');
$shape = (string) ($extra[1] ?? '');
if (!preg_match('/^\d+$/', $nid) || !in_array($shape, ['single', 'multi'], TRUE)) {
  fwrite(STDERR, "appverse-review-findings: usage: <review nid> <single|multi>\n");
  exit(1);
}

$review = Node::load((int) $nid);
if (!$review || $review->bundle() !== 'appverse_review') {
  fwrite(STDERR, "appverse-review-findings: node $nid is not an appverse_review.\n");
  exit(1);
}

$storage = \Drupal::entityTypeManager()->getStorage('node');
$app_ids = $storage->getQuery()->accessCheck(FALSE)
  ->condition('type', 'appverse_app')
  ->condition('field_appverse_repo', $review->get('field_arv_repo')->target_id)
  ->sort('nid')
  ->execute();
$want = $shape === 'single' ? 1 : 2;
if (count($app_ids) !== $want) {
  fwrite(STDERR, "appverse-review-findings: shape $shape needs $want member app(s), the repo has " . count($app_ids) . ".\n");
  exit(1);
}

$repo = $review->get('field_arv_repo')->entity;
if (!$repo instanceof Node) {
  fwrite(STDERR, "appverse-review-findings: review $nid has no repo.\n");
  exit(1);
}
$repo_title = (string) $repo->label();
$software_title = $repo_title . ' Software';
$other_repo_title = $repo_title . ' Other';

// Purge what a previous run of this fixture made: the other repo's apps, the
// other repo, then the Software entry, in that order.
$old_repo_ids = $storage->getQuery()->accessCheck(FALSE)
  ->condition('type', 'appverse_repo')
  ->condition('title', $other_repo_title)
  ->execute();
if ($old_repo_ids) {
  $old_app_ids = $storage->getQuery()->accessCheck(FALSE)
    ->condition('type', 'appverse_app')
    ->condition('field_appverse_repo', array_values($old_repo_ids), 'IN')
    ->execute();
  $storage->delete($storage->loadMultiple($old_app_ids));
  $storage->delete($storage->loadMultiple($old_repo_ids));
}
$old_software_ids = $storage->getQuery()->accessCheck(FALSE)
  ->condition('type', 'appverse_software')
  ->condition('title', $software_title)
  ->execute();
$storage->delete($storage->loadMultiple($old_software_ids));

// A Software entry no other app implements: the duplicate check of the app
// it is assigned to is clear.
$software = Node::create([
  'type' => 'appverse_software',
  'title' => $software_title,
  'uid' => $repo->getOwnerId(),
  'status' => 1,
]);
$software->save();

// In multi, a published app under another published repo implements it, so
// app 2's duplicate check has one app to compare against.
$other_app = NULL;
if ($shape === 'multi') {
  $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  $app_type = $term_storage->loadByProperties(['vid' => 'appverse_app_type', 'name' => 'batch-connect-VNC']);
  $license = $term_storage->loadByProperties(['vid' => 'appverse_license', 'name' => 'Open-Source License']);
  $domain = \Drupal::entityTypeManager()->getStorage('domain')->load('openondemand_cyberinfrastructure_org');
  if (!$app_type || !$license || !$domain) {
    fwrite(STDERR, "appverse-review-findings: the app type, license or domain for the other app is missing.\n");
    exit(1);
  }
  $other_repo = Node::create([
    'type' => 'appverse_repo',
    'title' => $other_repo_title,
    'uid' => $repo->getOwnerId(),
    'moderation_state' => 'published',
    'field_repo_url' => ['uri' => 'https://github.com/Sweet-and-Fizzy/appverse-example-monorepo'],
    'field_repo_description' => 'Cypress fixture Collection whose app shares software with a reviewed app.',
    'field_repo_validation_st' => 'valid',
  ]);
  $other_repo->save();
  $other_app = Node::create([
    'type' => 'appverse_app',
    'title' => $repo_title . ' Other App',
    'uid' => $repo->getOwnerId(),
    'moderation_state' => 'published',
    'field_appverse_repo' => $other_repo->id(),
    'field_appverse_app_type' => reset($app_type)->id(),
    'field_license' => reset($license)->id(),
    'field_domain_access' => [$domain->id()],
    'field_appverse_software_implemen' => $software->id(),
  ]);
  $other_app->save();
}

// The catalog checks of an inferred repo, as the review tool sends them.
$catalog = json_encode([
  'shape' => 'inferred',
  'software' => ['status' => 'inferred'],
  'same_software_apps' => [],
]);

/**
 * Creates one review_finding paragraph.
 */
function appverse_review_findings_make(string $rule, string $severity, string $result, string $aspect, ?string $category, string $summary, string $app_id, bool $dismissed = FALSE): Paragraph {
  $finding = Paragraph::create([
    'type' => 'review_finding',
    'field_rvf_stable_id' => strtolower($rule) . '-' . $app_id,
    'field_rvf_app_id' => $app_id,
    'field_rvf_rule' => $rule,
    'field_rvf_defect_key' => 'run.sh:cypress-fixture',
    'field_rvf_aspect' => $aspect,
    'field_rvf_category' => $category,
    'field_rvf_severity' => $severity,
    'field_rvf_summary' => $summary,
    'field_rvf_evidence' => 'run.sh:3 - fixture evidence',
    'field_rvf_anchor' => 'run.sh',
    'field_rvf_line' => 3,
    'field_rvf_source' => 'ai',
    'field_rvf_result' => $result,
    'field_rvf_dismissed' => $dismissed,
    // A dismissal is always backed by a note, or every save would refuse it.
    'field_rvf_reviewer_prose' => $dismissed ? 'Not worth raising for this app.' : '',
  ]);
  $finding->save();
  return $finding;
}

$verdicts = [];
$findings = [];
$index = 0;
foreach (array_values($app_ids) as $app_nid) {
  $index++;
  $app_id = 'app' . $index;
  $own = [];
  if ($index === 1) {
    $own[] = $findings['OODT-01'] = appverse_review_findings_make('OODT-01', 'high', 'fail', 'security', 'security', 'Shell command built from user input', $app_id);
    if ($shape === 'single') {
      $own[] = $findings['QUA-01'] = appverse_review_findings_make('QUA-01', 'low', 'warn', 'quality', 'documentation', 'README leaves out the setup steps', $app_id);
      $own[] = $findings['QUA-03'] = appverse_review_findings_make('QUA-03', 'medium', 'warn', 'quality', NULL, 'Script ignores a failed download', $app_id);
      // Dismissed by the reviewer, so it is listed in the closed fold.
      $own[] = $findings['QUA-04'] = appverse_review_findings_make('QUA-04', 'low', 'warn', 'quality', NULL, 'Script leaves a temp file behind', $app_id, TRUE);
      // A passed check, so "Passed checks" has a fold to open.
      $own[] = $findings['STR-02'] = appverse_review_findings_make('STR-02', 'low', 'pass', 'structure', NULL, 'Manifest has every required field', $app_id);
    }
  }
  // The app whose duplicate check the Software entry settles: the only app
  // in single, app 2 in multi.
  if ($index === $want) {
    Node::load((int) $app_nid)->set('field_appverse_software_implemen', $software->id())->save();
  }
  $verdict = Paragraph::create([
    'type' => 'review_verdict',
    'field_rvv_app_ref' => (int) $app_nid,
    'field_rvv_app_id' => $app_id,
    'field_rvv_criteria' => json_encode(new \stdClass()),
    'field_rvv_catalog' => $catalog,
    'field_rvv_findings' => $own,
  ]);
  $verdict->save();
  $verdicts[] = $verdict;
}

$review->set('field_arv_verdicts', $verdicts);
$review->save();

echo 'APPVERSE_REVIEW_FINDINGS:' . json_encode([
  'apps' => array_map(static fn ($v) => (int) $v->id(), $verdicts),
  'findings' => array_map(static fn ($f) => (int) $f->id(), $findings),
  'software' => $software_title,
  'other_app' => $other_app ? [
    'nid' => (int) $other_app->id(),
    'title' => (string) $other_app->label(),
    'url' => $other_app->toUrl()->toString(),
  ] : NULL,
]) . "\n";
