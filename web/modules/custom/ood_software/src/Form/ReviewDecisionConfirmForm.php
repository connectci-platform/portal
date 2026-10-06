<?php

namespace Drupal\ood_software\Form;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Service\ReviewDecision;
use Drupal\ood_software\Service\ReviewDecisionApplier;
use Drupal\ood_software\Service\ReviewProgress;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Confirms sending a review's decision, showing what it will cause.
 *
 * Reached from the review page's "Send decision…" (which saves the page
 * first). Lists each app's decision, the overall one (the strictest), and
 * the "When you confirm" rows; confirming records the decision and moves the
 * repo and the review together (appverse-planning#29).
 */
final class ReviewDecisionConfirmForm extends ConfirmFormBase {

  protected NodeInterface $review;

  protected string $decision;

  public function __construct(protected ReviewDecisionApplier $applier) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('ood_software.review_decision_applier'));
  }

  public function getFormId(): string {
    return 'ood_software_review_decision_confirm';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if ($node === NULL || $node->bundle() !== 'appverse_review') {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }
    $this->review = $node;
    if (!$node->get('field_arv_decision_sent_at')->isEmpty()) {
      $this->messenger()->addWarning($this->t('The decision on this review has already been sent.'));
      throw new EnforcedResponseException(new RedirectResponse($this->getCancelUrl()->toString()));
    }
    $appDecisions = $this->appDecisions();
    $response = (string) ($node->get('field_arv_contributor_response')->value ?? '');
    $problems = ReviewDecision::problems($appDecisions, $response);
    if ($problems !== []) {
      foreach ($problems as $problem) {
        $this->messenger()->addError($problem);
      }
      throw new EnforcedResponseException(new RedirectResponse($this->getCancelUrl()->toString()));
    }
    $this->decision = (string) ReviewProgress::strictestDecision(array_values($appDecisions));

    $repo = $node->get('field_arv_repo')->entity;
    $form['apps'] = count($appDecisions) > 1 ? [
      '#theme' => 'item_list',
      '#title' => $this->t('Per app'),
      '#items' => array_map(
        fn ($app, $d) => new FormattableMarkup('@app: <strong>@d</strong>', ['@app' => $app, '@d' => ReviewProgress::DECISION_LABELS[$d]]),
        array_keys($appDecisions), $appDecisions,
      ),
      '#weight' => -20,
    ] : [];
    $rows = [];
    foreach (ReviewDecision::effects($this->decision, $repo instanceof NodeInterface && $repo->isPublished()) as [$heading, $text]) {
      $rows[] = [['data' => $heading, 'header' => TRUE], $text];
    }
    $form['effects'] = [
      '#type' => 'table',
      '#caption' => $this->t('When you confirm'),
      '#rows' => $rows,
      '#weight' => -10,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * App name => its decision, from the review's verdicts.
   */
  protected function appDecisions(): array {
    $decisions = [];
    foreach ($this->review->get('field_arv_verdicts')->referencedEntities() as $verdict) {
      $app = $verdict->get('field_rvv_app_ref')->entity;
      $name = $app ? $app->label() : (string) ($verdict->get('field_rvv_app_id')->value ?? 'App');
      $decisions[$name] = $verdict->get('field_rvv_conclusion')->value;
    }
    return $decisions;
  }

  public function getQuestion() {
    $repo = $this->review->get('field_arv_repo')->entity;
    return $this->t('Send the decision for %repo: %decision?', [
      '%repo' => $repo ? $repo->label() : $this->review->label(),
      '%decision' => ReviewProgress::DECISION_LABELS[$this->decision] ?? $this->decision,
    ]);
  }

  public function getDescription() {
    return $this->t('This moves the repo and the review together, and lets the contributor read the review and your response.');
  }

  public function getConfirmText() {
    return match ($this->decision) {
      'accept' => $this->t('Accept and publish'),
      'accept_with_suggestions' => $this->t('Accept and send suggestions'),
      'request_changes' => $this->t('Request changes'),
      default => $this->t('Decline'),
    };
  }

  public function getCancelUrl() {
    return Url::fromRoute('ood_software.review_page', ['node' => $this->review->id()]);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $response = (string) ($this->review->get('field_arv_contributor_response')->value ?? '');
    $this->applier->send($this->review, $this->decision, $response);
    $this->messenger()->addStatus($this->t('Decision sent: @d.', ['@d' => ReviewProgress::DECISION_LABELS[$this->decision]]));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
