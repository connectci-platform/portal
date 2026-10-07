<?php

namespace Drupal\ood_software\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Service\AppverseReviewService;
use Drupal\ood_software\Service\RepoMemberApps;
use Drupal\ood_software\Service\RepoProgress;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * A contributor withdraws a submission that is in review
 * (appverse-planning#34).
 *
 * Only while the repo waits in review (ready_for_review) and before a
 * decision is sent. The repo goes back to draft; the AI run is forgotten, so
 * a run still in flight seeds no review when it finishes; the round's unsent
 * review is marked withdrawn (field_arv_withdrawn_at), which takes it out of
 * the progress line and the decision. Re-submitting starts a new round.
 */
final class WithdrawConfirmForm extends ConfirmFormBase {

  protected NodeInterface $repo;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RepoProgress $repoProgress,
    protected TimeInterface $time,
    protected AppverseReviewService $reviews,
    protected RepoMemberApps $repoMemberApps,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('ood_software.repo_progress'),
      $container->get('datetime.time'),
      $container->get('ood_software.review_dispatcher'),
      $container->get('ood_software.repo_member_apps'),
    );
  }

  public function getFormId(): string {
    return 'ood_software_withdraw_confirm';
  }

  /**
   * Whether the repo's submission can be withdrawn now.
   */
  public static function canWithdraw(NodeInterface $repo, RepoProgress $progress): bool {
    return $repo->bundle() === 'appverse_repo'
      && ($repo->get('moderation_state')->value ?? '') === 'ready_for_review'
      && !$progress->facts($repo)['decision_sent'];
  }

  /**
   * @param array<string, mixed> $form
   * @return array<string, mixed>
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if ($node === NULL || $node->bundle() !== 'appverse_repo') {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }
    $this->repo = $node;
    if (!self::canWithdraw($node, $this->repoProgress)) {
      $this->messenger()->addWarning($this->t('@title is not in review, or a reviewer has already decided on it, so there is nothing to withdraw.', ['@title' => $node->label()]));
      throw new EnforcedResponseException(new RedirectResponse($this->getCancelUrl()->toString()));
    }
    return parent::buildForm($form, $form_state);
  }

  public function getQuestion() {
    return $this->t('Withdraw %title from review?', ['%title' => $this->repo->label()]);
  }

  public function getDescription() {
    return $this->t('It goes back to draft and leaves the review queue; any review in progress is set aside. You can re-submit it whenever you are ready, which starts a new review.');
  }

  public function getConfirmText() {
    return $this->t('Withdraw');
  }

  public function getCancelUrl() {
    return Url::fromUserInput('/user/' . $this->currentUser()->id() . '/my-appverse');
  }

  /**
   * @param array<string, mixed> $form
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $storage = $this->entityTypeManager->getStorage('node');
    $now = $this->time->getCurrentTime();
    $who = $this->currentUser()->getDisplayName();

    // The round's unsent review, if one was seeded.
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appverse_review')
      ->condition('field_arv_repo', $this->repo->id())
      ->sort('created', 'DESC')
      ->sort('nid', 'DESC')
      ->range(0, 1)
      ->execute();
    $review = $ids ? $storage->load(reset($ids)) : NULL;
    if ($review instanceof NodeInterface && $review->get('field_arv_decision_sent_at')->isEmpty() && $review->get('field_arv_withdrawn_at')->isEmpty()) {
      $review->set('field_arv_withdrawn_at', $now);
      $review->setNewRevision(TRUE);
      $review->setRevisionUserId((int) $this->currentUser()->id());
      $review->setRevisionCreationTime($now);
      $review->setRevisionLogMessage('Withdrawn by ' . $who);
      $review->setValidationRequired(FALSE);
      $review->save();
    }

    $repo = $storage->loadUnchanged($this->repo->id());
    $repo->set('moderation_state', 'draft');
    // Forget the run: cancel it on GitHub if it is still going, and clear
    // the run fields so a re-submit dispatches at once. The poller only
    // follows pending and in-progress runs, so one still in flight seeds no
    // review.
    $this->reviews->forgetRun($repo);
    $repo->setNewRevision(TRUE);
    $repo->setRevisionUserId((int) $this->currentUser()->id());
    $repo->setRevisionCreationTime($now);
    $repo->setRevisionLogMessage('Withdrawn from review by ' . $who);
    $repo->setValidationRequired(FALSE);
    $repo->_ood_software_suppress_notifications = TRUE;
    $repo->save();
    // Its apps went into review with it, so they come back out with it.
    $this->repoMemberApps->cascadeModeration($repo, 'draft', ['ready_for_review'], 'Withdrawn from review with the repo by ' . $who);

    $this->messenger()->addStatus($this->t('Withdrew @title from review. It is a draft again; re-submit it when you are ready.', ['@title' => $repo->label()]));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
