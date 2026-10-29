<?php

namespace Drupal\ood_software\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\DestructableInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Lock\LockBackendInterface;
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
class AppverseReviewService implements DestructableInterface {

  /**
   * Minimum seconds between dispatches for the same node.
   */
  const DEBOUNCE_SECONDS = 300;

  /**
   * How long a dispatch lock lasts if its process dies before releasing it.
   */
  const DISPATCH_LOCK_SECONDS = 120;

  /**
   * Key-value collection of the transition dispatches not yet started.
   *
   * One key per repo nid, valued ['queued' => request time, 'queued_at' =>
   * current time, 'retry_after' => time, 'attempts' => int]. queued_at is the
   * transition's time and never changes; retry_after is when the cron poll may
   * next try it. A transition's run starts in
   * destruct(), after the response; a fatal or timeout before then would lose
   * it, so the cron poll retries what is left. One key per repo, not one map,
   * so concurrent requests settling different repos do not overwrite each
   * other's entries.
   */
  const PENDING_TRANSITION_COLLECTION = 'ood_software.pending_transition_dispatch';

  /**
   * Seconds an owed transition dispatch is left to its own request.
   */
  const PENDING_TRANSITION_RETRY_AFTER = 300;

  /**
   * Attempts at an owed transition dispatch before it is given up.
   */
  const PENDING_TRANSITION_MAX_ATTEMPTS = 3;

  /**
   * Seconds after which a pending review is considered stale.
   * Reviewers then rerun it with Run AI report.
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
   * The default where the ood_software.review_model setting names none, or
   * names a model the workflow does not accept (see reviewModel()). The
   * on-prem model, as the workflow's own default is: a review goes to the
   * Anthropic API only when a setting chooses it.
   */
  const DEFAULT_MODEL = 'qwen';

  /**
   * The model inputs the review workflow accepts.
   */
  const MODELS = ['qwen', 'sonnet', 'opus'];

  /**
   * Drupal Key module key ID for the GitHub token.
   */
  const GITHUB_KEY_ID = 'appverse_review_github';

  /**
   * Fallback key ID if the dedicated key doesn't exist.
   */
  const GITHUB_KEY_FALLBACK = 'appverse_github';

  protected LoggerInterface $logger;

