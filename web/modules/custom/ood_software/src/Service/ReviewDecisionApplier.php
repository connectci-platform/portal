<?php

namespace Drupal\ood_software\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Controller\AppverseHubController;

/**
 * Carries out a review's decision on the review and its repo.
 *
 * The decision moves the repo and the review together
 * (appverse-planning#29; REVIEW-STATES.md):
 * - Accept publishes the repo (and its apps, on first publish) and the review.
 * - Accept with suggestions changes neither; the review page then offers
 *   "Publish app and review" (publish()).
 * - Request changes sends the repo back as needs_adjustment.
 * - Reject declines the repo.
 * Every decision records decision_sent_at / _by, which also opens the review
 * to the repo's owner. The repo transitions reuse the hub's paths
 * (adminPublish, the request-changes cascade), so today's notification
 * emails fire as before until the decision emails (#32) replace them.
 */
final class ReviewDecisionApplier {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
    protected TimeInterface $time,
    protected MessengerInterface $messenger,
    protected RepoMemberApps $repoMemberApps,
    protected ClassResolverInterface $classResolver,
  ) {}

  /**
   * Sends the decision: records it and applies its effect on the repo.
   */
  public function send(NodeInterface $review, string $decision, string $response): void {
    $repo = $review->get('field_arv_repo')->entity;
    $review->set('field_arv_decision_sent_at', $this->time->getCurrentTime());
    $review->set('field_arv_decision_sent_by', $this->currentUser->id());
    if (($review->get('moderation_state')->value ?? '') === 'draft') {
      $review->set('moderation_state', 'in_review');
    }
    $this->saveRevision($review, sprintf('Decision sent (%s) by %s', $decision, $this->currentUser->getDisplayName()));

    if (!$repo instanceof NodeInterface) {
      return;
    }
    match ($decision) {
      'accept' => $this->publish($review),
      'request_changes' => $this->moveRepo($repo, 'needs_adjustment', $response, 'Auto-unpublished: changes requested on the review.'),
      'reject' => $this->moveRepo($repo, 'declined', $response, 'Auto-unpublished: the review declined the repo.'),
      default => NULL,
    };
  }

  /**
   * Publishes the repo (with its apps, on first publish) and the review.
   *
   * Accept does this at once; after Accept with suggestions the review
   * page's "Publish app and review" does it.
   */
  public function publish(NodeInterface $review): void {
    $repo = $review->get('field_arv_repo')->entity;
    if ($repo instanceof NodeInterface && !$repo->isPublished()) {
      // The hub's publish: the transition, its message and the first-publish
      // cascade to member apps.
      $this->classResolver->getInstanceFromDefinition(AppverseHubController::class)->adminPublish($repo);
    }
    $storage = $this->entityTypeManager->getStorage('node');
    foreach (['draft' => ['in_review', 'published'], 'in_review' => ['published']][$review->get('moderation_state')->value ?? ''] ?? [] as $state) {
      $fresh = $storage->loadUnchanged($review->id());
      $fresh->set('moderation_state', $state);
      $this->saveRevision($fresh, sprintf('Review page: moved to %s by %s', $state, $this->currentUser->getDisplayName()));
    }
  }

  /**
   * Moves the repo to needs_adjustment or declined, as the hub's request
   * changes does: the response becomes the revision log and today's
   * notification comment, and a live repo's apps are unpublished with it.
   */
  protected function moveRepo(NodeInterface $repo, string $state, string $response, string $cascadeLog): void {
    // save() does not validate the transition, so check it here: a repo
    // already sent back (needs_adjustment) cannot move again until it is
    // re-submitted.
    $from = (string) ($repo->get('moderation_state')->value ?? '');
    $workflow = $this->entityTypeManager->getStorage('workflow')->load('appverse_editorial')?->getTypePlugin();
    if (!$workflow || !$workflow->hasState($from) || !$workflow->getState($from)->canTransitionTo($state)) {
      $this->messenger->addWarning($this->t('The decision was recorded, but @title stays @from: it cannot move to @to from there.', ['@title' => $repo->label(), '@from' => $from, '@to' => $state]));
      return;
    }
    $wasPublished = $repo->isPublished();
    $repo->set('moderation_state', $state);
    $repo->setRevisionLogMessage($response);
    $repo->setNewRevision(TRUE);
    // Read by ood_software_node_update() and passed to the notifier.
    $repo->_ood_software_review_comment = $response;
    $repo->save();
    if ($wasPublished) {
      $count = $this->repoMemberApps->cascadeModeration($repo, 'draft', [], $cascadeLog, TRUE);
      if ($count > 0) {
        $this->messenger->addStatus($this->t('Also unpublished @count member apps under @title.', ['@count' => $count, '@title' => $repo->label()]));
      }
    }
  }

  protected function saveRevision(NodeInterface $node, string $message): void {
    $node->setNewRevision(TRUE);
    $node->setRevisionUserId((int) $this->currentUser->id());
    $node->setRevisionCreationTime($this->time->getCurrentTime());
    $node->setRevisionLogMessage($message);
    if (method_exists($node, 'setValidationRequired')) {
      $node->setValidationRequired(FALSE);
    }
    $node->save();
  }

}
