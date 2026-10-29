<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Commands\AppverseBackfillCommands;
use Drupal\Tests\ood_software\Kernel\Traits\AppverseModerationTestTrait;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;

/**
 * Covers drush appverse:repair-moderation.
 *
 * The fixtures use ood_software_nested_save_test, which reproduces the old
 * nested save, to put nodes into the state production was left in: a second
 * moderation record in draft and, for a repo, member apps never cascaded.
 *
 * @group ood_software
 */
class RepairModerationCommandTest extends KernelTestBase {

  use AppverseModerationTestTrait;
  use AssertMailTrait;
  use ProdConfigTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'options',
    'datetime', 'link', 'taxonomy', 'path', 'path_alias', 'file',
    'content_moderation', 'workflows', 'key', 'flag', 'ood_software',
    'ood_software_nested_save_test', 'content_moderation_notifications',
  ];

  /**
   * The repo's owner, the contributor.
   */
  protected UserInterface $owner;

  /**
   * The command under test.
   */
  protected AppverseBackfillCommands $command;

  /**
   * A repo sent for review while the old nested save was active.
   */
  protected NodeInterface $broken;

  /**
   * The broken repo's member apps, never cascaded.
   *
   * @var \Drupal\node\NodeInterface[]
   */
  protected array $brokenApps = [];

  /**
   * A repo a person moved to draft by hand.
   */
  protected NodeInterface $manualDraft;

  /**
   * A reviewer who gets the ready-for-review email.
   */
  protected UserInterface $reviewer;

  /**
   * An appverse_pm, who gets the contrib "New Appverse App submitted" email.
   */
  protected UserInterface $projectManager;

  /**
   * The review service, recording runs instead of starting them.
   */
  protected StubDispatchReviewService $dispatcher;

  /**
   * A standalone app sent for review while the old nested save was active.
   */
  protected NodeInterface $brokenApp;

  /**
   * A repo in ready_for_review whose member app was never cascaded.
   */
  protected NodeInterface $skippedCascade;

  /**
   * The skipped-cascade repo's member app, still in draft.
   */
  protected NodeInterface $skippedCascadeApp;

  /**
   * A repo in ready_for_review whose apps are all past draft.
   */
  protected NodeInterface $healthy;

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
    $this->installSchema('flag', ['flag_counts']);
    $this->installConfig(['system', 'filter', 'user', 'node', 'content_moderation', 'workflows']);
    $this->importProdConfig([
      'node.type.appverse_repo',
      'node.type.appverse_app',
      'workflows.workflow.appverse_editorial',
      'field.storage.node.field_repo_url',
      'field.field.node.appverse_repo.field_repo_url',
      'field.storage.node.field_appverse_repo',
      'field.field.node.appverse_app.field_appverse_repo',
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
      'content_moderation_notifications.content_moderation_notification.appverse_review_requested',
    ]);
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('system.site')->set('name', 'Appverse')->set('mail', 'site@example.org')->save();
    $this->createUser([], 'admin');
    $this->owner = $this->createUser([], 'owner', FALSE, ['mail' => 'owner@example.com']);
    // An active user in a role granting the permission is who the notifier
    // emails when a repo enters ready_for_review.
    $this->reviewer = $this->createUser(['administer appverse content'], 'reviewer', FALSE, ['mail' => 'reviewer@example.com']);
    // The contrib notification emails appverse_pm users on send_for_review.
    // They see unpublished apps, or the notification leaves them out.
    $this->createRole(['access content', 'view any unpublished content'], 'appverse_pm');
    $this->projectManager = $this->createUser([], 'pm', FALSE, ['mail' => 'pm@example.com', 'roles' => ['appverse_pm']]);
    $this->setCurrentUser($this->owner);

    $this->command = new AppverseBackfillCommands(
      $this->container->get('entity_type.manager'),
      $this->container->get('ood_software.repo_sync'),
      $this->container->get('ood_software.gh'),
      $this->container->get('logger.factory'),
      $this->container->get('ood_software.repo_member_apps'),
      $this->container->get('current_user'),
      $this->container->get('datetime.time'),
    );

    \Drupal::state()->set('ood_software_nested_save_test.on', TRUE);
    $this->broken = $this->makeRepo('draft');
    foreach (['one', 'two'] as $name) {
      $app = Node::create([
        'type' => 'appverse_app',
        'title' => $name,
        'uid' => $this->owner->id(),
        'field_appverse_repo' => $this->broken->id(),
        'moderation_state' => 'draft',
      ]);
      $app->save();
      $this->brokenApps[] = $app;
    }
    $this->broken->set('moderation_state', 'ready_for_review')->save();

    $this->brokenApp = Node::create([
      'type' => 'appverse_app',
      'title' => 'solo',
      'uid' => $this->owner->id(),
      'moderation_state' => 'draft',
    ]);
    $this->brokenApp->save();
    $this->brokenApp->set('moderation_state', 'ready_for_review')->save();
    \Drupal::state()->set('ood_software_nested_save_test.on', FALSE);

    // A repo someone put back in ready_for_review by hand: the cascade the
    // send-for-review should have run is still owed.
    $this->skippedCascade = $this->makeRepo('draft');
    $this->skippedCascadeApp = $this->makeMemberApp($this->skippedCascade, 'draft');
    \Drupal::state()->set('ood_software_nested_save_test.on', TRUE);
    $this->skippedCascade->set('moderation_state', 'ready_for_review')->save();
    \Drupal::state()->set('ood_software_nested_save_test.on', FALSE);
    $this->latest($this->skippedCascade)->set('moderation_state', 'ready_for_review')->save();

    // Same record history, but its apps already moved on.
    \Drupal::state()->set('ood_software_nested_save_test.on', TRUE);
    $this->healthy = $this->makeRepo('draft');
    $this->makeMemberApp($this->healthy, 'ready_for_review');
    $this->makeMemberApp($this->healthy, 'published');
    $this->healthy->set('moderation_state', 'ready_for_review')->save();
    \Drupal::state()->set('ood_software_nested_save_test.on', FALSE);
    $this->latest($this->healthy)->set('moderation_state', 'ready_for_review')->save();

    $this->manualDraft = $this->makeRepo('draft');
    $this->manualDraft->set('moderation_state', 'ready_for_review')->save();
    $this->latest($this->manualDraft)->set('moderation_state', 'draft')->save();
    \Drupal::state()->set('system.test_mail_collector', []);

    // Built after the fixtures so it only sees what the command does. The
    // same arguments as ood_software.review_dispatcher in services.yml.
    $this->dispatcher = new StubDispatchReviewService(
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
    );
    $this->container->set('ood_software.review_dispatcher', $this->dispatcher);
  }

  /**
   * Creates a member app of a repo, in a moderation state.
   */
  protected function makeMemberApp(NodeInterface $repo, string $state): NodeInterface {
    $app = Node::create([
      'type' => 'appverse_app',
      'title' => 'member',
      'uid' => $this->owner->id(),
      'field_appverse_repo' => $repo->id(),
      'moderation_state' => $state,
    ]);
    $app->save();
    return $app;
  }

  /**
   * Asserts no review run was started and no review was created.
   */
  protected function assertNoReviewStarted(): void {
    $this->dispatcher->destruct();
    $this->assertSame([], $this->dispatcher->sent);
    $reviews = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appverse_review')
      ->execute();
    $this->assertSame([], $reviews);
  }

  /**
   * Control: the fixture can mail and dispatch when a save is not suppressed.
   */
  public function testUnsuppressedSendForReviewMailsAndDispatches(): void {
    $repo = $this->makeRepo('draft');
    $repo->set('moderation_state', 'ready_for_review')->save();
    $this->dispatcher->destruct();
    $this->assertCount(1, array_filter($this->getMails(), fn ($m) => $m['to'] === 'reviewer@example.com'));
    $this->assertSame(['example/app'], $this->dispatcher->sent);
  }

  /**
   * The "New Appverse App submitted" emails sent to the project manager.
   *
   * @return array<int, array<string, mixed>>
   *   The collected mails.
   */
  protected function projectManagerMails(): array {
    return array_values(array_filter($this->getMails(), fn ($m) => str_contains((string) ($m['headers']['Bcc'] ?? ''), 'pm@example.com')));
  }

  /**
   * Control: a normal cascade emails the project manager about each app.
   *
   * So the no-email assertions on the repair are not vacuous for apps.
   */
  public function testUnsuppressedAppCascadeEmailsProjectManager(): void {
    $repo = $this->makeRepo('ready_for_review');
    $this->makeMemberApp($repo, 'draft');
    \Drupal::state()->set('system.test_mail_collector', []);
    $moved = $this->container->get('ood_software.repo_member_apps')->cascadeModeration($repo, 'ready_for_review', ['draft'], 'Sent with the repo.');
    $this->assertSame(1, $moved);
    $mails = $this->projectManagerMails();
    $this->assertCount(1, $mails);
    $this->assertSame('New Appverse App submitted', $mails[0]['subject']);
  }

  /**
   * Control: a standalone app sent for review emails the project manager.
   */
  public function testUnsuppressedAppSendForReviewEmailsProjectManager(): void {
    $app = Node::create([
      'type' => 'appverse_app',
      'title' => 'solo two',
      'uid' => $this->owner->id(),
      'moderation_state' => 'draft',
    ]);
    $app->save();
    $app->set('moderation_state', 'ready_for_review')->save();
    $this->assertCount(1, $this->projectManagerMails());
  }

  /**
   * Runs the command and returns its rows.
   *
   * @return array<int, array<string, mixed>>
   *   The report rows.
   */
  protected function report(string $bundle, string $nids = '', bool $apply = FALSE): array {
    return $this->command->repairModeration(['bundle' => $bundle, 'nids' => $nids, 'apply' => $apply])->getArrayCopy();
  }

  /**
   * Keeps only the asserted row keys.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Rows from the command.
   *
   * @return array<int, array<string, mixed>>
   *   Rows with nid, current, lost_state, member_apps, human_edits, action.
   */
  protected function project(array $rows): array {
    $keep = array_flip(['nid', 'current', 'lost_state', 'member_apps', 'human_edits', 'action']);
    return array_map(fn (array $row) => array_intersect_key($row, $keep), array_values($rows));
  }

  /**
   * The fixture really reproduces the broken state.
   */
  public function testFixtureIsBroken(): void {
    $this->assertSame(2, $this->moderationStateEntityCount($this->broken));
    $this->assertLatestState('draft', $this->broken);
    $this->assertSame(2, $this->moderationStateEntityCount($this->brokenApp));
    $this->assertLatestState('draft', $this->brokenApp);
    $this->assertSame(1, $this->moderationStateEntityCount($this->manualDraft));
    foreach ($this->brokenApps as $app) {
      $this->assertLatestState('draft', $app);
    }
  }

  /**
   * A dry run reports the broken repo only and changes nothing.
   */
  public function testDryRunListsWithoutChanging(): void {
    $rows = $this->project($this->report('appverse_repo'));
    $this->assertSame([
      [
        'nid' => (int) $this->broken->id(),
        'current' => 'draft',
        'lost_state' => 'ready_for_review',
        'member_apps' => 'draft:2',
        'human_edits' => 0,
        'action' => 'restore',
      ],
      [
        'nid' => (int) $this->skippedCascade->id(),
        'current' => 'ready_for_review',
        'lost_state' => 'ready_for_review',
        'member_apps' => 'draft:1',
        'human_edits' => 0,
        'action' => 'cascade',
      ],
      [
        'nid' => (int) $this->healthy->id(),
        'current' => 'ready_for_review',
        'lost_state' => 'ready_for_review',
        'member_apps' => 'published:1,ready_for_review:1',
        // Only counted for rows that would be repaired.
        'human_edits' => '',
        'action' => 'skip',
      ],
    ], $rows);
    $this->assertLatestState('draft', $this->broken);
  }

  /**
   * Applying a cascade moves the apps, not the repo, silently.
   */
  public function testApplyCascadeMovesAppsWithoutSavingRepo(): void {
    $revisions = $this->revisionCount($this->skippedCascade);
    $rows = $this->report('appverse_repo', (string) $this->skippedCascade->id(), TRUE);
    $this->assertSame('cascaded', array_column($rows, 'action', 'nid')[(int) $this->skippedCascade->id()]);
    $this->assertLatestState('ready_for_review', $this->skippedCascadeApp);
    $this->assertSame($revisions, $this->revisionCount($this->skippedCascade));
    $this->assertCount(0, $this->getMails(), "No email is sent.");
    $this->assertNoReviewStarted();
  }

  /**
   * A repo whose apps are past draft is left alone even when listed.
   */
  public function testApplyOnHealthyRepoIsSkipped(): void {
    $rows = $this->report('appverse_repo', (string) $this->healthy->id(), TRUE);
    $this->assertSame('skip', array_column($rows, 'action', 'nid')[(int) $this->healthy->id()]);
  }

  /**
   * The report counts default revisions, as the cascade acts on them.
   */
  public function testMemberAppsCountsDefaultRevisions(): void {
    $app = $this->makeMemberApp($this->healthy, 'published');
    $app->set('moderation_state', 'ready_for_review')->save();
    $this->assertAppLatestDiffers($app);
    $rows = array_column($this->report('appverse_repo'), 'member_apps', 'nid');
    $this->assertSame('published:2,ready_for_review:1', $rows[(int) $this->healthy->id()]);
  }

  /**
   * Asserts the app has a forward revision, so latest and default differ.
   */
  protected function assertAppLatestDiffers(NodeInterface $app): void {
    $this->assertSame('ready_for_review', $this->latest($app)->get('moderation_state')->value);
    $this->assertSame('published', $this->defaultRevision($app)->get('moderation_state')->value);
  }

  /**
   * The report shows what happened after the bad record began.
   */
  public function testEditsSinceAndLastEdit(): void {
    $rows = array_column($this->report('appverse_repo'), NULL, 'nid');
    $before = $rows[(int) $this->broken->id()];
    $this->assertSame(0, $before['edits_since']);

    $edit = $this->latest($this->broken);
    $edit->setNewRevision(TRUE);
    $edit->setRevisionLogMessage('Moved to draft on purpose');
    $edit->setRevisionUserId((int) $this->owner->id());
    $edit->save();

    $after = array_column($this->report('appverse_repo'), NULL, 'nid')[(int) $this->broken->id()];
    $this->assertSame(1, $after['edits_since']);
    $this->assertSame($this->owner->id() . ': Moved to draft on purpose', $after['last_edit']);
  }

  /**
   * The restore revision is attributed and dated.
   */
  public function testRestoreIsAttributed(): void {
    $this->report('appverse_repo', (string) $this->broken->id(), TRUE);
    $restored = $this->latest($this->broken);
    $this->assertSame((int) $this->owner->id(), (int) $restored->getRevisionUserId());
    $this->assertGreaterThanOrEqual(\Drupal::time()->getRequestTime(), (int) $restored->getRevisionCreationTime());
  }

  /**
   * Without a logged-in user (drush without -u) the restore is user 1.
   */
  public function testRestoreAsAnonymousIsAttributedToUserOne(): void {
    $this->setCurrentUser(new AnonymousUserSession());
    $this->report('appverse_repo', (string) $this->broken->id(), TRUE);
    $this->assertSame(1, (int) $this->latest($this->broken)->getRevisionUserId());
  }

  /**
   * Apply restores the listed repo and cascades to its apps, silently.
   */
  public function testApplyRestoresListedRepoAndItsApps(): void {
    $revisions = $this->revisionCount($this->broken);
    $this->report('appverse_repo', (string) $this->broken->id(), TRUE);
    $this->assertLatestState('ready_for_review', $this->broken);
    $this->assertSame($revisions + 1, $this->revisionCount($this->broken));
    foreach ($this->brokenApps as $app) {
      $this->assertLatestState('ready_for_review', $app);
    }
    $this->assertLatestState('draft', $this->manualDraft);
    $this->assertCount(0, $this->getMails(), "No email is sent.");
    $this->assertNoReviewStarted();
  }

  /**
   * The row for one nid from a dry run.
   *
   * @return array<string, mixed>
   *   The row.
   */
  protected function rowFor(NodeInterface $node): array {
    $rows = array_column($this->report($node->bundle()), NULL, 'nid');
    $this->assertArrayHasKey((int) $node->id(), $rows);
    return $rows[(int) $node->id()];
  }

  /**
   * Saves a new revision of the node's latest, as a person would.
   */
  protected function editLatest(NodeInterface $node, string $state, string $log = ''): void {
    $edit = $this->latest($node);
    $edit->set('moderation_state', $state);
    $edit->setNewRevision(TRUE);
    if ($log !== '') {
      $edit->setRevisionLogMessage($log);
    }
    $edit->setRevisionUserId((int) $this->owner->id());
    $edit->save();
  }

  /**
   * Saves a new revision without touching state or log, as old cron did.
   *
   * The new revision carries the previous revision's log message.
   */
  protected function churn(NodeInterface $node): void {
    $copy = $this->latest($node);
    $copy->setNewRevision(TRUE);
    $copy->save();
  }

  /**
   * A repo the contributor withdrew after the bad record is left to a person.
   */
  public function testWithdrawnAfterBadRecordIsReviewManually(): void {
    $this->editLatest($this->broken, 'ready_for_review');
    $this->editLatest($this->broken, 'draft', 'Withdrawn from review by owner');
    $row = $this->rowFor($this->broken);
    $this->assertSame('draft', $row['current']);
    $this->assertSame('ready_for_review', $row['lost_state']);
    $this->assertSame(2, $row['human_edits']);
    $this->assertSame('review-manually', $row['action']);

    $revisions = $this->revisionCount($this->broken);
    $rows = $this->report('appverse_repo', (string) $this->broken->id(), TRUE);
    $this->assertSame('review-manually', array_column($rows, 'action', 'nid')[(int) $this->broken->id()]);
    $this->assertSame($revisions, $this->revisionCount($this->broken));
    $this->assertLatestState('draft', $this->broken);
    foreach ($this->brokenApps as $app) {
      $this->assertLatestState('draft', $app);
    }
  }

  /**
   * A log message with no state change is a human edit too.
   */
  public function testLoggedEditWithoutStateChangeIsReviewManually(): void {
    $this->editLatest($this->broken, 'draft', 'Holding this back for now');
    $row = $this->rowFor($this->broken);
    $this->assertSame(1, $row['human_edits']);
    $this->assertSame('review-manually', $row['action']);
  }

  /**
   * Old cron revisions that copy the previous log are not human edits.
   */
  public function testCopiedLogIsNotCountedAsHumanEdit(): void {
    $repo = $this->makeRepo('draft');
    $this->makeMemberApp($repo, 'draft');
    \Drupal::state()->set('ood_software_nested_save_test.on', TRUE);
    $repo->set('moderation_state', 'ready_for_review')->save();
    \Drupal::state()->set('ood_software_nested_save_test.on', FALSE);
    $this->editLatest($repo, 'ready_for_review', 'Sent for review with its member app');
    $this->churn($repo);
    $this->churn($repo);
    $this->assertSame('Sent for review with its member app', $this->latest($repo)->getRevisionLogMessage());
    $row = $this->rowFor($repo);
    $this->assertSame(0, $row['human_edits']);
    $this->assertSame('cascade', $row['action']);
  }

  /**
   * A human edit after the re-send makes a cascade candidate manual.
   */
  public function testHumanEditAfterResendIsReviewManually(): void {
    $this->editLatest($this->skippedCascade, 'ready_for_review', 'Reviewer note: waiting on the contributor');
    $row = $this->rowFor($this->skippedCascade);
    $this->assertSame(1, $row['human_edits']);
    $this->assertSame('review-manually', $row['action']);
    $this->report('appverse_repo', (string) $this->skippedCascade->id(), TRUE);
    $this->assertLatestState('draft', $this->skippedCascadeApp);
  }

  /**
   * An app a reviewer sent back is not a cascade's to move.
   */
  public function testSentBackAppIsNotCascaded(): void {
    $repo = $this->makeRepo('draft');
    $app = $this->makeMemberApp($repo, 'needs_adjustment');
    \Drupal::state()->set('ood_software_nested_save_test.on', TRUE);
    $repo->set('moderation_state', 'ready_for_review')->save();
    \Drupal::state()->set('ood_software_nested_save_test.on', FALSE);
    $this->latest($repo)->set('moderation_state', 'ready_for_review')->save();
    $row = $this->rowFor($repo);
    $this->assertSame('needs_adjustment:1', $row['member_apps']);
    $this->assertSame('skip', $row['action']);

    $this->report('appverse_repo', (string) $repo->id(), TRUE);
    $this->assertLatestState('needs_adjustment', $app);
  }

  /**
   * Applying a cascade moves draft apps only, not sent-back ones.
   */
  public function testApplyCascadeLeavesSentBackApps(): void {
    $sentBack = $this->makeMemberApp($this->skippedCascade, 'needs_adjustment');
    $this->assertSame('cascade', $this->rowFor($this->skippedCascade)['action']);
    $this->report('appverse_repo', (string) $this->skippedCascade->id(), TRUE);
    $this->assertLatestState('ready_for_review', $this->skippedCascadeApp);
    $this->assertLatestState('needs_adjustment', $sentBack);
  }

  /**
   * A cascade leaves a draft app the contributor drafted on purpose.
   *
   * The human-edit rule applies to each draft member app since the repo
   * entered ready_for_review: an app the contributor sent and pulled back is
   * reported and left, and an untouched one is moved.
   */
  public function testCascadeLeavesAnAppTheContributorDrafted(): void {
    $drafted = $this->makeMemberApp($this->skippedCascade, 'draft');
    $this->editLatest($drafted, 'ready_for_review');
    $this->editLatest($drafted, 'draft', 'Not ready yet');

    $row = $this->rowFor($this->skippedCascade);
    $this->assertSame('draft:2', $row['member_apps']);
    $this->assertSame((string) $drafted->id(), $row['held_apps']);
    $this->assertSame(0, $row['human_edits']);
    $this->assertSame('cascade', $row['action']);

    $rows = $this->report('appverse_repo', (string) $this->skippedCascade->id(), TRUE);
    $this->assertSame('cascaded', array_column($rows, 'action', 'nid')[(int) $this->skippedCascade->id()]);
    $this->assertLatestState('ready_for_review', $this->skippedCascadeApp);
    $this->assertLatestState('draft', $drafted);
  }

  /**
   * A cascade whose only draft app was drafted on purpose has nothing to do.
   */
  public function testCascadeWithOnlyDraftedAppsIsSkipped(): void {
    $this->editLatest($this->skippedCascadeApp, 'ready_for_review');
    $this->editLatest($this->skippedCascadeApp, 'draft', 'Not ready yet');

    $row = $this->rowFor($this->skippedCascade);
    $this->assertSame((string) $this->skippedCascadeApp->id(), $row['held_apps']);
    $this->assertSame('skip', $row['action']);
    $this->report('appverse_repo', (string) $this->skippedCascade->id(), TRUE);
    $this->assertLatestState('draft', $this->skippedCascadeApp);
  }

  /**
   * Nodes not listed in --nids are left alone.
   */
  public function testApplyOnlyTouchesListedNids(): void {
    $this->report('appverse_repo', (string) $this->manualDraft->id(), TRUE);
    $this->assertLatestState('draft', $this->broken);
    $this->assertLatestState('draft', $this->manualDraft);
  }

  /**
   * Apply needs an explicit list.
   */
  public function testApplyWithoutNidsRefuses(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->report('appverse_repo', '', TRUE);
  }

  /**
   * Apps are reported and can be restored too.
   */
  public function testAppsAreReportedNotSkipped(): void {
    $rows = $this->report('appverse_app');
    $this->assertContains((int) $this->brokenApp->id(), array_map('intval', array_column($rows, 'nid')));
    $this->report('appverse_app', (string) $this->brokenApp->id(), TRUE);
    $this->assertLatestState('ready_for_review', $this->brokenApp);
    $this->assertCount(0, $this->getMails(), "No email is sent.");
    $this->assertNoReviewStarted();
  }

  /**
   * A standalone app a person edited after the bad record is left to a person.
   */
  public function testEditedAppIsReviewManually(): void {
    $this->editLatest($this->brokenApp, 'draft', 'Holding this app back');
    $row = $this->rowFor($this->brokenApp);
    $this->assertSame('ready_for_review', $row['lost_state']);
    $this->assertSame(1, $row['human_edits']);
    $this->assertSame('review-manually', $row['action']);

    $revisions = $this->revisionCount($this->brokenApp);
    $rows = $this->report('appverse_app', (string) $this->brokenApp->id(), TRUE);
    $this->assertSame('review-manually', array_column($rows, 'action', 'nid')[(int) $this->brokenApp->id()]);
    $this->assertSame($revisions, $this->revisionCount($this->brokenApp));
    $this->assertLatestState('draft', $this->brokenApp);
  }

  /**
   * A listed nid that cannot be restored is reported, not silently ignored.
   */
  public function testListedNidsThatCannotBeRestoredAreReported(): void {
    $typo = 99999;
    $nids = implode(',', [$this->broken->id(), $this->manualDraft->id(), $typo]);
    $rows = $this->report('appverse_repo', $nids, TRUE);
    $actions = array_column($rows, 'action', 'nid');
    $this->assertSame('restored', $actions[(int) $this->broken->id()]);
    $this->assertSame('not-a-candidate', $actions[$typo]);
    // One state record is not a candidate either.
    $this->assertSame('not-a-candidate', $actions[(int) $this->manualDraft->id()]);
  }

}
