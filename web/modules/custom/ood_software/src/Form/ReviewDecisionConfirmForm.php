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
use Drupal\ood_software\Service\ReviewFloors;
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

  protected bool $mixed = FALSE;

  public function __construct(protected ReviewDecisionApplier $applier) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('ood_software.review_decision_applier'));
  }

  public function getFormId(): string {
    return 'ood_software_review_decision_confirm';
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
    if (!$node->get('field_arv_decision_sent_at')->isEmpty()) {
      $this->messenger()->addWarning($this->t('The decision on this review has already been sent.'));
      throw new EnforcedResponseException(new RedirectResponse($this->getCancelUrl()->toString()));
    }
    if ($node->hasField('field_arv_withdrawn_at') && !$node->get('field_arv_withdrawn_at')->isEmpty()) {
      $this->messenger()->addWarning($this->t('The contributor withdrew this submission; there is nothing to decide unless they re-submit.'));
      throw new EnforcedResponseException(new RedirectResponse($this->getCancelUrl()->toString()));
    }
    if (($blocker = $this->applier->repoBlocker($node)) !== NULL) {
      $this->messenger()->addError($blocker);
      throw new EnforcedResponseException(new RedirectResponse($this->getCancelUrl()->toString()));
    }
    $appDecisions = $this->appDecisions();
    $response = (string) ($node->get('field_arv_contributor_response')->value ?? '');
    // The page offers no choice below an app's floor; this catches one saved
    // before the floor existed or before a finding changed (#30).
    $names = $this->appNames();
    $problems = array_merge(
      ReviewDecision::problems($appDecisions, $response),
      ReviewFloors::problems($this->applier->appDecisions($node), ReviewFloors::forReview($node), $names),
    );
    if ($problems !== []) {
      foreach ($problems as $problem) {
        $this->messenger()->addError($problem);
      }
      throw new EnforcedResponseException(new RedirectResponse($this->getCancelUrl()->toString()));
    }
    $this->decision = (string) ReviewProgress::strictestDecision(array_values($appDecisions));
    // Apps decided differently each go their own way (#30), so the overall
    // (strictest) decision would mislabel the button.
    $this->mixed = count(array_unique(array_values($appDecisions))) > 1;

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
    foreach (ReviewDecision::effectsFor($appDecisions, $repo instanceof NodeInterface && $repo->isPublished()) as [$heading, $text]) {
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
   *
   * @return array<string, string>
   */
  protected function appDecisions(): array {
    $names = $this->appNames();
    $decisions = [];
    foreach ($this->applier->appDecisions($this->review) as $pid => $decision) {
      $decisions[$names[$pid]] = $decision;
    }
    return $decisions;
  }

  /**
   * Verdict paragraph id => the app's name.
   *
   * @return array<string, string>
   */
  protected function appNames(): array {
    return $this->applier->appNames($this->review);
  }

  public function getQuestion() {
    $repo = $this->review->get('field_arv_repo')->entity;
    if ($this->mixed) {
      return $this->t('Send the decisions for %repo? Overall: %decision', [
        '%repo' => $repo instanceof NodeInterface ? $repo->label() : $this->review->label(),
        '%decision' => ReviewProgress::DECISION_LABELS[$this->decision] ?? $this->decision,
      ]);
    }
    return $this->t('Send the decision for %repo: %decision?', [
      '%repo' => $repo instanceof NodeInterface ? $repo->label() : $this->review->label(),
      '%decision' => ReviewProgress::DECISION_LABELS[$this->decision] ?? $this->decision,
    ]);
  }

  public function getDescription() {
    return $this->t('This moves the repo and the review together, and lets the contributor read the review and your response.');
  }

  public function getConfirmText() {
    if ($this->mixed) {
      return $this->t('Send the decisions');
    }
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

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $response = (string) ($this->review->get('field_arv_contributor_response')->value ?? '');
    $form_state->setRedirectUrl($this->getCancelUrl());
    // The repo can move between building the form and submitting it.
    $blocker = $this->applier->repoBlocker($this->review);
    if ($blocker !== NULL || !$this->applier->send($this->review, $response)) {
      $this->messenger()->addError($blocker ?? $this->t('The decision was not sent.'));
      return;
    }
    $this->messenger()->addStatus($this->mixed
      ? $this->t('Decisions sent. Overall: @d.', ['@d' => ReviewProgress::DECISION_LABELS[$this->decision]])
      : $this->t('Decision sent: @d.', ['@d' => ReviewProgress::DECISION_LABELS[$this->decision]]));
  }

}
