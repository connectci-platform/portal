<?php

namespace Drupal\ood_software\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Service\RepoMemberApps;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin form for the request_adjustment moderation transition.
 *
 * Drupal's content_moderation lets admins move a node from
 * ready_for_review to needs_adjustment (workflow transition
 * `request_adjustment`). This form captures a required comment
 * that the contributor sees on their hub card.
 *
 * Comment is stored as the node's revision log message — the standard
 * Drupal place for moderation-state change rationale.
 */
final class AppverseHubRequestChangesForm extends FormBase {

  protected NodeInterface $node;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RepoMemberApps $repoMemberApps,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('ood_software.repo_member_apps'),
    );
  }

  public function getFormId(): string {
    return 'appverse_hub_request_changes_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if (!$node) {
      throw new \InvalidArgumentException('Expected a node argument.');
    }
    $this->node = $node;

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Request changes on <strong>@title</strong>. Your comment will be visible to the contributor on their maintenance hub.', ['@title' => $node->label()]) . '</p>',
    ];

    $form['comment'] = [
      '#type' => 'textarea',
      '#title' => $this->t('What needs to change?'),
      '#description' => $this->t('Be specific. The contributor sees this on their hub card.'),
      '#required' => TRUE,
      '#rows' => 5,
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Request changes'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromUri('internal:/appverse/manage-repos'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $comment = $form_state->getValue('comment');

    // A decision only affects what is under review (D8-2882). needs_adjustment
    // is not a default-revision state, so a live repo stays published on its
    // live revision and the request waits on a forward revision. It is built
    // on the newest revision so the contributor's pending edits go back to
    // them with the comment, which is the revision log the hub card shows.
    $storage = $this->entityTypeManager->getStorage('node');
    $latestId = $storage->getLatestRevisionId($this->node->id());
    $latest = $latestId ? $storage->loadRevision($latestId) : NULL;
    $repo = $latest instanceof NodeInterface ? $latest : $this->node;
    $repo->set('moderation_state', 'needs_adjustment');
    $repo->setRevisionLogMessage((string) $comment);
    $repo->setNewRevision(TRUE);
    // Stash the comment as a runtime property so ood_software_node_update
    // can forward it to RepoNotificationService as $extras['comment'].
    // Drupal's entity API ignores unknown properties on save, so nothing
    // is persisted; this only survives in memory long enough for
    // hook_node_update to read it.
    // @phpstan-ignore-next-line
    $repo->_ood_software_review_comment = $comment;
    $repo->save();

    // Member apps follow only if the repo actually left the catalog; a live
    // repo no longer does.
    $default = $storage->loadUnchanged($this->node->id());
    if ($default instanceof NodeInterface && !$default->isPublished() && $this->node->isPublished()) {
      $this->cascadeUnpublishMemberApps($this->node);
    }

    $this->messenger()->addStatus($this->t('Requested changes on @title. The contributor will see your comment.', ['@title' => $this->node->label()]));
    $form_state->setRedirectUrl(Url::fromUri('internal:/appverse/manage-repos'));
  }

  /**
   * Drop all currently-published member apps of $repo to draft.
   */
  protected function cascadeUnpublishMemberApps(NodeInterface $repo): void {
    $count = $this->repoMemberApps->cascadeModeration(
      $repo, 'draft', [], 'Auto-unpublished via parent Repo request-changes.', TRUE
    );
    if ($count > 0) {
      $this->messenger()->addStatus($this->t(
        'Also unpublished @count member apps under @title.',
        ['@count' => $count, '@title' => $repo->label()]
      ));
    }
  }

}
