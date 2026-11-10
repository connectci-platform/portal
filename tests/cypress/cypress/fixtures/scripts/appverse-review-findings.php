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
 *     (documentation) and QUA-03 medium WARN (code quality). OODT-01 is the
 *     only finding that sets a floor, so Accept is possible once it is lowered.
 *   - multi: two apps. OODT-01 high FAIL (security) in app 1, a floor for
 *     every app.
 *
 * On success the only stdout line is:
 * APPVERSE_REVIEW_FINDINGS:{"apps":[<verdict pid>,...],"findings":{"OODT-01":<pid>,...}}
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

/**
 * Creates one review_finding paragraph.
 */
function appverse_review_findings_make(string $rule, string $severity, string $result, string $aspect, ?string $category, string $summary, string $app_id): Paragraph {
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
    }
  }
  $verdict = Paragraph::create([
    'type' => 'review_verdict',
    'field_rvv_app_ref' => (int) $app_nid,
    'field_rvv_app_id' => $app_id,
    'field_rvv_criteria' => json_encode(new \stdClass()),
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
]) . "\n";
