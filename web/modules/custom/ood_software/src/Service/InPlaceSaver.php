<?php

namespace Drupal\ood_software\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Saves nodes for non-editorial writes without adding a revision.
 *
 * A background write (a sync, a review status) saved the normal way makes a
 * new default revision. On a published repo with a pending ready_for_review
 * forward revision, that buries the forward revision. These saves rewrite the
 * revision in place, so the default revision and any forward revision keep
 * their moderation states.
 */
class InPlaceSaver {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Saves a node a background sync changed.
   *
   * A new node, or one whose moderation state moved, is an editorial save and
   * goes through the normal path. Anything else rewrites the revision the
   * node was loaded from.
   */
  public function save(NodeInterface $node): void {
    if ($this->stateMoved($node)) {
      $node->save();
      return;
    }
    $node->setNewRevision(FALSE);
    $node->setSyncing(TRUE);
    try {
      $node->save();
    }
    finally {
      $node->setSyncing(FALSE);
    }
  }

  /**
   * Applies a field write to the default revision and the latest revision.
   *
   * Not an editorial change, so each is saved in place (setSyncing): no new
   * revision and no moderation move. A forward revision gets the write too, so
   * publishing it keeps it. The suppress flag keeps ood_software_node_update()
   * from reading the save as a ready_for_review self-transition.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node whose revisions to write; only its id is used.
   * @param callable $apply
   *   Called with each revision (NodeInterface) to set its fields.
   */
  public function updateRevisions(NodeInterface $node, callable $apply): void {
    $storage = $this->entityTypeManager->getStorage('node');
    $revisions = [$storage->loadUnchanged($node->id())];
    $latest = $storage->getLatestRevisionId($node->id());
    if ($revisions[0] instanceof NodeInterface && $latest && (int) $latest !== (int) $revisions[0]->getRevisionId()) {
      $revisions[] = $storage->loadRevision($latest);
    }
    foreach ($revisions as $revision) {
      if (!$revision instanceof NodeInterface) {
        continue;
      }
      $apply($revision);
      $revision->setNewRevision(FALSE);
      $revision->setSyncing(TRUE);
      // A runtime flag ood_software_node_update() reads, not a field.
      // @phpstan-ignore-next-line
      $revision->_ood_software_suppress_notifications = TRUE;
      $revision->setValidationRequired(FALSE);
      try {
        $revision->save();
      }
      finally {
        $revision->setSyncing(FALSE);
      }
    }
  }

  /**
   * Whether the node is new or its moderation state differs from its source.
   *
   * Compares with the revision the node was loaded from, not $node->original,
   * which is the default revision.
   */
  protected function stateMoved(NodeInterface $node): bool {
    if ($node->isNew()) {
      return TRUE;
    }
    $loaded = $node->getLoadedRevisionId();
    $source = $loaded ? $this->entityTypeManager->getStorage('node')->loadRevision($loaded) : NULL;
    if (!$source instanceof NodeInterface) {
      return TRUE;
    }
    if (!$node->hasField('moderation_state')) {
      return FALSE;
    }
    return $node->get('moderation_state')->value !== $source->get('moderation_state')->value;
  }

}
