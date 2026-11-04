<?php

namespace Drupal\ood_software\Form;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Asks to rerun the AI report, confirmed before the run starts (D8-2931).
 *
 * The page posts straight to the hub's run-review route, which starts the run
 * and sends the reviewer back here to the review.
 */
final class ReviewRerunConfirmForm extends ConfirmFormBase {

  /**
   * The review the reviewer is on.
   */
  protected NodeInterface $review;

  /**
   * The review's repo.
   */
  protected NodeInterface $repo;

  /**
   * Constructs the form.
   *
   * @param \Drupal\Core\Access\CsrfTokenGenerator $csrfToken
   *   The CSRF token generator.
   */
  public function __construct(protected CsrfTokenGenerator $csrfToken) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('csrf_token'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ood_software_review_rerun_confirm';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\node\NodeInterface|null $node
   *   The review.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if ($node === NULL || $node->bundle() !== 'appverse_review') {
      throw new NotFoundHttpException();
    }
    $repo = $node->get('field_arv_repo')->entity;
    if (!$repo instanceof NodeInterface) {
      throw new NotFoundHttpException();
    }
    $this->review = $node;
    $this->repo = $repo;
    $form = parent::buildForm($form, $form_state);
    // The form posts to the run-review controller, not to submitForm(), which
    // is never reached. That route needs its CSRF token in the URL, minted
    // here for the viewer, so the page must never be cached across users.
    $path = 'appverse/repo/' . $repo->id() . '/run-review';
    $form['#action'] = '/' . $path . '?token=' . $this->csrfToken->get($path)
      . '&destination=' . urlencode('/appverse/review/' . $node->id());
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Rerun the AI report for @repo? The new report becomes the newest review of this repo. This review, including your notes and edits, stays as it is.', ['@repo' => $this->repo->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    // @phpstan-ignore-next-line
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Rerun report');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('ood_software.review_page', ['node' => $this->review->id()]);
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Not reached: #action posts to ood_software.repo_run_review.
  }

}
