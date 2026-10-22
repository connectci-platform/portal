<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Lock\DatabaseLockBackend;
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
    'ood_software_nested_save_test',
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
   * The review service, recording runs instead of starting them.
   */
  protected StubDispatchReviewService $dispatcher;

  /**
   * A standalone app sent for review while the old nested save was active.
   */
  protected NodeInterface $brokenApp;

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
    ]);
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('system.site')->set('name', 'Appverse')->set('mail', 'site@example.org')->save();
    $this->createUser([], 'admin');
    $this->owner = $this->createUser([], 'owner', FALSE, ['mail' => 'owner@example.com']);
    // An active user in a role granting the permission is who the notifier
    // emails when a repo enters ready_for_review.
    $this->reviewer = $this->createUser(['administer appverse content'], 'reviewer', FALSE, ['mail' => 'reviewer@example.com']);
    $this->setCurrentUser($this->owner);

    $this->command = new AppverseBackfillCommands(
      $this->container->get('entity_type.manager'),
      $this->container->get('ood_software.repo_sync'),
      $this->container->get('ood_software.gh'),
      $this->container->get('logger.factory'),
      $this->container->get('ood_software.repo_member_apps'),
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
    );
    $this->container->set('ood_software.review_dispatcher', $this->dispatcher);
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
   *   Rows with nid, current, lost_state, member_apps, action.
   */
  protected function project(array $rows): array {
    $keep = array_flip(['nid', 'current', 'lost_state', 'member_apps', 'action']);
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
        'action' => 'restore',
      ],
    ], $rows);
    $this->assertLatestState('draft', $this->broken);
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
