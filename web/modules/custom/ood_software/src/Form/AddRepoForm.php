<?php

declare(strict_types=1);

namespace Drupal\ood_software\Form;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Plugin\GitHubService;
use Drupal\ood_software\Service\RepoSyncService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Dedicated form for submitting an Appverse repo.
 *
 * Three stages:
 *   1. URL paste → fetch + detect shape (AJAX rebuild)
 *   2. Preview (via _ood_software_build_declared_preview /
 *      _ood_software_build_single_app_preview)
 *   3. Confirm → sync (declared: Batch; inferred: synchronous)
 */
final class AddRepoForm extends FormBase {

  use MessengerTrait;

  public function __construct(
    private GitHubService $github,
    private RepoSyncService $repoSync,
    private EntityTypeManagerInterface $entityTypeManager,
    private AccountInterface $currentUser,
    private ModuleHandlerInterface $moduleHandler,
    MessengerInterface $messenger,
  ) {
    $this->setMessenger($messenger);
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      // The GitHubService service ID is ood_software.gh, NOT ood_software.github.
      $container->get('ood_software.gh'),
      $container->get('ood_software.repo_sync'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('module_handler'),
      $container->get('messenger'),
    );
  }

  /**
   * Don't serialize service dependencies — they come from the container.
   *
   * Drupal caches FormBase instances across the AJAX → non-AJAX hop (Fetch
   * → preview → Confirm). PHP's unserialize() skips the constructor, so
   * typed properties stay uninitialized after wakeup. We override __sleep
   * to exclude services and __wakeup to re-resolve them from the container.
   */
  public function __sleep(): array {
    return [];
  }

  public function __wakeup(): void {
    // __wakeup must re-resolve services via the container: PHP's unserialize
    // skips the constructor, so DI-promoted properties stay uninitialized.
    // @phpstan-ignore-next-line
    $container = \Drupal::getContainer();
    $this->github = $container->get('ood_software.gh');
    $this->repoSync = $container->get('ood_software.repo_sync');
    $this->entityTypeManager = $container->get('entity_type.manager');
    $this->currentUser = $container->get('current_user');
    $this->moduleHandler = $container->get('module_handler');
    $this->setMessenger($container->get('messenger'));
  }

  public function getFormId(): string {
    return 'ood_software_add_repo';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $stage = $form_state->get('stage') ?? 'url';

    $form['#prefix'] = '<div id="add-repo-form-wrapper">';
    $form['#suffix'] = '</div>';
    // Reuse the existing library; do not invent a new one.
    $form['#attached']['library'][] = 'ood_software/appverse_app_form';

    $form['stage'] = ['#type' => 'hidden', '#value' => $stage];

    return match ($stage) {
      'url' => $this->buildUrlStage($form, $form_state),
      'preview' => $this->buildPreviewStage($form, $form_state),
      default => $this->buildUrlStage($form, $form_state),
    };
  }

