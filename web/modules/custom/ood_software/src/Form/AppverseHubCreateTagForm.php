<?php

declare(strict_types=1);

namespace Drupal\ood_software\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Plugin\GitHubService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Confirmation form: create an implementation-tag term verbatim, then resync.
 *
 * Reviewers land here from the hub when an app declares a tag that does not
 * yet exist in the appverse_implementation_tags vocabulary.  The form shows
 * the exact string (quoted, so odd casing or trailing spaces are visible) and
 * asks the reviewer to confirm creation.  On submit:
 *   1. The term is created VERBATIM (trimmed only) in
 *      appverse_implementation_tags — NO normalisation.
 *   2. The app's parent repo is resynced so the now-matching tag resolves and
 *      the field_appverse_unresolved_tags flag clears.
 *
 * Single-stage FormBase — no __sleep/__wakeup DI-serialisation workaround
 * needed (only multi-stage forms in this module require that).
 */
final class AppverseHubCreateTagForm extends FormBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected GitHubService $gitHub,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('ood_software.gh'),
    );
  }

  public function getFormId(): string {
    return 'appverse_hub_create_tag_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $declared = trim((string) $this->getRequest()->query->get('tag', ''));
    $form_state->set('node', $node);
    $form_state->set('declared', $declared);
    // Show the EXACT string (quoted so trailing spaces/odd casing are visible).
    $form['confirm'] = [
      '#markup' => $this->t(
        'Create the implementation tag <strong>"@t"</strong> exactly as written? It will be created verbatim and applied to apps that declare it. If this looks like a typo, do not create it — request changes from the contributor instead.',
        ['@t' => $declared]
      ),
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create "@t"', ['@t' => $declared]),
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $declared = trim((string) $form_state->get('declared'));
    $node = $form_state->get('node');
    if ($declared === '' || !$node instanceof NodeInterface) {
      $this->messenger()->addError($this->t('No tag to create.'));
      return;
    }

    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    // Idempotent: use existing term if one already exists with that exact name.
    $existing = $termStorage->loadByProperties([
      'vid' => 'appverse_implementation_tags',
      'name' => $declared,
    ]);
    if (!$existing) {
      $termStorage->create([
        'vid' => 'appverse_implementation_tags',
        'name' => $declared,
      ])->save();
    }

    // Resync the app's repo so the now-matching tag resolves and the flag
    // clears.  Reuses the shared batch helper from Task C.
    $repo = $node->get('field_appverse_repo')->entity;
    if ($repo instanceof NodeInterface) {
      $repoUrl = $repo->get('field_repo_url')->first()?->getValue()['uri'] ?? NULL;
      if ($repoUrl) {
        try {
          if ($this->gitHub->parseUrl($repoUrl)) {
            $repoMetadata = [
              'stars' => $this->gitHub->getStars(),
              'lastCommittedDate' => $this->gitHub->getLastComittedDate(),
              'organization' => $this->gitHub->getOrganization(),
              'description' => $this->gitHub->getDescription(),
              'readme' => $this->gitHub->getReadme(),
              'license' => $this->gitHub->getLicense(),
              'licenseLink' => $this->gitHub->getLicenseLink(),
              'isArchived' => $this->gitHub->getIsArchived(),
            ];
            $appverseYml = $this->gitHub->getAppverseYmlText();
            $parsed = $appverseYml !== NULL ? (Yaml::parse($appverseYml) ?: []) : [];
            _ood_software_resync_repo_batch(
              (int) $repo->id(),
              $repoUrl,
              $appverseYml,
              $repoMetadata,
              is_array($parsed) ? $parsed : [],
            );
            $this->messenger()->addStatus($this->t(
              'Created tag "@t" and re-synced the repo.',
              ['@t' => $declared]
            ));
            $this->redirectBack($form_state);
            return;
          }
        }
        catch (\Throwable) {
          // GitHub call failed — fall through to warning below.
        }
      }
      $this->messenger()->addWarning($this->t(
        'Created tag "@t". Could not reach the repo to re-sync now; it will apply on the next sync.',
        ['@t' => $declared]
      ));
    }
    else {
      $this->messenger()->addStatus($this->t('Created tag "@t".', ['@t' => $declared]));
    }
    $this->redirectBack($form_state);
  }

  /**
   * Send the reviewer back to the hub they came from.
   *
   * The create link carries a ?destination= param (set when the hub builds the
   * link), which Drupal's form submitter honors automatically, returning the
   * reviewer to whichever hub display they were on (manage-repos or
   * my-appverse). We set a real fallback route for the rare case the param is
   * absent — the user's own my-appverse page (display page_user).
   */
  private function redirectBack(FormStateInterface $form_state): void {
    $form_state->setRedirect('view.my_appverse.page_user', [
      'user' => (int) $this->currentUser()->id(),
    ]);
  }

}
