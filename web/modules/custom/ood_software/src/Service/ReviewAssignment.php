<?php

namespace Drupal\ood_software\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Who reviews a repo (appverse-planning#33).
 *
 * The assignee lives on the repo, not on each review, so a repo can be
 * assigned while it waits for its AI report and keeps its reviewer across
 * rounds; who decided each round is the review's decision_sent_by. Only
 * Only appverse_pm can be assigned (the field's handler). Administrators were
 * offered too, which put people who do not review into the picker
 * (appverse-planning#33, #55); the field's declared dependencies already
 * listed appverse_pm alone.
 */
final class ReviewAssignment {

  const FIELD = 'field_repo_assigned_reviewer';

  const ROLES = ['appverse_pm'];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * The repo's reviewer, or NULL.
   */
  public function assignee(NodeInterface $repo): ?AccountInterface {
    if (!$repo->hasField(self::FIELD)) {
      return NULL;
    }
    $user = $repo->get(self::FIELD)->entity;
    return $user instanceof AccountInterface ? $user : NULL;
  }

  /**
   * The people who can be assigned: active users with a reviewer role,
   * uid => display name, by name.
   */
  public function reviewers(): array {
    $storage = $this->entityTypeManager->getStorage('user');
    $uids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('roles', self::ROLES, 'IN')
      ->execute();
    $names = [];
    foreach ($storage->loadMultiple($uids) as $user) {
      $names[(int) $user->id()] = $user->getDisplayName();
    }
    // Two reviewers can share a display name; the user id tells them apart
    // (the account name can be an email address).
    $counts = array_count_values($names);
    foreach ($names as $uid => $name) {
      if ($counts[$name] > 1) {
        $names[$uid] = "$name (user $uid)";
      }
    }
    natcasesort($names);
    return $names;
  }

  /**
   * Assigns the repo to a reviewer, or unassigns it (NULL).
   *
   * Not an editorial change, so it is saved as one (setSyncing): no new
   * revision, no moderation move, and the default revision stays the default.
   * A pending draft revision gets it too, so publishing that draft keeps it.
   * The suppress flag keeps ood_software_node_update() from reading the save
   * as a ready_for_review self-transition, which would start a review run.
   *
   * @return bool
   *   FALSE when the user cannot be assigned or nothing changed.
   */
  public function assign(NodeInterface $repo, ?int $uid): bool {
    if ($uid !== NULL && !isset($this->reviewers()[$uid])) {
      return FALSE;
    }
    $current = $this->assignee($repo);
    if (($current ? (int) $current->id() : NULL) === $uid) {
      return FALSE;
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $revisions = [$storage->loadUnchanged($repo->id())];
    $latest = $storage->getLatestRevisionId($repo->id());
    if ($latest && (int) $latest !== (int) $revisions[0]->getRevisionId()) {
      $revisions[] = $storage->loadRevision($latest);
    }
    foreach (array_filter($revisions) as $revision) {
      $revision->set(self::FIELD, $uid);
      $revision->setSyncing(TRUE);
      $revision->setNewRevision(FALSE);
      $revision->_ood_software_suppress_notifications = TRUE;
      $revision->save();
    }
    $this->loggerFactory->get('ood_software')->info('Repo @repo: reviewer @change by @by.', [
      '@repo' => $repo->id(),
      '@change' => $uid === NULL ? 'unassigned' : 'assigned to user ' . $uid,
      '@by' => $this->currentUser->getAccountName(),
    ]);
    return TRUE;
  }

}
