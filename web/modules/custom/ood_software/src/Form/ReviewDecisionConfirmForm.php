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
use Drupal\ood_software\Service\DuplicateCheck;
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
   * Whether this confirms an update of a sent decision (?update=1,
   * appverse-planning#56) rather than the first decision.
   */
  protected bool $updating = FALSE;

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
    $this->updating = (bool) $this->getRequest()->query->get('update');
    // Already sent, withdrawn, published, superseded by a newer review, or a
    // repo no longer awaiting review: the page hides Send decision for these,
    // and this route can be opened by URL (appverse-planning#50). An update
    // has its own rule: a sent decision, until the contributor re-submits.
    $blocker = $this->updating ? $this->applier->updateBlocker($node) : $this->applier->decisionBlocker($node);
    if ($blocker !== NULL) {
      $this->messenger()->addWarning($blocker);
      throw new EnforcedResponseException(new RedirectResponse($this->getCancelUrl()->toString()));
    }
    // Keyed by verdict, since two apps can share a name (#48).
    $appDecisions = $this->applier->appDecisions($node);
    $names = $this->applier->appNames($node);
    $response = (string) ($node->get('field_arv_contributor_response')->value ?? '');
    // The page offers no choice below an app's floor; this catches one saved
    // before the floor existed or before a finding changed (#30).
    $duplicates = $this->applier->appDuplicateChecks($node);
    $problems = array_merge(
      ReviewDecision::problems($appDecisions, $response, $names),
      ReviewFloors::problems($appDecisions, ReviewFloors::forReview($node), $names),
      // Any Accept is conditional on the duplicate check (A4).
      DuplicateCheck::problems($appDecisions, $duplicates, $names),
    );
    if ($problems !== []) {
      foreach ($problems as $problem) {
        $this->messenger()->addError($problem);
      }
      throw new EnforcedResponseException(new RedirectResponse($this->getCancelUrl()->toString()));
    }
    // Accepting an app marked a duplicate is the reviewer's call; say so.
    foreach (DuplicateCheck::warnings($appDecisions, $duplicates, $names) as $warning) {
      $this->messenger()->addWarning($warning);
    }
    $this->decision = (string) ReviewProgress::strictestDecision(array_values($appDecisions));
    // Apps decided differently each go their own way (#30), so the overall
    // (strictest) decision would mislabel the button.
    $this->mixed = count(array_unique(array_values($appDecisions))) > 1;

    $this->appDecisions = $appDecisions;
    $this->preview = $this->applier->previewDecision($node, $this->updating);

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
    // What sending moves, one line per thing that moves. An update says where
    // each ends up from where it is now; a send says what the decision causes.
    // Both were a run-together paragraph of "Catalog: ... Review: ..." clauses
    // on the send path (appverse-planning#53).
    $moves = $this->updating ? $this->updateMoves($node) : $this->sendMoves($node);
    if ($moves !== []) {
      $form['moves'] = [
        '#theme' => 'item_list',
        '#items' => $moves,
        '#weight' => -15,
      ];
    }
    $form = parent::buildForm($form, $form_state);
    // What sending causes reads before the email, not after it. On a send the
    // moves are listed first (-15) and this closing sentence follows them; on
    // an update the description introduces the list, so it leads.
    $form['description']['#weight'] = $this->updating ? -20 : -14;
    $form['actions']['submit']['#attributes']['class'] = ['btn', 'primary'];
    $form['actions']['cancel']['#attributes']['class'] = ['btn', 'ghost'];
    return $form;
  }

  /**
   * Where an update leaves the repo, each app and the review, from where they
   * are now (appverse-planning#56). The preview below shows the email.
   *
   * @return array<int, string>
   */
  protected function updateMoves(NodeInterface $review): array {
    $state = static fn (NodeInterface $n): string => str_replace('_', ' ', (string) ($n->get('moderation_state')->value ?? ''));
    $sent = ReviewDecisionApplier::sent($review);
    $apps = $this->applier->appNodes($review);
    $repo = $review->get('field_arv_repo')->entity;
    $targets = ReviewDecision::updateTargets(
      $this->applier->appDecisions($review),
      array_map(static fn (NodeInterface $app): bool => $app->isPublished(), $apps),
      $sent['was_live'] ?? ($repo instanceof NodeInterface && $repo->isPublished()),
    );
    $move = static fn (string $from, ?string $to): string => $to === NULL || str_replace('_', ' ', $to) === $from
      ? $from . ' (no change)' : $from . ' → ' . str_replace('_', ' ', $to);
    $rows = [];
    if ($repo instanceof NodeInterface) {
      $rows[] = ['Repo', $move($state($repo), $targets['repo'])];
    }
    $names = $this->applier->appNames($review);
    foreach ($targets['apps'] as $pid => $target) {
      if (isset($apps[$pid])) {
        $rows[] = [$names[$pid] ?? 'App', $move($state($apps[$pid]), $target)];
      }
    }
    $rows[] = ['Review', $targets['review'] === 'published' ? 'Public with its summary.' : 'Not public.'];
    return array_map(static fn (array $row): string => $row[0] . ': ' . $row[1], $rows);
  }

  public function getQuestion() {
    $repo = $this->review->get('field_arv_repo')->entity;
    if ($this->updating) {
      return $this->t('Update the decision on @repo', ['@repo' => $repo instanceof NodeInterface ? $repo->label() : $this->review->label()]);
    }
    return ReviewDecision::headline($this->appDecisions, (string) ($repo instanceof NodeInterface ? $repo->label() : $this->review->label()));
  }

  /**
   * What sending moves, one line each: the catalog, the review, and anything
   * a mixed decision leaves out. The sentence about what the contributor can
   * do is not a move, so it stays in the description under them.
   *
   * @return array<int, string>
   */
  protected function sendMoves(NodeInterface $review): array {
    $sentences = $this->consequences($review);
    array_pop($sentences);
    return array_map('strval', $sentences);
  }

  /**
   * @return array<int, \Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  protected function consequences(NodeInterface $review): array {
    $repo = $review->get('field_arv_repo')->entity;
    return ReviewDecision::consequences(
      $this->appDecisions,
      $repo instanceof NodeInterface && $repo->isPublished(),
      $this->preview['contributor'] ?? '',
    );
  }

  public function getDescription() {
    if ($this->updating) {
      return $this->t('This replaces the decision sent earlier, which stays on record, and moves the repo and the review to match.');
    }
    // The moves are listed above; this is the one sentence that is not one.
    $sentences = $this->consequences($this->review);
    return end($sentences) ?: '';
  }

  public function getConfirmText() {
    $name = $this->preview['contributor'] ?? '';
    if ($this->updating) {
      return $name !== '' ? $this->t('Send the update to @name', ['@name' => $name]) : $this->t('Send the update to the contributor');
    }
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
    // send() and update() check again under a lock: the repo can move, or a
    // second submit arrive, between building the form and submitting it.
    $error = $this->updating ? $this->applier->update($this->review, $response) : $this->applier->send($this->review, $response);
    if ($error !== NULL) {
      $this->messenger()->addError($error);
      return;
    }
    if ($this->updating) {
      $this->messenger()->addStatus($this->t('Decision updated: @d.', ['@d' => ReviewProgress::DECISION_LABELS[$this->decision]]));
      return;
    }
    $this->messenger()->addStatus($this->mixed
      ? $this->t('Decisions sent. Overall: @d.', ['@d' => ReviewProgress::decisionLabel($this->decision)])
      : $this->t('Decision sent: @d.', ['@d' => ReviewProgress::decisionLabel($this->decision)]));
  }

}
