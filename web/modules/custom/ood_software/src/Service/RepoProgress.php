<?php

namespace Drupal\ood_software\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * A repo's progress line: gathers the stored facts ReviewProgress maps.
 *
 * Shared by the hub card and the review page header (appverse-planning#31),
 * so both always show the same step. Reads every review of the repo without
 * an access check: the facts are about the repo, and what each viewer sees of
 * them is ReviewProgress's reviewer / contributor split.
 */
final class RepoProgress {

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * The repo's steps, as ReviewProgress::steps() returns them.
   */
  public function steps(NodeInterface $repo): array {
    return ReviewProgress::steps($this->facts($repo));
  }

  /**
   * The facts ReviewProgress::steps() takes, for one repo.
   *
   * The newest review is the current round's unless its decision was sent
   * before the repo's latest run was dispatched: then the contributor has
   * re-submitted, and that review belongs to the previous round. The round is
   * one more than the reviews of earlier rounds that requested changes.
   */
  public function facts(NodeInterface $repo): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appverse_review')
      ->condition('field_arv_repo', $repo->id())
      ->sort('created', 'DESC')
      ->sort('nid', 'DESC')
      ->execute();
    $reviews = array_values($storage->loadMultiple($ids));

    $dispatchedAt = $repo->hasField('field_review_dispatched_at') ? (int) ($repo->get('field_review_dispatched_at')->value ?? 0) : 0;
    $newest = $reviews[0] ?? NULL;
    $newestSentAt = $newest ? (int) ($newest->get('field_arv_decision_sent_at')->value ?? 0) : 0;
    $current = $newest && !($newestSentAt && $dispatchedAt > $newestSentAt) ? $newest : NULL;

    $changesRequested = 0;
    foreach ($reviews as $review) {
      if ($review !== $current && !$review->get('field_arv_decision_sent_at')->isEmpty()
        && $this->decision($review) === 'request_changes') {
        $changesRequested++;
      }
    }

    $sent = $current && !$current->get('field_arv_decision_sent_at')->isEmpty();
    return [
      'repo_state' => (string) ($repo->get('moderation_state')->value ?? 'draft'),
      'run_status' => $repo->hasField('field_review_status') ? ($repo->get('field_review_status')->value ?: NULL) : NULL,
      'review_state' => $current?->get('moderation_state')->value,
      'decision_sent' => $sent,
      'decision' => $sent ? $this->decision($current) : NULL,
      'suggestion' => $current?->get('field_arv_recommendation')->value,
      'round' => 1 + $changesRequested,
    ];
  }

  /**
   * A review's overall decision: its strictest per-app one.
   */
  protected function decision(NodeInterface $review): ?string {
    $decisions = [];
    foreach ($review->get('field_arv_verdicts')->referencedEntities() as $verdict) {
      $decisions[] = $verdict->get('field_rvv_conclusion')->value;
    }
    return ReviewProgress::strictestDecision($decisions);
  }

}
