<?php

namespace Drupal\ood_software\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Psr\Log\LoggerInterface;
use Drupal\file\FileInterface;

/**
 * Seeds an appverse_review node from a review artifact.
 *
 * The artifact is the appverse-review plugin's envelope (schema 1.x — see
 * references/artifact-envelope.md in the plugin repo). The seeder writes the
 * machine half of the review: provenance, recommendation, findings, criteria,
 * and indicator defaults. Reviewer-owned fields (conclusion, reviewer prose,
 * assessment, overrides) are left empty for the human pass.
 *
 * Artifacts without an "indicators" key (schema 1.0) seed cleanly; the
 * indicator fields simply stay unset.
 */
class AppverseReviewSeeder {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected FileRepositoryInterface $fileRepository;
  protected FileSystemInterface $fileSystem;
  protected LoggerInterface $logger;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, FileRepositoryInterface $file_repository, FileSystemInterface $file_system, LoggerChannelFactoryInterface $logger_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->fileRepository = $file_repository;
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('ood_software');
  }

  /**
   * The review's author: the reviewer who started the run, else the site
   * admin (appverse-planning#44; see seedFromArtifact()).
   */
  public static function authorFor(mixed $starter): int {
    return $starter instanceof AccountInterface && $starter->hasPermission('administer appverse content')
      ? (int) $starter->id() : 1;
  }

  /**
   * Creates a Draft appverse_review node (verdicts + findings) from an artifact.
   *
   * @param array<mixed> $artifact
   *   Decoded artifact JSON.
   * @param \Drupal\node\NodeInterface|null $repo
   *   The appverse_repo node; resolved from reviewed.repo_url when NULL.
   * @param string|null $reports_dir
   *   Directory holding the report files named in artifacts.*; when set and
   *   the files exist they are attached, otherwise the fields stay empty.
   * @param bool $force
   *   Seed even when a review for the same repo + SHA already exists.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved review node.
   *
   * @throws \InvalidArgumentException
   *   On a malformed artifact or unresolvable repo.
   * @throws \RuntimeException
   *   When a review for this repo + SHA exists and $force is FALSE.
   */
  public function seedFromArtifact(array $artifact, ?NodeInterface $repo = NULL, ?string $reports_dir = NULL, bool $force = FALSE): NodeInterface {
    $reviewed = $artifact['reviewed'] ?? [];
    $sha = $reviewed['sha'] ?? '';
    if ($sha === '') {
      throw new \InvalidArgumentException('Artifact has no reviewed.sha — not a valid review artifact.');
    }

    if ($repo === NULL) {
      $repo_url = $reviewed['repo_url'] ?? '';
      $repo = $repo_url !== '' ? $this->loadRepoByUrl($repo_url) : NULL;
      if ($repo === NULL) {
        throw new \InvalidArgumentException(sprintf('Could not resolve an appverse_repo node for "%s"; pass --repo.', $repo_url));
      }
    }

    $existing = $this->findExistingReview($repo, $sha);
    if ($existing !== NULL && !$force) {
      throw new \RuntimeException(sprintf('Review node %d already covers %s @ %s — use --force to seed another.', $existing, $repo->label(), substr($sha, 0, 7)));
    }

    $node_storage = $this->entityTypeManager->getStorage('node');

    $repo_level = $artifact['repo_level'] ?? [];
    $recommendation = $artifact['recommendation'] ?? [];
    $short_sha = substr($sha, 0, 7);

    // Authored by the reviewer who started the run (recorded on the repo at
    // dispatch), not the anonymous cron user that imports it. A run the
    // contributor started (send-for-review) is authored by the site admin:
    // an author holds "view own unpublished content", which would let the
    // contributor reach the undecided review, through JSON:API filters for
    // one (appverse-planning#44). Who started the run stays on the repo. The
    // site admin also when no one is recorded (a hand-run import, or a run
    // dispatched before this).
    $author = self::authorFor($repo->hasField('field_review_dispatched_by') ? $repo->get('field_review_dispatched_by')->entity : NULL);

    /** @var \Drupal\node\NodeInterface $review */
    $review = $node_storage->create([
      'type' => 'appverse_review',
      'uid' => $author,
      'revision_uid' => $author,
      'title' => sprintf('Review: %s @ %s', $repo->label(), $short_sha),
      'moderation_state' => 'draft',
      'field_arv_repo' => $repo->id(),
      'field_arv_sha' => $sha,
      'field_arv_ref' => $reviewed['ref'] ?? '',
      'field_arv_tool_version' => $reviewed['tool_version'] ?? '',
      'field_arv_repo_shape' => $reviewed['repo_shape'] ?? NULL,
      'field_arv_recommendation' => $recommendation['decision'] ?? NULL,
      'field_arv_recommendation_note' => $recommendation['note'] ?? '',
      'field_arv_run_meta' => json_encode($artifact['run_meta'] ?? new \stdClass()),
      'field_arv_repo_criteria' => json_encode($repo_level['criteria'] ?? new \stdClass()),
    ]);

    if (!empty($reviewed['at']) && ($ts = strtotime($reviewed['at'])) !== FALSE) {
      $review->set('field_arv_reviewed_at', $ts);
    }

    $repo_indicators = $repo_level['indicators'] ?? [];
    if (isset($repo_indicators['maintenance'])) {
      $maint = $repo_indicators['maintenance'];
      $review->set('field_arv_maint_level', $maint['level'] ?? NULL);
      $review->set('field_arv_maint_summary', $maint['summary'] ?? '');
      $review->set('field_arv_maint_anchor', $maint['anchor'] ?? '');
      $review->set('field_arv_indicators_default', json_encode($repo_indicators));
    }

    $repo_findings = [];
    foreach ($repo_level['findings'] ?? [] as $finding) {
      $repo_findings[] = $this->buildFindingParagraph($finding);
    }
    $review->set('field_arv_repo_findings', $repo_findings);

    $verdicts = [];
    foreach ($artifact['apps'] ?? [] as $app) {
      $verdicts[] = $this->buildVerdictParagraph($app, $repo);
    }
    $review->set('field_arv_verdicts', $verdicts);

    // Only the Markdown report is imported. The PDF and HTML are the tool's
    // first pass and go stale as soon as the reviewer edits the review, so
    // they stay in the CI artifact and not on the portal
    // (appverse-planning#42). The field's storage config decides where the
    // file lives; the Markdown is private, so a Draft review's report is not
    // readable at a guessable public URL.
    foreach (['report_md' => 'field_arv_report_md'] as $key => $field) {
      $scheme = $review->getFieldDefinition($field)->getSetting('uri_scheme') ?: 'private';
      $file = $this->attachReport($artifact['artifacts'][$key] ?? '', $reports_dir, $sha, $scheme);
      if ($file !== NULL) {
        $review->set($field, $file);
      }
    }

    // The report's draft feedback is the reviewer's starting point for the
    // response to the contributor: pre-fill it, to be edited before sending.
    // The response is never shown publicly.
    // From the md report too: the repo-level gate table's evidence and the
    // Catalog checks, which Step 1 of the Reviewer Process asks the reviewer
    // to settle and which the artifact JSON does not carry.
    $mdName = basename((string) ($artifact['artifacts']['report_md'] ?? ''));
    $mdPath = $mdName !== '' && $reports_dir !== NULL ? rtrim($reports_dir, '/') . '/' . $mdName : '';
    $markdown = $mdPath !== '' && is_readable($mdPath) ? (string) file_get_contents($mdPath) : '';
    if ($markdown !== '') {
      $draft = self::extractDraftFeedback($markdown);
      if ($draft !== '' && $review->get('field_arv_contributor_response')->isEmpty()) {
        $review->set('field_arv_contributor_response', ['value' => $draft, 'format' => 'plain_text']);
      }
      if ($review->hasField('field_arv_gate_evidence') && ($rows = self::gateRows($markdown)) !== []) {
        $review->set('field_arv_gate_evidence', json_encode($rows));
      }
      if ($review->hasField('field_arv_catalog_checks') && ($catalog = self::extractSection($markdown, 'Catalog checks')) !== '') {
        $review->set('field_arv_catalog_checks', $catalog);
      }
    }

    $review->save();
    $this->logger->notice('Seeded review @nid for @repo @ @sha (@apps app(s), @findings finding(s)).', [
      '@nid' => $review->id(),
      '@repo' => $repo->label(),
      '@sha' => $short_sha,
      '@apps' => count($artifact['apps'] ?? []),
      '@findings' => count($repo_level['findings'] ?? []) + array_sum(array_map(fn($a) => count($a['findings'] ?? []), $artifact['apps'] ?? [])),
    ]);

    return $review;
  }

  /**
   * Builds one review_verdict paragraph for an apps[] entry.
   *
   * @param array<mixed> $app
   */
  protected function buildVerdictParagraph(array $app, NodeInterface $repo): Paragraph {
    $app_id = $app['app_id'] ?? 'root';
    $verdict = Paragraph::create([
      'type' => 'review_verdict',
      'field_rvv_app_id' => $app_id,
      'field_rvv_criteria' => json_encode($app['criteria'] ?? new \stdClass()),
    ]);

    $app_node = $this->resolveAppNode($repo, $app_id);
    if ($app_node !== NULL) {
      $verdict->set('field_rvv_app_ref', $app_node->id());
    }
    else {
      $this->logger->warning('No appverse_app matched app_id "@id" for repo @repo; verdict seeded without app_ref.', [
        '@id' => $app_id,
        '@repo' => $repo->label(),
      ]);
    }

    $indicators = $app['indicators'] ?? [];
    // No security indicator since schema 1.2; a 1.1 artifact's is ignored.
    $prefixes = ['portability' => 'port', 'documentation' => 'docs'];
    foreach ($prefixes as $category => $prefix) {
      if (!isset($indicators[$category])) {
        continue;
      }
      $ind = $indicators[$category];
      $verdict->set("field_rvv_{$prefix}_level", $ind['level'] ?? NULL);
      $verdict->set("field_rvv_{$prefix}_summary", $ind['summary'] ?? '');
      $verdict->set("field_rvv_{$prefix}_anchor", $ind['anchor'] ?? '');
    }
    if ($indicators !== []) {
      $verdict->set('field_rvv_indicators_default', json_encode($indicators));
    }

    $findings = [];
    foreach ($app['findings'] ?? [] as $finding) {
      $findings[] = $this->buildFindingParagraph($finding);
    }
    $verdict->set('field_rvv_findings', $findings);

    return $verdict;
  }

  /**
   * Builds one review_finding paragraph from an artifact finding record.
   *
   * @param array<mixed> $finding
   */
  protected function buildFindingParagraph(array $finding): Paragraph {
    $paragraph = Paragraph::create([
      'type' => 'review_finding',
      'field_rvf_stable_id' => $finding['id'] ?? '',
      'field_rvf_app_id' => $finding['app_id'] ?? 'root',
      'field_rvf_rule' => $finding['rule'] ?? '',
      'field_rvf_defect_key' => $finding['defect_key'] ?? '',
      'field_rvf_aspect' => $finding['aspect'] ?? NULL,
      'field_rvf_severity' => $finding['severity'] ?? NULL,
      'field_rvf_summary' => $finding['summary'] ?? '',
      'field_rvf_evidence' => $finding['evidence'] ?? '',
      'field_rvf_anchor' => $finding['anchor'] ?? '',
      // Reviewers add findings on the review page with source "reviewer".
      'field_rvf_source' => 'ai',
      // PASS and NOT CHECKED rows are checks, not findings; the page keeps
      // them out of the counts and lists them apart.
      'field_rvf_result' => self::resultKey($finding['result'] ?? NULL),
    ]);
    // The artifact's findings do not yet emit "category"; set it when present.
    if (isset($finding['category'])) {
      $paragraph->set('field_rvf_category', $finding['category']);
    }
    if (isset($finding['line']) && is_numeric($finding['line'])) {
      $paragraph->set('field_rvf_line', (int) $finding['line']);
    }
    return $paragraph;
  }

  /**
   * Matches an apps[] app_id to an appverse_app node of this repo.
   *
   * "root" matches an app with an empty subpath (or the repo's only app);
   * anything else matches field_appverse_app_subpath.
   */
  protected function resolveAppNode(NodeInterface $repo, string $app_id): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appverse_app')
      ->condition('field_appverse_repo', $repo->id())
      ->execute();
    if ($nids === []) {
      return NULL;
    }
    $apps = $storage->loadMultiple($nids);
    if ($app_id === 'root' && count($apps) === 1) {
      return reset($apps);
    }
    foreach ($apps as $app) {
      $subpath = $app->hasField('field_appverse_app_subpath') ? ($app->get('field_appverse_app_subpath')->value ?? '') : '';
      if ($app_id === 'root' ? $subpath === '' : $subpath === $app_id) {
        return $app;
      }
    }
    return NULL;
  }

  /**
   * The field_rvf_result key for an artifact row's result ("PASS", "NOT
   * CHECKED", …); NULL for a missing or unknown one.
   */
  public static function resultKey(?string $result): ?string {
    $key = str_replace(' ', '_', strtolower(trim((string) $result)));
    return in_array($key, ['fail', 'warn', 'pass', 'not_checked'], TRUE) ? $key : NULL;
  }

  /**
   * The report's draft feedback, ready to edit as the response.
   *
   * The section under "## Draft feedback…" (reviewer mode) or "## Fix before
   * submitting" (submitter mode), up to the next level-2 heading, without the
   * heading itself or HTML comments (the feedback-covers key list the
   * report's checker reads). '' when the report has no such section.
   */
  public static function extractDraftFeedback(string $markdown): string {
    return self::extractSection($markdown, '(?:Draft feedback|Fix before submitting)');
  }

  /**
   * A level-2 section of the md report: the body under the first "## "
   * heading matching $headingPattern (a regex fragment, case-insensitive),
   * up to the next level-2 heading, without the heading or HTML comments.
   * '' when the report has no such section.
   */
  public static function extractSection(string $markdown, string $headingPattern): string {
    if (!preg_match('/^##\s+' . $headingPattern . '\b[^\n]*\n/mi', $markdown, $m, PREG_OFFSET_CAPTURE)) {
      return '';
    }
    $body = substr($markdown, $m[0][1] + strlen($m[0][0]));
    if (preg_match('/^##\s/m', $body, $next, PREG_OFFSET_CAPTURE)) {
      $body = substr($body, 0, $next[0][1]);
    }
    $body = preg_replace('/<!--.*?-->/s', '', $body);
    return trim(preg_replace("/\n{3,}/", "\n\n", $body));
  }

  /**
   * The rows of the report's "Repo-level gate criteria" table.
   *
   * Each row is rule ('' for the table's "—"), result (PASS / FAIL / WARN …)
   * and evidence, the report's own wording. [] when there is no table.
   *
   * @return array<int, array{rule: string, result: string, evidence: string}>
   */
  public static function gateRows(string $markdown): array {
    $rows = [];
    foreach (explode("\n", self::extractSection($markdown, 'Repo-level gate criteria')) as $line) {
      $line = trim($line);
      // Table rows only; skip the header and the |---| separator.
      if (!str_starts_with($line, '|') || preg_match('/^\|[\s:|-]+\|$/', $line)) {
        continue;
      }
      $cells = array_map('trim', explode('|', trim($line, '|')));
      if (count($cells) < 3 || strcasecmp($cells[0], 'Rule') === 0) {
        continue;
      }
      $rows[] = [
        'rule' => in_array($cells[0], ['—', '-', '–'], TRUE) ? '' : $cells[0],
        'result' => strtoupper($cells[1]),
        'evidence' => implode(' | ', array_slice($cells, 2)),
      ];
    }
    return $rows;
  }

  /**
   * Saves a report file into managed storage and returns it, if available.
   *
   * @param string $scheme
   *   The stream wrapper scheme of the field the file is for ('private' or
   *   'public'), taken from that field's storage config so the two cannot
   *   disagree.
   */
  protected function attachReport(string $artifact_path, ?string $reports_dir, string $sha, string $scheme): ?FileInterface {
    if ($artifact_path === '' || $reports_dir === NULL) {
      return NULL;
    }
    $source = rtrim($reports_dir, '/') . '/' . basename($artifact_path);
    if (!is_readable($source)) {
      $this->logger->warning('Report file @file not found in @dir; field left empty.', ['@file' => basename($artifact_path), '@dir' => $reports_dir]);
      return NULL;
    }
    $directory = $scheme . '://appverse-reviews/' . substr($sha, 0, 7);
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    return $this->fileRepository->writeData(file_get_contents($source), $directory . '/' . basename($artifact_path), FileExists::Replace);
  }

  /**
   * Finds an existing review node for this repo + SHA, if any.
   */
  protected function findExistingReview(NodeInterface $repo, string $sha): ?int {
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appverse_review')
      ->condition('field_arv_repo', $repo->id())
      ->condition('field_arv_sha', $sha)
      ->range(0, 1)
      ->execute();
    return $nids === [] ? NULL : (int) reset($nids);
  }

  /**
   * Loads an appverse_repo node by its repository URL.
   */
  protected function loadRepoByUrl(string $repo_url): ?NodeInterface {
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appverse_repo')
      ->condition('field_repo_url.uri', $repo_url)
      ->range(0, 1)
      ->execute();
    if ($nids === []) {
      return NULL;
    }
    return $this->entityTypeManager->getStorage('node')->load(reset($nids));
  }

}
