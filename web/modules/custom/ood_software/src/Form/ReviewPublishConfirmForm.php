<?php

namespace Drupal\ood_software\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Service\ReviewDecisionApplier;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * "Publish app and review", after an Accept with suggestions.
 *
 * Accept publishes at once; Accept with suggestions leaves this step for when
 * the contributor has had a chance to act on the suggestions
 * (appverse-planning#29). Publishes the accepted apps, the repo and the
 * review.
 */
final class ReviewPublishConfirmForm extends ConfirmFormBase {

  protected NodeInterface $review;

  public function __construct(protected ReviewDecisionApplier $applier) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('ood_software.review_decision_applier'));
  }

  public function getFormId(): string {
    return 'ood_software_review_publish_confirm';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *
   * @return array<string, mixed>
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if ($node === NULL || $node->bundle() !== 'appverse_review') {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }
    $this->review = $node;
    if (!self::canPublish($node)) {
      $this->messenger()->addWarning($this->t('This review has nothing to publish: publishing follows an Accept with suggestions decision.'));
      throw new EnforcedResponseException(new RedirectResponse($this->getCancelUrl()->toString()));
    }
    if (($blocker = $this->applier->repoBlocker($node)) !== NULL) {
      $this->messenger()->addError($blocker);
      throw new EnforcedResponseException(new RedirectResponse($this->getCancelUrl()->toString()));
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * Whether "Publish app and review" applies: a decision has been sent, some
   * app was accepted with suggestions, and that app or the review is not live
   * yet. In a monorepo the other apps may have been sent back (#30); only the
   * accepted ones are published.
   */
  public static function canPublish(NodeInterface $review): bool {
    if ($review->get('field_arv_decision_sent_at')->isEmpty()) {
      return FALSE;
    }
    $waiting = FALSE;
    $suggestions = FALSE;
    foreach ($review->get('field_arv_verdicts')->referencedEntities() as $verdict) {
      if ($verdict->get('field_rvv_conclusion')->value === 'accept_with_suggestions') {
        $suggestions = TRUE;
        $app = $verdict->get('field_rvv_app_ref')->entity;
        $waiting = $waiting || ($app instanceof NodeInterface && !$app->isPublished());
      }
    }
    return $suggestions && ($waiting || !$review->isPublished());
  }

  public function getQuestion() {
    $repo = $this->review->get('field_arv_repo')->entity;
    return $this->t('Publish %repo and its review?', ['%repo' => $repo instanceof NodeInterface ? $repo->label() : $this->review->label()]);
  }

  public function getDescription() {
    return $this->t('The accepted apps and their repo become visible in the public AppVerse catalog, and the public sees the review summary. Apps sent back or declined stay out.');
  }

  public function getConfirmText() {
    return $this->t('Publish app and review');
  }

  public function getCancelUrl() {
    return Url::fromRoute('ood_software.review_page', ['node' => $this->review->id()]);
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirectUrl($this->getCancelUrl());
    // The repo can move between building the form and submitting it.
    $blocker = $this->applier->repoBlocker($this->review);
    // The applier reports each app and the repo it publishes.
    if ($blocker !== NULL || !$this->applier->publish($this->review)) {
      $this->messenger()->addError($blocker ?? $this->t('Nothing was published.'));
      return;
    }
    $this->messenger()->addStatus($this->t('Published the review.'));
  }

}
