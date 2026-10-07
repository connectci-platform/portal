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
   * Whether "Publish app and review" applies (appverse-planning#29, #41, #51).
   *
   * Only after a decision was sent that accepted some app with suggestions,
   * read from what was sent, not from the page, which can be edited later.
   * The repo must still be awaiting review, or live under re-review, the
   * states repoBlocker() allows, and this must be its newest review. Then: an
   * app accepted with suggestions is not live yet, or the review is not. In a
   * monorepo the other apps may have been sent back (#30); only the accepted
   * ones are published.
   */
  public static function canPublish(NodeInterface $review): bool {
    $sent = ReviewDecisionApplier::sent($review);
    $repo = $review->get('field_arv_repo')->entity;
    if ($sent === NULL || !in_array('accept_with_suggestions', $sent['apps'], TRUE)
      || !$repo instanceof NodeInterface
      || !in_array($repo->get('moderation_state')->value ?? '', ['ready_for_review', 'published'], TRUE)) {
      return FALSE;
    }
    $newest = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'appverse_review')
      ->condition('field_arv_repo', $repo->id())
      ->sort('created', 'DESC')
      ->sort('nid', 'DESC')
      ->range(0, 1)
      ->execute();
    if ((int) reset($newest) !== (int) $review->id()) {
      return FALSE;
    }
    if (!$review->isPublished()) {
      return TRUE;
    }
    foreach ($review->hasField('field_arv_verdicts') ? $review->get('field_arv_verdicts')->referencedEntities() : [] as $verdict) {
      $app = $verdict->get('field_rvv_app_ref')->entity;
      if (($sent['apps'][(string) $verdict->id()] ?? NULL) === 'accept_with_suggestions'
        && $app instanceof NodeInterface && !$app->isPublished()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  public function getQuestion() {
    $repo = $this->review->get('field_arv_repo')->entity;
    return $this->t('Publish %repo and its review?', ['%repo' => $repo instanceof NodeInterface ? $repo->label() : $this->review->label()]);
  }

  public function getDescription() {
    return $this->t('The accepted apps and their repo become visible in the public Appverse catalog, and the public sees the review summary. Apps sent back or declined stay out.');
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
    // The applier reports each app and the repo it publishes, and checks the
    // repo again, since it can move between building the form and submitting.
    if (($error = $this->applier->publish($this->review)) !== NULL) {
      $this->messenger()->addError($error);
      return;
    }
    $this->messenger()->addStatus($this->t('Published the review.'));
  }

}