  /**
   * Repos whose save entered ready_for_review, by node id, awaiting destruct().
   *
   * @var array<int, int>
   */
  protected array $pendingTransitionDispatch = [];

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
    // Emails the reviewers when a run is ready or fails (#47).
    protected ?RepoNotificationService $notifier = NULL,
    // Writes review tracking fields without burying a forward revision.
    protected ?InPlaceSaver $inPlaceSaver = NULL,
    // Serializes dispatches per repo. Optional: unit tests skip locking.
    protected ?LockBackendInterface $lock = NULL,
    // Keeps transition dispatches until started, for the cron retry.
    // Optional: unit tests skip it.
    protected ?KeyValueFactoryInterface $keyValue = NULL,
  ) {
    $this->logger = $loggerFactory->get('ood_software');
  }

  /**
   * The store of transition dispatches not yet started, if there is one.
   */
  protected function owedDispatches(): ?KeyValueStoreInterface {
    return $this->keyValue?->get(self::PENDING_TRANSITION_COLLECTION);
  }

  /**
   * The saver for review tracking writes.
   *
   * Built on demand for callers constructed without one (unit tests).
   */
  protected function inPlaceSaver(): InPlaceSaver {
    return $this->inPlaceSaver ??= new InPlaceSaver($this->entityTypeManager);
  }

  /**
   * React to a moderation state transition on an appverse_repo node.
   *
   * Only queues a review when entering ready_for_review from a different
   * state, and never while a run is pending or in progress. A save that stays
   * in ready_for_review is not a request for a new run: the app updater,
   * Re-sync and a contributor's edit all save a repo that is awaiting review,
   * and each used to start a run that superseded the review being curated. A
   * deliberate rerun is Run AI report (runReview()) or Start the next round.
   *
   * The run starts in destruct(), after the save: dispatching here would
   * re-save the node inside its own hook_node_update(), before
   * content_moderation has written its state record, which creates a second
   * state entity in draft and drops the repo from the reviewer queue.
   *
   * The repo is also written to a key-value store (not a node save),
   * so a request that dies before destruct() leaves the run for the cron
   * poll to start (retryPendingTransitionDispatches()).
   *
   * @param \Drupal\node\NodeInterface $node
   *   The appverse_repo node (post-save).
   * @param string|null $previousState
   *   The moderation_state before this save, or NULL if unknown.
   */
  public function onTransition(NodeInterface $node, ?string $previousState): void {
    if ($this->shouldDispatchOnTransition($node, $previousState)) {
      $nid = (int) $node->id();
      $this->pendingTransitionDispatch[$nid] = $nid;
      // queued_at is the transition's real time, on the same clock as
      // field_review_dispatched_at. In a long drush or queue process the
      // request time is the process start, so a run started between the two
      // must not read as this one (startedSinceQueued()).
      $now = $this->time->getCurrentTime();
      $this->owedDispatches()?->set((string) $nid, [
        'queued' => $this->time->getRequestTime(),
        'queued_at' => $now,
        'retry_after' => $now + self::PENDING_TRANSITION_RETRY_AFTER,
        'attempts' => 0,
      ]);
    }
  }

  /**
   * Whether a save that moved a repo to $node's state should start a run.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The appverse_repo node (post-save).
   * @param string|null $previousState
   *   The moderation_state before this save, or NULL if unknown.
   */
  public function shouldDispatchOnTransition(NodeInterface $node, ?string $previousState): bool {
    if ($node->bundle() !== 'appverse_repo') {
      return FALSE;
    }

    $newState = $node->get('moderation_state')->value;
    if ($newState !== 'ready_for_review') {
      return FALSE;
    }

    // Skip if the previous state is unknown (NULL) to avoid false
    // triggers on bulk operations or migrations.
    if ($previousState === NULL) {
      return FALSE;
    }

    if ($previousState === 'ready_for_review') {
      return FALSE;
    }
    if ($this->isRunInFlight($node)) {
      $this->logger->info('Skipping review dispatch for node @nid: a run is already @status.', [
        '@nid' => $node->id(),
        '@status' => $node->get('field_review_status')->value,
      ]);
      return FALSE;
    }

    if ($this->isWithinDebounce($node)) {
      $this->logger->info('Skipping review dispatch for node @nid: debounce window (@sec s).', [
        '@nid' => $node->id(),
        '@sec' => self::DEBOUNCE_SECONDS,
      ]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * The newest revision of a node, reloaded; $node when it has none.
   */
  public function latestRevision(NodeInterface $node): NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $revisionId = $storage->getLatestRevisionId((int) $node->id());
    $latest = $revisionId ? $storage->loadRevision($revisionId) : NULL;
    return $latest instanceof NodeInterface ? $latest : $node;
  }

  /**
   * Whether a review run for the repo has been started and not yet finished.
   *
   * A pending or in-progress status older than STALE_TIMEOUT_SECONDS is not
   * in flight. The poller times such a status out only on a default revision,
   * so one left on a forward revision (older code wrote only the default)
   * would otherwise block Run AI report for good. A status with no dispatch
   * time is not in flight either: nothing can ever clear it.
   *
   * @phpstan-impure
   */
  public function isRunInFlight(NodeInterface $node): bool {
    if (!$node->hasField('field_review_status')) {
      return FALSE;
    }
    if (!in_array((string) ($node->get('field_review_status')->value ?? ''), ['pending', 'in_progress'], TRUE)) {
      return FALSE;
    }
    if (!$node->hasField('field_review_dispatched_at') || $node->get('field_review_dispatched_at')->isEmpty()) {
      return FALSE;
    }
    // The current time: the dispatch was stamped with it, and a long drush or
    // queue process's request time would make a run look ever younger.
    $age = $this->time->getCurrentTime() - (int) $node->get('field_review_dispatched_at')->value;
    return $age < self::STALE_TIMEOUT_SECONDS;
  }

  /**
   * {@inheritdoc}
   *
   * Starts the runs queued by onTransition(), once the saves are complete.
   * A repo whose latest revision is no longer ready_for_review (a later save
   * moved it on) is skipped.
   */
  public function destruct(): void {
    $pending = $this->pendingTransitionDispatch;
    $this->pendingTransitionDispatch = [];
    foreach ($pending as $nid) {
      $this->startOwedDispatch($nid);
    }
  }

  /**
   * Retries transition dispatches whose own request did not start them.
   *
   * Called by the cron review poll. Only entries past their retry_after, so
   * a request still running keeps its own and a failed attempt backs off.
   * One key-value read when nothing is owed.
   */
  public function retryPendingTransitionDispatches(): void {
    try {
      $owed = $this->owedDispatches()?->getAll();
    }
    catch (\Throwable $e) {
      $this->logger->error('Could not read the owed review dispatches: @message', ['@message' => $e->getMessage()]);
      return;
    }
    if (!$owed) {
      return;
    }
    $now = $this->time->getCurrentTime();
    foreach ($owed as $nid => $entry) {
      if (self::retryAfter($entry) < $now) {
        $this->startOwedDispatch((int) $nid);
      }
    }
  }

  /**
   * Starts an owed transition run and settles its entry.
   *
   * The entry is removed once the run is started or no longer needed: the
   * repo's latest revision left ready_for_review, a run is in flight, or a run
   * was already started at or after the entry was queued. The last covers an
   * entry another process re-wrote after it was settled. Any other failure
   * counts an attempt.
   */
  protected function startOwedDispatch(int $nid): void {
    $settled = FALSE;
    // One repo failing must not stop the others from being dispatched.
    try {
      $storage = $this->entityTypeManager->getStorage('node');
      $revisionId = $storage->getLatestRevisionId($nid);
      $latest = $revisionId ? $storage->loadRevision($revisionId) : NULL;
      if (!$latest instanceof NodeInterface || $latest->get('moderation_state')->value !== 'ready_for_review') {
        $settled = TRUE;
      }
      elseif ($this->startedSinceQueued($latest)) {
        $settled = TRUE;
      }
      else {
        $settled = $this->dispatchForNode($latest) || $this->isRunInFlight($this->latestRevision($latest));
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Review dispatch for repo node @nid failed: @message', [
        '@nid' => $nid,
        '@message' => $e->getMessage(),
      ]);
    }
    // Nothing may escape: in destruct() an exception would skip the other
    // services' destruct().
    try {
      $this->settleOwedDispatch($nid, $settled);
    }
    catch (\Throwable $e) {
      $this->logger->error('Could not settle the owed review dispatch of repo node @nid: @message', [
        '@nid' => $nid,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * When an owed dispatch was queued: queued_at, or queued for older entries.
   *
   * @param array<string, int> $entry
   *   The owed dispatch.
   */
  protected static function queuedAt(array $entry): int {
    return (int) ($entry['queued_at'] ?? $entry['queued'] ?? 0);
  }

  /**
   * When an owed dispatch may next be tried by the cron poll.
   *
   * Entries written before retry_after existed wait
   * PENDING_TRANSITION_RETRY_AFTER seconds from when they were queued.
   *
   * @param array<string, int> $entry
   *   The owed dispatch.
   */
  protected static function retryAfter(array $entry): int {
    return (int) ($entry['retry_after'] ?? self::queuedAt($entry) + self::PENDING_TRANSITION_RETRY_AFTER);
  }

  /**
   * Whether the repo's run was started at or after its entry was queued.
   *
   * Both field_review_dispatched_at and queued_at are the current time when
   * written, so a dispatch made after the transition, in its own request, a
   * reviewer's or a cron retry, is at or after queued_at. queued_at never
   * moves, so a run started after a failed attempt still counts. When it
   * misses one, the entry is dispatched again and dispatchForNode()'s
   * in-flight check stops a second run.
   */
  protected function startedSinceQueued(NodeInterface $latest): bool {
    if (!$latest->hasField('field_review_dispatched_at') || $latest->get('field_review_dispatched_at')->isEmpty()) {
      return FALSE;
    }
    $entry = $this->owedDispatches()?->get((string) $latest->id());
    return is_array($entry) && (int) $latest->get('field_review_dispatched_at')->value >= self::queuedAt($entry);
  }

  /**
   * Removes an owed dispatch, or counts a failed attempt at it.
   */
  protected function settleOwedDispatch(int $nid, bool $settled): void {
    $store = $this->owedDispatches();
    $entry = $store?->get((string) $nid);
    if (!is_array($entry)) {
      return;
    }
    if (!$settled) {
      $attempts = (int) ($entry['attempts'] ?? 0) + 1;
      if ($attempts < self::PENDING_TRANSITION_MAX_ATTEMPTS) {
        $entry['attempts'] = $attempts;
        // The next retry waits another PENDING_TRANSITION_RETRY_AFTER
        // seconds. queued_at stays the transition's time, so a run started
        // meanwhile (Run AI report) still settles the entry.
        $entry['retry_after'] = $this->time->getCurrentTime() + self::PENDING_TRANSITION_RETRY_AFTER;
        $store->set((string) $nid, $entry);
        return;
      }
      $this->logger->error('AI review for repo @nid could not be started after 3 attempts.', ['@nid' => $nid]);
    }
    $store->delete((string) $nid);
  }

  /**
   * Starts a review for a repo node, without touching its moderation state.
   *
   * The one implementation behind the ready_for_review transition, the hub's
   * Run AI report action, and any explicit caller. Resolves owner/repo from
   * the node, sends the dispatch with a correlation id, and on success records
   * the same timestamp on the node so the id can be recomputed when the run
   * is polled for. A failed dispatch leaves the node untouched: marking it
   * pending would have the poll loop wait for a run that was never created.
   *
   * One run per repo at a time: the dispatch holds a per-repo lock and, under
   * it, refuses when the latest revision already has a run in flight. The
   * GitHub call comes before the pending write, so without the lock two clicks
   * seconds apart both saw no run and started two.
   *
   * @return bool
   *   TRUE when GitHub accepted the dispatch; FALSE when it did not or a run
   *   is already in flight or being started.
   */
  public function dispatchForNode(NodeInterface $node, ?string $model = NULL, ?string $aspectsOverride = NULL, ?string $ref = NULL): bool {
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
    $lockName = 'ood_software.review_dispatch.' . $node->id();
    if ($this->lock && !$this->lock->acquire($lockName, self::DISPATCH_LOCK_SECONDS)) {
      $this->logger->info('Skipping review dispatch for node @nid: another dispatch holds the lock.', [
        '@nid' => $node->id(),
      ]);
      return FALSE;
    }
    try {
      // Under the lock, so a dispatch that just finished is seen.
      $latest = $this->latestRevision($node);
      if ($this->isRunInFlight($latest)) {
        $this->logger->info('Skipping review dispatch for node @nid: a run is already @status.', [
          '@nid' => $node->id(),
          '@status' => $latest->get('field_review_status')->value,
        ]);
        return FALSE;
      }
      // One timestamp for both the node and the id, so the id can be
      // recomputed from the node when the run is polled for. The current
      // time, not the request's: owed transition entries are stamped on the
      // same clock (startedSinceQueued()), and it is still no later than the
      // run's creation, which matchRun() checks.
      $dispatchedAt = $this->time->getCurrentTime();
      $correlationId = self::correlationId((int) $node->id(), $dispatchedAt);
      if (!$this->dispatch($ownerRepo, $model, $correlationId, $aspectsOverride, $ref)) {
        return FALSE;
      }
      $this->recordDispatch($node, $dispatchedAt);
      return TRUE;
    }
    finally {
      $this->lock?->release($lockName);
    }
  }

  /**
   * The repo's newest release, or its newest tag when it has no releases.
   *
   * One GraphQL call returns the latest release and the newest tag, each
   * with the commit date it points at, so the watcher can tell newer code
   * from a deleted, re-created or older tag (appverse-planning#45).
   *
   * @return array{tag: string, at: int}|false|null
   *   The tag and its commit time; NULL when the repo has neither a release
   *   nor a tag; FALSE when it could not be checked, including a repo that
   *   is private, gone or out of the token's reach, which must not read as
   *   "no release".
   */
  public function latestRelease(NodeInterface $repo): array|false|null {
    $url = $this->extractRepoUrl($repo);
    $ownerRepo = $url !== NULL ? $this->parseOwnerRepo($url) : NULL;
    $token = $this->getToken();
    if ($ownerRepo === NULL || $token === NULL) {
      return FALSE;
    }
    [$owner, $name] = explode('/', $ownerRepo, 2);
    $query = 'query($owner: String!, $name: String!) { repository(owner: $owner, name: $name) {'
      . ' latestRelease { tagName tagCommit { committedDate } }'
      . ' refs(refPrefix: "refs/tags/", first: 1, orderBy: {field: TAG_COMMIT_DATE, direction: DESC}) {'
      . ' nodes { name target { ... on Commit { committedDate } ... on Tag { target { ... on Commit { committedDate } } } } } } } }';
    try {
      $response = $this->httpClient->request('POST', 'https://api.github.com/graphql', [
        'headers' => $this->githubHeaders($token),
        'json' => ['query' => $query, 'variables' => ['owner' => $owner, 'name' => $name]],
        'http_errors' => FALSE,
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Release check for @repo failed: @msg', ['@repo' => $ownerRepo, '@msg' => $e->getMessage()]);
      return FALSE;
    }
    $body = json_decode((string) $response->getBody(), TRUE);
    $repository = is_array($body) ? ($body['data']['repository'] ?? NULL) : NULL;
    if ($response->getStatusCode() !== 200 || !is_array($repository)) {
      $this->logger->warning('Release check for @repo: GitHub answered @status without the repository.', [
        '@repo' => $ownerRepo,
        '@status' => $response->getStatusCode(),
      ]);
      return FALSE;
    }
    return self::newestTag($repository);
  }

  /**
   * The newest release, else the newest tag, from a GraphQL repository.
   *
   * Pure.
   *
   * @param array<string, mixed> $repository
   *   data.repository from the latestRelease() query.
   *
   * @return array{tag: string, at: int}|null
   *   The tag and its commit time, or NULL with neither.
   */
  public static function newestTag(array $repository): ?array {
    $release = $repository['latestRelease'] ?? NULL;
    if (is_array($release) && !empty($release['tagName'])) {
      $at = strtotime((string) ($release['tagCommit']['committedDate'] ?? ''));
      return ['tag' => (string) $release['tagName'], 'at' => $at === FALSE ? 0 : $at];
    }
    $node = $repository['refs']['nodes'][0] ?? NULL;
    if (is_array($node) && !empty($node['name'])) {
      $date = $node['target']['committedDate'] ?? $node['target']['target']['committedDate'] ?? '';
      $at = strtotime((string) $date);
      return ['tag' => (string) $node['name'], 'at' => $at === FALSE ? 0 : $at];
    }
    return NULL;
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
    $elapsed = $this->time->getCurrentTime() - $lastDispatch;
    return $elapsed < self::DEBOUNCE_SECONDS;
  }

  /**
   * Record a successful dispatch on the node's review tracking fields.
   *
   * Re-loads the node to avoid saving stale state from the caller's copy.
   * A dispatch from a transition happens in destruct(), after the save.
   * Sets dispatched_at, status=pending, and clears prior
   * recommendation/report/run_id.
   */
  protected function recordDispatch(NodeInterface $node, int $dispatchedAt): void {
    try {
      // Starter: Run AI report, or the contributor's send-for-review
      // transition.
      $starter = $this->currentUser && $this->currentUser->isAuthenticated() ? $this->currentUser->id() : NULL;
      $this->inPlaceSaver()->updateRevisions($node, function (NodeInterface $revision) use ($dispatchedAt, $starter): void {
        if ($revision->hasField('field_review_dispatched_at')) {
          $revision->set('field_review_dispatched_at', $dispatchedAt);
        }
        if ($revision->hasField('field_review_status')) {
          $revision->set('field_review_status', 'pending');
        }
        if ($revision->hasField('field_review_recommendation')) {
          $revision->set('field_review_recommendation', NULL);
        }
        if ($revision->hasField('field_review_run_id')) {
          $revision->set('field_review_run_id', NULL);
        }
        if ($revision->hasField('field_review_dispatched_by')) {
          $revision->set('field_review_dispatched_by', $starter);
        }
      });
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
   *   The workflow's model input. NULL takes this environment's model from
   *   the ood_software.review_model setting (see reviewModel()).
   * @param string|null $ref
   *   The branch or tag to review; NULL reviews the default branch.
   *
   * @return bool
   *   TRUE if the dispatch succeeded (HTTP 204), FALSE otherwise.
   */
  public function dispatch(string $targetRepo, ?string $model = NULL, string $correlationId = '', ?string $aspectsOverride = NULL, ?string $ref = NULL): bool {
    $model = $model ?? $this->reviewModel();
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
      $response = $this->httpClient->request('POST', $url, [
        'headers' => $this->githubHeaders($token) + ['Content-Type' => 'application/json'],
        'json' => [
          'ref' => self::WORKFLOW_REF,
          'inputs' => [
            'target_repo' => $targetRepo,
            // A branch or tag to review; empty is the default branch. A
            // new release is reviewed at its tag (ReleaseWatcher).
            'target_branch' => $ref ?? '',
            'review_aspects' => $aspects,
            'model' => $model,
            'correlation_id' => $correlationId,
          ],
        ],
      ]);

      $status = $response->getStatusCode();
      if ($status === 204) {
        $this->logger->info('Dispatched Appverse review for @repo.', [
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
    // Before the token check: dispatch() logs a missing token itself, and
    // the failed attempt counts toward giving the repo up.
    $this->retryPendingTransitionDispatches();

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

    // The clock field_review_dispatched_at is stamped with.
    $now = $this->time->getCurrentTime();
    $outcomes = [];

    foreach ($pendingNodes as $node) {
      $dispatchedAt = $node->hasField('field_review_dispatched_at')
        ? (int) $node->get('field_review_dispatched_at')->value
        : 0;

      if ($dispatchedAt <= 0) {
        // Nothing to correlate on; the stale timeout below cannot fire either.
        $this->logger->warning('Review for node @nid is pending with no dispatch time; marking as error.', ['@nid' => $node->id()]);
        $this->updateNodeReviewStatus($node, 'error', 0, 'the run has no dispatch time to match it by');
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
      // can rerun it with Run AI report.
      if (($now - $dispatchedAt) > self::STALE_TIMEOUT_SECONDS) {
        $this->logger->warning('Review for node @nid has been pending for @hours hours with no matching run — marking as error.', [
          '@nid' => $node->id(),
          '@hours' => round(($now - $dispatchedAt) / 3600, 1),
        ]);
        $this->updateNodeReviewStatus($node, 'error', 0, sprintf('no run was found for it after %d hours', (int) round(($now - $dispatchedAt) / 3600)));
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
   * @return array<int, array<string, mixed>>|null
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
      $response = $this->httpClient->request('GET', $url, [
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
   * @return array<string, mixed>|null
   *   The matched run object, or NULL if no match.
   * @param array<int, array<string, mixed>> $runs
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
   *
   * @param array<string, mixed> $run
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
      $this->updateNodeReviewStatus($node, 'error', $runId, sprintf('the run concluded with %s', $conclusion));
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
      $this->updateNodeReviewStatus($node, 'error', $runId, 'the run left no usable review artifact');
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
      $this->updateNodeReviewStatus($node, 'error', $runId, sprintf('importing the review failed: %s', $e->getMessage()));
      return;
    }
    $this->cleanupReviewFiles($files);

    $this->recordSeededReview($node, $runId, $review, $artifact['recommendation']['decision'] ?? NULL);
  }



  /**
   * Forgets a repo's review run when the contributor withdraws it.
   *
   * Cancels the GitHub run when its id is known, so a withdrawn run stops
   * spending, then clears the run fields on $repo; the caller saves it.
   * Clearing field_review_dispatched_at lets a re-submit dispatch at once
   * instead of falling inside the debounce window. Nothing else reads it once
   * the run is forgotten: the poller only follows pending and in-progress
   * runs (appverse-planning#46).
   *
   * @return bool
   *   TRUE when a run was cancelled.
   */
  public function forgetRun(NodeInterface $repo): bool {
    $runId = $repo->hasField('field_review_run_id') ? (int) $repo->get('field_review_run_id')->getString() : 0;
    $cancelled = $runId > 0 && $this->cancelRun($runId);
    foreach (['field_review_status', 'field_review_run_id', 'field_review_recommendation', 'field_review_dispatched_at'] as $field) {
      if ($repo->hasField($field)) {
        $repo->set($field, NULL);
      }
    }
    return $cancelled;
  }

  /**
   * Cancels a review run on GitHub Actions.
   *
   * A run that already finished answers 409; that, like any other failure,
   * is logged and returns FALSE, since there is nothing left to stop.
   */
  public function cancelRun(int $runId): bool {
    $token = $this->getToken();
    if ($token === NULL) {
      $this->logger->warning('Cannot cancel review run @id: no GitHub token.', ['@id' => $runId]);
      return FALSE;
    }
    $url = sprintf('https://api.github.com/repos/%s/actions/runs/%d/cancel', self::REVIEW_REPO, $runId);
    try {
      $this->httpClient->request('POST', $url, ['headers' => $this->githubHeaders($token)]);
      $this->logger->info('Cancelled review run @id.', ['@id' => $runId]);
      return TRUE;
    }
    catch (GuzzleException $e) {
      $this->logger->info('Could not cancel review run @id (it may have finished): @msg', [
        '@id' => $runId,
        '@msg' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Fetch the artifacts list for a workflow run.
   *
   * @return array<int, array<string, mixed>>|null
   */
  protected function fetchArtifacts(int $runId, string $token): ?array {
    $url = sprintf(
      'https://api.github.com/repos/%s/actions/runs/%d/artifacts',
      self::REVIEW_REPO,
      $runId,
    );

    try {
      $response = $this->httpClient->request('GET', $url, [
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
      $response = $this->httpClient->request('GET', $url, [
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
   *
   * @param array<int, string> $allowed
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
   * The model the ood_software.review_model setting names for an environment.
   *
   * The setting is a model for every environment ('qwen'), or one per
   * environment (['md-2788' => 'qwen']) with an optional 'default' key. No
   * environment name (local ddev) reads as "local". NULL when the setting
   * names nothing for $env; the value is not validated here.
   */
  public static function configuredModel(?string $env, mixed $setting): ?string {
    $env = ($env === NULL || $env === '') ? 'local' : $env;
    if (is_string($setting) && $setting !== '') {
      return $setting;
    }
    if (is_array($setting)) {
      $model = $setting[$env] ?? $setting['default'] ?? NULL;
      return is_string($model) && $model !== '' ? $model : NULL;
    }
    return NULL;
  }

  /**
   * The model this site's reviews run on: the setting's model for
   * PANTHEON_ENVIRONMENT, or DEFAULT_MODEL. A model the workflow does not
   * accept is logged and replaced by DEFAULT_MODEL, so a typo in settings
   * cannot make every dispatch fail.
   */
  public function reviewModel(): string {
    $model = self::configuredModel(getenv('PANTHEON_ENVIRONMENT') ?: NULL, Settings::get('ood_software.review_model'));
    if ($model === NULL) {
      return self::DEFAULT_MODEL;
    }
    if (!in_array($model, self::MODELS, TRUE)) {
      $this->logger->warning('ood_software.review_model names "@model", which the review workflow does not accept (@models); using @default.', [
        '@model' => $model,
        '@models' => implode(', ', self::MODELS),
        '@default' => self::DEFAULT_MODEL,
      ]);
      return self::DEFAULT_MODEL;
    }
    return $model;
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
   *
   * @param array<string, mixed> $run
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
   *
   * @param array<string, mixed> $run
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
   *
   * @return array<string, string>
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
   * @return array<string, string>|null
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
   *
   * @param array<string, string>|null $files
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
      $mapped = self::mapRecommendation($decision);
      if ($mapped === NULL && $decision !== NULL) {
        $this->logger->warning('Run @id: recommendation "@decision" has no field value; left empty.', [
          '@id' => $runId,
          '@decision' => $decision,
        ]);
      }
      $this->inPlaceSaver()->updateRevisions($node, function (NodeInterface $revision) use ($runId, $mapped): void {
        if ($revision->hasField('field_review_status')) {
          $revision->set('field_review_status', 'complete');
        }
        if ($revision->hasField('field_review_run_id')) {
          $revision->set('field_review_run_id', $runId);
        }
        if ($revision->hasField('field_review_recommendation')) {
          $revision->set('field_review_recommendation', $mapped);
        }
      });
      $fresh = $this->entityTypeManager->getStorage('node')->loadUnchanged($node->id());

      $this->logger->notice('Seeded review @review from run @id for repo @nid (recommendation: @rec).', [
        '@review' => $review->id(),
        '@id' => $runId,
        '@nid' => $node->id(),
        '@rec' => $decision ?? 'none',
      ]);
      if ($fresh instanceof NodeInterface) {
        $this->notifier?->notifyRunReady($fresh, $review);
      }
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
  protected function updateNodeReviewStatus(NodeInterface $node, string $status, int $runId, string $reason = ''): void {
    try {
      $storage = $this->entityTypeManager->getStorage('node');
      $current = $storage->loadUnchanged($node->id());
      if (!$current instanceof NodeInterface) {
        return;
      }
      $was = $current->hasField('field_review_status') ? (string) ($current->get('field_review_status')->value ?? '') : '';

      $this->inPlaceSaver()->updateRevisions($node, function (NodeInterface $revision) use ($status, $runId): void {
        if ($revision->hasField('field_review_status')) {
          $revision->set('field_review_status', $status);
        }
        if ($revision->hasField('field_review_run_id')) {
          $revision->set('field_review_run_id', $runId);
        }
      });
      $fresh = $storage->loadUnchanged($node->id());
      // One email per failure: only the change into error sends it, so a
      // later poll of the same run cannot send it again (appverse-planning#47).
      if ($fresh instanceof NodeInterface && $status === 'error' && $was !== 'error') {
        $this->notifier?->notifyRunFailed($fresh, $reason !== '' ? $reason : 'the run ended without a report', $runId);
      }
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
   *
   * @return array<string, string>
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
