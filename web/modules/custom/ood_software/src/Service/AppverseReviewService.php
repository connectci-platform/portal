<?php

namespace Drupal\ood_software\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\node\NodeInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Dispatches AI reviews via GitHub Actions when repos enter ready_for_review.
 *
 * Triggered from ood_software_node_update() alongside RepoNotificationService.
 * Fires a workflow_dispatch on Sweet-and-Fizzy/appverse-review, which runs the
 * Claude-based review pipeline and produces a PDF report + JSON summary.
 *
 * This is fire-and-forget: a dispatch failure never blocks the Drupal
 * moderation transition. Errors are logged for admin visibility.
 */
class AppverseReviewService {

  /**
   * Minimum seconds between dispatches for the same node.
   * Self-transitions (review_to_review) bypass this.
   */
  const DEBOUNCE_SECONDS = 300;

  /**
   * Seconds after which a pending review is considered stale.
   * Reviewers can re-trigger via the review_to_review transition.
   */
  const STALE_TIMEOUT_SECONDS = 7200;

  /**
   * The GitHub owner/repo where the review workflow lives.
   */
  const REVIEW_REPO = 'Sweet-and-Fizzy/appverse-review';

  /**
   * The workflow filename (GitHub API accepts filename or numeric ID).
   */
  const WORKFLOW_FILE = 'appverse-review.yaml';

  /**
   * The branch to run the workflow on.
   */
  const WORKFLOW_REF = 'main';

  /**
   * Drupal Key module key ID for the GitHub token.
   */
  const GITHUB_KEY_ID = 'appverse_review_github';

  /**
   * Fallback key ID if the dedicated key doesn't exist.
   */
  const GITHUB_KEY_FALLBACK = 'appverse_github';

  protected LoggerInterface $logger;

  public function __construct(
    protected ClientInterface $httpClient,
    protected KeyRepositoryInterface $keyRepository,
    LoggerChannelFactoryInterface $loggerFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
    protected FileSystemInterface $fileSystem,
  ) {
    $this->logger = $loggerFactory->get('ood_software');
  }

  /**
   * React to a moderation state transition on an appverse_repo node.
   *
   * Only dispatches a review when entering ready_for_review from a
   * different state. The review_to_review self-transition is allowed
   * so admins can explicitly re-trigger a review.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The appverse_repo node (post-save).
   * @param string|null $previousState
   *   The moderation_state before this save, or NULL if unknown.
   */
  public function onTransition(NodeInterface $node, ?string $previousState): void {
    if ($node->bundle() !== 'appverse_repo') {
      return;
    }

    $newState = $node->get('moderation_state')->value;
    if ($newState !== 'ready_for_review') {
      return;
    }

    // Skip if the previous state is unknown (NULL) to avoid false
    // triggers on bulk operations or migrations.
    if ($previousState === NULL) {
      return;
    }

    // Self-transition (review_to_review) is an explicit admin action
    // to re-trigger a review — it always bypasses the debounce.
    $isSelfTransition = ($previousState === 'ready_for_review');

    if (!$isSelfTransition && $this->isWithinDebounce($node)) {
      $this->logger->info('Skipping review dispatch for node @nid: debounce window (@sec s).', [
        '@nid' => $node->id(),
        '@sec' => self::DEBOUNCE_SECONDS,
      ]);
      return;
    }

    $repoUrl = $this->extractRepoUrl($node);
    if ($repoUrl === NULL) {
      $this->logger->warning('Cannot dispatch review for repo node @nid: no field_repo_url value.', [
        '@nid' => $node->id(),
      ]);
      return;
    }

    $ownerRepo = $this->parseOwnerRepo($repoUrl);
    if ($ownerRepo === NULL) {
      $this->logger->warning('Cannot dispatch review for repo node @nid: could not parse owner/repo from URL @url.', [
        '@nid' => $node->id(),
        '@url' => $repoUrl,
      ]);
      return;
    }

    if ($this->dispatch($ownerRepo)) {
      $this->recordDispatch($node);
    }
  }

