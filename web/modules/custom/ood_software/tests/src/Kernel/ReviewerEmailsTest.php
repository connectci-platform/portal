<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Logger\RfcLogLevel;
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
    'content_moderation', 'workflows', 'key', 'flag', 'ood_software', 'dblog',
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
   * A Site Developer who is not a reviewer.
   */
  protected UserInterface $developer;

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
    $this->installSchema('dblog', ['watchdog']);
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
    Role::create(['id' => 'site_developer', 'label' => 'Site Developer'])->save();
    // User 1 bypasses access checks, so take that id first.
    $this->createUser([], 'admin');
    $this->alice = $this->makeUser('alice', TRUE);
    $this->bob = $this->makeUser('bob', TRUE);
    $this->owner = $this->makeUser('owner', FALSE);
    $this->developer = $this->makeUser('dev', FALSE);
    $this->developer->addRole('site_developer');
    $this->developer->save();
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
   * A resubmission goes only to the assigned reviewer, even with the emails
   * off, and tells them it is theirs.
   */
  public function testResubmittedGoesOnlyToTheAssignee(): void {
    $repo = $this->makeRepo('ready_for_review', ['field_repo_assigned_reviewer' => $this->bob->id()]);
    $this->notifier()->notifyTransition($repo, 'needs_adjustment');
    $this->assertSame(['bob@example.com'], $this->recipients('resubmitted'));
    $this->assertSame([], $this->recipients('ready_for_review'), 'not sent as a new submission');
    $this->assertStringContainsString("You're the assigned reviewer.", $this->mailBody('resubmitted'));
  }

  /**
   * With nobody assigned, a resubmission reaches the reviewers who want it.
   */
  public function testResubmittedUnassignedGoesToReviewers(): void {
    $this->notifier()->notifyTransition($this->makeRepo('ready_for_review'), 'needs_adjustment');
    $this->assertSame(['alice@example.com'], $this->recipients('resubmitted'));
    $this->assertStringNotContainsString('assigned reviewer', $this->mailBody('resubmitted'));
  }

  /**
   * A submission to a repo that already has an assignee goes only to them.
   */
  public function testSubmittedGoesOnlyToAnAssignee(): void {
    $repo = $this->makeRepo('ready_for_review', ['field_repo_assigned_reviewer' => $this->bob->id()]);
    $this->notifier()->notifyTransition($repo, 'draft');
    $this->assertSame(['bob@example.com'], $this->recipients('ready_for_review'));
  }

  /**
   * A blocked assignee, or one who is no longer a reviewer, does not own the
   * repo: its emails go to the reviewers as if nobody were assigned.
   */
  public function testAssigneeWhoCannotReviewFallsBackToReviewers(): void {
    $blocked = $this->makeUser('blocked', TRUE);
    $blocked->block()->save();
    $former = $this->makeUser('former', FALSE);
    // Administrators also hold the reviewer permission, but only a reviewer
    // can be assigned, so only a reviewer owns the repo.
    Role::create(['id' => 'administrator', 'label' => 'Administrator'])
      ->grantPermission('administer appverse content')->save();
    $admin = $this->makeUser('siteadmin', FALSE);
    $admin->addRole('administrator');
    $admin->save();
    foreach ([$blocked, $former, $admin] as $assignee) {
      \Drupal::state()->set('system.test_mail_collector', []);
      $repo = $this->makeRepo('ready_for_review', ['field_repo_assigned_reviewer' => $assignee->id()]);
      $this->notifier()->notifyTransition($repo, 'needs_adjustment');
      // The fallback is every reviewer who wants the emails, which includes
      // the administrator: they hold the permission, they just do not own.
      $this->assertSame(['alice@example.com', 'siteadmin@example.com'], $this->recipients('resubmitted'), $assignee->getAccountName());
      $this->assertStringNotContainsString('assigned reviewer', $this->mailBody('resubmitted'), $assignee->getAccountName());
    }
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
   * A contributor who sent their repo for review is recorded as the run's
   * starter, but never gets the reviewers' AI report emails.
   */
  public function testContributorNeverGetsTheRunEmails(): void {
    $repo = $this->makeRepo('ready_for_review', ['field_review_dispatched_by' => $this->owner->id()]);
    $review = Node::create(['type' => 'appverse_review', 'title' => 'Review', 'field_arv_repo' => $repo->id()]);
    $review->save();
    $this->notifier()->notifyRunReady($repo, $review);
    $this->notifier()->notifyRunFailed($repo, 'the run concluded with failure', 7);
    $this->assertNotContains('owner@example.com', array_merge($this->recipients('review_run_ready'), $this->recipients('review_run_failed')));
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

    // Started by cron with nobody assigned: only the Site Developers.
    $this->assertSame(['dev@example.com'], $this->recipients('review_run_failed'));
    $mail = array_values(array_filter($this->getMails(), fn ($m) => $m['key'] === 'review_run_failed'))[0];
    $this->assertStringContainsString('failed: importing the review failed: no sha.', $mail['body']);
    $this->assertStringContainsString('actions/runs/4242', $mail['body']);
  }

  /**
   * A failure reaches who started the run, the assignee and the Site
   * Developers, and no other reviewer.
   */
  public function testRunFailedGoesToStarterAssigneeAndDevelopers(): void {
    $carol = $this->makeUser('carol', TRUE);
    $repo = $this->makeRepo('ready_for_review', [
      'field_review_dispatched_by' => $this->alice->id(),
      'field_repo_assigned_reviewer' => $carol->id(),
    ]);
    $this->notifier()->notifyRunFailed($repo, 'the run concluded with failure', 7);
    $this->assertSame(['alice@example.com', 'carol@example.com', 'dev@example.com'], $this->recipients('review_run_failed'));
  }

  /**
   * A failure skips an assignee who is no longer a reviewer.
   */
  public function testRunFailedSkipsAFormerReviewerAssignee(): void {
    $former = $this->makeUser('former', FALSE);
    $repo = $this->makeRepo('ready_for_review', ['field_repo_assigned_reviewer' => $former->id()]);
    $this->notifier()->notifyRunFailed($repo, 'the run concluded with failure', 7);
    $this->assertSame(['dev@example.com'], $this->recipients('review_run_failed'));
  }

  /**
   * A failure nobody can be told about is logged rather than dropped.
   */
  public function testRunFailedWithNoRecipientIsLogged(): void {
    $this->developer->block()->save();
    $repo = $this->makeRepo('ready_for_review');
    $this->notifier()->notifyRunFailed($repo, 'the run concluded with failure', 7);
    $this->assertSame([], $this->recipients('review_run_failed'));
    $logged = \Drupal::database()->select('watchdog', 'w')
      ->fields('w', ['message'])
      ->condition('type', 'ood_software')
      ->condition('severity', RfcLogLevel::WARNING)
      ->execute()->fetchCol();
    $this->assertNotEmpty(array_filter($logged, fn ($m) => str_contains($m, 'failed AI report')), 'A warning names the failed AI report.');
  }

  /**
   * An update goes only to the assigned reviewer.
   */
  public function testUpdateSubmitted(): void {
    $repo = $this->makeRepo('published', ['field_repo_assigned_reviewer' => $this->bob->id()]);
    $this->notifier()->notifyUpdateSubmitted($repo, 'v2.0');
    $this->assertSame(['bob@example.com'], $this->recipients('review_update'));
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
   * The emails that link to the repo's review.
   *
   * The run-ready email links to the review it names when it has one, and
   * to the repo's review otherwise.
   */
  const REPO_LINK_KEYS = [
    'ready_for_review',
    'resubmitted',
    'review_run_ready',
    'review_run_failed',
    'review_assigned',
    'review_update',
  ];

  /**
   * The emails link to the repo's review, labeled as the review.
   *
   * /appverse/review/{repo id} goes to the repo's newest review when the link
   * is clicked, so the link names the repo rather than the generic queue.
   */
  public function testRepoEmailsLinkToTheRepoReview(): void {
    $repo = $this->makeRepo('ready_for_review', ['title' => 'Tools & Things']);
    Node::create(['type' => 'appverse_review', 'title' => 'Review', 'field_arv_repo' => $repo->id()])->save();

    foreach (self::REPO_LINK_KEYS as $key) {
      $body = $this->renderedBody($key, $repo);
      $this->assertStringContainsString('<p>The review: <a href="/appverse/review/' . $repo->id() . '">', $body, $key);
      $this->assertStringNotContainsString('manage-repos', $body, $key);
      $this->assertStringNotContainsString('review queue', $body, $key);
    }
  }

  /**
   * Before the repo has a review, they link to the queue filtered to it.
   *
   * The link is labeled as the queue, not as a review that does not exist
   * yet, and shows the repo whatever its moderation state.
   */
  public function testRepoEmailsLinkToTheFilteredQueueWithoutReview(): void {
    $repo = $this->makeRepo('ready_for_review', ['title' => 'Tools & Things']);

    foreach (self::REPO_LINK_KEYS as $key) {
      $body = $this->renderedBody($key, $repo);
      $this->assertStringContainsString('<p>The repo in the review queue: <a href="/appverse/manage-repos?title=Tools%20%26%20Things&amp;moderation_state=All">', $body, $key);
      $this->assertStringNotContainsString('/appverse/review/', $body, $key);
      $this->assertStringNotContainsString('The review:', $body, $key);
    }
  }

  /**
   * The raw body ood_software_mail() builds for a key, joined.
   */
  protected function renderedBody(string $key, NodeInterface $repo): string {
    $message = ['subject' => '', 'body' => [], 'langcode' => 'en'];
    ood_software_mail($key, $message, ['node' => $repo, 'reason' => 'it broke', 'tag' => 'v2.0']);
    return implode("\n", array_map('strval', $message['body']));
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
   * The body of the first email sent with this key, line wrapping undone.
   */
  protected function mailBody(string $key): string {
    $mails = array_values(array_filter($this->getMails(), fn ($m) => $m['key'] === $key));
    $this->assertNotEmpty($mails, "No $key email sent.");
    return (string) preg_replace('/\s+/', ' ', (string) $mails[0]['body']);
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
