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
   * The newest review is the current round's unless the contributor has
   * re-submitted since its decision was sent, in which case it belongs to the
   * previous round. Re-submitting means a run dispatched after the decision
   * AND the repo having moved off the state that decision left it in: an
   * admin re-running the AI report dispatches too but moves nothing. The
   * round is one more than the reviews of earlier rounds that requested
   * changes. A review the contributor withdrew is never the current one.
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
    // A review the contributor withdrew (appverse-planning#34) is set aside.
    $withdrawn = $newest && $newest->hasField('field_arv_withdrawn_at') && !$newest->get('field_arv_withdrawn_at')->isEmpty();
    // A run dispatched after the decision was sent only starts a new round if
    // the contributor re-submitted. Sending for review moves the repo to
    // ready_for_review (or, for a live repo, leaves it published with its
    // sent-back apps moved); an admin's Run AI report moves nothing, so a
    // rerun on a repo still sitting in needs_adjustment or declined used to
    // read as "Resubmitted · round 2" and told the contributor their repo was
    // in review while the Re-submit button was still in front of them
    // (appverse-planning#49).
    $repoState = (string) ($repo->get('moderation_state')->value ?? 'draft');
    $resubmitted = $newestSentAt
      && $dispatchedAt > $newestSentAt
      && !in_array($repoState, ['needs_adjustment', 'declined'], TRUE);
    $current = $newest && !$withdrawn && !$resubmitted ? $newest : NULL;

    $changesRequested = 0;
    foreach ($reviews as $review) {
      if ($review !== $current && !$review->get('field_arv_decision_sent_at')->isEmpty()
        && $this->decision($review) === 'request_changes') {
        $changesRequested++;
      }
    }

    $sent = $current && !$current->get('field_arv_decision_sent_at')->isEmpty();
    return [
      // Per-app decisions, for a monorepo whose apps did not all go the same
      // way: the single collapsed decision shows the strictest one, so a
      // mixed repo said "Changes requested" with no word on which apps went
      // live (appverse-planning#49). Empty for a single-app repo, where the
      // one decision already says it.
      'app_decisions' => $sent ? $this->appDecisions($current) : [],
      'repo_state' => $repoState,
      'run_status' => $repo->hasField('field_review_status') ? ($repo->get('field_review_status')->value ?: NULL) : NULL,
      'review_state' => $current?->get('moderation_state')->value,
      'decision_sent' => $sent,
      'decision' => $sent ? $this->decision($current) : NULL,
      'suggestion' => $current?->get('field_arv_recommendation')->value,
      'round' => 1 + $changesRequested,
    ];
  }

  /**
   * Each app's own sent decision, as name => decision.
   *
   * Only for a repo with more than one app: with one app the repo's decision
   * and the app's are the same thing said twice.
   *
   * @return array<string, string>
   */
  protected function appDecisions(NodeInterface $review): array {
    $sent = ReviewDecisionApplier::sent($review);
    if ($sent === NULL || count($sent['apps']) < 2) {
      return [];
    }
    $names = [];
    foreach ($review->get('field_arv_verdicts')->referencedEntities() as $verdict) {
      $app = $verdict->get('field_rvv_app_ref')->entity;
      $names[(string) $verdict->id()] = $app instanceof NodeInterface
        ? (string) $app->label()
        : (string) ($verdict->get('field_rvv_app_id')->value ?? 'App');
    }
    $decisions = [];
    foreach ($sent['apps'] as $verdictId => $decision) {
      $name = $names[(string) $verdictId] ?? NULL;
      if ($name !== NULL && $decision !== '') {
        $decisions[$name] = (string) $decision;
      }
    }
    return $decisions;
  }

  /**
   * A review's overall decision: its strictest per-app one.
   */
  protected function decision(NodeInterface $review): ?string {
    // What was sent, not what the page holds now (appverse-planning#51).
    $sent = ReviewDecisionApplier::sent($review);
    if ($sent !== NULL) {
      return ReviewProgress::strictestDecision(array_values($sent['apps']));
    }
    $decisions = [];
    foreach ($review->get('field_arv_verdicts')->referencedEntities() as $verdict) {
      $decisions[] = $verdict->get('field_rvv_conclusion')->value;
    }
    return ReviewProgress::strictestDecision($decisions);
  }

}
