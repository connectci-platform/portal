<?php

namespace Drupal\ood_software\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
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
 */
class RepoNotificationService {

  use StringTranslationTrait;

  protected LoggerInterface $logger;

  public function __construct(
    protected MailManagerInterface $mailManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
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

    if ($newState === 'ready_for_review') {
      $this->sendToAdmins($node, 'ready_for_review', $extras);
    }
    elseif ($newState === 'needs_adjustment') {
      $this->sendToOwner($node, 'needs_adjustment', $extras);
    }
    elseif ($newState === 'published' && $previousState !== 'published') {
      $this->sendToOwner($node, 'published', $extras);
    }
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
  protected function sendToAdmins(NodeInterface $node, string $key, array $extras): void {
    $roleIds = $this->rolesGranting('administer appverse content');
    if (!$roleIds) {
      // No role grants the permission. The old load-all-and-filter code would
      // still have emailed uid 1 here (the superuser bypass grants every
      // permission with no role). We intentionally do NOT notify uid 1: it is
      // a break-glass account, not a reviewer, and reintroducing an all-users
      // scan to find it is the very cost this method exists to avoid.
      return;
    }
    $uids = $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('roles', $roleIds, 'IN')
      ->execute();
    $users = $this->entityTypeManager->getStorage('user')->loadMultiple($uids);
    foreach ($users as $user) {
      if ($user->getEmail()) {
        $this->dispatch($key, $user->getEmail(), $user->getPreferredLangcode(), $node, $extras);
      }
    }
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
  protected function sendToOwner(NodeInterface $node, string $key, array $extras): void {
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
      return;
    }
    $this->dispatch($key, $owner->getEmail(), $owner->getPreferredLangcode(), $node, $extras);
  }

  /**
   * Dispatch one notification mail.
   *
   * @param array<string, mixed> $extras
   */
  protected function dispatch(string $key, string $to, string $langcode, NodeInterface $node, array $extras): void {
    $params = [
      'node' => $node,
    ] + $extras;
    $this->mailManager->mail('ood_software', $key, $to, $langcode, $params);
  }
}