  private function buildUrlStage(array $form, FormStateInterface $form_state): array {
    $form['intro'] = [
      '#type' => 'item',
      '#markup' => $this->t('Paste the GitHub URL for your Appverse repo. New here? See the <a href=":url">contributor documentation</a> for how to structure your repo before submitting.', [
        ':url' => '/appverse-contributor-documentation',
      ]),
    ];

    $form['repo_url'] = [
      '#type' => 'url',
      '#title' => $this->t('GitHub repo URL'),
      '#required' => TRUE,
      '#placeholder' => 'https://github.com/owner/repo',
      '#default_value' => $form_state->getValue('repo_url') ?? '',
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['fetch'] = [
      '#type' => 'submit',
      '#value' => $this->t('Fetch repo'),
      '#submit' => ['::submitFetch'],
      '#ajax' => [
        'callback' => '::ajaxReplace',
        'wrapper' => 'add-repo-form-wrapper',
      ],
    ];
    return $form;
  }

  public function submitFetch(array &$form, FormStateInterface $form_state): void {
    $url = trim((string) $form_state->getValue('repo_url'));

    // submitFetch runs in the submit phase (it advances stage via setRebuild
    // rather than firing the final submit), so use messenger()->addError
    // instead of $form_state->setErrorByName — the latter throws
    // LogicException because form validation has already finished.
    if (!$this->github->parseUrl($url)) {
      $this->messenger()->addError($this->t('We could not parse @url as a GitHub repository.', ['@url' => $url]));
      $form_state->setRebuild();
      return;
    }
    if ($this->github->getIsArchived()) {
      $this->messenger()->addError($this->t('This repo is archived on GitHub. Archived repos cannot be submitted.'));
      $form_state->setRebuild();
      return;
    }

    // Dup-URL gate. Match all stored variants (case, .git suffix, trailing
    // slash) so a contributor can't paste a non-canonical URL, miss this
    // check, and silently take over an existing repo via resolveRepo()'s
    // downstream canonicalization. MySQL's default collation is
    // case-insensitive on '=' / 'IN', covering casing variants.
    $canonicalUrl = $this->github->getRepoUrl();
    $existingIds = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appverse_repo')
      ->condition('field_repo_url.uri', [
        $canonicalUrl,
        $canonicalUrl . '/',
        $canonicalUrl . '.git',
      ], 'IN')
      ->range(0, 1)
      ->execute();
    if (!empty($existingIds)) {
      /** @var \Drupal\node\NodeInterface $existing */
      $existing = $this->entityTypeManager->getStorage('node')->load(reset($existingIds));
      $existingOwnerId = (int) $existing->getOwnerId();
      $currentUserId = (int) $this->currentUser->id();

      // Orphan record (anonymous/uid-0 owner) — no real maintainer to displace;
      // treat as a stale leftover and fall through to overwrite on submit.
      if ($existingOwnerId === 0) {
        // No warning needed.
      }
      elseif ($existingOwnerId === $currentUserId) {
        // Self re-submit — always block, regardless of role.
        $hubUrl = Url::fromUri('internal:/user/' . $currentUserId . '/my-appverse')->toString();
        $this->messenger()->addError(
          $this->t('You\'ve already registered this repository. <a href=":hub">Manage it on your hub</a> — use Re-sync to refresh from GitHub instead of re-submitting.', [':hub' => $hubUrl]),
        );
        $form_state->setRebuild();
        return;
      }
      elseif (!$this->currentUser->hasPermission('administer appverse content')) {
        // Cross-owner non-admin — block.
        $ownerName = $existing->getOwner()?->getDisplayName() ?? '(unknown)';
        $this->messenger()->addError(
          $this->t('This repo was already submitted by @owner. Ask @owner to add you as a contributor, or contact an Appverse admin if you need to take over maintenance.', ['@owner' => $ownerName]),
        );
        $form_state->setRebuild();
        return;
      }
      else {
        // Cross-owner admin — fall through (admin takeover path, matches legacy).
        // Catalog has no co-maintainer concept yet, so admin override is the only
        // way to reassign a repo (e.g., when a maintainer leaves a project).
        $ownerName = $existing->getOwner()?->getDisplayName() ?? '(unknown)';
        $this->messenger()->addWarning($this->t(
          'This repo is currently maintained by @owner. As an admin, submitting will reassign maintenance to you. Cancel here if that is not your intent.',
          ['@owner' => $ownerName],
        ));
      }
    }

    // Shape detection.
    if ($this->github->isEmptyRepo()) {
      $this->messenger()->addError($this->t('This repo has neither a root <code>appverse.yml</code> nor a root <code>manifest.yml</code>. Nothing to register.'));
      $form_state->setRebuild();
      return;
    }
    $shape = $this->github->isDeclaredRepo() ? 'declared' : 'inferred';

    $form_state->set('stage', 'preview');
    $form_state->set('repo_url', $url);
    $form_state->set('shape', $shape);
    $form_state->setRebuild();
  }

  private function buildPreviewStage(array $form, FormStateInterface $form_state): array {
    $url = (string) $form_state->get('repo_url');
    $shape = (string) $form_state->get('shape');

    // GitHubService is per-request stateful — reposition on this URL.
    $this->github->parseUrl($url);

    $form['summary'] = [
      '#type' => 'item',
      '#markup' => $this->t('<strong>Repo:</strong> <code>@url</code><br><strong>Shape:</strong> @shape', [
        '@url' => $url,
        '@shape' => $shape === 'declared'
          ? $this->t('declared (root appverse.yml)')
          : $this->t('inferred single-app (root manifest.yml only)'),
      ]),
    ];

    $this->moduleHandler->loadInclude('ood_software', 'module');
    $form['preview'] = $shape === 'declared'
      ? _ood_software_build_declared_preview($this->github, $form)
      : _ood_software_build_single_app_preview($this->github, $form);

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#submit' => ['::submitBack'],
      '#ajax' => [
        'callback' => '::ajaxReplace',
        'wrapper' => 'add-repo-form-wrapper',
      ],
      '#limit_validation_errors' => [],
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $shape === 'declared'
        ? $this->t('Confirm and sync apps')
        : $this->t('Confirm and register'),
      '#submit' => ['::submitForm'],
    ];
    return $form;
  }