  /**
   * Check if the node was dispatched within the debounce window.
   */
  protected function isWithinDebounce(NodeInterface $node): bool {
    if (!$node->hasField('field_review_dispatched_at') || $node->get('field_review_dispatched_at')->isEmpty()) {
      return FALSE;
    }
    $lastDispatch = (int) $node->get('field_review_dispatched_at')->value;
    $elapsed = $this->time->getRequestTime() - $lastDispatch;
    return $elapsed < self::DEBOUNCE_SECONDS;
  }

  /**
   * Record a successful dispatch on the node's review tracking fields.
   *
   * Re-loads the node to avoid saving stale state from the in-flight
   * hook_node_update() context. Sets dispatched_at, status=pending,
   * and clears prior recommendation/report/run_id.
   */
  protected function recordDispatch(NodeInterface $node): void {
    try {
      $storage = $this->entityTypeManager->getStorage('node');
      $fresh = $storage->loadUnchanged($node->id());
      if (!$fresh) {
        return;
      }

      $now = $this->time->getRequestTime();
      if ($fresh->hasField('field_review_dispatched_at')) {
        $fresh->set('field_review_dispatched_at', $now);
      }
      if ($fresh->hasField('field_review_status')) {
        $fresh->set('field_review_status', 'pending');
      }
      if ($fresh->hasField('field_review_recommendation')) {
        $fresh->set('field_review_recommendation', NULL);
      }
      if ($fresh->hasField('field_review_run_id')) {
        $fresh->set('field_review_run_id', NULL);
      }

      $fresh->_ood_software_suppress_notifications = TRUE;
      if (method_exists($fresh, 'setValidationRequired')) {
        $fresh->setValidationRequired(FALSE);
      }
      $fresh->save();
    }
    catch (\Throwable $e) {
      $this->logger->warning('Failed to record review dispatch on node @nid: @msg', [
        '@nid' => $node->id(),
        '@msg' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Trigger a workflow_dispatch on the appverse-review GitHub Actions workflow.
   *
   * @param string $targetRepo
   *   The target repo in "owner/name" format (e.g. "OSC/bc_osc_jupyter").
   * @param string $model
   *   Claude model to use. Defaults to "sonnet".
   *
   * @return bool
   *   TRUE if the dispatch succeeded (HTTP 204), FALSE otherwise.
   */
  public function dispatch(string $targetRepo, string $model = 'sonnet'): bool {
    // On non-production environments, only dispatch dry-run reviews to
    // avoid spending API credits on dev/staging test transitions.
    $env = getenv('PANTHEON_ENVIRONMENT');
    $aspects = ($env === 'live') ? 'all' : 'dry-run';
    if ($aspects === 'dry-run') {
      $this->logger->info('Non-production environment (@env): dispatching dry-run review for @repo.', [
        '@env' => $env ?: 'local',
        '@repo' => $targetRepo,
      ]);
    }

    $token = $this->getToken();
    if ($token === NULL) {
      $this->logger->error('Cannot dispatch review: no GitHub token found (tried keys @primary, @fallback).', [
        '@primary' => self::GITHUB_KEY_ID,
        '@fallback' => self::GITHUB_KEY_FALLBACK,
      ]);
      return FALSE;
    }

    $url = sprintf(
      'https://api.github.com/repos/%s/actions/workflows/%s/dispatches',
      self::REVIEW_REPO,
      self::WORKFLOW_FILE,
    );

    try {
      $response = $this->httpClient->post($url, [
        'headers' => $this->githubHeaders($token) + ['Content-Type' => 'application/json'],
        'json' => [
          'ref' => self::WORKFLOW_REF,
          'inputs' => [
            'target_repo' => $targetRepo,
            'target_branch' => '',
            'review_aspects' => $aspects,
            'model' => $model,
          ],
        ],
      ]);

      $status = $response->getStatusCode();
      if ($status === 204) {
        $this->logger->info('Dispatched AppVerse review for @repo.', [
          '@repo' => $targetRepo,
        ]);
        return TRUE;
      }

      $this->logger->warning('Unexpected status @status dispatching review for @repo.', [
        '@status' => $status,
        '@repo' => $targetRepo,
      ]);
      return FALSE;
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to dispatch review for @repo: @message', [
        '@repo' => $targetRepo,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Extract the GitHub repo URL from an appverse_repo node.
   *
   * @return string|null
   *   The raw URL string, or NULL if the field is empty/missing.
   */
  protected function extractRepoUrl(NodeInterface $node): ?string {
    if (!$node->hasField('field_repo_url') || $node->get('field_repo_url')->isEmpty()) {
      return NULL;
    }
    $value = $node->get('field_repo_url')->first()->getValue();
    $uri = $value['uri'] ?? NULL;
    return ($uri !== NULL && $uri !== '') ? $uri : NULL;
  }

  /**
   * Parse a GitHub URL into "owner/repo" format.
   *
   * @param string $url
   *   A GitHub repo URL (e.g. "https://github.com/OSC/bc_osc_jupyter").
   *
   * @return string|null
   *   "owner/repo" or NULL if the URL doesn't match.
   */
  protected function parseOwnerRepo(string $url): ?string {
    $parsed = parse_url($url);
    if (!isset($parsed['host']) || $parsed['host'] !== 'github.com') {
      return NULL;
    }
    $parts = explode('/', trim($parsed['path'] ?? '', '/'));
    if (count($parts) < 2) {
      return NULL;
    }
    $owner = $parts[0];
    $repo = preg_replace('/\.git$/', '', $parts[1]);
    // Enforce GitHub's naming rules: alphanumeric, hyphens, dots, underscores.
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $owner) || !preg_match('/^[A-Za-z0-9._-]+$/', $repo)) {
      return NULL;
    }
    return $owner . '/' . $repo;
  }

  /**
   * Poll GitHub Actions for completed review runs and process results.
   *
   * Called from CronManager. Finds appverse_repo nodes with
   * field_review_status=pending, checks for matching completed workflow
   * runs, downloads artifacts, and updates the nodes.
   */
  public function pollForResults(): void {
    $token = $this->getToken();
    if ($token === NULL) {
      return;
    }

    $pendingNodes = $this->getPendingReviewNodes();
    if (empty($pendingNodes)) {
      return;
    }

    $completedRuns = $this->fetchWorkflowRuns($token, 'completed');
    if ($completedRuns === NULL) {
      return;
    }
    $activeRuns = $this->fetchWorkflowRuns($token, 'in_progress') ?? [];

    $now = $this->time->getRequestTime();

    foreach ($pendingNodes as $node) {
      $dispatchedAt = $node->hasField('field_review_dispatched_at')
        ? (int) $node->get('field_review_dispatched_at')->value
        : 0;

      $repoUrl = $this->extractRepoUrl($node);
      if ($repoUrl === NULL) {
        continue;
      }
      $ownerRepo = $this->parseOwnerRepo($repoUrl);
      if ($ownerRepo === NULL) {
        continue;
      }

      // Check for a completed run first.
      $matchedRun = $this->matchRun($completedRuns, $ownerRepo, $dispatchedAt);
      if ($matchedRun !== NULL) {
        $this->processCompletedRun($node, $matchedRun, $token);
        continue;
      }

      // If a matching run is still in progress, update status and move on.
      $activeRun = $this->matchRun($activeRuns, $ownerRepo, $dispatchedAt);
      if ($activeRun !== NULL) {
        if ($node->hasField('field_review_status') && $node->get('field_review_status')->value !== 'in_progress') {
          $this->updateNodeReviewStatus($node, 'in_progress', (int) $activeRun['id']);
        }
        continue;
      }

      // No matching run found (completed or active). If the dispatch
      // is older than the stale timeout, mark as error so reviewers
      // can re-trigger via the review_to_review transition.
      if ($dispatchedAt > 0 && ($now - $dispatchedAt) > self::STALE_TIMEOUT_SECONDS) {
        $this->logger->warning('Review for node @nid has been pending for @hours hours with no matching run — marking as error.', [
          '@nid' => $node->id(),
          '@hours' => round(($now - $dispatchedAt) / 3600, 1),
        ]);
        $this->updateNodeReviewStatus($node, 'error', 0);
      }
    }
  }

  /**
   * Find appverse_repo nodes with a pending review dispatch.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  protected function getPendingReviewNodes(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->condition('type', 'appverse_repo')
      ->condition('field_review_status', 'pending')
      ->accessCheck(FALSE)
      ->execute();

    if (empty($nids)) {
      return [];
    }

    return $storage->loadMultiple($nids);
  }

  /**
   * Fetch recent workflow runs from GitHub Actions filtered by status.
   *
   * @param string $token
   *   GitHub API token.
   * @param string $status
   *   Run status filter: 'completed', 'in_progress', 'queued', etc.
   *
   * @return array|null
   *   Array of run objects, or NULL on failure.
   */
  protected function fetchWorkflowRuns(string $token, string $status = 'completed'): ?array {
    $url = sprintf(
      'https://api.github.com/repos/%s/actions/workflows/%s/runs?event=workflow_dispatch&status=%s&per_page=20',
      self::REVIEW_REPO,
      self::WORKFLOW_FILE,
      urlencode($status),
    );

    try {
      $response = $this->httpClient->get($url, [
        'headers' => $this->githubHeaders($token),
      ]);
      $body = Json::decode($response->getBody()->getContents());
      return $body['workflow_runs'] ?? [];
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to fetch workflow runs: @msg', [
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Match a completed run to a pending node by target_repo and timing.
   *
   * GitHub's workflow_dispatch API doesn't return a run ID, so we
   * correlate by checking that the run's inputs.target_repo matches
   * and the run was created after the dispatch timestamp.
   *
   * @return array|null
   *   The matched run object, or NULL if no match.
   */
  protected function matchRun(array $runs, string $targetRepo, int $dispatchedAt): ?array {
    foreach ($runs as $run) {
      $runTargetRepo = $run['inputs']['target_repo'] ?? NULL;
      if ($runTargetRepo !== $targetRepo) {
        continue;
      }

      $runCreatedAt = strtotime($run['created_at'] ?? '');
      if ($runCreatedAt === FALSE) {
        continue;
      }

      // Run must have been created within a reasonable window after dispatch.
      // GitHub may take a few seconds to create the run after dispatch.
      if ($runCreatedAt >= ($dispatchedAt - 60)) {
        return $run;
      }
    }
    return NULL;
  }

  /**
   * Process a completed workflow run: download artifacts, update the node.
   */
  protected function processCompletedRun(NodeInterface $node, array $run, string $token): void {
    $runId = $run['id'];
    $conclusion = $run['conclusion'] ?? 'unknown';

    if ($conclusion !== 'success') {
      $this->logger->warning('Review run @id for node @nid concluded with @conclusion.', [
        '@id' => $runId,
        '@nid' => $node->id(),
        '@conclusion' => $conclusion,
      ]);
      $this->updateNodeReviewStatus($node, 'error', $runId);
      return;
    }

    $summary = $this->downloadReviewSummary($runId, $token);
    $pdfFile = $this->downloadReviewPdf($runId, $token, $node);

    $this->updateNodeWithResults($node, $runId, $summary, $pdfFile);
  }

  /**
   * Download and parse review-summary.json from a workflow run's artifacts.
   *
   * @return array|null
   *   Parsed JSON summary, or NULL on failure.
   */
  protected function downloadReviewSummary(int $runId, string $token): ?array {
    $artifacts = $this->fetchArtifacts($runId, $token);
    if ($artifacts === NULL) {
      return NULL;
    }

    foreach ($artifacts as $artifact) {
      $name = $artifact['name'] ?? '';
      if (!str_starts_with($name, 'review-')) {
        continue;
      }

      $zipContents = $this->downloadArtifactZip($artifact['archive_download_url'], $token);
      if ($zipContents === NULL) {
        continue;
      }

      $extracted = $this->extractFromZip($zipContents, 'review-summary.json');
      if ($extracted !== NULL) {
        $parsed = Json::decode($extracted);
        if (is_array($parsed)) {
          return $parsed;
        }
      }
    }

    $this->logger->warning('No review-summary.json found in artifacts for run @id.', [
      '@id' => $runId,
    ]);
    return NULL;
  }

  /**
   * Download the review PDF from a workflow run's artifacts.
   *
   * @return \Drupal\file\FileInterface|null
   *   Saved file entity, or NULL on failure.
   */
  protected function downloadReviewPdf(int $runId, string $token, NodeInterface $node): ?\Drupal\file\FileInterface {
    $artifacts = $this->fetchArtifacts($runId, $token);
    if ($artifacts === NULL) {
      return NULL;
    }

    foreach ($artifacts as $artifact) {
      $name = $artifact['name'] ?? '';
      if (!str_starts_with($name, 'review-')) {
        continue;
      }

      $zipContents = $this->downloadArtifactZip($artifact['archive_download_url'], $token);
      if ($zipContents === NULL) {
        continue;
      }

      $pdfData = $this->extractFromZip($zipContents, '.pdf');
      if ($pdfData !== NULL) {
        return $this->savePdfFile($pdfData, $node);
      }
    }

    return NULL;
  }

  /**
   * Fetch the artifacts list for a workflow run.
   */
  protected function fetchArtifacts(int $runId, string $token): ?array {
    $url = sprintf(
      'https://api.github.com/repos/%s/actions/runs/%d/artifacts',
      self::REVIEW_REPO,
      $runId,
    );

    try {
      $response = $this->httpClient->get($url, [
        'headers' => $this->githubHeaders($token),
      ]);
      $body = Json::decode($response->getBody()->getContents());
      return $body['artifacts'] ?? [];
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to fetch artifacts for run @id: @msg', [
        '@id' => $runId,
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Download an artifact zip from GitHub.
   *
   * @return string|null
   *   Raw zip file contents, or NULL on failure.
   */
  protected function downloadArtifactZip(string $url, string $token): ?string {
    try {
      $response = $this->httpClient->get($url, [
        'headers' => $this->githubHeaders($token),
        'allow_redirects' => TRUE,
      ]);
      return $response->getBody()->getContents();
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to download artifact zip: @msg', [
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Extract a file from a zip archive by name suffix.
   *
   * @param string $zipContents
   *   Raw zip bytes.
   * @param string $nameSuffix
   *   Filename or suffix to match (e.g. 'review-summary.json' or '.pdf').
   *
   * @return string|null
   *   File contents, or NULL if not found.
   */
  protected function extractFromZip(string $zipContents, string $nameSuffix): ?string {
    $tmpFile = $this->fileSystem->tempnam('temporary://', 'review_');
    if ($tmpFile === FALSE) {
      return NULL;
    }
    file_put_contents($tmpFile, $zipContents);

    $zip = new \ZipArchive();
    if ($zip->open($tmpFile) !== TRUE) {
      @unlink($tmpFile);
      return NULL;
    }

    $result = NULL;
    for ($i = 0; $i < $zip->numFiles; $i++) {
      $entryName = $zip->getNameIndex($i);
      if (str_ends_with($entryName, $nameSuffix)) {
        $result = $zip->getFromIndex($i);
        if ($result === FALSE) {
          $result = NULL;
        }
        break;
      }
    }

    $zip->close();
    @unlink($tmpFile);
    return $result;
  }

  /**
   * Save PDF data as a Drupal file entity in private://.
   *
   * @return \Drupal\file\FileInterface|null
   */
  protected function savePdfFile(string $pdfData, NodeInterface $node): ?\Drupal\file\FileInterface {
    $directory = 'private://appverse-reviews';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);

    $repoUrl = $this->extractRepoUrl($node);
    $slug = $repoUrl ? str_replace('/', '-', $this->parseOwnerRepo($repoUrl) ?? 'unknown') : 'unknown';
    $filename = sprintf('review-%s-%s.pdf', $slug, date('Y-m-d'));
    $destination = $directory . '/' . $filename;

    $uri = $this->fileSystem->saveData($pdfData, $destination, FileSystemInterface::EXISTS_RENAME);
    if ($uri === FALSE) {
      $this->logger->error('Failed to save review PDF for node @nid.', [
        '@nid' => $node->id(),
      ]);
      return NULL;
    }

    $fileStorage = $this->entityTypeManager->getStorage('file');
    $file = $fileStorage->create([
      'uri' => $uri,
      'filename' => $filename,
      'filemime' => 'application/pdf',
      'status' => 1,
    ]);
    $file->save();
    return $file;
  }

  /**
   * Update an appverse_repo node with review results.
   */
  protected function updateNodeWithResults(NodeInterface $node, int $runId, ?array $summary, ?\Drupal\file\FileInterface $pdfFile): void {
    try {
      $storage = $this->entityTypeManager->getStorage('node');
      $fresh = $storage->loadUnchanged($node->id());
      if (!$fresh) {
        return;
      }

      if ($fresh->hasField('field_review_status')) {
        $fresh->set('field_review_status', 'complete');
      }
      if ($fresh->hasField('field_review_run_id')) {
        $fresh->set('field_review_run_id', $runId);
      }

      if ($summary !== NULL && $fresh->hasField('field_review_recommendation')) {
        $recommendation = $summary['recommendation'] ?? NULL;
        $fresh->set('field_review_recommendation', $recommendation);
      }

      if ($pdfFile !== NULL && $fresh->hasField('field_review_report')) {
        $fresh->set('field_review_report', ['target_id' => $pdfFile->id()]);
      }

      $fresh->_ood_software_suppress_notifications = TRUE;
      if (method_exists($fresh, 'setValidationRequired')) {
        $fresh->setValidationRequired(FALSE);
      }
      $fresh->save();

      $this->logger->info('Review results saved for node @nid (run @runId, recommendation: @rec).', [
        '@nid' => $node->id(),
        '@runId' => $runId,
        '@rec' => $summary['recommendation'] ?? 'unknown',
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to save review results for node @nid: @msg', [
        '@nid' => $node->id(),
        '@msg' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Mark a node's review status (e.g. on run failure).
   */
  protected function updateNodeReviewStatus(NodeInterface $node, string $status, int $runId): void {
    try {
      $storage = $this->entityTypeManager->getStorage('node');
      $fresh = $storage->loadUnchanged($node->id());
      if (!$fresh) {
        return;
      }

      if ($fresh->hasField('field_review_status')) {
        $fresh->set('field_review_status', $status);
      }
      if ($fresh->hasField('field_review_run_id')) {
        $fresh->set('field_review_run_id', $runId);
      }

      $fresh->_ood_software_suppress_notifications = TRUE;
      if (method_exists($fresh, 'setValidationRequired')) {
        $fresh->setValidationRequired(FALSE);
      }
      $fresh->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to update review status for node @nid: @msg', [
        '@nid' => $node->id(),
        '@msg' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Build standard GitHub API headers.
   */
  protected function githubHeaders(string $token): array {
    return [
      'Authorization' => 'Bearer ' . $token,
      'Accept' => 'application/vnd.github+json',
      'X-GitHub-Api-Version' => '2022-11-28',
    ];
  }

  /**
   * Retrieve the GitHub token from the Key module.
   *
   * Tries the dedicated review key first, falls back to the shared key.
   */
  protected function getToken(): ?string {
    $key = $this->keyRepository->getKey(self::GITHUB_KEY_ID);
    if ($key) {
      $value = $key->getKeyValue();
      if ($value !== NULL && $value !== '') {
        return $value;
      }
    }

    $key = $this->keyRepository->getKey(self::GITHUB_KEY_FALLBACK);
    if ($key) {
      $value = $key->getKeyValue();
      if ($value !== NULL && $value !== '') {
        return $value;
      }
    }

    return NULL;
  }

}
