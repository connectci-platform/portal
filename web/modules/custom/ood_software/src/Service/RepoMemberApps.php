<?php

declare(strict_types=1);

namespace Drupal\ood_software\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Shared helpers for a Repo's member appverse_app nodes.
 *
 * Consolidates two patterns that were previously hand-copied across the hub
 * controller and forms (and had already diverged in ways that caused bugs):
 *   - querying the apps whose field_appverse_repo points at a given Repo, and
 *   - cascading a moderation-state change from a Repo to those member apps.
 *
 * Keeping the cascade in one place means the setValidationRequired(FALSE)
 * guard (required on non-form moderation saves in Drupal 10.2+, or save()
 * throws EntityStorageException) can never be dropped from just one copy.
 */
class RepoMemberApps {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a RepoMemberApps service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * IDs of the appverse_app nodes belonging to $repo.
   *
   * @param \Drupal\node\NodeInterface $repo
   *   The appverse_repo node.
   * @param bool $published_only
   *   When TRUE, restrict to currently-published (status = 1) apps.
   *
   * @return int[]
   *   Member app node IDs.
   */
  public function queryMemberAppIds(NodeInterface $repo, bool $published_only = FALSE): array {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appverse_app')
      ->condition('field_appverse_repo', $repo->id());
    if ($published_only) {
      $query->condition('status', 1);
    }
    return array_map('intval', array_values($query->execute()));
  }

  /**
   * Cascade a moderation-state change from a Repo to its member apps.
   *
   * @param \Drupal\node\NodeInterface $repo
   *   The parent Repo node.
   * @param string $target_state
   *   The moderation state to set on qualifying member apps.
   * @param string[] $from_states
   *   Only apps currently in one of these moderation states are changed. An
   *   empty array means "any state" — but callers should still pass
   *   $published_only where they only want to touch live apps.
   * @param string $log_message
   *   Revision log message recorded on each changed app.
   * @param bool $published_only
   *   Restrict the member-app query to published apps (status = 1).
   *
   * @return int
   *   The number of member apps changed.
   */
  public function cascadeModeration(NodeInterface $repo, string $target_state, array $from_states, string $log_message, bool $published_only = FALSE): int {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $this->queryMemberAppIds($repo, $published_only);

    $count = 0;
    foreach ($storage->loadMultiple($ids) as $app) {
      if (!$app->hasField('moderation_state')) {
        continue;
      }
      if ($from_states && !in_array($app->get('moderation_state')->value, $from_states, TRUE)) {
        continue;
      }
      $app->set('moderation_state', $target_state);
      $app->setNewRevision(TRUE);
      $app->setRevisionLogMessage($log_message);
      // Non-form moderation save: Drupal 10.2+ throws EntityStorageException
      // when validationRequired is TRUE without a recent validate() call.
      if (method_exists($app, 'setValidationRequired')) {
        $app->setValidationRequired(FALSE);
      }
      $app->save();
      $count++;
    }

    return $count;
  }

}
