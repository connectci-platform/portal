<?php

namespace Drupal\ood_software\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Psr\Log\LoggerInterface;

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
   * Creates a Draft appverse_review node (verdicts + findings) from an artifact.
   *
   * @param array $artifact
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

    /** @var \Drupal\node\NodeInterface $review */
    $review = $node_storage->create([
      'type' => 'appverse_review',
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

    foreach (['report_md' => 'field_arv_report_md', 'report_pdf' => 'field_arv_report_pdf', 'report_html' => 'field_arv_report_html'] as $key => $field) {
      $file = $this->attachReport($artifact['artifacts'][$key] ?? '', $reports_dir, $sha);
      if ($file !== NULL) {
        $review->set($field, $file);
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
    $prefixes = ['security' => 'sec', 'portability' => 'port', 'documentation' => 'docs'];
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
   * Saves a report file into managed storage and returns it, if available.
   */
  protected function attachReport(string $artifact_path, ?string $reports_dir, string $sha) {
    if ($artifact_path === '' || $reports_dir === NULL) {
      return NULL;
    }
    $source = rtrim($reports_dir, '/') . '/' . basename($artifact_path);
    if (!is_readable($source)) {
      $this->logger->warning('Report file @file not found in @dir; field left empty.', ['@file' => basename($artifact_path), '@dir' => $reports_dir]);
      return NULL;
    }
    $directory = 'public://appverse-reviews/' . substr($sha, 0, 7);
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
