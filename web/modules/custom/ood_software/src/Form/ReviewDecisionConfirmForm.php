<?php

namespace Drupal\ood_software\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Service\ReviewDecision;
use Drupal\ood_software\Service\ReviewDecisionApplier;
use Drupal\ood_software\Service\ReviewFloors;
use Drupal\ood_software\Service\ReviewProgress;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Confirms sending a review's decision by previewing the email it sends.
 *
 * Reached from the review page's send button, which names the decision and
 * saves the page first. The heading says the decision whole, a sentence or
 * two say what it causes, and the email follows exactly as the contributor
 * will get it (appverse-planning#52). Confirming records the decision and
 * moves the repo and the review together (#29).
 */
final class ReviewDecisionConfirmForm extends ConfirmFormBase {

  protected NodeInterface $review;

  protected string $decision;

  protected bool $mixed = FALSE;

  /**
   * Verdict id => its decision.
   *
   * @var array<string, string>
   */
  protected array $appDecisions = [];

  /**
   * The email as it will go out.
   *
   * @var array{to: string, reply_to: string, subject: string, body: array<int, \Drupal\Component\Render\MarkupInterface|string>, contributor: string}|null
   */
  protected ?array $preview = NULL;

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
    // Already sent, withdrawn, published, superseded by a newer review, or a
    // repo no longer awaiting review: the page hides Send decision for these,
    // and this route can be opened by URL (appverse-planning#50).
    if (($blocker = $this->applier->decisionBlocker($node)) !== NULL) {
      $this->messenger()->addWarning($blocker);
      throw new EnforcedResponseException(new RedirectResponse($this->getCancelUrl()->toString()));
    }
    // Keyed by verdict, since two apps can share a name (#48).
    $appDecisions = $this->applier->appDecisions($node);
    $names = $this->applier->appNames($node);
    $response = (string) ($node->get('field_arv_contributor_response')->value ?? '');
    // The page offers no choice below an app's floor; this catches one saved
    // before the floor existed or before a finding changed (#30).
    $problems = array_merge(
      ReviewDecision::problems($appDecisions, $response, $names),
      ReviewFloors::problems($appDecisions, ReviewFloors::forReview($node), $names),
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

    $this->appDecisions = $appDecisions;
    $this->preview = $this->applier->previewDecision($node);

    $form['#attributes']['class'][] = 'arv-page';
    $form['#attached']['library'][] = 'ood_software/appverse_review';
    if ($this->preview !== NULL) {
      $headers = '';
      foreach ([
        (string) $this->t('To') => $this->preview['to'] !== '' ? $this->preview['to'] : (string) $this->t('(no address on file)'),
        (string) $this->t('Reply-To') => $this->preview['reply_to'],
        (string) $this->t('Subject') => $this->preview['subject'],
      ] as $label => $value) {
        $headers .= '<dt>' . Html::escape($label) . '</dt><dd>' . Html::escape($value) . '</dd>';
      }
      $form['preview'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['arv-email']],
        'headers' => ['#markup' => Markup::create('<dl class="arv-email__headers">' . $headers . '</dl>')],
        // The body is DecisionEmail::render()'s safe HTML, as mailed.
        'body' => ['#markup' => Markup::create('<div class="arv-email__body">' . implode('', array_map('strval', $this->preview['body'])) . '</div>')],
        '#weight' => -10,
      ];
    }
    $form = parent::buildForm($form, $form_state);
    // What sending causes reads before the email, not after it.
    $form['description']['#weight'] = -20;
    $form['actions']['submit']['#attributes']['class'] = ['btn', 'primary'];
    $form['actions']['cancel']['#attributes']['class'] = ['btn', 'ghost'];
    return $form;
  }

  public function getQuestion() {
    $repo = $this->review->get('field_arv_repo')->entity;
    return ReviewDecision::headline($this->appDecisions, (string) ($repo instanceof NodeInterface ? $repo->label() : $this->review->label()));
  }

  public function getDescription() {
    $repo = $this->review->get('field_arv_repo')->entity;
    $sentences = ReviewDecision::consequences($this->appDecisions, $repo instanceof NodeInterface && $repo->isPublished(), $this->preview['contributor'] ?? '');
    return $this->t('@consequences', ['@consequences' => implode(' ', array_map('strval', $sentences))]);
  }

  public function getConfirmText() {
    $name = $this->preview['contributor'] ?? '';
    return $name !== '' ? $this->t('Send to @name', ['@name' => $name]) : $this->t('Send to the contributor');
  }

  public function getCancelText() {
    return $this->t('Back to the review');
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
    // send() checks again under a lock: the repo can move, or a second
    // submit arrive, between building the form and submitting it.
    if (($error = $this->applier->send($this->review, $response)) !== NULL) {
      $this->messenger()->addError($error);
      return;
    }
    $this->messenger()->addStatus($this->mixed
      ? $this->t('Decisions sent. Overall: @d.', ['@d' => ReviewProgress::DECISION_LABELS[$this->decision]])
      : $this->t('Decision sent: @d.', ['@d' => ReviewProgress::DECISION_LABELS[$this->decision]]));
  }

}