  public function submitBack(array &$form, FormStateInterface $form_state): void {
    $form_state->set('stage', 'url');
    $form_state->setRebuild();
  }

  public function ajaxReplace(array &$form, FormStateInterface $form_state): array {
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $url = (string) $form_state->get('repo_url');
    $shape = (string) $form_state->get('shape');

    if (!$this->github->parseUrl($url)) {
      $this->messenger()->addError($this->t('Lost connection to GitHub between preview and submit. Paste the URL again.'));
      $form_state->set('stage', 'url');
      $form_state->setRebuild();
      return;
    }
    $repoMetadata = $this->buildRepoMetadata();

    if ($shape === 'declared') {
      $this->submitDeclared($url, $repoMetadata, $form_state);
      return;
    }
    $this->submitInferred($url, $repoMetadata, $form_state);
  }

  /**
   * Build the metadata array passed into RepoSyncService::resolveRepo().
   */
  private function buildRepoMetadata(): array {
    return [
      // Title fallback: the GitHub repo name, else the URL's repo segment
      // (e.g. "bc_jupyter"). Never the repo *description* — that's often a
      // full sentence and makes a poor node title.
      'name' => $this->github->getRepoName()
        ?: $this->urlSegment($this->github->getRepoUrl()),
      'description' => $this->github->getRepoDescription(),
      'organization' => $this->github->getOrganization(),
      'stars' => $this->github->getStars(),
      'lastCommittedDate' => $this->github->getLastComittedDate(),
      'readme' => $this->github->getReadme() ?? '',
      'licenseLink' => $this->github->getLicenseLink(),
      // field_license is an entity reference to a taxonomy term; getLicense()
      // returns the SPDX string (e.g., "MIT"), not a term ID. Until a
      // resolveLicenseTerm() helper lands, inferred member apps land without
      // a license term. See Out of Scope in the form plan.
      'repoUrl' => $this->github->getRepoUrl(),
    ];
  }

  private function urlSegment(string $repoUrl): string {
    $parts = array_filter(explode('/', parse_url($repoUrl, PHP_URL_PATH) ?? ''));
    return (string) (end($parts) ?: 'untitled-repo');
  }

