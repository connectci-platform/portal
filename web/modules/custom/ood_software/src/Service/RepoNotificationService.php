<?php

namespace Drupal\ood_software\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends moderation-transition emails for appverse_repo nodes.
 *
 * Triggered from ood_software_node_update() when moderation_state changes.
 * Three transitions are notified:
 *   - <any> → ready_for_review : email all users with the
 *     'administer appverse content' permission. Fires for resubmit too.
 *   - <any> → needs_adjustment : email the Repo owner. The
 *     reviewer comment reaches hook_mail via two paths:
 *       1. $params['comment'] set by AppverseHubRequestChangesForm
 *          (the primary path; the form stashes the comment on a
 *          runtime property that hook_node_update forwards).
 *       2. Fallback in hook_mail: read getRevisionLogMessage() on the
 *          latest revision (same source the hub preprocess uses),
 *          which covers any future code path that drives the
 *          transition with a revision log message but doesn't set
 *          the runtime property.
 *   - <non-published> → published : email the Repo owner. Covers
 *     archived → published (restore from archive).
 *
 * App-level state changes are NOT notified — this is intentional to avoid
 * a 50-app cascade-publish triggering 50 emails for a single admin action.
 *
 * The review page's decisions do not go through these transitions: the
 * decision applier suppresses them and sends one composed email per decision
 * instead (sendReviewEmail(); appverse-planning#32).
 *
 * The reviewers' emails from the canvas (appverse-planning#47): the AI report
 * ready or failed, a reviewer assigned, an update submitted by a release, and
 * a repo resubmitted. A reviewer can turn off the submitted, resubmitted and
 * update emails on their profile (SUBMITTED_OPT_OUT); the others are about
 * their own work and always go.
 */
class RepoNotificationService {

  use StringTranslationTrait;

  /**
   * User data key: set to 1 when a reviewer stops the submitted emails.
   */
  const SUBMITTED_OPT_OUT = 'no_submitted_emails';

  protected LoggerInterface $logger;

  public function __construct(
    protected MailManagerInterface $mailManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    // Optional so callers built without it (unit tests) still work.
    protected ?UserDataInterface $userData = NULL,
  ) {
    // Match the rest of ood_software's services: take the logger factory
    // and resolve the channel by name. Avoids needing a dedicated
    // logger.channel.ood_software service definition.
    $this->logger = $loggerFactory->get('ood_software');
  }

  /**
   * Send the appropriate notification(s) for a moderation transition.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The Repo (post-save).
   * @param string|null $previousState
   *   The moderation_state value before this save, or NULL if unknown.
   * @param array<string, mixed> $extras
   *   Optional context that hook_node_update can't recover on its own.
   *   Currently supports: 'comment' (reviewer note for needs_adjustment).
   */
  public function notifyTransition(NodeInterface $node, ?string $previousState, array $extras = []): void {
    if ($node->bundle() !== 'appverse_repo') {
      return;
    }
    $newState = $node->get('moderation_state')->value;
    if ($newState === $previousState) {
      return;
    }

    if ($newState === 'ready_for_review' && $previousState === 'needs_adjustment') {
      $this->notifyResubmitted($node);
    }
    elseif ($newState === 'ready_for_review') {
      $this->sendToAdmins($node, 'ready_for_review', $extras, TRUE);
    }
    elseif ($newState === 'needs_adjustment') {
      $this->sendToOwner($node, 'needs_adjustment', $extras);
    }
    elseif ($newState === 'published' && $previousState !== 'published') {
      $this->sendToOwner($node, 'published', $extras);
    }
  }

  /**
   * Tells the reviewers a live repo was re-submitted.
   *
   * A live repo stays published while its sent-back apps are reviewed again,
   * so no transition into ready_for_review sends the usual email.
   */
  public function notifyResubmitted(NodeInterface $repo): void {
    // The assigned reviewer hears of it even with the submitted emails off:
    // it is their review.
    $this->sendToUsers(array_merge($this->admins(TRUE), array_filter([$this->assignee($repo)])), 'resubmitted', $repo, [
      'assignee' => $this->assignee($repo)?->getDisplayName(),
    ]);
  }

  /**
   * The AI report was imported: whoever started the run, and the assigned
   * reviewer.
   */
  public function notifyRunReady(NodeInterface $repo, NodeInterface $review): void {
    $this->sendToUsers(array_filter([$this->starter($repo), $this->assignee($repo)]), 'review_run_ready', $repo, ['review' => $review]);
  }

  /**
   * The AI report failed: the admins and whoever started the run, with the
   * reason, since an import failure otherwise shows only in the cron log.
   */
  public function notifyRunFailed(NodeInterface $repo, string $reason, int $runId): void {
    $this->sendToUsers(array_merge($this->admins(FALSE), array_filter([$this->starter($repo)])), 'review_run_failed', $repo, [
      'reason' => $reason,
      'run_id' => $runId,
    ]);
  }

  /**
   * A reviewer was assigned by someone else.
   */
  public function notifyAssigned(NodeInterface $repo, AccountInterface $assignee): void {
    $user = $this->entityTypeManager->getStorage('user')->load($assignee->id());
    if ($user instanceof UserInterface) {
      $this->sendToUsers([$user], 'review_assigned', $repo, []);
    }
  }

  /**
   * A release started a re-review of a live repo: the admins and the
   * assigned reviewer.
   */
  public function notifyUpdateSubmitted(NodeInterface $repo, string $tag): void {
    $this->sendToUsers(array_merge($this->admins(TRUE), array_filter([$this->assignee($repo)])), 'review_update', $repo, ['tag' => $tag]);
  }

  /**
   * Whether a reviewer has turned off the submitted-for-review emails.
   */
  public function optedOut(AccountInterface $account): bool {
    return (bool) $this->userData?->get('ood_software', (int) $account->id(), self::SUBMITTED_OPT_OUT);
  }

  /**
   * Sets a reviewer's choice about the submitted-for-review emails.
   */
  public function setOptedOut(AccountInterface $account, bool $optOut): void {
    if ($optOut) {
      $this->userData?->set('ood_software', (int) $account->id(), self::SUBMITTED_OPT_OUT, 1);
    }
    else {
      $this->userData?->delete('ood_software', (int) $account->id(), self::SUBMITTED_OPT_OUT);
    }
  }

  /**
   * The repo's assigned reviewer, if any.
   */
  protected function assignee(NodeInterface $repo): ?UserInterface {
    $user = $repo->hasField(ReviewAssignment::FIELD) ? $repo->get(ReviewAssignment::FIELD)->entity : NULL;
    return $user instanceof UserInterface ? $user : NULL;
  }

  /**
   * Who started the repo's last run; nobody for one started by cron.
   */
  protected function starter(NodeInterface $repo): ?UserInterface {
    $user = $repo->hasField('field_review_dispatched_by') ? $repo->get('field_review_dispatched_by')->entity : NULL;
    return $user instanceof UserInterface && !$user->isAnonymous() ? $user : NULL;
  }

  /**
   * Emails each user once, in their own language.
   *
   * @param array<int, \Drupal\user\UserInterface> $users
   *   The recipients; repeats are sent once.
   * @param string $key
   *   The ood_software_mail() key.
   * @param \Drupal\node\NodeInterface $repo
   *   The repo the email is about.
   * @param array<string, mixed> $extras
   *   Params for hook_mail.
   */
  protected function sendToUsers(array $users, string $key, NodeInterface $repo, array $extras): void {
    $seen = [];
    foreach ($users as $user) {
      if (isset($seen[$user->id()]) || !$user->isActive() || !$user->getEmail()) {
        continue;
      }
      $seen[$user->id()] = TRUE;
      $this->dispatch($key, $user->getEmail(), $user->getPreferredLangcode(), $repo, $extras);
    }
  }

  /**
   * Email the repo's owner a composed review email.
   *
   * @param string $key
   *   review_decision or review_published (see ood_software_mail()).
   * @param array<string, mixed> $email
   *   From DecisionEmail.
   * @param string|null $replyTo
   *   The reviewer's address, so a reply reaches them; NULL for the site's.
   *
   * @return bool
   *   Whether the mail system accepted it.
   */
  public function sendReviewEmail(NodeInterface $repo, string $key, array $email, ?string $replyTo = NULL): bool {
    return $this->sendToOwner($repo, $key, ['email' => $email], $replyTo);
  }

  /**
   * Email every user with 'administer appverse content' permission.
   *
   * Query users by the roles that grant the permission, rather than loading
   * every active user and filtering in PHP. On a site with tens of thousands
   * of accounts the load-all-and-filter approach loaded every active user
   * into memory on each ready_for_review transition, so the synchronous
   * send-for-review request timed out ("did not respond in time"). Resolving
   * the granting roles first keeps this bounded to the handful of reviewers.
   *
   * @param array<string, mixed> $extras
   */
  protected function sendToAdmins(NodeInterface $node, string $key, array $extras, bool $optional = FALSE): void {
    $this->sendToUsers($this->admins($optional), $key, $node, $extras);
  }

  /**
   * The active reviewers, without those who opted out when $optional.
   *
   * @return array<int, \Drupal\user\UserInterface>
   *   The users.
   */
  protected function admins(bool $optional): array {
    $roleIds = $this->rolesGranting('administer appverse content');
    if (!$roleIds) {
      // No role grants the permission. The old load-all-and-filter code would
      // still have emailed uid 1 here (the superuser bypass grants every
      // permission with no role). We intentionally do NOT notify uid 1: it is
      // a break-glass account, not a reviewer, and reintroducing an all-users
      // scan to find it is the very cost this method exists to avoid.
      return [];
    }
    $uids = $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('roles', $roleIds, 'IN')
      ->execute();
    $users = [];
    foreach ($this->entityTypeManager->getStorage('user')->loadMultiple($uids) as $user) {
      if ($user instanceof UserInterface && !($optional && $this->optedOut($user))) {
        $users[] = $user;
      }
    }
    return $users;
  }

  /**
   * Role IDs that grant the given permission.
   *
   * Includes roles flagged as "is admin" (all permissions), which do not list
   * individual permissions but grant them all. Derived rather than hardcoded
   * so a role rename or a new reviewer role is picked up automatically.
   *
   * @param string $permission
   *   The permission machine name.
   *
   * @return array<int, string>
   *   Matching role IDs, excluding the anonymous/authenticated pseudo-roles.
   */
  protected function rolesGranting(string $permission): array {
    $roleIds = [];
    /** @var \Drupal\user\RoleInterface $role */
    foreach ($this->entityTypeManager->getStorage('user_role')->loadMultiple() as $rid => $role) {
      if ($rid === 'anonymous' || $rid === 'authenticated') {
        continue;
      }
      if ($role->isAdmin() || $role->hasPermission($permission)) {
        $roleIds[] = $rid;
      }
    }
    return $roleIds;
  }

  /**
   * Email the Repo owner.
   *
   * @param array<string, mixed> $extras
   */
  protected function sendToOwner(NodeInterface $node, string $key, array $extras, ?string $replyTo = NULL): bool {
    $owner = $node->getOwner();
    // getOwner() is typed non-nullable, but at runtime a node whose owner
    // account was deleted resolves to null. Without this guard the deleted-owner
    // case fatals inside the moderation-transition save. The static analyser
    // cannot see the deleted-reference case, so the null check stays.
    // @phpstan-ignore-next-line
    if (!$owner || !$owner->getEmail()) {
      $this->logger->warning('Repo @id has no owner email; skipping @key notification.', [
        '@id' => $node->id(),
        '@key' => $key,
      ]);
      return FALSE;
    }
    return $this->dispatch($key, $owner->getEmail(), $owner->getPreferredLangcode(), $node, $extras, $replyTo);
  }

  /**
   * Dispatch one notification mail.
   *
   * @param array<string, mixed> $extras
   */
  protected function dispatch(string $key, string $to, string $langcode, NodeInterface $node, array $extras, ?string $replyTo = NULL): bool {
    $params = [
      'node' => $node,
    ] + $extras;
    // NULL Reply-To falls back to the site address.
    $result = $this->mailManager->mail('ood_software', $key, $to, $langcode, $params, $replyTo);
    return !empty($result['result']);
  }
}
