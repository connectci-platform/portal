<?php

namespace Drupal\ood_software\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Generates a static JSON cache of appverse data for fast frontend loading.
 */
class AppverseCacheService {

  const CACHE_DIR = 'public://appverse-cache';
  const CACHE_FILE = 'public://appverse-cache/appverse-data.json';

  protected LoggerInterface $logger;

  /**
   * Whether an appverse content change in this request needs a cache flush.
   *
   * Set by markDirty(); consumed once per request by flushIfDirty() (called
   * from AppverseCacheFlushSubscriber on kernel.terminate). Holding the flag
   * on the service lets any number of saves in a request collapse to a single
   * generate() at the end, replacing the old per-save throttle.
   */
  protected bool $dirty = FALSE;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected FileSystemInterface $fileSystem,
    LoggerChannelFactoryInterface $loggerFactory,
    protected AppverseLogoUrl $appverseLogoUrl,
  ) {
    $this->logger = $loggerFactory->get('ood_software');
  }

  /**
   * Flag that the appverse cache is stale and should be regenerated.
   *
   * Cheap and idempotent — only sets a flag. The actual (expensive)
   * regeneration runs at most once per request via flushIfDirty().
   */
  public function markDirty(): void {
    $this->dirty = TRUE;
  }

  /**
   * Regenerate the cache once if it was marked dirty this request.
   *
   * Called from the kernel.terminate subscriber after the response is sent,
   * and explicitly at the end of drush batch/queue runs where terminate may
   * not fire. Clears the flag before regenerating so a single flush can't
   * loop, but re-sets it if generate() reports failure so the next flush
   * opportunity (a later request, the explicit drush/batch caller's next run,
   * or cron) retries rather than silently dropping the stale state.
   *
   * @return bool
   *   TRUE if a regeneration ran and succeeded, FALSE if the cache was not
   *   dirty or the regeneration failed (in which case the dirty flag is
   *   left set for a later retry).
   */
  public function flushIfDirty(): bool {
    if (!$this->dirty) {
      return FALSE;
    }
    // Clear first: within one request, terminate fires once, so this can't
    // loop. Re-set on failure so the staleness isn't dropped until cron.
    $this->dirty = FALSE;
    if (!$this->generate()) {
      $this->dirty = TRUE;
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Generate and write the static JSON cache file.
   */
  public function generate(): bool {
    $this->reviewByRepo = [];
    try {
      $software = $this->buildSoftwareData();
      $repos = $this->buildReposData($software);
      $this->annotateAppsWithRepoBackRefs($software, $repos);

      $data = [
        'software' => $software,
        'repos' => $repos,
        'filterOptions' => $this->extractFilterOptions($software, $repos),
        'generated' => date('c'),
      ];

      $cacheDir = self::CACHE_DIR;
      $this->fileSystem->prepareDirectory($cacheDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

      $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      $this->fileSystem->saveData($json, self::CACHE_FILE, FileExists::Replace);

      $this->logger->info('Appverse cache generated: @size bytes, @sw software, @col repos.', [
        '@size' => strlen($json),
        '@sw' => count($software),
        '@col' => count($repos),
      ]);
      return TRUE;
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to generate appverse cache: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Build the software array with nested apps.
   *
   * @return array<mixed>
   */
  protected function buildSoftwareData(): array {
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    // Load all published software nodes.
    $softwareNids = $nodeStorage->getQuery()
      ->condition('type', 'appverse_software')
      ->condition('status', 1)
      ->sort('title')
      ->accessCheck(FALSE)
      ->execute();
    $softwareNodes = $nodeStorage->loadMultiple($softwareNids);

    // Load all published app nodes.
    $appQuery = $nodeStorage->getQuery()
      ->condition('type', 'appverse_app')
      ->condition('status', 1)
      ->accessCheck(FALSE);
    $this->applyRepoCascadeFilter($appQuery);
    $appNids = $appQuery->execute();
    $appNodes = $nodeStorage->loadMultiple($appNids);

    // Group apps by software UUID.
    $appsBySoftware = [];
    foreach ($appNodes as $app) {
      $softwareRef = $app->get('field_appverse_software_implemen')->entity;
      if ($softwareRef instanceof NodeInterface) {
        $appsBySoftware[$softwareRef->uuid()][] = $app;
      }
    }

    $result = [];
    foreach ($softwareNodes as $software) {
      $uuid = $software->uuid();
      $apps = $appsBySoftware[$uuid] ?? [];

      $result[] = [
        'id' => $uuid,
        'title' => $software->getTitle(),
        'slug' => basename($software->toUrl()->toString()),
        'nid' => (int) $software->id(),
        'logoUrl' => $this->getLogoUrl($software),
        'websiteUrl' => $software->get('field_appverse_software_website')->uri ?? NULL,
        'docsUrl' => $software->get('field_appverse_software_doc')->uri ?? NULL,
        'body' => $software->get('body')->processed ?? $software->get('body')->value ?? NULL,
        'topics' => $this->getTerms($software, 'field_appverse_topics'),
        'license' => $this->getTerm($software, 'field_license'),
        'tags' => $this->getTerms($software, 'field_tags'),
        'appCount' => count($apps),
        'apps' => array_map(fn($app) => $this->buildAppData($app, $uuid), $apps),
      ];
    }

    return $result;
  }

  /**
   * Build the repos array with nested member apps.
   *
   * @param array<mixed> $softwareData
   * @return array<mixed>
   */
  protected function buildReposData(array $softwareData): array {
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $nids = $nodeStorage->getQuery()
      ->condition('type', 'appverse_repo')
      ->condition('status', 1)
      ->sort('title')
      ->accessCheck(FALSE)
      ->execute();
    $repoNodes = $nodeStorage->loadMultiple($nids);

    // Build a flat lookup of apps by node id (Drupal internal NID) for member resolution.
    $appByNid = [];
    foreach ($softwareData as $sw) {
      foreach ($sw['apps'] as $app) {
        $appByNid[$app['nid']] = $app + ['softwareId' => $sw['id'], 'softwareTitle' => $sw['title']];
      }
    }

    // Build a reverse lookup: repo nid → list of member app nodes.
    // Apps without a field_appverse_software_implemen reference (e.g. apps
    // declared only via a repo's apps[]) are still members of the
    // repo — build minimal app data from the node directly.
    $appNidsByRepo = [];
    $memberAppQuery = $nodeStorage->getQuery()
      ->condition('type', 'appverse_app')
      ->condition('status', 1)
      ->exists('field_appverse_repo')
      ->accessCheck(FALSE);
    $this->applyRepoCascadeFilter($memberAppQuery);
    $appNids = $memberAppQuery->execute();
    $memberAppNodes = $nodeStorage->loadMultiple($appNids);
    foreach ($memberAppNodes as $app) {
      $repoRef = $app->get('field_appverse_repo')->entity;
      if ($repoRef instanceof NodeInterface) {
        $appNidsByRepo[(int) $repoRef->id()][] = (int) $app->id();
      }
    }

    $result = [];
    foreach ($repoNodes as $repo) {
      $nid = (int) $repo->id();
      $memberNids = $appNidsByRepo[$nid] ?? [];
      $memberApps = [];
      foreach ($memberNids as $appNid) {
        if (isset($appByNid[$appNid])) {
          $memberApps[] = $appByNid[$appNid];
        }
        elseif (isset($memberAppNodes[$appNid])) {
          // Not in $appByNid (which is keyed from PUBLISHED software only).
          // Two cases to tell apart:
          //  - The app genuinely has NO software ref → legitimate repo-only
          //    app, emit it with empty softwareId.
          //  - The app HAS a software ref but that software is unpublished
          //    (so it dropped out of $appByNid) → do NOT leak it into the
          //    public catalog with an empty softwareId; skip it.
          $app = $memberAppNodes[$appNid];
          if (!$app->get('field_appverse_software_implemen')->isEmpty()) {
            continue;
          }
          $memberApps[] = $this->buildAppData($app, '');
        }
      }

      // Slug: derived from the canonical URL's last segment. The pathauto
      // pattern '/repo/[node:title]' ensures this is a stable URL
      // slug like 'ood-apps-v3', not '/node/123'.
      $canonicalUrl = $repo->toUrl()->toString();
      $slug = basename($canonicalUrl);

      $result[] = [
        'id' => $repo->uuid(),
        'title' => $repo->getTitle(),
        'slug' => $slug,
        'nid' => $nid,
        'description' => $repo->get('field_repo_description')->value ?? '',
        'repoUrl' => $repo->get('field_repo_url')->uri ?? NULL,
        'maintainer' => [
          'name' => $repo->get('field_repo_maintainer_name')->value ?? '',
          'supportUrl' => $repo->get('field_repo_maintainer_url')->uri ?? NULL,
        ],
        'stars' => (int) ($repo->get('field_repo_stars')->value ?? 0),
        'lastUpdated' => $repo->get('field_repo_last_commit')->value
          ? (int) $repo->get('field_repo_last_commit')->value
          : NULL,
        'organization' => $this->getTerm($repo, 'field_repo_organization'),
        'wwwUrl' => $repo->get('field_repo_www_url')->uri ?? NULL,
        'docsUrl' => $repo->get('field_repo_docs_url')->uri ?? NULL,
        'readme' => $repo->get('field_repo_readme')->value ?? NULL,
        'tags' => $this->getTerms($repo, 'field_repo_tags'),
        'sharedPaths' => $this->getStringList($repo, 'field_repo_shared_paths'),
        'relatedRepos' => $this->getRelatedRepoUuids($repo),
        'validationStatus' => $repo->get('field_repo_validation_st')->value ?? 'valid',
        'validationErrors' => $this->getStringList($repo, 'field_repo_validation_er'),
        'lastSyncedAt' => $repo->get('field_repo_last_synced')->value
          ? date('c', (int) $repo->get('field_repo_last_synced')->value)
          : NULL,
        'apps' => $memberApps,
      ];
    }
    return $result;
  }

  /**
   * Apply the parent-Repo cascade filter to an Apps query.
   *
   * Hides Apps whose parent repo is unpublished. Drupal's
   * content_moderation keeps node.status in sync with moderation_state, so
   * a single status=0 check on the repo covers both the publish toggle
   * and any non-'published' moderation state — no separate moderation_state
   * check is needed.
   *
   * Legacy apps with NULL field_appverse_repo (pre-backfill) remain
   * visible — the cascade only fires when an explicit parent reference
   * exists and that parent is unpublished.
   */
  protected function applyRepoCascadeFilter(QueryInterface $appQuery): void {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $unpublishedRepoIds = $nodeStorage->getQuery()
      ->condition('type', 'appverse_repo')
      ->condition('status', 0)
      ->accessCheck(FALSE)
      ->execute();
    if (empty($unpublishedRepoIds)) {
      return;
    }
    $orphanGroup = $appQuery->orConditionGroup()
      ->notExists('field_appverse_repo')
      ->condition('field_appverse_repo', $unpublishedRepoIds, 'NOT IN');
    $appQuery->condition($orphanGroup);
  }

  /**
   * Get a list of scalar string values from a multi-valued string field.
   *
   * @return array<mixed>
   */
  protected function getStringList(NodeInterface $entity, string $fieldName): array {
    if (!$entity->hasField($fieldName) || $entity->get($fieldName)->isEmpty()) {
      return [];
    }
    $out = [];
    foreach ($entity->get($fieldName) as $item) {
      $out[] = $item->getValue()['value'] ?? NULL;
    }
    return $out;
  }

  /**
   * Get the UUIDs of related repos referenced by a repo node.
   *
   * @return array<mixed>
   */
  protected function getRelatedRepoUuids(NodeInterface $repo): array {
    if (!$repo->hasField('field_repo_related') || $repo->get('field_repo_related')->isEmpty()) {
      return [];
    }
    $uuids = [];
    foreach ($repo->get('field_repo_related')->referencedEntities() as $other) {
      $uuids[] = $other->uuid();
    }
    return $uuids;
  }

  /**
   * Mutate the software data in place, adding repoId/repoTitle on each app.
   *
   * Reverse lookup is computed server-side once per cache rebuild so the React
   * app doesn't need to walk repos to render "Part of X" lines.
   *
   * @param array<mixed> $repos
   * @param array<mixed> $software
   */
  protected function annotateAppsWithRepoBackRefs(array &$software, array $repos): void {
    $appToColl = [];
    foreach ($repos as $coll) {
      foreach ($coll['apps'] as $app) {
        $appToColl[$app['nid']] = ['id' => $coll['id'], 'title' => $coll['title']];
      }
    }
    foreach ($software as &$sw) {
      foreach ($sw['apps'] as &$app) {
        $ref = $appToColl[$app['nid']] ?? NULL;
        $app['repoId'] = $ref['id'] ?? NULL;
        $app['repoTitle'] = $ref['title'] ?? NULL;
      }
    }
  }

  /**
   * Build a single app data array.
   *
   * @return array<mixed>
   */
  protected function buildAppData(NodeInterface $app, string $softwareId): array {
    return [
      'id' => $app->uuid(),
      'title' => $app->getTitle(),
      'nid' => (int) $app->id(),
      'githubUrl' => $app->get('field_appverse_github_url')->uri ?? NULL,
      'stars' => (int) ($app->get('field_appverse_stars')->value ?? 0),
      'flagCount' => $app->hasField('flag_count') ? (int) ($app->get('flag_count')->value ?? 0) : 0,
      'lastUpdated' => $app->get('field_appverse_lastupdated')->value ? (int) $app->get('field_appverse_lastupdated')->value : NULL,
      'appTypes' => $this->getTerms($app, 'field_appverse_app_type'),
      'tags' => $this->getTerms($app, 'field_add_implementation_tags'),
      'organization' => $this->getTerm($app, 'field_appverse_organization'),
      'maintainerName' => $app->get('field_appverse_maintainer_name')->value ?? NULL,
      'review' => $this->reviewForApp($app),
      'softwareId' => $softwareId,
    ];
  }

  /**
   * Published review data per repo nid, memoised for one generation.
   *
   * @var array<int, array<mixed>|null>
   */
  protected array $reviewByRepo = [];

  /**
   * The public review object for an app, or NULL when the app's repo has no
   * Published review or the review has no verdict for this app.
   *
   * Only Published reviews are read: this file is world-readable, so a Draft
   * review's levels must never reach it. Levels are the stored field values,
   * which already include any reviewer override.
   *
   * @return array<mixed>
   */
  protected function reviewForApp(NodeInterface $app): ?array {
    $repo = $app->hasField('field_appverse_repo') ? $app->get('field_appverse_repo')->entity : NULL;
    if (!$repo instanceof NodeInterface) {
      return NULL;
    }
    $review = $this->publishedReviewFor($repo);
    if ($review === NULL) {
      return NULL;
    }
    $subpath = $app->hasField('field_appverse_app_subpath') ? (string) ($app->get('field_appverse_app_subpath')->value ?? '') : '';
    $verdict = ReviewSignals::pickVerdict($review['verdicts'], (int) $app->id(), $subpath);
    $lastCommit = $repo->get('field_repo_last_commit')->value ? (int) $repo->get('field_repo_last_commit')->value : NULL;
    return ReviewSignals::shape($review, $verdict, $lastCommit);
  }

  /**
   * The newest Published appverse_review for a repo, as plain arrays.
   *
   * @return array<mixed>
   */
  protected function publishedReviewFor(NodeInterface $repo): ?array {
    $nid = (int) $repo->id();
    if (array_key_exists($nid, $this->reviewByRepo)) {
      return $this->reviewByRepo[$nid];
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appverse_review')
      ->condition('status', 1)
      ->condition('field_arv_repo', $nid)
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();
    $review = $ids ? $storage->load(reset($ids)) : NULL;
    if (!$review instanceof NodeInterface) {
      return $this->reviewByRepo[$nid] = NULL;
    }
    $verdicts = [];
    foreach ($review->get('field_arv_verdicts')->referencedEntities() as $verdict) {
      $axes = [];
      foreach (['portability' => 'port', 'documentation' => 'docs'] as $axis => $prefix) {
        $axes[$axis] = [
          'level' => $verdict->get("field_rvv_{$prefix}_level")->value,
          'summary' => (string) ($verdict->get("field_rvv_{$prefix}_summary")->value ?? ''),
          'anchor' => (string) ($verdict->get("field_rvv_{$prefix}_anchor")->value ?? ''),
        ];
      }
      $verdicts[] = [
        'app_ref' => $verdict->get('field_rvv_app_ref')->target_id !== NULL ? (int) $verdict->get('field_rvv_app_ref')->target_id : NULL,
        'app_id' => (string) ($verdict->get('field_rvv_app_id')->value ?? ''),
        'axes' => $axes,
      ];
    }
    return $this->reviewByRepo[$nid] = [
      'reviewed_at' => (int) ($review->get('field_arv_reviewed_at')->value ?? $review->getCreatedTime()),
      'sha' => (string) ($review->get('field_arv_sha')->value ?? ''),
      'url' => Url::fromRoute('ood_software.review_page', ['node' => $review->id()])->toString(),
      'upkeep' => [
        'level' => $review->get('field_arv_maint_level')->value,
        'summary' => (string) ($review->get('field_arv_maint_summary')->value ?? ''),
        'anchor' => (string) ($review->get('field_arv_maint_anchor')->value ?? ''),
      ],
      'verdicts' => $verdicts,
    ];
  }

  /**
   * Get multiple taxonomy terms from an entity reference field.
   *
   * @return array<mixed>
   */
  protected function getTerms(FieldableEntityInterface $entity, string $fieldName): array {
    if (!$entity->hasField($fieldName) || $entity->get($fieldName)->isEmpty()) {
      return [];
    }

    $terms = [];
    foreach ($entity->get($fieldName)->referencedEntities() as $term) {
      $terms[] = [
        'id' => $term->uuid(),
        'name' => $term->getName(),
      ];
    }
    return $terms;
  }

  /**
   * Get a single taxonomy term from an entity reference field.
   *
   * @return array<mixed>
   */
  protected function getTerm(FieldableEntityInterface $entity, string $fieldName): ?array {
    $terms = $this->getTerms($entity, $fieldName);
    return $terms[0] ?? NULL;
  }

  /**
   * Get logo URL from a software node's media field.
   *
   * Delegates to the shared AppverseLogoUrl service. The feed is a static file
   * with no render cacheability, so no cache tags are accumulated.
   */
  protected function getLogoUrl(NodeInterface $software): ?string {
    // Relative URL — the React app prepends siteBaseUrl.
    return $this->appverseLogoUrl->get($software);
  }

  /**
   * Extract unique filter options from the built software data.
   *
   * @param array<mixed> $softwareList
   * @param array<mixed> $repos
   *
   * @return array<mixed>
   */
  protected function extractFilterOptions(array $softwareList, array $repos): array {
    $topics = [];
    $licenses = [];
    $tags = [];
    $appTypes = [];
    $organizations = [];

    foreach ($softwareList as $sw) {
      foreach ($sw['topics'] as $t) {
        $topics[$t['id']] = $t;
      }
      if ($sw['license']) {
        $licenses[$sw['license']['id']] = $sw['license'];
      }
      foreach ($sw['tags'] as $t) {
        $tags[$t['id']] = $t;
      }
      foreach ($sw['apps'] as $app) {
        foreach ($app['tags'] as $t) {
          $tags[$t['id']] = $t;
        }
        foreach ($app['appTypes'] as $t) {
          $appTypes[$t['id']] = $t;
        }
      }
    }
    foreach ($repos as $c) {
      if (!empty($c['organization'])) {
        $organizations[$c['organization']['id']] = $c['organization'];
      }
    }

    $sort = fn($a, $b) => strcasecmp($a['name'], $b['name']);
    foreach ([&$topics, &$licenses, &$tags, &$appTypes, &$organizations] as &$list) {
      $list = array_values($list);
      usort($list, $sort);
    }

    return compact('topics', 'licenses', 'tags', 'appTypes', 'organizations');
  }

}
