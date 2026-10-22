<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\KeyValueStore\MemoryStorage;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\ood_software\Controller\AppverseHubController;
use Drupal\ood_software\Service\AppverseReviewService;
use Drupal\Tests\ood_software\Kernel\Traits\AppverseModerationTestTrait;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use Symfony\Component\ErrorHandler\BufferingLogger;

/**
 * Sending a repo for review keeps it in review.
 *
 * The run is started after the save. Started inside the save's
 * hook_node_update(), the dispatch re-saved the repo before content_moderation
 * wrote its state record, so a second state entity was created in draft and
 * the repo dropped out of the reviewer queue.
 *
 * @group ood_software
 */
class ReviewDispatchModerationTest extends KernelTestBase {

  use AppverseModerationTestTrait;
  use ProdConfigTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'options',
    'datetime', 'link', 'taxonomy', 'path', 'path_alias', 'file',
    'content_moderation', 'workflows', 'key', 'flag', 'ood_software',
    'ood_software_nested_save_test',
  ];

  /**
   * The repo's owner, the contributor.
   */
  protected UserInterface $owner;

  /**
   * The review service, with the GitHub call recorded instead of sent.
   */
  protected StubDispatchReviewService $dispatcher;

  /**
   * Records what is logged.
   */
  protected BufferingLogger $logs;

  /**
   * Records drained from $logs so far.
   *
   * @var array<int, array<int, mixed>>
   */
  protected array $logged = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'filter', 'user', 'node', 'content_moderation', 'workflows']);
    $this->importProdConfig([
      'node.type.appverse_repo',
      'node.type.appverse_app',
      'workflows.workflow.appverse_editorial',
      'field.storage.node.field_repo_url',
      'field.field.node.appverse_repo.field_repo_url',
      'field.storage.node.field_review_status',
      'field.field.node.appverse_repo.field_review_status',
      'field.storage.node.field_review_dispatched_at',
      'field.field.node.appverse_repo.field_review_dispatched_at',
      'field.storage.node.field_review_dispatched_by',
      'field.field.node.appverse_repo.field_review_dispatched_by',
      'field.storage.node.field_review_recommendation',
      'field.field.node.appverse_repo.field_review_recommendation',
      'field.storage.node.field_review_run_id',
      'field.field.node.appverse_repo.field_review_run_id',
    ]);
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('system.site')->set('name', 'Appverse')->set('mail', 'site@example.org')->save();
    // User 1 bypasses access checks, so take that id first.
    $this->createUser([], 'admin');
    $this->owner = $this->createUser([], 'owner', FALSE, ['mail' => 'owner@example.com']);
    $this->setCurrentUser($this->owner);

    $this->logs = new BufferingLogger();
    $this->container->get('logger.factory')->addLogger($this->logs);

    $this->dispatcher = $this->newDispatcher();
    $this->container->set('ood_software.review_dispatcher', $this->dispatcher);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Unread records would go to the PHP error log.
    $this->logs->cleanLogs();
    parent::tearDown();
  }

  /**
   * A review service like a fresh request's: nothing queued in memory.
   */
  protected function newDispatcher(?TimeInterface $time = NULL): StubDispatchReviewService {
    // The same arguments as ood_software.review_dispatcher in services.yml.
    return new StubDispatchReviewService(
      $this->container->get('http_client'),
      $this->container->get('key.repository'),
      $this->container->get('logger.factory'),
      $this->container->get('entity_type.manager'),
      $time ?? $this->container->get('datetime.time'),
      $this->container->get('file_system'),
      $this->container->get('ood_software.review_seeder'),
      $this->container->get('current_user'),
      $this->container->get('ood_software.repo_notifier'),
      $this->container->get('ood_software.in_place_saver'),
      // The kernel container's lock is a no-op, so use the database one.
      new DatabaseLockBackend(\Drupal::database()),
      $this->container->get('keyvalue'),
    );
  }

  /**
   * A clock for another request, offset from the real one.
   *
   * @param int $requestOffset
   *   Seconds from now to that request's start.
   * @param int $currentOffset
   *   Seconds from now to that request's current time.
   */
  protected function clock(int $requestOffset, int $currentOffset): TimeInterface {
    return new class($requestOffset, $currentOffset) implements TimeInterface {

      public function __construct(protected int $requestOffset, protected int $currentOffset) {}

      /**
       * {@inheritdoc}
       */
      public function getRequestTime() {
        return time() + $this->requestOffset;
      }

      /**
       * {@inheritdoc}
       */
      public function getRequestMicroTime() {
        return microtime(TRUE) + $this->requestOffset;
      }

      /**
       * {@inheritdoc}
       */
      public function getCurrentTime() {
        return time() + $this->currentOffset;
      }

      /**
       * {@inheritdoc}
       */
      public function getCurrentMicroTime() {
        return microtime(TRUE) + $this->currentOffset;
      }

    };
  }

  /**
   * The store of transition dispatches still owed, as another process sees it.
   */
  protected function owedStore(): KeyValueStoreInterface {
    return \Drupal::keyValue(AppverseReviewService::PENDING_TRANSITION_COLLECTION);
  }

  /**
   * The transition dispatches still owed, by repo nid.
   *
   * @return array<int, array<string, int>>
   *   The entries.
   */
  protected function owed(): array {
    return $this->owedStore()->getAll();
  }

  /**
   * Backdates an owed dispatch, as if $seconds passed since it was written.
   */
  protected function backdate(int $nid, int $seconds): void {
    $entry = $this->owedStore()->get((string) $nid);
    $entry['queued'] = \Drupal::time()->getRequestTime() - $seconds;
    $entry['queued_at'] = \Drupal::time()->getCurrentTime() - $seconds;
    if (isset($entry['retry_after'])) {
      $entry['retry_after'] -= $seconds;
    }
    $this->owedStore()->set((string) $nid, $entry);
  }

  /**
   * Sends a new repo for review and returns its nid.
   */
  protected function sendNewRepoForReview(): int {
    $repo = $this->makeRepo('draft');
    $repo->set('moderation_state', 'ready_for_review')->save();
    return (int) $repo->id();
  }

  /**
   * The transition is recorded, and a dispatch in destruct() clears it.
   */
  public function testDestructClearsTheOwedDispatchOnSuccess(): void {
    $nid = $this->sendNewRepoForReview();
    $owed = $this->owed();
    $this->assertSame(0, $owed[$nid]['attempts']);
    $this->assertSame(\Drupal::time()->getRequestTime(), $owed[$nid]['queued']);
    $this->assertGreaterThanOrEqual($owed[$nid]['queued'], $owed[$nid]['queued_at']);

    $this->dispatcher->destruct();
    $this->assertSame(['example/app'], $this->dispatcher->sent);
    $this->assertSame([], $this->owed());
  }

  /**
   * Settling one repo keeps an entry another process queued meanwhile.
   */
  public function testSettlingKeepsAnotherProcessesEntry(): void {
    $nid = $this->sendNewRepoForReview();
    $other = 424242;
    // While this request's destruct() dispatches, another request queues
    // repo B.
    $this->dispatcher->onDispatch = function () use ($other): void {
      $this->owedStore()->set((string) $other, ['queued' => \Drupal::time()->getRequestTime(), 'attempts' => 0]);
    };
    $this->dispatcher->destruct();
    $owed = $this->owed();
    $this->assertArrayNotHasKey($nid, $owed);
    $this->assertArrayHasKey($other, $owed);
  }

  /**
   * A transition whose destruct() never ran is dispatched by the cron poll.
   */
  public function testPollDispatchesAnOwedTransitionOnceOlderThanFiveMinutes(): void {
    $nid = $this->sendNewRepoForReview();
    // A later request: this one died before destruct().
    $cron = $this->newDispatcher();

    $cron->pollForResults();
    $this->assertSame([], $cron->sent, 'A dispatch queued just now is left to its own request.');
    $this->assertArrayHasKey($nid, $this->owed());

    $this->backdate($nid, 301);
    $cron->pollForResults();
    $this->assertSame(['example/app'], $cron->sent);
    $this->assertSame([], $this->owed());
    $this->assertSame('pending', $this->latest(Node::load($nid))->get('field_review_status')->value);
  }

  /**
   * An entry brought back after its run started is settled without a run.
   *
   * Another request can write an entry back after destruct() settled it.
   */
  public function testPollSettlesAnEntryWhoseRunAlreadyStarted(): void {
    $nid = $this->sendNewRepoForReview();
    $entry = $this->owedStore()->get((string) $nid);
    // The run is started and recorded the way production does it.
    $this->dispatcher->destruct();
    $this->assertSame(['example/app'], $this->dispatcher->sent);
    $this->assertSame([], $this->owed());
    // A racing request writes the transition's entry back.
    $this->owedStore()->set((string) $nid, $entry);
    // The run has finished: not in flight.
    $this->latest(Node::load($nid))->set('field_review_status', 'complete')->save();

    $cron = $this->newDispatcher($this->clock(AppverseReviewService::PENDING_TRANSITION_RETRY_AFTER + 60, AppverseReviewService::PENDING_TRANSITION_RETRY_AFTER + 60));
    $cron->pollForResults();
    $this->assertSame([], $cron->sent);
    $this->assertSame([], $this->owed());
  }

  /**
   * A run its own request started settles the entry, though the request died.
   *
   * GitHub accepted the dispatch and the node recorded it, but the request
   * ended before destruct() could settle the entry. The web request started a
   * few seconds before the transition, so its request time is earlier than
   * the entry's queued_at.
   */
  public function testRunStartedInTheTransitionsRequestSettlesOnTheNextDrain(): void {
    $request = $this->newDispatcher($this->clock(-5, 0));
    $this->container->set('ood_software.review_dispatcher', $request);
    $nid = $this->sendNewRepoForReview();
    $this->assertTrue($request->dispatchForNode(Node::load($nid)));
    $this->assertArrayHasKey($nid, $this->owed(), 'The request died before settling its entry.');
    // The run finished before the cron poll got to the entry.
    $this->latest(Node::load($nid))->set('field_review_status', 'complete')->save();

    $later = AppverseReviewService::PENDING_TRANSITION_RETRY_AFTER + 60;
    $cron = $this->newDispatcher($this->clock($later, $later));
    $cron->pollForResults();
    $this->assertSame([], $cron->sent, 'The run the request started is not started again.');
    $this->assertSame([], $this->owed());
  }

  /**
   * A reviewer's run after a failed attempt settles the entry.
   *
   * A failed attempt keeps the transition's queued_at and backs off with
   * retry_after, so a run a reviewer starts afterwards still counts.
   */
  public function testReviewerRunAfterFailedAttemptSettlesTheEntry(): void {
    $nid = $this->sendNewRepoForReview();
    $queued = $this->owed()[$nid];
    $this->dispatcher->accepts = FALSE;
    $this->dispatcher->destruct();
    $failed = $this->owed()[$nid];
    $this->assertSame(1, $failed['attempts']);
    $this->assertSame($queued['queued_at'], $failed['queued_at'], 'A failed attempt keeps queued_at.');
    $this->assertGreaterThanOrEqual(\Drupal::time()->getCurrentTime() + AppverseReviewService::PENDING_TRANSITION_RETRY_AFTER, $failed['retry_after']);

    // Run AI report, in a reviewer request that began a little earlier.
    $reviewer = $this->newDispatcher($this->clock(-10, 0));
    $this->assertTrue($reviewer->dispatchForNode(Node::load($nid)));
    $this->latest(Node::load($nid))->set('field_review_status', 'complete')->save();

    $later = AppverseReviewService::PENDING_TRANSITION_RETRY_AFTER + 60;
    $cron = $this->newDispatcher($this->clock($later, $later));
    $cron->pollForResults();
    $this->assertSame([], $cron->sent, "The reviewer's run is not started again.");
    $this->assertSame([], $this->owed());
  }

  /**
   * Run AI report stamps the dispatch with the current time, not the request's.
   */
  public function testDispatchIsStampedWithTheCurrentTime(): void {
    $repo = $this->makeRepo('ready_for_review');
    $reviewer = $this->newDispatcher($this->clock(-30, 0));
    $before = \Drupal::time()->getCurrentTime();
    $this->assertTrue($reviewer->dispatchForNode($repo));
    $this->assertGreaterThanOrEqual($before, (int) $this->latest($repo)->get('field_review_dispatched_at')->value);
  }

  /**
   * In a long-running process, a run started before the transition is not it.
   *
   * The request time there is the process start. A run dispatched after the
   * process started but before the transition did not start this review.
   */
  public function testRunBeforeTransitionInLongProcessDoesNotSettle(): void {
    $nid = $this->sendNewRepoForReview();
    $now = \Drupal::time()->getCurrentTime();
    $this->owedStore()->set((string) $nid, [
      // Process start.
      'queued' => $now - 1000,
      // The transition itself.
      'queued_at' => $now - 400,
      'attempts' => 0,
    ]);
    $this->latest(Node::load($nid))
      ->set('field_review_dispatched_at', $now - 700)
      ->set('field_review_status', 'complete')
      ->save();

    $cron = $this->newDispatcher();
    $cron->pollForResults();
    $this->assertSame(['example/app'], $cron->sent);
    $this->assertSame([], $this->owed());
  }

  /**
   * A repo that left ready_for_review is dropped without a dispatch.
   */
  public function testPollDropsAnOwedRepoThatLeftReview(): void {
    $nid = $this->sendNewRepoForReview();
    $this->latest(Node::load($nid))->set('moderation_state', 'draft')->save();
    $this->backdate($nid, 301);

    $cron = $this->newDispatcher();
    $cron->pollForResults();
    $this->assertSame([], $cron->sent);
    $this->assertSame([], $this->owed());
  }

  /**
   * A repo GitHub keeps refusing is dropped after 3 attempts, with an error.
   */
  public function testOwedDispatchIsDroppedAfterThreeAttempts(): void {
    $nid = $this->sendNewRepoForReview();
    $this->dispatcher->accepts = FALSE;
    $this->dispatcher->destruct();
    $this->assertSame(1, $this->owed()[$nid]['attempts']);

    $cron = $this->newDispatcher();
    $cron->accepts = FALSE;
    $this->backdate($nid, 301);
    $cron->pollForResults();
    $this->assertSame(2, $this->owed()[$nid]['attempts']);
    $this->assertSame([], $this->droppedErrors($nid));

    $this->backdate($nid, 301);
    $cron->pollForResults();
    $this->assertSame([], $this->owed());
    $this->assertCount(3, array_merge($this->dispatcher->sent, $cron->sent));
    $this->assertCount(1, $this->droppedErrors($nid));
  }

  /**
   * A failed retry waits another five minutes before the next one.
   */
  public function testFailedRetryWaitsAnotherFiveMinutes(): void {
    $nid = $this->sendNewRepoForReview();
    $cron = $this->newDispatcher();
    $cron->accepts = FALSE;
    $this->backdate($nid, 301);
    $cron->pollForResults();
    $this->assertCount(1, $cron->sent);
    $this->assertSame(1, $this->owed()[$nid]['attempts']);

    $cron->pollForResults();
    $this->assertCount(1, $cron->sent, 'A retry that just failed is not retried at once.');

    $this->backdate($nid, 301);
    $cron->pollForResults();
    $this->assertCount(2, $cron->sent);
    $this->assertSame(2, $this->owed()[$nid]['attempts']);
  }

  /**
   * A store that fails on write does not make destruct() throw.
   *
   * An exception escaping destruct() would skip other services' destruct().
   */
  public function testStorageFailureDoesNotEscapeDestruct(): void {
    $store = new class('ood_software.pending_transition_dispatch') extends MemoryStorage {

      /**
       * Whether writes throw.
       */
      public bool $broken = FALSE;

      /**
       * {@inheritdoc}
       */
      public function set($key, $value): void {
        if ($this->broken) {
          throw new \RuntimeException('Database went away');
        }
        parent::set($key, $value);
      }

      /**
       * {@inheritdoc}
       */
      public function delete($key): void {
        if ($this->broken) {
          throw new \RuntimeException('Database went away');
        }
        parent::delete($key);
      }

    };
    $factory = new class($store) implements KeyValueFactoryInterface {

      public function __construct(protected KeyValueStoreInterface $store) {}

      /**
       * {@inheritdoc}
       */
      public function get($collection) {
        return $this->store;
      }

    };
    $dispatcher = new StubDispatchReviewService(
      $this->container->get('http_client'),
      $this->container->get('key.repository'),
      $this->container->get('logger.factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('datetime.time'),
      $this->container->get('file_system'),
      $this->container->get('ood_software.review_seeder'),
      $this->container->get('current_user'),
      $this->container->get('ood_software.repo_notifier'),
      $this->container->get('ood_software.in_place_saver'),
      new DatabaseLockBackend(\Drupal::database()),
      $factory,
    );
    $this->container->set('ood_software.review_dispatcher', $dispatcher);
    $nid = $this->sendNewRepoForReview();
    $this->assertNotNull($store->get((string) $nid));
    $store->broken = TRUE;

    $dispatcher->destruct();
    $this->assertSame(['example/app'], $dispatcher->sent);
    $this->logged = array_merge($this->logged, $this->logs->cleanLogs());
    $errors = array_filter($this->logged, fn (array $r) => $r[0] === RfcLogLevel::ERROR && str_contains((string) ($r[2]['@message'] ?? ''), 'Database went away'));
    $this->assertCount(1, $errors);
  }

  /**
   * A dispatch that throws counts an attempt and keeps the entry.
   */
  public function testThrowingDispatchCountsAnAttempt(): void {
    $nid = $this->sendNewRepoForReview();
    $this->dispatcher->onDispatch = function (): void {
      throw new \RuntimeException('GitHub is down');
    };
    $this->dispatcher->destruct();
    $this->assertSame(1, $this->owed()[$nid]['attempts']);
  }

  /**
   * Recording a transition saves no entity.
   *
   * It runs inside the save's hook_node_update(); a nested save
   * there is what broke review moderation.
   */
  public function testOnTransitionSavesNoEntity(): void {
    $repo = $this->makeRepo('ready_for_review');
    $latest = $this->latest($repo);
    $revisions = $this->revisionCount($repo);
    \Drupal::state()->set('ood_software_nested_save_test.count_saves', 0);

    $this->dispatcher->onTransition($latest, 'draft');

    $this->assertSame(0, \Drupal::state()->get('ood_software_nested_save_test.count_saves'));
    $this->assertSame($revisions, $this->revisionCount($repo));
    $this->assertArrayHasKey((int) $repo->id(), $this->owed());
  }

  /**
   * The "could not be started" errors logged for a repo.
   *
   * @return array<int, mixed>
   *   The matching log records.
   */
  protected function droppedErrors(int $nid): array {
    $this->logged = array_merge($this->logged, $this->logs->cleanLogs());
    return array_values(array_filter($this->logged, fn (array $r) => $r[0] === RfcLogLevel::ERROR
      && $r[1] === 'AI review for repo @nid could not be started after 3 attempts.'
      && (int) ($r[2]['@nid'] ?? 0) === $nid));
  }

  /**
   * The run starts after the save and leaves the repo awaiting review.
   */
  public function testSendForReviewKeepsReadyForReview(): void {
    $repo = $this->makeRepo('draft');
    $repo->set('moderation_state', 'ready_for_review')->save();
    $this->dispatcher->destruct();

    $this->assertSame(['example/app'], $this->dispatcher->sent);
    $this->assertLatestState('ready_for_review', $repo);
    $this->assertSame('pending', $this->latest($repo)->get('field_review_status')->value);
    $this->assertSame(1, $this->moderationStateEntityCount($repo));
  }

  /**
   * A repo moved on before the request ended is not reviewed.
   */
  public function testNoDispatchWhenTheTransitionDidNotStick(): void {
    $repo = $this->makeRepo('draft');
    $repo->set('moderation_state', 'ready_for_review')->save();
    $this->latest($repo)->set('moderation_state', 'draft')->save();
    $this->dispatcher->destruct();

    $this->assertSame([], $this->dispatcher->sent);
  }

  /**
   * Recording the dispatch on a published repo keeps its forward revision.
   */
  public function testDispatchOnPublishedRepoKeepsForwardRevision(): void {
    $repo = $this->publishedRepoWithForwardRevision('ready_for_review');
    $this->dispatcher->dispatchForNode($this->latest($repo));
    $this->assertLatestState('ready_for_review', $repo);
    $this->assertTrue($this->defaultRevision($repo)->isPublished());
  }

  /**
   * A second Run AI report while the first run is in flight starts nothing.
   */
  public function testSecondRunReviewWhileFirstInFlightDoesNotDispatch(): void {
    $repo = $this->makeRepo('ready_for_review');
    $controller = AppverseHubController::create($this->container);
    $controller->runReview($this->latest($repo));
    $controller->runReview($this->latest($repo));
    $this->assertCount(1, $this->dispatcher->sent);
  }

  /**
   * A dispatch for a repo another request is dispatching is refused.
   */
  public function testConcurrentDispatchIsLockedOut(): void {
    $repo = $this->makeRepo('ready_for_review');
    // A second backend has its own lock id, like a second request would.
    (new DatabaseLockBackend(\Drupal::database()))->acquire('ood_software.review_dispatch.' . $repo->id(), 120);
    $this->assertFalse($this->dispatcher->dispatchForNode($this->latest($repo)));
    $this->assertSame([], $this->dispatcher->sent);
  }

  /**
   * A reviewer can rerun a failed run at once; the debounce does not apply.
   */
  public function testRetryAfterErrorIsNotDebounced(): void {
    $repo = $this->makeRepo('ready_for_review');
    $this->latest($repo)->set('field_review_status', 'error')->set('field_review_dispatched_at', \Drupal::time()->getRequestTime() - 10)->save();
    AppverseHubController::create($this->container)->runReview($this->latest($repo));
    $this->assertCount(1, $this->dispatcher->sent);
  }

  /**
   * A stale pending status on a forward revision does not block a rerun.
   *
   * The poller only times out default revisions, so nothing else clears it.
   */
  public function testStalePendingOnForwardRevisionDoesNotBlockRunReview(): void {
    $repo = $this->publishedRepoWithForwardRevision('ready_for_review');
    $this->latest($repo)
      ->set('field_review_status', 'pending')
      ->set('field_review_dispatched_at', \Drupal::time()->getRequestTime() - (AppverseReviewService::STALE_TIMEOUT_SECONDS + 60))
      ->save();
    $this->assertSame('pending', $this->latest($repo)->get('field_review_status')->value);
    AppverseHubController::create($this->container)->runReview($this->latest($repo));
    $this->assertCount(1, $this->dispatcher->sent);
  }

  /**
   * The check under the lock stops a second dispatch of the same repo.
   *
   * Calls the service directly, past the controller's own check.
   */
  public function testSecondDispatchForNodeWhileFirstInFlightIsRefused(): void {
    $repo = $this->publishedRepoWithForwardRevision('ready_for_review');
    $this->assertTrue($this->dispatcher->dispatchForNode($this->latest($repo)));
    $this->assertFalse($this->dispatcher->dispatchForNode($this->latest($repo)));
    $this->assertCount(1, $this->dispatcher->sent);
  }

  /**
   * A dispatch that throws does not stop the other queued repos.
   */
  public function testFailingDispatchDoesNotStopTheRest(): void {
    $throwing = new class(
      $this->container->get('http_client'),
      $this->container->get('key.repository'),
      $this->container->get('logger.factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('datetime.time'),
      $this->container->get('file_system'),
      $this->container->get('ood_software.review_seeder'),
      $this->container->get('current_user'),
      $this->container->get('ood_software.repo_notifier'),
      $this->container->get('ood_software.in_place_saver'),
      new DatabaseLockBackend(\Drupal::database()),
      $this->container->get('keyvalue'),
    ) extends StubDispatchReviewService {

      /**
       * {@inheritdoc}
       */
      public function dispatch(string $targetRepo, ?string $model = NULL, string $correlationId = '', ?string $aspectsOverride = NULL, ?string $ref = NULL): bool {
        if (!$this->sent) {
          $this->sent[] = $targetRepo;
          throw new \RuntimeException('GitHub is down');
        }
        return parent::dispatch($targetRepo, $model, $correlationId, $aspectsOverride, $ref);
      }

    };
    $this->container->set('ood_software.review_dispatcher', $throwing);
    $first = $this->makeRepo('draft');
    $second = $this->makeRepo('draft');
    $first->set('moderation_state', 'ready_for_review')->save();
    $second->set('moderation_state', 'ready_for_review')->save();

    $throwing->destruct();

    $this->assertCount(2, $throwing->sent);
    $this->assertSame('pending', $this->latest($second)->get('field_review_status')->value);
  }

}
