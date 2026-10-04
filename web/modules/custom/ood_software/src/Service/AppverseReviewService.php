<?php

namespace Drupal\ood_software\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
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
 * LLM-based review pipeline and produces a PDF report + JSON summary.
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
   * The workflow's model input: "qwen" runs on the on-prem gateway, "sonnet"
   * or "opus" on the Anthropic API.
   *
   * Sonnet until Qwen is benchmarked: the first Qwen review through the
   * portal (appverse-review run 36917068688) failed the feedback-floor and
   * key checks on a repo Sonnet had passed the same day.
   */
  const DEFAULT_MODEL = 'sonnet';

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
    protected AppverseReviewSeeder $seeder,
    // Who starts a review run; the imported review is authored by them.
    // Optional so callers built without it (unit tests) still work.
    protected ?AccountInterface $currentUser = NULL,
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

    $this->dispatchForNode($node);
  }

  /**
   * Starts a review for a repo node, without touching its moderation state.
   *
   * The one implementation behind the ready_for_review transition, the hub's
   * Start review action, and any explicit caller. Resolves owner/repo from
   * the node, sends the dispatch with a correlation id, and on success records
   * the same timestamp on the node so the id can be recomputed when the run
   * is polled for. A failed dispatch leaves the node untouched: marking it
   * pending would have the poll loop wait for a run that was never created.
   *
   * @return bool
   *   TRUE when GitHub accepted the dispatch.
   */
  public function dispatchForNode(NodeInterface $node, string $model = self::DEFAULT_MODEL, ?string $aspectsOverride = NULL): bool {
    $repoUrl = $this->extractRepoUrl($node);
    if ($repoUrl === NULL) {
      $this->logger->warning('Cannot dispatch review for repo node @nid: no field_repo_url value.', [
        '@nid' => $node->id(),
      ]);
      return FALSE;
    }
    $ownerRepo = $this->parseOwnerRepo($repoUrl);
    if ($ownerRepo === NULL) {
      $this->logger->warning('Cannot dispatch review for repo node @nid: could not parse owner/repo from URL @url.', [
        '@nid' => $node->id(),
        '@url' => $repoUrl,
      ]);
      return FALSE;
    }
    // One timestamp for both the node and the id, so the id can be recomputed
    // from the node when the run is polled for.
    $dispatchedAt = $this->time->getRequestTime();
    $correlationId = self::correlationId((int) $node->id(), $dispatchedAt);
    if (!$this->dispatch($ownerRepo, $model, $correlationId, $aspectsOverride)) {
      return FALSE;
    }
    $this->recordDispatch($node, $dispatchedAt);
    return TRUE;
  }

  /**
   * The newest appverse_review node for a repo, or NULL.
   *
   * Access-checked, so a caller building a link only gets a review the
   * current user may view.
   */
  public function latestReviewFor(NodeInterface $repo): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    // The query's access check applies node-access grants only; it does not
    // drop unpublished (draft) reviews for a viewer who may not see them.
    // So walk the reviews newest first and return the first the viewer may
    // actually view.
    $nids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'appverse_review')
      ->condition('field_arv_repo', $repo->id())
      ->sort('created', 'DESC')
      ->sort('nid', 'DESC')
      ->execute();
    foreach ($storage->loadMultiple($nids) as $review) {
      if ($review instanceof NodeInterface && $review->access('view')) {
        return $review;
      }
    }
    return NULL;
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
  protected function recordDispatch(NodeInterface $node, int $dispatchedAt): void {
    try {
      $storage = $this->entityTypeManager->getStorage('node');
      $fresh = $storage->loadUnchanged($node->id());
      if (!$fresh) {
        return;
      }

      if ($fresh->hasField('field_review_dispatched_at')) {
        $fresh->set('field_review_dispatched_at', $dispatchedAt);
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
      if ($fresh->hasField('field_review_dispatched_by')) {
        // Start review, or the contributor's send-for-review transition.
        $starter = $this->currentUser && $this->currentUser->isAuthenticated() ? $this->currentUser->id() : NULL;
        $fresh->set('field_review_dispatched_by', $starter);
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
   *   The workflow's model input. Defaults to self::DEFAULT_MODEL.
   *
   * @return bool
   *   TRUE if the dispatch succeeded (HTTP 204), FALSE otherwise.
   */
  public function dispatch(string $targetRepo, string $model = self::DEFAULT_MODEL, string $correlationId = '', ?string $aspectsOverride = NULL): bool {
    // On non-production environments, only dispatch dry-run reviews to
    // avoid spending API credits on dev/staging test transitions.
    // $aspectsOverride exists for explicit callers (drush php:eval) that
    // want one real review from a non-production site to test the loop;
    // the transition hook never sets it.
    $env = getenv('PANTHEON_ENVIRONMENT');
    $aspects = $aspectsOverride ?? ($this->fullReviewsEnabled() ? 'all' : 'dry-run');
    if ($aspects === 'dry-run') {
      $this->logger->info('Environment @env is not allowed full reviews: dispatching dry-run review for @repo.', [
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
            'correlation_id' => $correlationId,
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
   * Called from CronManager. Finds appverse_repo nodes whose review is
   * pending or in progress, checks for matching completed workflow runs,
   * downloads artifacts, and updates the nodes.
   *
   * Every pass that has a review to poll logs one line per node saying what
   * it found and a summary line, so a cron run can be read back from the
   * log; a pass with nothing to poll logs only at debug level.
   */
  public function pollForResults(): void {
    $token = $this->getToken();
    if ($token === NULL) {
      $this->logger->error('Review poll: no GitHub token found (tried keys @primary, @fallback); nothing polled.', [
        '@primary' => self::GITHUB_KEY_ID,
        '@fallback' => self::GITHUB_KEY_FALLBACK,
      ]);
      return;
    }

    $pendingNodes = $this->getPendingReviewNodes();
    if (empty($pendingNodes)) {
      $this->logger->debug('Review poll: no pending or in-progress reviews.');
      return;
    }

    $completedRuns = $this->fetchWorkflowRuns($token, 'completed');
    if ($completedRuns === NULL) {
      return;
    }
    $activeRuns = $this->fetchWorkflowRuns($token, 'in_progress') ?? [];

    $now = $this->time->getRequestTime();
    $outcomes = [];

    foreach ($pendingNodes as $node) {
      $dispatchedAt = $node->hasField('field_review_dispatched_at')
        ? (int) $node->get('field_review_dispatched_at')->value
        : 0;

      if ($dispatchedAt <= 0) {
        // Nothing to correlate on; the stale timeout below cannot fire either.
        $this->logger->warning('Review for node @nid is pending with no dispatch time; marking as error.', ['@nid' => $node->id()]);
        $this->updateNodeReviewStatus($node, 'error', 0);
        $outcomes['error'] = ($outcomes['error'] ?? 0) + 1;
        continue;
      }
      $correlationId = self::correlationId((int) $node->id(), $dispatchedAt);
      $minutes = (int) round(($now - $dispatchedAt) / 60);

      // Check for a completed run first. processCompletedRun() logs what it
      // did with the run (seeded, dry-run, failed).
      $matchedRun = $this->matchRun($completedRuns, $correlationId, $dispatchedAt);
      if ($matchedRun !== NULL) {
        $this->logger->info('Review poll: node @nid matched completed run @id (@conclusion), dispatched @min min ago.', [
          '@nid' => $node->id(),
          '@id' => $matchedRun['id'],
          '@conclusion' => $matchedRun['conclusion'] ?? 'unknown',
          '@min' => $minutes,
        ]);
        $this->processCompletedRun($node, $matchedRun, $token);
        $outcomes['completed'] = ($outcomes['completed'] ?? 0) + 1;
        continue;
      }

      // If a matching run is still in progress, update status and move on.
      $activeRun = $this->matchRun($activeRuns, $correlationId, $dispatchedAt);
      if ($activeRun !== NULL) {
        $this->logger->info('Review poll: node @nid run @id is still running, dispatched @min min ago.', [
          '@nid' => $node->id(),
          '@id' => $activeRun['id'],
          '@min' => $minutes,
        ]);
        if ($node->hasField('field_review_status') && $node->get('field_review_status')->value !== 'in_progress') {
          $this->updateNodeReviewStatus($node, 'in_progress', (int) $activeRun['id']);
        }
        $outcomes['running'] = ($outcomes['running'] ?? 0) + 1;
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
        $outcomes['timed out'] = ($outcomes['timed out'] ?? 0) + 1;
        continue;
      }
      // Queued runs are not fetched, so a run GitHub has not started yet
      // lands here too.
      $this->logger->info('Review poll: node @nid has no completed or running run titled @cid yet, dispatched @min min ago.', [
        '@nid' => $node->id(),
        '@cid' => $correlationId,
        '@min' => $minutes,
      ]);
      $outcomes['waiting'] = ($outcomes['waiting'] ?? 0) + 1;
    }

    $parts = [];
    foreach ($outcomes as $outcome => $count) {
      $parts[] = $count . ' ' . $outcome;
    }
    $this->logger->info('Review poll: @n review(s) to poll against @c completed and @a running run(s): @outcomes.', [
      '@n' => count($pendingNodes),
      '@c' => count($completedRuns),
      '@a' => count($activeRuns),
      '@outcomes' => implode(', ', $parts),
    ]);
  }

  /**
   * Find appverse_repo nodes whose review is pending or in progress.
   *
   * Both, not just pending: the loop moves a node to in_progress when it
   * first sees the run running, and the node still needs polling until the
   * run completes (or the stale timeout fires).
   *
   * @return \Drupal\node\NodeInterface[]
   */
  protected function getPendingReviewNodes(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->condition('type', 'appverse_repo')
      ->condition('field_review_status', ['pending', 'in_progress'], 'IN')
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
   * Match a run to a pending node by its correlation id.
   *
   * GitHub's workflow_dispatch API doesn't return a run ID, and the
   * workflow-runs API doesn't return dispatch inputs, so we correlate on
   * the id the workflow echoes into its run title (see correlationId()).
   *
   * @return array|null
   *   The matched run object, or NULL if no match.
   */
  protected function matchRun(array $runs, string $correlationId, int $dispatchedAt): ?array {
    foreach ($runs as $run) {
      if (!self::runMatches($run, $correlationId)) {
        continue;
      }
      $runCreatedAt = strtotime($run['created_at'] ?? '');
      if ($runCreatedAt === FALSE) {
        continue;
      }
      // Sanity check only: the id is unique per dispatch. GitHub may take a
      // few seconds to create the run after the dispatch call returns.
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
    $runId = (int) $run['id'];
    $conclusion = $run['conclusion'] ?? 'unknown';
    if ($conclusion !== 'success') {
      // Since appverse-review#43 a review that writes no output concludes
      // red rather than green-with-no-artifact, so this is the terminal
      // state for a failed review.
      $this->logger->warning('Review run @id for node @nid concluded with @conclusion.', [
        '@id' => $runId,
        '@nid' => $node->id(),
        '@conclusion' => $conclusion,
      ]);
      $this->updateNodeReviewStatus($node, 'error', $runId);
      return;
    }

    // Non-production environments dispatch dry-runs: the workflow writes a
    // placeholder report and no artifact, by design.
    if (self::runAspects($run) === 'dry-run') {
      $this->logger->notice('Review run @id for node @nid was a dry-run; nothing to seed.', [
        '@id' => $runId,
        '@nid' => $node->id(),
      ]);
      $this->updateNodeReviewStatus($node, 'complete', $runId);
      return;
    }

    $files = $this->downloadReviewFiles($runId, $token);
    $artifact = isset($files['artifact']) ? Json::decode((string) file_get_contents($files['artifact'])) : NULL;
    if (!is_array($artifact)) {
      $this->logger->error('Review run @id for node @nid has no usable artifact JSON (files: @files); marking as error.', [
        '@id' => $runId,
        '@nid' => $node->id(),
        '@files' => implode(', ', array_keys($files ?? [])) ?: 'none',
      ]);
      $this->cleanupReviewFiles($files);
      $this->updateNodeReviewStatus($node, 'error', $runId);
      return;
    }

    try {
      // The repo node is passed explicitly: this run was dispatched for it,
      // so there is nothing to look up by URL. force: a re-dispatched run on
      // the same commit is a deliberate re-review and gets its own review
      // node; the reviewer asked for it and the results will differ.
      $review = $this->seeder->seedFromArtifact($artifact, $node, dirname($files['artifact']), TRUE);
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to seed a review from run @id for node @nid: @msg', [
        '@id' => $runId,
        '@nid' => $node->id(),
        '@msg' => $e->getMessage(),
      ]);
      $this->cleanupReviewFiles($files);
      $this->updateNodeReviewStatus($node, 'error', $runId);
      return;
    }
    $this->cleanupReviewFiles($files);

    $this->recordSeededReview($node, $runId, $review, $artifact['recommendation']['decision'] ?? NULL);
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
   * Whether an environment may dispatch full (credit-spending) reviews.
   *
   * live always may. Any other environment must be named in the
   * ood_software.review_full_environments setting — a Pantheon multidev
   * that is testing the loop, say, via its settings.php. No name (local ddev)
   * reads as "local". Everything else dispatches dry-runs, which produce a
   * placeholder report and cost nothing.
   */
  public static function fullReviewsAllowed(?string $env, array $allowed): bool {
    $env = ($env === NULL || $env === '') ? 'local' : $env;
    return $env === 'live' || in_array($env, $allowed, TRUE);
  }

  /**
   * fullReviewsAllowed() for this site: PANTHEON_ENVIRONMENT against the
   * ood_software.review_full_environments setting.
   */
  public function fullReviewsEnabled(): bool {
    $allowed = Settings::get('ood_software.review_full_environments', []);
    return self::fullReviewsAllowed(getenv('PANTHEON_ENVIRONMENT') ?: NULL, is_array($allowed) ? $allowed : []);
  }

  /**
   * The id the portal sends with a dispatch and finds in the run's title.
   *
   * Recomputable from the node (nid + field_review_dispatched_at), so no
   * field is needed to remember it. The workflow-runs API does not return
   * dispatch inputs, so the workflow echoes this id into its run-name and
   * the loop matches on that instead of on target_repo and timing.
   */
  public static function correlationId(int $nid, int $dispatchedAt): string {
    return sprintf('portal-%d-%d', $nid, $dispatchedAt);
  }

  /**
   * Whether a run object from the workflow-runs API carries this id.
   *
   * Matches the id as a whole token of the title, so portal-1-10 does not
   * match portal-1-100.
   */
  public static function runMatches(array $run, string $correlationId): bool {
    if ($correlationId === '') {
      return FALSE;
    }
    $title = (string) ($run['display_title'] ?? '');
    return (bool) preg_match('/(?<![\w-])' . preg_quote($correlationId, '/') . '(?![\w-])/', $title);
  }

  /**
   * The review_aspects the run was dispatched with, read from its title.
   *
   * The title is "Review <target_repo> · <aspects> · <correlation_id>";
   * NULL when the run predates run-name or was not dispatched that way.
   */
  public static function runAspects(array $run): ?string {
    $title = (string) ($run['display_title'] ?? '');
    $parts = array_map('trim', explode(' · ', $title));
    if (count($parts) < 2 || !str_starts_with($parts[0], 'Review ')) {
      return NULL;
    }
    return $parts[1] !== '' ? $parts[1] : NULL;
  }

  /**
   * Maps the artifact's recommendation to the repo node's field enum.
   *
   * The artifact says accept / accept_with_suggestions / request_changes /
   * reject; field_review_recommendation allows accepted /
   * accepted_with_suggestions / changes_requested / rejected. An unknown
   * value maps to NULL rather than a guess.
   */
  public static function mapRecommendation(?string $decision): ?string {
    $map = [
      'accept' => 'accepted',
      'accept_with_suggestions' => 'accepted_with_suggestions',
      'request_changes' => 'changes_requested',
      'reject' => 'rejected',
    ];
    $key = strtolower(trim((string) $decision));
    if ($key === '') {
      return NULL;
    }
    if (isset($map[$key])) {
      return $map[$key];
    }
    return in_array($key, $map, TRUE) ? $key : NULL;
  }

  /**
   * Extracts the review files from a workflow-run artifact zip into $dir.
   *
   * GitHub packs the run's files under a nested path; only the basename
   * matters. Returns the extracted paths keyed 'artifact' (the
   * *.artifact.json), 'md', 'pdf', 'html' — whichever were present, in that
   * order — and nothing else lands in $dir. Bytes that are not a zip give [].
   *
   * The reports are the files that share the artifact JSON's stem
   * (review-<slug>.md beside review-<slug>.artifact.json). The artifact holds
   * other files of the same types — the pre-review facts include
   * pre-review/tool-table.md, zipped ahead of the report — and taking the
   * first .md found would store that instead and leave the report empty.
   * With no artifact JSON (a dry-run), any review-* report file counts.
   */
  public static function extractReviewFiles(string $zipContents, string $dir): array {
    $tmpFile = tempnam(sys_get_temp_dir(), 'review_zip_');
    if ($tmpFile === FALSE) {
      return [];
    }
    file_put_contents($tmpFile, $zipContents);
    $zip = new \ZipArchive();
    if ($zip->open($tmpFile) !== TRUE) {
      @unlink($tmpFile);
      return [];
    }
    // First pass: the artifact JSON names the stem, wherever it sits in the
    // zip's order.
    $stem = NULL;
    for ($i = 0; $i < $zip->numFiles; $i++) {
      $name = basename($zip->getNameIndex($i));
      if (str_ends_with($name, '.artifact.json')) {
        $stem = substr($name, 0, -strlen('.artifact.json'));
        break;
      }
    }
    $found = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
      $name = basename($zip->getNameIndex($i));
      if (str_ends_with($name, '.artifact.json')) {
        $kind = 'artifact';
      }
      elseif (preg_match('/^(.+)\.(md|pdf|html)$/', $name, $m)) {
        $isReport = $stem !== NULL ? $m[1] === $stem : str_starts_with($name, 'review-');
        if (!$isReport) {
          continue;
        }
        $kind = $m[2];
      }
      else {
        continue;
      }
      if (isset($found[$kind])) {
        continue;
      }
      $bytes = $zip->getFromIndex($i);
      if ($bytes === FALSE) {
        continue;
      }
      $path = rtrim($dir, '/') . '/' . $name;
      file_put_contents($path, $bytes);
      $found[$kind] = $path;
    }
    $zip->close();
    @unlink($tmpFile);
    $ordered = [];
    foreach (['artifact', 'md', 'pdf', 'html'] as $kind) {
      if (isset($found[$kind])) {
        $ordered[$kind] = $found[$kind];
      }
    }
    return $ordered;
  }

  /**
   * Downloads the run's review-* artifact and extracts the review files.
   *
   * @return array|null
   *   The extracted files as extractReviewFiles() returns them, or NULL when
   *   the run has no review-* artifact or it could not be fetched.
   */
  protected function downloadReviewFiles(int $runId, string $token): ?array {
    $artifacts = $this->fetchArtifacts($runId, $token);
    if ($artifacts === NULL) {
      return NULL;
    }
    foreach ($artifacts as $artifact) {
      if (!str_starts_with($artifact['name'] ?? '', 'review-')) {
        continue;
      }
      $zipContents = $this->downloadArtifactZip($artifact['archive_download_url'], $token);
      if ($zipContents === NULL) {
        continue;
      }
      $dir = $this->fileSystem->realpath('temporary://') . '/review-run-' . $runId . '-' . bin2hex(random_bytes(4));
      if (!@mkdir($dir, 0700, TRUE)) {
        $this->logger->error('Could not create a temp directory for run @id.', ['@id' => $runId]);
        return NULL;
      }
      $files = self::extractReviewFiles($zipContents, $dir);
      if ($files !== []) {
        return $files;
      }
      @rmdir($dir);
    }
    $this->logger->warning('No review-* artifact with review files found for run @id.', ['@id' => $runId]);
    return NULL;
  }

  /**
   * Removes the extracted review files and their temp directory.
   */
  protected function cleanupReviewFiles(?array $files): void {
    if (!$files) {
      return;
    }
    foreach ($files as $path) {
      @unlink($path);
    }
    @rmdir(dirname(reset($files)));
  }

  /**
   * Marks the repo node complete and records the tool's recommendation.
   *
   * field_review_report is left alone on purpose: the review's PDF is a
   * private file owned by the review node, and referencing it from the repo
   * node would let anyone who can view the repo download a Draft review.
   * The hub should link to the review node instead.
   */
  protected function recordSeededReview(NodeInterface $node, int $runId, NodeInterface $review, ?string $decision): void {
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
      if ($fresh->hasField('field_review_recommendation')) {
        $mapped = self::mapRecommendation($decision);
        if ($mapped === NULL && $decision !== NULL) {
          $this->logger->warning('Run @id: recommendation "@decision" has no field value; left empty.', [
            '@id' => $runId,
            '@decision' => $decision,
          ]);
        }
        $fresh->set('field_review_recommendation', $mapped);
      }
      $fresh->_ood_software_suppress_notifications = TRUE;
      if (method_exists($fresh, 'setValidationRequired')) {
        $fresh->setValidationRequired(FALSE);
      }
      $fresh->save();

      $this->logger->notice('Seeded review @review from run @id for repo @nid (recommendation: @rec).', [
        '@review' => $review->id(),
        '@id' => $runId,
        '@nid' => $node->id(),
        '@rec' => $decision ?? 'none',
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->error('Review @review was seeded from run @id but the repo node @nid could not be updated: @msg', [
        '@review' => $review->id(),
        '@id' => $runId,
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
