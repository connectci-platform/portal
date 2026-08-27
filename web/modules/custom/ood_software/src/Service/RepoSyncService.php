<?php

namespace Drupal\ood_software\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Plugin\GitHubService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * Resolves Repos from GitHub repos.
 *
 * Each source repo is a Repo. appverse.yml at the repo root enriches
 * the Repo's metadata (title, description, maintainer, multi-app list);
 * when absent, the sync derives minimal metadata from the repo itself.
 */
class RepoSyncService {

  protected LoggerInterface $logger;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    protected TimeInterface $time,
    protected GitHubService $githubService,
  ) {
    $this->logger = $loggerFactory->get('ood_software');
  }

  /**
   * Resolve or create the Repo for a repo.
   *
   * @param string $repoUrl
   *   GitHub repo URL (e.g., https://github.com/owner/name).
   * @param string|null $appverseYmlText
   *   The raw text of appverse.yml at repo root, or NULL if absent.
   * @param array $repoMetadata
   *   Metadata from GitHubService (organization name, description, default branch, etc.).
   *
   * @return \Drupal\node\NodeInterface
   *   The Repo node (newly created or updated).
   */
  public function resolveRepo(string $repoUrl, ?string $appverseYmlText, array $repoMetadata): NodeInterface {
    $repo = $this->loadRepoByUrl($repoUrl);

    if ($appverseYmlText !== NULL) {
      return $this->applyDeclared($repo, $repoUrl, $appverseYmlText, $repoMetadata);
    }
    return $this->applyInferred($repo, $repoUrl, $repoMetadata);
  }

  /**
   * Public read-only lookup of an existing Repo by URL.
   *
   * Use this from callers that need to inspect an existing Repo (e.g.,
   * for ownership checks) before invoking resolveRepo(), which mutates.
   */
  public function findRepoByUrl(string $repoUrl): ?NodeInterface {
    return $this->loadRepoByUrl($repoUrl);
  }

  /**
   * Load a Repo by its repo URL, or NULL if not yet created.
   */
  protected function loadRepoByUrl(string $repoUrl): ?NodeInterface {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $nids = $nodeStorage->getQuery()
      ->condition('type', 'appverse_repo')
      ->condition('field_repo_url.uri', $repoUrl)
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();
    if (!$nids) {
      return NULL;
    }
    return $nodeStorage->load(reset($nids));
  }

  /**
   * Apply appverse.yml-derived metadata to a Repo node.
   */
  protected function applyDeclared(?NodeInterface $existing, string $repoUrl, string $appverseYmlText, array $repoMetadata): NodeInterface {
    try {
      $parsed = Yaml::parse($appverseYmlText);
    }
    catch (ParseException $e) {
      // Fatal: malformed appverse.yml. Mark stale_invalid and preserve
      // existing data if a Repo already exists.
      $node = $existing ?? $this->createBlankRepo($repoUrl);
      $node->set('field_repo_shape', 'declared');
      $node->set('field_repo_validation_st', 'stale_invalid');
      $node->set('field_repo_validation_er', [
        sprintf('appverse.yml: parse error — %s. Fix: validate YAML syntax at https://www.yamllint.com/', $this->sanitizeError($e->getMessage())),
      ]);
      $node->set('field_repo_last_synced', $this->time->getCurrentTime());
      $node->save();
      $this->logger->warning('Repo sync failed for @repo: malformed appverse.yml.', ['@repo' => $repoUrl]);
      return $node;
    }

    if (!is_array($parsed) || (count($parsed) > 0 && array_is_list($parsed))) {
      $node = $existing ?? $this->createBlankRepo($repoUrl);
      $node->set('field_repo_shape', 'declared');
      $node->set('field_repo_validation_st', 'stale_invalid');
      $node->set('field_repo_validation_er', [
        'appverse.yml: top-level must be a YAML mapping. Fix: ensure the file starts with `key: value` pairs, not a list or scalar.',
      ]);
      $node->set('field_repo_last_synced', $this->time->getCurrentTime());
      $node->save();
      return $node;
    }

    // Check schema version if declared.
    $minVersion = $parsed['appverse']['min_version'] ?? NULL;
    if ($minVersion !== NULL && version_compare($minVersion, '1.0', '>')) {
      $node = $existing ?? $this->createBlankRepo($repoUrl);
      $node->set('field_repo_shape', 'declared');
      $node->set('field_repo_validation_st', 'stale_invalid');
      $node->set('field_repo_validation_er', [
        sprintf('appverse.yml: appverse.min_version = "%s" but this catalog supports up to "1.0". Fix: upgrade the catalog or lower min_version.', $minVersion),
      ]);
      $node->set('field_repo_last_synced', $this->time->getCurrentTime());
      $node->save();
      return $node;
    }

    // Resolve title (declared, falling back to repo name).
    $title = $parsed['title'] ?? $this->deriveTitleFromRepoUrl($repoUrl);
    $description = $parsed['description'] ?? '';
    $maintainerName = $parsed['maintainer']['name'] ?? ($repoMetadata['organization'] ?? '');
    $maintainerSupport = $parsed['maintainer']['support_url'] ?? '';

    // Create or update the node.
    $node = $existing ?? $this->createBlankRepo($repoUrl);
    $node->setTitle($title);
    $node->set('field_repo_description', $description);
    $node->set('field_repo_url', ['uri' => $repoUrl]);
    $node->set('field_repo_maintainer_name', $maintainerName);
    if ($maintainerSupport) {
      $node->set('field_repo_maintainer_url', ['uri' => $maintainerSupport]);
    }
    $node->set('field_repo_shared_paths', $parsed['shared_paths'] ?? []);
    $node->set('field_repo_shape', 'declared');
    $node->set('field_repo_validation_st', 'valid');
    $node->set('field_repo_validation_er', []);
    $node->set('field_repo_last_synced', $this->time->getCurrentTime());
    if (isset($repoMetadata['stars'])) {
      $node->set('field_repo_stars', (int) $repoMetadata['stars']);
    }
    if (isset($repoMetadata['lastCommittedDate'])) {
      $node->set('field_repo_last_commit', (int) $repoMetadata['lastCommittedDate']);
    }

    // Resolve organization taxonomy term from the GitHub repo owner
    // (match-only; no auto-create). The repo owner — not maintainer.name —
    // is the team/lab/center that owns the repo. maintainer.name is
    // typically a person (e.g. "Sean Anderson") and lives in
    // field_repo_maintainer_name above.
    // Always set — null'd when the repo owner isn't in the org vocab so
    // stale assignments clear.
    $repoOwner = $repoMetadata['organization'] ?? '';
    $org = $repoOwner ? $this->resolveOrganizationTerm($repoOwner) : NULL;
    $node->set('field_repo_organization', $org);

    // appverse.yml-derived repo-level fields.
    $websiteUrl = $parsed['website'] ?? '';
    if ($websiteUrl) {
      $node->set('field_repo_www_url', ['uri' => $websiteUrl]);
    }
    else {
      $node->set('field_repo_www_url', NULL);
    }

    $docsUrl = $parsed['docs'] ?? '';
    if ($docsUrl) {
      $node->set('field_repo_docs_url', ['uri' => $docsUrl]);
    }
    else {
      $node->set('field_repo_docs_url', NULL);
    }

    // Repo-level discovery tags resolve against the shared `tags` vocabulary
    // via the same exact-match + suggestion resolver used for implementation
    // tags (retires the old LIKE matcher, which treated %/_ in a declared tag
    // as SQL wildcards and could match the wrong term). No auto-create — the
    // `tags` vocab is portal-wide and not AppVerse's to grow.
    $declaredDiscoveryTags = $parsed['tags'] ?? NULL;
    $discoveryInfo = $this->githubService->resolveTaxonomyTermsFromAppverseYml(
      'tags',
      is_array($declaredDiscoveryTags) ? $declaredDiscoveryTags : NULL
    );
    $repoTagIds = [];
    foreach (($discoveryInfo['resolved'] ?? []) as $resolved) {
      $repoTagIds[] = $resolved['tid'];
    }
    $node->set('field_repo_tags', $repoTagIds);
    // Read-only flag for reviewers — discovery-tag misses are NEVER reviewer-
    // created (the `tags` vocab is portal-wide). Always set (cleared when none).
    $repoUnresolved = [];
    foreach (($discoveryInfo['unresolved'] ?? []) as $unresolved) {
      $repoUnresolved[] = $unresolved['declared'];
    }
    $node->set('field_repo_unresolved_tags', $repoUnresolved);

    if (isset($repoMetadata['readme'])) {
      $node->set('field_repo_readme', [
        'value' => $repoMetadata['readme'],
        'format' => 'markdown',
      ]);
    }

    // Auto-archive when the source repo is archived on GitHub. One-way:
    // subsequent syncs that find the repo un-archived do NOT auto-un-archive.
    // Contributor re-submit is the recovery path.
    if (!empty($repoMetadata['isArchived'])) {
      $node->set('moderation_state', 'archived');
      $this->logger->info('Auto-archiving Repo (@repo) — repo is archived on GitHub.', [
        '@repo' => $repoUrl,
      ]);
    }

    $node->save();
    return $node;
  }

  /**
   * Walk the apps[] list from a parsed root appverse.yml and create/update
   * one appverse_app node per declared subpath.
   *
   * Per-app metadata is sourced from <path>/appverse.yml when present
   * (already fetched into $subpathFiles by the caller). Apps missing any
   * required field (name, description, app_type, maintainer.name,
   * maintainer.support_url) are flagged validation_st = rejected; siblings
   * are unaffected.
   *
   * Inline-at-root per-app data (spec §5) is not yet implemented — only
   * the <path>/appverse.yml-per-subpath style is supported here.
   *
   * @param \Drupal\node\NodeInterface $repo
   *   The parent Repo node (already saved).
   * @param array $parsedRootYml
   *   The full parsed root appverse.yml.
   * @param array<string, array{manifestYml: ?string, appverseYml: ?string}> $subpathFiles
   *   Per-subpath manifest.yml + appverse.yml text, keyed by path.
   * @param string $repoUrl
   *   The Repo's repo URL.
   * @param array $repoMetadata
   *   Metadata from GitHubService (used to thread the repo owner down to
   *   applyDeclaredApp so per-app organization terms resolve from the
   *   GitHub owner rather than the per-app maintainer.name).
   *
   * @param bool $reconcile
   *   If TRUE (default), delete existing member Apps whose subpath is no
   *   longer in apps[]. Set FALSE when the caller passes a narrowed apps[]
   *   list that represents a single subpath (e.g. Batch-per-subpath ops);
   *   reconciliation against the narrowed list would mass-delete every
   *   other member App. Call reconcileDeclaredApps() once at the end of
   *   the full-list pass instead.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The created/updated app nodes, keyed by subpath.
   */
  public function applyDeclaredApps(NodeInterface $repo, array $parsedRootYml, array $subpathFiles, string $repoUrl, array $repoMetadata = [], bool $reconcile = TRUE): array {
    $appsList = $parsedRootYml['apps'] ?? [];
    if (!is_array($appsList)) {
      return [];
    }

    // Reconcile existing member Apps against the new apps[] list. Any App
    // whose subpath is no longer in apps[] is deleted (yaml is canonical).
    // Skipped when the caller passed a narrowed apps[] list — see the
    // $reconcile param docblock above.
    if ($reconcile) {
      $declaredSubpaths = [];
      foreach ($appsList as $entry) {
        $path = trim((string) ($entry['path'] ?? ''), '/');
        if ($path !== '') {
          $declaredSubpaths[$path] = TRUE;
        }
      }

      // Guard against transient yaml-parse failures that produce empty apps[].
      // If the new yaml declares no apps at all, treat this as "no signal" and
      // preserve existing member Apps rather than mass-deleting them. The
      // alternative (delete all) would conflict with the one-way-archive
      // contract — a stale_invalid yaml shouldn't silently empty the catalog.
      if (!empty($declaredSubpaths)) {
        $this->reconcileDeclaredApps($repo, $declaredSubpaths, $repoUrl);
      }
    }

    $result = [];
    foreach ($appsList as $entry) {
      if (!is_array($entry) || empty($entry['path'])) {
        continue;
      }
      $subpath = trim((string) $entry['path'], '/');
      if ($subpath === '') {
        continue;
      }

      $files = $subpathFiles[$subpath] ?? ['manifestYml' => NULL, 'appverseYml' => NULL];
      $appNode = $this->resolveAppNode($repoUrl, $subpath);
      // Thread the root-level maintainer down so an app that declares no
      // maintainer of its own can inherit the repo-level maintainer. Only
      // the multi-app (apps[]) path passes this; the single-app declared
      // path already carries the maintainer inline in $entry, so it does
      // NOT pass a fallback and is unaffected by inheritance.
      $rootMaintainer = (isset($parsedRootYml['maintainer']) && is_array($parsedRootYml['maintainer']))
        ? $parsedRootYml['maintainer']
        : NULL;
      // Thread the root-level shared_implementation_tags down so each member
      // app's effective implementation tags are the union of its own
      // `implementation_tags:` and the repo-level shared list (additive).
      // Only a non-empty array
      // signal is forwarded; anything else leaves per-app behaviour unchanged.
      $sharedImplementationTags = (isset($parsedRootYml['shared_implementation_tags']) && is_array($parsedRootYml['shared_implementation_tags']) && $parsedRootYml['shared_implementation_tags'] !== [])
        ? $parsedRootYml['shared_implementation_tags']
        : NULL;
      $this->applyDeclaredApp($appNode, $repo, $subpath, $files, $repoUrl, $repoMetadata, $entry, $rootMaintainer, $sharedImplementationTags);
      $result[$subpath] = $appNode;
    }

    // Cascade auto-archive from Repo to member Apps. Mirrors the
    // one-way archive contract on the Repo itself.
    if (!empty($repoMetadata['isArchived'])) {
      $memberAppIds = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'appverse_app')
        ->condition('field_appverse_repo', $repo->id())
        ->execute();
      foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($memberAppIds) as $memberApp) {
        if ($memberApp->hasField('moderation_state')) {
          $this->logger->info('Cascade-archiving App @nid (subpath @sub) — parent Repo @repo is archived on GitHub.', [
            '@nid' => $memberApp->id(),
            '@sub' => $memberApp->get('field_appverse_app_subpath')->value ?? '',
            '@repo' => $repoUrl,
          ]);
          $memberApp->set('moderation_state', 'archived');
          $memberApp->save();
        }
      }
    }

    return $result;
  }

  /**
   * Create/update the single member app for a declared single-app repo.
   *
   * SHAPE 1 (docs/appverse.yml): a root appverse.yml that declares top-level
   * app metadata (software, app_type, tags, maintainer, description, title)
   * but no `apps:` list. The repo IS the one app, living at the repo root
   * (subpath ''). This is the declared-shape analogue of
   * syncInferredMemberApp(): one app at root, created synchronously by the
   * AddRepoForm so the contributor's hub shows it immediately.
   *
   * Reuses applyDeclaredApp() for all field logic. We synthesize the apps[]
   * "entry" from the root yaml by dropping repo-only/structural keys
   * (apps, shared_paths, website, docs, appverse, shared_implementation_tags)
   * and mapping the root `title` to the app's `name` (applyDeclaredApp reads
   * `name`, not `title`). shared_implementation_tags is monorepo-only and has
   * nothing to inherit into for a single app, so it is ignored here.
   *
   * The app's IMPLEMENTATION tags are declared at the root via
   * `implementation_tags:` (NOT `tags:` — root `tags:` is repo-level DISCOVERY
   * tags handled in applyDeclared()). That key is deliberately kept in the
   * synthesized $entry so it flows through applyDeclaredApp()'s
   * $appverseLayer['implementation_tags'] read and lands the single app's
   * implementation tags in field_add_implementation_tags.
   *
   * @param \Drupal\node\NodeInterface $repo
   *   The Repo node returned by resolveRepo() for this URL (already saved).
   * @param array $parsedRootYml
   *   The full parsed root appverse.yml mapping.
   * @param array $rootFiles
   *   Root manifest/appverse/readme text, in the same {manifestYml,
   *   appverseYml, readme} shape applyDeclaredApp expects for a subpath.
   *   Pass [] when none were fetched.
   * @param string $repoUrl
   *   Canonical GitHub repo URL.
   * @param array $repoMetadata
   *   Metadata from GitHubService (used for the organization term, etc.).
   *
   * @return \Drupal\node\NodeInterface
   *   The created/updated appverse_app node at subpath ''.
   */
  public function applyDeclaredSingleApp(NodeInterface $repo, array $parsedRootYml, array $rootFiles, string $repoUrl, array $repoMetadata = []): NodeInterface {
    // Synthesize the apps[] entry from the root mapping. Drop repo-only and
    // structural keys; what remains (software, app_type, implementation_tags,
    // maintainer, description) are the app's Appverse fields. Map root
    // `title` -> `name` since applyDeclaredApp() reads `name` for the app
    // title. Root `implementation_tags:` survives (it is NOT in the drop list)
    // so the single app gets its implementation tags. A root `tags:` key (if
    // present) also survives into $entry but is harmless: applyDeclaredApp()
    // no longer reads `tags` at the app level, and repo-level discovery
    // `tags:` are handled separately in applyDeclared() -> field_repo_tags.
    $entry = $parsedRootYml;
    unset(
      $entry['apps'],
      $entry['shared_paths'],
      $entry['website'],
      $entry['docs'],
      $entry['appverse'],
      // shared_implementation_tags is a monorepo-only concept: it exists to
      // be inherited additively by member apps[]. A single-app repo has no
      // member apps to inherit into — its own `implementation_tags:` already
      // cover it — so we drop the key here. Leaving it would let array_replace
      // fold it into $appverseLayer where it is not an app field, and
      // (harmlessly today) it is never read, but dropping it keeps the
      // synthesized entry clean and prevents any future spurious resolution.
      $entry['shared_implementation_tags'],
    );
    if (isset($entry['title']) && !isset($entry['name'])) {
      $entry['name'] = $entry['title'];
    }
    unset($entry['title']);

    $files = $rootFiles ?: ['manifestYml' => NULL, 'appverseYml' => NULL];

    // Resolve the existing root app by parent repo, mirroring
    // syncInferredMemberApp(). A single-app repo has exactly one member app
    // at subpath '' (the repo root). We deliberately look up by
    // field_appverse_repo rather than resolveAppNode($repoUrl, '') because
    // an empty-string subpath does not round-trip through a string-field
    // equality query (Drupal treats '' as an empty field item), so a
    // subpath='' lookup would never re-find the app and re-syncs would
    // duplicate it.
    $existing = $this->entityTypeManager->getStorage('node')->loadByProperties([
      'type' => 'appverse_app',
      'field_appverse_repo' => $repo->id(),
    ]);
    if ($existing) {
      $appNode = reset($existing);
    }
    else {
      $appNode = $this->entityTypeManager->getStorage('node')->create([
        'type' => 'appverse_app',
        'field_appverse_github_url' => ['uri' => $repoUrl],
        'field_appverse_app_subpath' => '',
        // New apps land in 'draft' (mirrors resolveAppNode + createBlankRepo).
        'moderation_state' => 'draft',
      ]);
    }

    // Stamp ownership on a brand-new node, mirroring syncInferredMemberApp().
    if ($appNode->isNew()) {
      $appNode->setOwnerId((int) $repo->getOwnerId());
    }

    $this->applyDeclaredApp($appNode, $repo, '', $files, $repoUrl, $repoMetadata, $entry);
    return $appNode;
  }

  /**
   * Delete member Apps whose subpath isn't in the canonical apps[] list.
   *
   * Extracted from applyDeclaredApps so callers that pass a narrowed
   * single-subpath list (Batch-per-subpath ops) can defer reconciliation
   * until the full list is known and call this once at the end.
   *
   * Caller's responsibility to ensure $declaredSubpaths reflects the
   * full apps[] list, not a narrowed slice. An empty $declaredSubpaths
   * is treated as "no signal" (preserve existing) — never mass-delete.
   *
   * @param \Drupal\node\NodeInterface $repo
   *   The Repo whose member apps to reconcile.
   * @param array<string, bool> $declaredSubpaths
   *   Map of declared subpath => TRUE from the full canonical apps[].
   * @param string $repoUrl
   *   For logging context only.
   */
  public function reconcileDeclaredApps(NodeInterface $repo, array $declaredSubpaths, string $repoUrl): void {
    if (empty($declaredSubpaths)) {
      return;
    }
    $existingAppIds = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appverse_app')
      ->condition('field_appverse_repo', $repo->id())
      ->execute();

    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($existingAppIds) as $existingApp) {
      $subpath = trim((string) $existingApp->get('field_appverse_app_subpath')->value, '/');
      // This method only runs when the repo has a non-empty apps[] list (guarded
      // above), so a subpath-'' member cannot be a legitimate declared-single-app
      // or inferred root app here — it's the stale remnant from before the repo
      // gained its apps[] list, and must be reconciled away like any other
      // subpath no longer in the canonical set.
      if (!isset($declaredSubpaths[$subpath])) {
        $this->logger->info('Deleting App @nid (subpath "@sub") — no longer in apps[] of @repo.', [
          '@nid' => $existingApp->id(),
          '@sub' => $subpath,
          '@repo' => $repoUrl,
        ]);
        $existingApp->delete();
      }
    }
  }

  /**
   * Create or update the single member app for an inferred-shape repo.
   *
   * Inferred repos have one app, no appverse.yml. The repo's root manifest.yml
   * is the source of truth. Replaces the cron-deferred attach that the
   * AppverseAppUpdater queue worker used to perform on first run — the
   * AddRepoForm calls this synchronously so the new card appears on the
   * contributor's hub immediately.
   *
   * @param \Drupal\node\NodeInterface $repo
   *   The Repo node returned by resolveRepo() for this URL.
   * @param string $repoUrl
   *   Canonical GitHub repo URL.
   * @param array $rootManifest
   *   Parsed root manifest.yml. Required: name. Optional: description, role,
   *   subcategory, license, licenseLink. Optional convenience: '_app_type_ids'
   *   (int[]) — the caller's resolved term IDs from
   *   GitHubService::getAppTypeIds(), since this method can't reach the
   *   GitHubService itself.
   * @param array $repoMetadata
   *   {name, description, organization, stars, lastCommittedDate, readme,
   *   licenseLink}. 'organization' is the raw GitHub owner login string;
   *   this method resolves it to a term ID via resolveOrganizationTerm().
   *
   * @return \Drupal\node\NodeInterface
   *   The saved appverse_app node.
   */
  public function syncInferredMemberApp(
    NodeInterface $repo,
    string $repoUrl,
    array $rootManifest,
    array $repoMetadata,
  ): NodeInterface {
    $existing = $this->entityTypeManager->getStorage('node')->loadByProperties([
      'type' => 'appverse_app',
      'field_appverse_repo' => $repo->id(),
    ]);
    $isNew = !$existing;
    $app = $existing ? reset($existing) : Node::create(['type' => 'appverse_app']);

    $title = $rootManifest['name']
      ?? ($repoMetadata['name'] ?? NULL)
      ?? $this->urlSegment($repoUrl);
    $app->setTitle($title);

    $body = $rootManifest['description'] ?? $repoMetadata['description'] ?? '';
    // Match production text format (declared sync + AppverseAppUpdater both use markdown).
    $app->set('body', ['value' => $body, 'format' => 'markdown']);

    $app->set('field_appverse_repo', $repo->id());
    $app->set('field_appverse_github_url', ['uri' => $repoUrl]);

    // Organization as TERM REFERENCE, not string. Mirrors applyDeclaredApp.
    $repoOwner = (string) ($repoMetadata['organization'] ?? '');
    $orgTid = $repoOwner !== '' ? $this->resolveOrganizationTerm($repoOwner) : NULL;
    $app->set('field_appverse_organization', $orgTid);

    $app->set('field_appverse_stars', (int) ($repoMetadata['stars'] ?? 0));
    if (!empty($repoMetadata['lastCommittedDate'])) {
      $app->set('field_appverse_lastupdated', (int) $repoMetadata['lastCommittedDate']);
    }

    $readme = (string) ($repoMetadata['readme'] ?? '');
    if ($readme !== '') {
      $app->set('field_appverse_readme', ['value' => $readme, 'format' => 'markdown']);
    }

    if (!empty($rootManifest['_app_type_ids'])) {
      // Multi-value entity reference: each value is ['target_id' => N].
      $app->set('field_appverse_app_type', array_map(
        static fn (int $id): array => ['target_id' => $id],
        array_map('intval', $rootManifest['_app_type_ids']),
      ));
    }

    // License link is a plain link field — safe to set directly. field_license
    // is an entity reference to a taxonomy term; GitHubService::getLicense()
    // returns the SPDX string (e.g., "MIT"), not a term ID. Until a
    // resolveLicenseTerm() helper exists (parallel to resolveOrganizationTerm),
    // we don't set field_license here. See Out of Scope in the form plan.
    if (!empty($repoMetadata['licenseLink'])) {
      $app->set('field_appverse_license_link', ['uri' => $repoMetadata['licenseLink']]);
    }

    $app->set('field_appverse_app_validation_st', 'valid');

    if ($isNew) {
      $app->setOwnerId((int) $repo->getOwnerId());
      // Mirror createBlankRepo (line 696) and applyDeclaredApp — new member
      // apps start in draft.
      $app->set('moderation_state', 'draft');
    }

    $app->save();
    return $app;
  }

  /**
   * Derive a fallback title segment from a GitHub repo URL's last path segment.
   */
  protected function urlSegment(string $repoUrl): string {
    $parts = array_filter(explode('/', parse_url($repoUrl, PHP_URL_PATH) ?? ''));
    return (string) (end($parts) ?: 'untitled-app');
  }

  /**
   * Load an app node by (repo URL, subpath), or create a blank one.
   */
  protected function resolveAppNode(string $repoUrl, string $subpath): NodeInterface {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $nids = $nodeStorage->getQuery()
      ->condition('type', 'appverse_app')
      ->condition('field_appverse_github_url.uri', $repoUrl)
      ->condition('field_appverse_app_subpath', $subpath)
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();
    if ($nids) {
      return $nodeStorage->load(reset($nids));
    }
    return $nodeStorage->create([
      'type' => 'appverse_app',
      'title' => $subpath,
      'field_appverse_github_url' => ['uri' => $repoUrl],
      'field_appverse_app_subpath' => $subpath,
      // appverse_app uses content moderation. New apps land in 'draft'
      // and proceed to 'ready_for_review' via the contributor's
      // send_for_review action on the hub. status is forced from
      // moderation_state by content_moderation (draft -> 0).
      'moderation_state' => 'draft',
    ]);
  }

  /**
   * Populate per-app fields from <path>/appverse.yml + <path>/manifest.yml.
   *
   * Required fields: name, description, app_type, maintainer.name,
   * maintainer.support_url. If any are missing, mark validation_st =
   * rejected and skip writing optional fields.
   *
   * @param \Drupal\node\NodeInterface $app
   *   The app node to populate.
   * @param \Drupal\node\NodeInterface $repo
   *   The parent Repo/Repo node.
   * @param string $subpath
   *   The app's subpath within the repo ('' for a root single-app).
   * @param array $files
   *   {manifestYml, appverseYml, readme} text for this subpath.
   * @param string $repoUrl
   *   Canonical GitHub repo URL.
   * @param array $repoMetadata
   *   Metadata from GitHubService (organization term, etc.).
   * @param array|null $entry
   *   The root-inline apps[] entry for this app (or the synthesized entry on
   *   the single-app path). Wins over the per-subpath appverse.yml.
   * @param array|null $rootMaintainer
   *   The top-level `maintainer` mapping from the root appverse.yml, passed
   *   only by the multi-app applyDeclaredApps() path. When the app declares
   *   no `maintainer` of its own, the WHOLE root maintainer mapping is
   *   inherited; an app that declares its own maintainer always overrides.
   *   NULL (the single-app path / no repo maintainer) disables inheritance.
   * @param array|null $sharedImplementationTags
   *   The root-level `shared_implementation_tags` list, passed only by the
   *   multi-app applyDeclaredApps() path. Each member app's effective
   *   implementation tags are the UNION of its own declared
   *   `implementation_tags:` and these shared tags (deduplicated, additive —
   *   there is no override or opt-out).
   *   The combined list runs through the same resolve/suggest/reject path as
   *   the app's own tags, so an unresolved inherited tag rejects the app too.
   *   NULL (the single-app path / no shared tags) leaves behaviour unchanged.
   */
  protected function applyDeclaredApp(NodeInterface $app, NodeInterface $repo, string $subpath, array $files, string $repoUrl, array $repoMetadata = [], ?array $entry = NULL, ?array $rootMaintainer = NULL, ?array $sharedImplementationTags = NULL): void {
    // Reset validation status at the start of each sync run. Validation
    // checks below set 'rejected' + append errors when they detect
    // problems; if no checks reject, the app stays 'valid'. This lets a
    // previously-rejected app recover on a later sync once the source
    // fields are corrected. The earlier "never reset to valid" ordering
    // contract is now expressed via the in-method order: every check
    // OR-folds into 'rejected' but no later step downgrades 'rejected'
    // back to 'valid'.
    $app->set('field_appverse_app_validation_st', 'valid');
    $app->set('field_appverse_app_validation_er', []);

    // Precedence: content fields live in the canonical Appverse layer,
    // which is the root-inline apps[] entry merged with the per-subpath
    // <path>/appverse.yml file. Root-inline wins when both forms declare
    // the same field (more visible to readers of the root file).
    // manifest.yml is a final fallback for name + description only —
    // app_type, maintainer, software, and tags are Appverse-only concepts.
    $perSubpathAppverse = [];
    if (!empty($files['appverseYml'])) {
      try {
        $parsed = Yaml::parse($files['appverseYml']);
        if (is_array($parsed)) {
          $perSubpathAppverse = $parsed;
        }
      }
      catch (\Throwable $e) {
        $this->logger->warning('Could not parse @path/appverse.yml: @msg', ['@path' => $subpath, '@msg' => $e->getMessage()]);
      }
    }
    $rootInline = is_array($entry) ? $entry : [];
    // array_replace lets root-inline override per-subpath. Drop the
    // structural 'path' key from the merged layer — it identifies the
    // subpath, not an app field.
    $appverseLayer = array_replace($perSubpathAppverse, $rootInline);
    unset($appverseLayer['path']);

    // Repo-level maintainer inheritance (monorepo apps[] path only).
    // Rule: inherit the ENTIRE root-level maintainer mapping ONLY when the
    // app declares no `maintainer` at all (neither in its per-subpath
    // appverse.yml nor in its inline apps[] entry). An app that declares its
    // own `maintainer` always wins (override) — even a partial one; we do
    // NOT merge sub-fields, because a partial app-level maintainer is the
    // app's own (fixable) problem and per-field merging would be
    // unpredictable. $rootMaintainer is NULL on the single-app declared path
    // (applyDeclaredSingleApp), whose maintainer already lives in $entry, so
    // that path is never double-applied.
    if ($rootMaintainer !== NULL && !isset($appverseLayer['maintainer'])) {
      $appverseLayer['maintainer'] = $rootMaintainer;
    }

    $softwareDeclared = $appverseLayer['software'] ?? NULL;
    $softwareInfo = $this->githubService->resolveSoftwareForApp($softwareDeclared);

    if ($softwareInfo['resolvedNid'] !== NULL) {
      // Exact match — attach the Software ref. Do NOT touch validation_st
      // or validation_er; later steps may set rejected for other reasons.
      $app->set('field_appverse_software_implemen', $softwareInfo['resolvedNid']);
    }
    else {
      // No match. Flag rejected + APPEND helpful error (preserve any
      // existing errors set by earlier sync logic).
      $app->set('field_appverse_app_validation_st', 'rejected');
      $existingErrors = $app->get('field_appverse_app_validation_er')->getValue();
      $newError = $this->buildSoftwareError($softwareDeclared, $subpath, $softwareInfo);
      $existingErrors[] = ['value' => $newError];
      $app->set('field_appverse_app_validation_er', $existingErrors);
    }

    // Tags: same precedence + resolution pattern as software. Read the
    // `implementation_tags:` list from the merged appverse layer; resolve
    // against the appverse_implementation_tags vocabulary; write resolved
    // term ids to field_add_implementation_tags; append error(s) for
    // unresolved. NOTE: the app-level key is `implementation_tags` — a bare
    // `tags:` at the app level is repo-level DISCOVERY tags and is NOT read
    // here.
    //
    // Repo-level shared_implementation_tags inheritance (monorepo apps[] path
    // only). The effective implementation-tag list is the UNION of the app's
    // own declared `implementation_tags:` and the repo-level
    // $sharedImplementationTags —
    // additive, never an override, no opt-out. We dedup the combined list
    // (case-insensitive, first spelling wins) BEFORE resolving so a tag
    // declared at both levels produces a single term ref and, if unresolved,
    // a single "did you mean" error rather than a doubled one. The combined
    // list then runs through the SAME resolveTaxonomyTermsFromAppverseYml call
    // as before, so inherited tags also get the resolve/suggest/reject
    // treatment. $sharedImplementationTags is NULL on the single-app declared
    // path (shared_implementation_tags is a monorepo-only concept) and when no
    // shared tags are declared, leaving the single-app behaviour unchanged.
    $declaredTags = $appverseLayer['implementation_tags'] ?? NULL;
    $effectiveTags = [];
    foreach ([$declaredTags, $sharedImplementationTags] as $source) {
      if (!is_array($source)) {
        continue;
      }
      foreach ($source as $tag) {
        if (!is_scalar($tag)) {
          continue;
        }
        $trimmed = trim((string) $tag);
        if ($trimmed === '') {
          continue;
        }
        // Case-insensitive dedup; keep the first spelling encountered.
        $key = mb_strtolower($trimmed);
        if (!isset($effectiveTags[$key])) {
          $effectiveTags[$key] = $trimmed;
        }
      }
    }
    $tagsInfo = $this->githubService->resolveTaxonomyTermsFromAppverseYml(
      'appverse_implementation_tags',
      $effectiveTags !== [] ? array_values($effectiveTags) : NULL
    );
    // Always set the field (to the resolved tids, or an empty list when the
    // app declares no tags or none resolve) so that removing tags from the
    // appverse.yml clears them on the next sync instead of leaving stale ones.
    $tagTids = [];
    foreach (($tagsInfo['resolved'] ?? []) as $resolved) {
      $tagTids[] = $resolved['tid'];
    }
    $app->set('field_add_implementation_tags', $tagTids);
    // Drop-and-flag (NOT reject) for unresolved implementation tags: the app
    // keeps the tags that DID resolve (written above) and stays listed; each
    // unresolved tag is simply dropped and recorded as a flag in
    // validation_er for a reviewer to act on (map to an existing term or
    // create it). An unknown implementation tag must not delist an otherwise
    // valid app — unlike `software` (a hard gate above), a missing discovery
    // facet is not fatal. We deliberately do NOT set validation_st here.
    if (!empty($tagsInfo['unresolved'])) {
      $existingErrors = $app->get('field_appverse_app_validation_er')->getValue();
      foreach ($tagsInfo['unresolved'] as $unresolved) {
        $suggestion = $unresolved['suggestion'] ?? NULL;
        if ($suggestion !== NULL) {
          $msg = sprintf(
            "implementation_tags: '%s' is not in the implementation_tags vocabulary and was not applied. Did you mean '%s'? (A reviewer can map or create the term; see the contributor docs for the current list.)",
            $unresolved['declared'],
            $suggestion
          );
        }
        else {
          $msg = sprintf(
            "implementation_tags: '%s' is not in the implementation_tags vocabulary and was not applied. (A reviewer can map or create the term; see the contributor docs for the current list of valid values.)",
            $unresolved['declared']
          );
        }
        $existingErrors[] = ['value' => $msg];
      }
      $app->set('field_appverse_app_validation_er', $existingErrors);
    }
    // Persist the structured unresolved implementation tags so the hub can
    // render per-tag [Create term] actions without re-parsing prose or
    // re-fetching from GitHub. Always set (cleared when none) so it never
    // goes stale across syncs.
    $unresolvedTagValues = [];
    foreach (($tagsInfo['unresolved'] ?? []) as $unresolved) {
      $unresolvedTagValues[] = $unresolved['declared'];
    }
    $app->set('field_appverse_unresolved_tags', $unresolvedTagValues);

    // Fallback to manifest.yml's name + description if neither the per-app
    // appverse.yml nor the root-inline apps[] entry declare them.
    $manifestYml = NULL;
    if (!empty($files['manifestYml'])) {
      try {
        $manifestYml = Yaml::parse($files['manifestYml']);
      }
      catch (ParseException $e) {
        $manifestYml = NULL;
      }
    }
    if (!is_array($manifestYml)) {
      $manifestYml = [];
    }

    // Read content fields from the already-merged appverse layer
    // (per-subpath <path>/appverse.yml + root-inline apps[] entry, with
    // root-inline winning). The root-inline layer is the canonical place
    // to declare a small app's metadata without a per-subpath appverse.yml
    // file; it's the same precedence used above for `software` and `tags`.
    // manifest.yml is a final fallback for name + description only —
    // app_type and maintainer are Appverse-only fields not carried in
    // manifest.yml.
    $name = $appverseLayer['name'] ?? ($manifestYml['name'] ?? '');
    $description = $appverseLayer['description'] ?? ($manifestYml['description'] ?? '');
    $appType = $appverseLayer['app_type'] ?? NULL;
    $maintainerName = $appverseLayer['maintainer']['name'] ?? '';
    $maintainerSupport = $appverseLayer['maintainer']['support_url'] ?? '';

    $missing = [];
    if (!$name) {
      $missing[] = 'name';
    }
    if (!$description) {
      $missing[] = 'description';
    }
    if (!$appType) {
      $missing[] = 'app_type';
    }
    if (!$maintainerName) {
      $missing[] = 'maintainer.name';
    }
    if (!$maintainerSupport) {
      $missing[] = 'maintainer.support_url';
    }

    if ($missing) {
      $app->setTitle($name ?: $subpath);
      $app->set('field_appverse_repo', $repo->id());
      $app->set('field_appverse_app_subpath', $subpath);
      $app->set('field_appverse_app_validation_st', 'rejected');
      // Per spec §5: never silently delist. Previously-good content
      // (README, tags, organization, maintainer) stays in place when an
      // app flips to rejected, so the listing degrades gracefully rather
      // than vanishing. Once required fields are restored, the next sync
      // re-validates and rewrites those fields normally.
      $this->logger->warning(
        'App at @repo / @path rejected: missing required fields: @missing',
        ['@repo' => $repoUrl, '@path' => $subpath, '@missing' => implode(', ', $missing)]
      );
      $app->save();
      return;
    }

    // Happy path — write all fields.
    $app->setTitle($name);
    $app->set('body', [
      'value' => $description,
      'format' => 'markdown',
    ]);
    $app->set('field_appverse_repo', $repo->id());
    $app->set('field_appverse_app_subpath', $subpath);
    // validation_st was initialized to 'valid' at the top of this method.
    // Earlier checks (software, tags, required-fields) may have flipped
    // it to 'rejected' along with appended error messages. The happy
    // path here doesn't touch it — if no prior rejection landed, it
    // stays 'valid'.

    // App type — match-only against the appverse_app_type vocab.
    $appTypeTid = $this->resolveAppTypeTerm((string) $appType);
    if ($appTypeTid !== NULL) {
      $app->set('field_appverse_app_type', [$appTypeTid]);
    }

    // Per-app implementation tags are resolved + written above (against the
    // appverse_implementation_tags vocabulary, with validation errors for
    // unresolved values). Do NOT re-resolve them here against the generic
    // `tags` vocabulary — that was a duplicate write that clobbered the
    // correct one and silently dropped valid implementation tags.

    // Organization term — match-only against appverse_organization, keyed
    // off the GitHub repo owner (the team/lab/center that owns the repo),
    // not the per-app maintainer.name (typically a person).
    // Always set — null'd when the repo owner isn't in the org vocab so
    // stale assignments clear.
    $repoOwner = $repoMetadata['organization'] ?? '';
    $orgTid = $repoOwner ? $this->resolveOrganizationTerm($repoOwner) : NULL;
    $app->set('field_appverse_organization', $orgTid);

    // Per-app maintainer name — the person/team responsible for this app
    // (distinct from the repo-owning organization above).
    // Always set — empty string when YAML doesn't declare a maintainer, so
    // removed data clears rather than persisting stale.
    $app->set('field_appverse_maintainer_name', $maintainerName);

    // Per-app README — fetched from <path>/README.md by GitHubService.
    // Always set — null'd when the repo's per-path README is missing or
    // empty, so removed READMEs clear rather than persisting stale.
    if (!empty($files['readme'])) {
      $app->set('field_appverse_readme', [
        'value' => $files['readme'],
        'format' => 'markdown',
      ]);
    }
    else {
      $app->set('field_appverse_readme', NULL);
    }

    $app->save();
  }

  /**
   * Build a user-facing error message for an unresolved software: value.
   *
   * @param string|null $declared
   *   The raw software: value from appverse.yml's apps[] entry. NULL or
   *   empty string when the key is missing.
   * @param string $subpath
   *   The apps[].path the error refers to. Used in the missing-key message
   *   so the editor knows which app row to edit.
   * @param array $info
   *   The result of GitHubService::resolveSoftwareForApp() — used to pull
   *   the suggestion on fuzzy-match miss.
   */
  protected function buildSoftwareError(?string $declared, string $subpath, array $info): string {
    if ($declared === NULL || trim($declared) === '') {
      return sprintf(
        "software: required key missing from apps[].path '%s'. Add a 'software:' key matching an existing Software entry's title.",
        $subpath
      );
    }
    if ($info['suggestion'] !== NULL) {
      return sprintf(
        "software: '%s' not found in catalog. Did you mean '%s'? (Edit appverse.yml to fix.)",
        $declared,
        $info['suggestion']
      );
    }
    return sprintf(
      "software: '%s' not found in catalog. Check existing Software entries and update appverse.yml.",
      $declared
    );
  }

  /**
   * Match an existing app_type term by name; match-only.
   *
   * Matches by name; case-insensitivity depends on the database collation
   * (MySQL's default *_ci collations match case-insensitively, which is
   * what we rely on here).
   */
  protected function resolveAppTypeTerm(string $name): ?int {
    if ($name === '') {
      return NULL;
    }
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $termStorage->getQuery()
      ->condition('vid', 'appverse_app_type')
      ->condition('name', $name, 'LIKE')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();
    if (!$tids) {
      return NULL;
    }
    return (int) reset($tids);
  }

  /**
   * Create a new, unsaved Repo node bound to a repo URL.
   */
  protected function createBlankRepo(string $repoUrl): NodeInterface {
    return $this->entityTypeManager->getStorage('node')->create([
      'type' => 'appverse_repo',
      'title' => $this->deriveTitleFromRepoUrl($repoUrl),
      'field_repo_url' => ['uri' => $repoUrl],
      'field_repo_validation_st' => 'valid',
      // New Repos land in draft. status is forced to 0 by Drupal's
      // content_moderation when moderation_state != 'published', so we
      // don't set status here.
      'moderation_state' => 'draft',
    ]);
  }

  /**
   * Derive a fallback Repo title from a GitHub repo URL.
   */
  protected function deriveTitleFromRepoUrl(string $repoUrl): string {
    $parsed = parse_url($repoUrl);
    $path = trim($parsed['path'] ?? '', '/');
    $segments = explode('/', $path);
    return end($segments) ?: $repoUrl;
  }

  /**
   * Match an existing organization taxonomy term by name.
   *
   * Matches by name; case-insensitivity depends on the database collation
   * (MySQL's default *_ci collations match case-insensitively, which is
   * what we rely on here).
   *
   * Match-only, no auto-create. Auto-creating organization terms from raw
   * maintainer strings pollutes the vocab with typos and case variants
   * ("CHPC" vs "Chpc"). Curators add new organization terms explicitly
   * through the existing taxonomy admin UI; the sync then picks them up on
   * the next pass. Unmatched maintainer strings leave
   * field_repo_organization NULL — the Repo still functions; it
   * just isn't filterable by org until an admin adds the term and re-runs
   * the sync.
   */
  protected function resolveOrganizationTerm(string $name): ?int {
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $termStorage->getQuery()
      ->condition('vid', 'appverse_organization')
      ->condition('name', $name, 'LIKE')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();
    if (!$tids) {
      return NULL;
    }
    return (int) reset($tids);
  }

  /**
   * Sanitize an error message before storing it in a public field.
   *
   * Strips absolute file paths and stack traces; caps length.
   */
  protected function sanitizeError(string $message): string {
    $clean = preg_replace('@/[^\s:]+@', '<path>', $message);
    return mb_substr($clean, 0, 200);
  }

  /**
   * Apply minimal repo-derived metadata to a Repo node when appverse.yml is absent.
   */
  protected function applyInferred(?NodeInterface $existing, string $repoUrl, array $repoMetadata): NodeInterface {
    $node = $existing ?? $this->createBlankRepo($repoUrl);

    $title = $repoMetadata['name'] ?? $this->deriveTitleFromRepoUrl($repoUrl);
    $description = $repoMetadata['description'] ?? '';
    $maintainerName = $repoMetadata['organization'] ?? '';

    $node->setTitle($title);
    $node->set('field_repo_description', $description);
    $node->set('field_repo_url', ['uri' => $repoUrl]);
    if ($maintainerName) {
      $node->set('field_repo_maintainer_name', $maintainerName);
    }
    // Organization term comes from the GitHub repo owner, not the
    // maintainer string (which may be a person rather than an org).
    $repoOwner = $repoMetadata['organization'] ?? '';
    if ($repoOwner) {
      $org = $this->resolveOrganizationTerm($repoOwner);
      if ($org !== NULL) {
        $node->set('field_repo_organization', $org);
      }
    }
    // Inferred repos start valid; they roll up to degraded only if a
    // child app is rejected (app-level rejection is handled separately).
    $node->set('field_repo_shape', 'inferred');
    $node->set('field_repo_validation_st', 'valid');
    $node->set('field_repo_validation_er', []);
    $node->set('field_repo_last_synced', $this->time->getCurrentTime());
    if (isset($repoMetadata['stars'])) {
      $node->set('field_repo_stars', (int) $repoMetadata['stars']);
    }
    if (isset($repoMetadata['lastCommittedDate'])) {
      $node->set('field_repo_last_commit', (int) $repoMetadata['lastCommittedDate']);
    }
    if (isset($repoMetadata['readme'])) {
      $node->set('field_repo_readme', [
        'value' => $repoMetadata['readme'],
        'format' => 'markdown',
      ]);
    }

    // Auto-archive when the source repo is archived on GitHub. One-way:
    // subsequent syncs that find the repo un-archived do NOT auto-un-archive.
    // Contributor re-submit is the recovery path.
    if (!empty($repoMetadata['isArchived'])) {
      $node->set('moderation_state', 'archived');
      $this->logger->info('Auto-archiving Repo (@repo) — repo is archived on GitHub.', [
        '@repo' => $repoUrl,
      ]);
    }

    $node->save();
    return $node;
  }

}
