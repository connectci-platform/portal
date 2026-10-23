<?php

namespace Drupal\ood_software\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
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

  /**
   * Fields a sync never copies onto a forward revision.
   *
   * Identity, revision bookkeeping and the state fields, which the forward
   * revision keeps as its own.
   */
  protected const FORWARD_SKIP_FIELDS = [
    'nid', 'vid', 'uuid', 'langcode', 'status', 'changed', 'moderation_state',
    'revision_timestamp', 'revision_uid', 'revision_log', 'revision_default',
    'revision_translation_affected', 'default_langcode',
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Saves a node a background sync changed.
   *
   * A new node, or one whose moderation state moved, is an editorial save and
   * goes through the normal path. Anything else rewrites the revision the
   * node was loaded from. When a forward revision is pending (a published
   * node sent for review), the values the sync changed are written onto it
   * too, so publishing it keeps them, and its own edits are left alone.
   */
  public function save(NodeInterface $node): void {
    if ($this->stateMoved($node)) {
      $node->save();
      return;
    }

    $forwardId = $this->forwardRevisionId($node);
    $changed = $forwardId ? $this->changedValues($node) : [];

    $this->saveInPlace($node, (bool) $forwardId);
    if ($forwardId && $changed) {
      $forward = $this->entityTypeManager->getStorage('node')->loadRevision($forwardId);
      if ($forward instanceof NodeInterface) {
        foreach ($changed as $name => $value) {
          $forward->set($name, $value);
        }
        $this->saveInPlace($forward, TRUE);
      }
    }
  }

  /**
   * Whether a forward revision is pending on the node.
   */
  public function hasForwardRevision(NodeInterface $node): bool {
    return $this->forwardRevisionId($node) !== NULL;
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
   * Rewrites a revision without making a new one.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The revision to save.
   * @param bool $quiet
   *   Keep ood_software_node_update() from treating the save as a submission
   *   (no review email, no AI dispatch).
   */
  protected function saveInPlace(NodeInterface $node, bool $quiet): void {
    $node->setNewRevision(FALSE);
    $node->setSyncing(TRUE);
    if ($quiet) {
      // A runtime flag ood_software_node_update() reads, not a field.
      // @phpstan-ignore-next-line
      $node->_ood_software_suppress_notifications = TRUE;
    }
    try {
      $node->save();
    }
    finally {
      $node->setSyncing(FALSE);
    }
  }

  /**
   * The id of a forward revision newer than the node's revision, if any.
   */
  protected function forwardRevisionId(NodeInterface $node): ?int {
    if ($node->isNew() || !$node->hasField('moderation_state')) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('node');
    if (!$storage instanceof RevisionableStorageInterface) {
      return NULL;
    }
    $latest = $storage->getLatestRevisionId($node->id());
    return $latest && (int) $latest !== (int) $node->getRevisionId() ? (int) $latest : NULL;
  }

  /**
   * Field values the caller changed, compared with the stored revision.
   *
   * @return array<string, mixed>
   *   Field values keyed by field name.
   */
  protected function changedValues(NodeInterface $node): array {
    $source = $this->entityTypeManager->getStorage('node')->loadRevision($node->getLoadedRevisionId());
    if (!$source instanceof NodeInterface) {
      return [];
    }
    $changed = [];
    foreach ($node->getFields() as $name => $items) {
      if (!in_array($name, self::FORWARD_SKIP_FIELDS, TRUE) && !$items->equals($source->get($name))) {
        $changed[$name] = $items->getValue();
      }
    }
    return $changed;
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
