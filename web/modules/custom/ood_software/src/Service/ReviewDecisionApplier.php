<?php

namespace Drupal\ood_software\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;

/**
 * Carries out a review's decision on the review, its repo and its apps.
 *
 * The decision moves the repo and the review together (appverse-planning#29;
 * REVIEW-STATES.md), app by app (#30; ReviewDecision::plan()):
 * - An accepted app is published, with the repo and the review.
 * - An app accepted with suggestions waits: the review page offers "Publish
 *   app and review" (publish()).
 * - An app sent back goes to needs_adjustment, a declined one to declined.
 * - With no app accepted, the repo goes back to the contributor or, when every
 *   app is declined, is declined; a live repo takes its apps with it.
 * Every decision records decision_sent_at / _by, which also opens the review
 * to the repo's owner. Repo transitions go through ood_software_node_update(),
 * so today's notification emails fire as before until the decision emails
 * (#32) replace them; app transitions send none.
 */
final class ReviewDecisionApplier {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
    protected TimeInterface $time,
    protected MessengerInterface $messenger,
    protected RepoMemberApps $repoMemberApps,
    protected ModerationInformationInterface $moderationInformation,
  ) {}

  /**
   * Sends the decision: records it and moves the apps, the repo and the review.
   */
  public function send(NodeInterface $review, string $response): void {
    $decisions = $this->appDecisions($review);
    $overall = ReviewProgress::strictestDecision(array_values($decisions));
    $review->set('field_arv_decision_sent_at', $this->time->getCurrentTime());
    $review->set('field_arv_decision_sent_by', $this->currentUser->id());
    if (($review->get('moderation_state')->value ?? '') === 'draft') {
      $review->set('moderation_state', 'in_review');
    }
    $this->saveRevision($review, sprintf('Decision sent (%s) by %s', $overall, $this->currentUser->getDisplayName()));

    $plan = ReviewDecision::plan($decisions);
    $apps = $this->appNodes($review);
    // Apps first, so a repo leaving the catalog takes only the apps still
    // live with it.
    foreach ($plan['apps'] as $pid => $move) {
      $app = $apps[$pid] ?? NULL;
      if ($move === NULL || $app === NULL) {
        continue;
      }
      if ($move === 'publish') {
        $this->publishNode($app, 'Published: accepted on the review.');
      }
      else {
        $this->moveNode($app, $move, $move === 'declined' ? 'Declined on the review.' : 'Changes requested on the review.');
      }
    }

    $repo = $review->get('field_arv_repo')->entity;
    if ($repo instanceof NodeInterface) {
      match ($plan['repo']) {
        'publish' => $this->publishNode($repo, $response !== '' ? $response : 'Published: accepted on the review.'),
        'needs_adjustment' => $this->moveRepo($repo, 'needs_adjustment', $response, 'Auto-unpublished: changes requested on the review.'),
        'declined' => $this->moveRepo($repo, 'declined', $response, 'Auto-unpublished: the review declined the repo.'),
        default => NULL,
      };
    }
    if ($plan['review'] === 'publish') {
      $this->publishReview($review);
    }
  }

  /**
   * "Publish app and review": publishes every accepted app not yet live, the
   * repo, and the review.
   */
  public function publish(NodeInterface $review): void {
    $decisions = $this->appDecisions($review);
    foreach ($this->appNodes($review) as $pid => $app) {
      if (in_array($decisions[$pid] ?? NULL, ['accept', 'accept_with_suggestions'], TRUE) && !$app->isPublished()) {
        $this->publishNode($app, 'Published from the review.');
      }
    }
    $repo = $review->get('field_arv_repo')->entity;
    if ($repo instanceof NodeInterface && !$repo->isPublished()) {
      $this->publishNode($repo, 'Published from the review.');
    }
    $this->publishReview($review);
  }

  /**
   * Verdict paragraph id => its decision.
   */
  public function appDecisions(NodeInterface $review): array {
    $decisions = [];
    foreach ($review->get('field_arv_verdicts')->referencedEntities() as $verdict) {
      $decisions[(string) $verdict->id()] = $verdict->get('field_rvv_conclusion')->value;
    }
    return $decisions;
  }

  /**
   * Verdict paragraph id => the app it decides, where it names one.
   *
   * @return array<string, \Drupal\node\NodeInterface>
   */
  public function appNodes(NodeInterface $review): array {
    $apps = [];
    foreach ($review->get('field_arv_verdicts')->referencedEntities() as $verdict) {
      $app = $verdict->get('field_rvv_app_ref')->entity;
      if ($app instanceof NodeInterface) {
        $apps[(string) $verdict->id()] = $app;
      }
    }
    return $apps;
  }

  protected function publishReview(NodeInterface $review): void {
    $storage = $this->entityTypeManager->getStorage('node');
    foreach (['draft' => ['in_review', 'published'], 'in_review' => ['published']][$review->get('moderation_state')->value ?? ''] ?? [] as $state) {
      $fresh = $storage->loadUnchanged($review->id());
      $fresh->set('moderation_state', $state);
      $this->saveRevision($fresh, sprintf('Review page: moved to %s by %s', $state, $this->currentUser->getDisplayName()));
    }
  }

  /**
   * Publishes a repo or an app from its latest revision, as the hub's publish
   * does, so in-flight edits are what go live.
   */
  protected function publishNode(NodeInterface $node, string $log): void {
    // Already live: nothing to move, and a published-to-published save of a
    // repo would send the publish email again.
    if ($node->isPublished()) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $latest = $storage->getLatestRevisionId($node->id());
    $fresh = $latest ? $storage->loadRevision($latest) : $storage->loadUnchanged($node->id());
    if (!$fresh instanceof NodeInterface || !$this->canMove($fresh, 'published')) {
      return;
    }
    $fresh->set('moderation_state', 'published');
    $this->saveRevision($fresh, $log);
    $this->messenger->addStatus($this->t('Published @title.', ['@title' => $fresh->label()]));
  }

  /**
   * Moves an app to needs_adjustment or declined; one that cannot move from
   * where it is (a draft app is not live) stays.
   */
  protected function moveNode(NodeInterface $node, string $state, string $log): void {
    $fresh = $this->entityTypeManager->getStorage('node')->loadUnchanged($node->id());
    if (!$fresh instanceof NodeInterface || !$this->canMove($fresh, $state)) {
      return;
    }
    $fresh->set('moderation_state', $state);
    $this->saveRevision($fresh, $log);
    $this->messenger->addStatus($state === 'declined'
      ? $this->t('Declined @title.', ['@title' => $fresh->label()])
      : $this->t('Sent @title back for changes.', ['@title' => $fresh->label()]));
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
    if (!$this->canMove($repo, $state)) {
      $this->messenger->addWarning($this->t('The decision was recorded, but @title stays @from: it cannot move to @to from there.', [
        '@title' => $repo->label(),
        '@from' => $repo->get('moderation_state')->value,
        '@to' => $state,
      ]));
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

  /**
   * Whether the node's workflow allows moving it to $state from where it is.
   */
  protected function canMove(NodeInterface $node, string $state): bool {
    $workflow = $this->moderationInformation->getWorkflowForEntity($node)?->getTypePlugin();
    $from = (string) ($node->get('moderation_state')->value ?? '');
    return $workflow && $workflow->hasState($from) && $workflow->hasState($state)
      && $workflow->getState($from)->canTransitionTo($state);
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