  /**
   * Whether a parsed root appverse.yml (with no apps[]) describes one app.
   *
   * SHAPE 1 single-app repos declare top-level app metadata instead of an
   * apps[] list. We treat any of software / app_type / title / description
   * at the root as the signal that the contributor meant to register one app
   * here, rather than an empty repo with nothing to sync.
   */
  private function rootDeclaresSingleApp(array $parsedRootYml): bool {
    foreach (['software', 'app_type', 'title', 'description'] as $key) {
      if (isset($parsedRootYml[$key]) && $parsedRootYml[$key] !== '' && $parsedRootYml[$key] !== []) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Declared shape: resolveRepo + per-subpath Batch + reconcile + redirect.
   *
   * Replaced the legacy procedural declared-submit handler that lived in
   * ood_software.module (removed during the dedicated-repo-submit-form work).
   */
  private function submitDeclared(string $url, array $repoMetadata, FormStateInterface $form_state): void {
    // Prefer the canonical URL that GitHubService normalized; same rationale
    // as the legacy declared handler.
    $url = $repoMetadata['repoUrl'] ?? $url;

    $appverseYmlText = $this->github->getAppverseYmlText();
    if ($appverseYmlText === NULL) {
      $this->messenger()->addError($this->t('Declared shape but no appverse.yml? Something raced between fetch and submit. Try again.'));
      $form_state->set('stage', 'url');
      $form_state->setRebuild();
      return;
    }
    try {
      $parsedRootYml = Yaml::decode($appverseYmlText) ?? [];
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Could not parse appverse.yml: @msg', ['@msg' => $e->getMessage()]));
      $form_state->set('stage', 'url');
      $form_state->setRebuild();
      return;
    }

    // Defense in depth: check ownership BEFORE resolveRepo() mutates an
    // existing record. The dup-URL gate in submitFetch already catches
    // cross-owner takeover, but a URL-variant slip would otherwise let the
    // sync overwrite the existing repo's metadata before the form-layer
    // check could abort.
    $existing = $this->repoSync->findRepoByUrl($url);
    if ($existing && !$this->assertOwnershipOrAbort($existing, $form_state)) {
      return;
    }

    $repo = $this->repoSync->resolveRepo($url, $appverseYmlText, $repoMetadata);

    // Stamp the contributor as owner if it's a brand-new node.
    if ((int) $repo->getOwnerId() === 0) {
      $repo->setOwnerId((int) $this->currentUser->id());
      $repo->save();
    }

    // Walk apps[] as a LIST of objects with 'path' keys.
    // Mirrors ood_software.module:1224-1228.
    $apps = is_array($parsedRootYml) ? ($parsedRootYml['apps'] ?? []) : [];
    $subpaths = [];
    foreach ($apps as $app) {
      if (isset($app['path']) && is_string($app['path']) && $app['path'] !== '') {
        $subpaths[] = $app['path'];
      }
    }

    if (!$subpaths) {
      // SHAPE 1 (docs/appverse.yml): a root appverse.yml with top-level app
      // metadata but no apps[] is the documented single-app repo. The repo
      // IS the one app, at the repo root (subpath ''). Create it
      // synchronously via RepoSyncService::applyDeclaredSingleApp(), the
      // declared-shape analogue of the inferred path's syncInferredMemberApp.
      // Only do this when top-level app metadata is actually present;
      // otherwise the root yaml carries no app and there's nothing to sync.
      if ($this->rootDeclaresSingleApp($parsedRootYml)) {
        $rootFiles = [
          // The root appverse.yml is already parsed and passed as $entry to
          // applyDeclaredApp, so don't re-feed it as the per-subpath
          // appverseYml (that layer is merged under $entry anyway).
          'manifestYml' => NULL,
          'appverseYml' => NULL,
          'readme' => $this->github->getReadme() ?? '',
        ];
        $app = $this->repoSync->applyDeclaredSingleApp($repo, $parsedRootYml, $rootFiles, $url, $repoMetadata);

        if ($app->get('field_appverse_app_validation_st')->value === 'rejected') {
          $this->messenger()->addWarning($this->t('Repo registered and one app was created from the root appverse.yml, but it is missing required fields and was flagged for fixes. Edit appverse.yml and re-sync.'));
        }
        else {
          $this->messenger()->addStatus($this->t('Registered <em>@title</em> as a single-app repo. It is now in your hub as a draft — send it for review when you are ready.', [
            '@title' => $app->label(),
          ]));
        }
        $form_state->setRedirectUrl($repo->toUrl());
        return;
      }

      $this->messenger()->addWarning($this->t('Repo registered, but appverse.yml declared no apps[] entries with a path. Nothing to sync.'));
      $form_state->setRedirectUrl($repo->toUrl());
      return;
    }

    // resolveRepo has already run above; $repo is resolved and $apps[] is
    // non-empty. Call the helper to batch the per-subpath sync ops. The
    // helper's no-apps fallback won't trigger because $subpaths is non-empty.
    _ood_software_resync_repo_batch((int) $repo->id(), $url, $appverseYmlText, $repoMetadata, $parsedRootYml);
    $form_state->setRedirectUrl($repo->toUrl());
  }

  /**
   * Inferred shape: synchronous Repo + member app creation, redirect to hub.
   *
   * Differs from declared: no Batch, no Reconcile op, redirect to the
   * contributor's hub (not the Repo node) since the member app is the
   * thing they just created.
   */
  private function submitInferred(string $url, array $repoMetadata, FormStateInterface $form_state): void {
    // Prefer canonical URL (same rationale as submitDeclared).
    $url = $repoMetadata['repoUrl'] ?? $url;

    // Defense in depth: see comment in submitDeclared.
    $existing = $this->repoSync->findRepoByUrl($url);
    if ($existing && !$this->assertOwnershipOrAbort($existing, $form_state)) {
      return;
    }

    // resolveRepo routes through applyInferred() when appverseYmlText is NULL.
    $repo = $this->repoSync->resolveRepo($url, NULL, $repoMetadata);

    if ((int) $repo->getOwnerId() === 0) {
      $repo->setOwnerId((int) $this->currentUser->id());
      $repo->save();
    }

    // Root manifest is the inferred member app's source of truth.
    // getManifestData() returns array|false.
    $manifest = $this->github->getManifestData() ?: [];
    // Convenience: inject the resolved app-type term IDs so the service
    // doesn't need to reach the GitHubService itself.
    $manifest['_app_type_ids'] = $this->github->getAppTypeIds();

    $this->repoSync->syncInferredMemberApp($repo, $url, $manifest, $repoMetadata);

    $this->messenger()->addStatus($this->t('Registered <em>@title</em>. Your repo is now in your hub as a draft — send it for review when you are ready.', [
      '@title' => $repo->label(),
    ]));

    // Hub redirect — same destination as legacy ood_software__redirect_submit.
    $form_state->setRedirectUrl(
      Url::fromUri('internal:/user/' . (int) $this->currentUser->id() . '/my-appverse')
    );
  }

  /**
   * Check that the current user can claim or update $repo.
   *
   * Three legitimate cases:
   *   - brand-new node (uid 0): caller will stamp ownership next
   *   - current user already owns it: re-submit (the dup-URL gate normally
   *     catches this earlier, but a URL-shape mismatch could let it through)
   *   - admin: cross-owner takeover is allowed for the same reason it is in
   *     submitFetch — admins are the only way to reassign maintenance
   *
   * Any other case is a silent takeover attempt and we abort with the same
   * error copy the dup-URL gate uses.
   */
  private function assertOwnershipOrAbort(NodeInterface $repo, FormStateInterface $form_state): bool {
    $existingOwnerId = (int) $repo->getOwnerId();
    $currentUserId = (int) $this->currentUser->id();
    if ($existingOwnerId === 0 || $existingOwnerId === $currentUserId) {
      return TRUE;
    }
    if ($this->currentUser->hasPermission('administer appverse content')) {
      return TRUE;
    }
    $ownerName = $repo->getOwner()?->getDisplayName() ?? '(unknown)';
    $this->messenger()->addError($this->t(
      'This repo was already submitted by @owner. Ask @owner to add you as a contributor, or contact an Appverse admin if you need to take over maintenance.',
      ['@owner' => $ownerName],
    ));
    $form_state->set('stage', 'url');
    $form_state->setRebuild();
    return FALSE;
  }

}
