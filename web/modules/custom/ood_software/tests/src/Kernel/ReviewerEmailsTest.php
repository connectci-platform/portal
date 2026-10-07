<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Service\AppverseReviewService;
use Drupal\ood_software\Service\RepoNotificationService;
use Drupal\ood_software\Service\ReviewAssignment;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;

/**
 * The reviewers' emails from the canvas (appverse-planning#47): who gets
 * each, the opt-out, and that each person gets one copy.
 *
 * @group ood_software
 */
class ReviewerEmailsTest extends KernelTestBase {

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
  ];

  /**
   * Two reviewers, one of whom opted out of the submitted emails.
   */
  protected UserInterface $alice;

  /**
   * The reviewer who turned the submitted emails off.
   */
  protected UserInterface $bob;

  /**
   * The contributor.
   */
  protected UserInterface $owner;

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
      'node.type.appverse_review',
      'workflows.workflow.appverse_editorial',
      'field.storage.node.field_repo_assigned_reviewer',
      'field.field.node.appverse_repo.field_repo_assigned_reviewer',
      'field.storage.node.field_review_dispatched_by',
      'field.field.node.appverse_repo.field_review_dispatched_by',
      'field.storage.node.field_review_status',
      'field.field.node.appverse_repo.field_review_status',
      'field.storage.node.field_review_run_id',
      'field.field.node.appverse_repo.field_review_run_id',
      'field.storage.node.field_arv_repo',
      'field.field.node.appverse_review.field_arv_repo',
    ]);
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('system.site')->set('name', 'Appverse')->set('mail', 'site@example.org')->save();

    Role::create(['id' => 'appverse_pm', 'label' => 'Appverse PM'])
      ->grantPermission('administer appverse content')->save();
    // User 1 bypasses access checks, so take that id first.
    $this->createUser([], 'admin');
    $this->alice = $this->makeUser('alice', TRUE);
    $this->bob = $this->makeUser('bob', TRUE);
    $this->owner = $this->makeUser('owner', FALSE);
    $this->notifier()->setOptedOut($this->bob, TRUE);
  }

  /**
   * The submitted email skips a reviewer who opted out.
   */
  public function testSubmittedHonoursTheOptOut(): void {
    $this->notifier()->notifyTransition($this->makeRepo('ready_for_review'), 'draft');
    $this->assertSame(['alice@example.com'], $this->recipients('ready_for_review'));
  }

  /**
   * A resubmission reaches the assigned reviewer even with the emails off.
   */
  public function testResubmittedReachesTheAssignee(): void {
    $repo = $this->makeRepo('ready_for_review', ['field_repo_assigned_reviewer' => $this->bob->id()]);
    $this->notifier()->notifyTransition($repo, 'needs_adjustment');
    $this->assertSame(['alice@example.com', 'bob@example.com'], $this->recipients('resubmitted'));
    $this->assertSame([], $this->recipients('ready_for_review'), 'not sent as a new submission');
  }

  /**
   * The report goes to who started the run and the assignee, once each.
   */
  public function testRunReadyGoesToStarterAndAssignee(): void {
    $repo = $this->makeRepo('ready_for_review', [
      'field_review_dispatched_by' => $this->alice->id(),
      'field_repo_assigned_reviewer' => $this->alice->id(),
    ]);
    $review = Node::create(['type' => 'appverse_review', 'title' => 'Review', 'field_arv_repo' => $repo->id()]);
    $review->save();
    $this->notifier()->notifyRunReady($repo, $review);
    $this->assertSame(['alice@example.com'], $this->recipients('review_run_ready'));
  }

  /**
   * A failure emails the admins once, whatever their opt-out, with the
   * reason; a later poll of the same failure sends nothing.
   */
  public function testRunFailedIsSentOnce(): void {
    $repo = $this->makeRepo('ready_for_review', ['field_review_status' => 'in_progress']);
    $update = new \ReflectionMethod(AppverseReviewService::class, 'updateNodeReviewStatus');
    $service = \Drupal::service('ood_software.review_dispatcher');
    $update->invoke($service, $repo, 'error', 4242, 'importing the review failed: no sha');
    $update->invoke($service, $repo, 'error', 4242, 'importing the review failed: no sha');

    $this->assertSame(['alice@example.com', 'bob@example.com'], $this->recipients('review_run_failed'));
    $mail = array_values(array_filter($this->getMails(), fn ($m) => $m['key'] === 'review_run_failed'))[0];
    $this->assertStringContainsString('failed: importing the review failed: no sha.', $mail['body']);
    $this->assertStringContainsString('actions/runs/4242', $mail['body']);
  }

  /**
   * An update reaches the reviewers who want it and the assignee.
   */
  public function testUpdateSubmitted(): void {
    $repo = $this->makeRepo('published', ['field_repo_assigned_reviewer' => $this->bob->id()]);
    $this->notifier()->notifyUpdateSubmitted($repo, 'v2.0');
    $this->assertSame(['alice@example.com', 'bob@example.com'], $this->recipients('review_update'));
    $this->assertStringContainsString('(v2.0) is being reviewed', $this->getMails()[0]['body']);
  }

  /**
   * Assigning someone else emails them; assigning yourself does not.
   */
  public function testAssignedBySomeoneElse(): void {
    $repo = $this->makeRepo('ready_for_review');
    $this->setCurrentUser($this->alice);
    $assignment = \Drupal::service('ood_software.review_assignment');
    assert($assignment instanceof ReviewAssignment);

    $this->assertTrue($assignment->assign($repo, (int) $this->alice->id()));
    $this->assertSame([], $this->recipients('review_assigned'), 'not sent when you assign yourself');

    $this->assertTrue($assignment->assign($repo, (int) $this->bob->id()));
    $this->assertSame(['bob@example.com'], $this->recipients('review_assigned'));
  }

  /**
   * The notifier.
   */
  protected function notifier(): RepoNotificationService {
    $notifier = \Drupal::service('ood_software.repo_notifier');
    assert($notifier instanceof RepoNotificationService);
    return $notifier;
  }

  /**
   * Who got the emails with this key, sorted.
   *
   * @return array<int, string>
   *   The addresses.
   */
  protected function recipients(string $key): array {
    $to = array_column(array_filter($this->getMails(), fn ($m) => $m['key'] === $key), 'to');
    sort($to);
    return $to;
  }

  /**
   * Creates a user, a reviewer when $reviewer.
   */
  protected function makeUser(string $name, bool $reviewer): UserInterface {
    $user = $this->createUser([], $name, FALSE, ['mail' => "$name@example.com", 'roles' => $reviewer ? ['appverse_pm'] : []]);
    $this->assertInstanceOf(UserInterface::class, $user);
    return $user;
  }

  /**
   * Creates a repo owned by the contributor.
   *
   * @param string $state
   *   Its moderation state.
   * @param array<string, mixed> $values
   *   Other field values.
   */
  protected function makeRepo(string $state, array $values = []): NodeInterface {
    $repo = Node::create($values + ['type' => 'appverse_repo', 'title' => 'example/repo', 'uid' => $this->owner->id(), 'moderation_state' => $state]);
    // A runtime flag ood_software_node_update() reads, not a field.
    // @phpstan-ignore-next-line
    $repo->_ood_software_suppress_notifications = TRUE;
    $repo->save();
    return $repo;
  }

}
