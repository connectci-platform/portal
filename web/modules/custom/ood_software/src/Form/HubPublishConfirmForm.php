<?php

namespace Drupal\ood_software\Form;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Controller\AppverseHubController;

/**
 * Confirms a hub Publish / Unpublish before it happens.
 *
 * The hub's icons used to POST straight to the action, one unlabelled click
 * from the next icon. They now lead here; confirming runs the same
 * controller method the icon used to, so publishing behaviour (member-app
 * cascade, moderation transitions, messages, the redirect back to the list)
 * stays in one place. The routes carry the same access checks as the
 * actions they confirm.
 */
final class HubPublishConfirmForm extends ConfirmFormBase {

  /**
   * The route's operation: 'publish' (repo), 'unpublish' (repo), or
   * 'toggle_app' (an app, either direction).
   */
  protected string $op;

  protected NodeInterface $node;

  public function getFormId(): string {
    return 'ood_software_hub_publish_confirm';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL, string $op = 'publish'): array {
    $this->node = $node;
    $this->op = $op;
    return parent::buildForm($form, $form_state);
  }

  /**
   * Whether confirming will publish (rather than unpublish) the node.
   */
  protected function publishes(): bool {
    return $this->op === 'publish' || ($this->op === 'toggle_app' && !$this->node->isPublished());
  }

  public function getQuestion() {
    return $this->publishes()
      ? $this->t('Publish %title?', ['%title' => $this->node->label()])
      : $this->t('Unpublish %title?', ['%title' => $this->node->label()]);
  }

  public function getDescription() {
    if ($this->node->bundle() === 'appverse_repo') {
      return $this->publishes()
        ? $this->t('The repo and its apps become visible in the public AppVerse catalog.')
        : $this->t('The repo and its apps are removed from the public AppVerse catalog.');
    }
    return $this->publishes()
      ? $this->t('The app becomes visible in the public AppVerse catalog.')
      : $this->t('The app is removed from the public AppVerse catalog.');
  }

  public function getConfirmText() {
    return $this->publishes() ? $this->t('Publish') : $this->t('Unpublish');
  }

  /**
   * Back to the list the icon was clicked on (its ?destination), if safe.
   */
  public function getCancelUrl() {
    $destination = (string) $this->getRequest()->query->get('destination', '');
    if ($destination !== '' && !UrlHelper::isExternal($destination) && !str_contains($destination, '..') && str_starts_with($destination, '/')) {
      return Url::fromUserInput($destination);
    }
    return Url::fromUserInput('/appverse/manage-repos');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // The controller redirects to ?destination, which this form's URL still
    // carries, so the reviewer lands back on the same filtered list.
    $controller = \Drupal::classResolver(AppverseHubController::class);
    $response = match ($this->op) {
      'publish' => $controller->adminPublish($this->node),
      'unpublish' => $controller->toggleRepoPublish($this->node),
      default => $controller->toggleAppPublish($this->node),
    };
    $form_state->setResponse($response);
  }

}
