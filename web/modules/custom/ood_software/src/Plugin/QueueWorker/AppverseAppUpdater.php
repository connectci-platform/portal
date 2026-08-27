<?php

namespace Drupal\ood_software\Plugin\QueueWorker;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ood_software\Plugin\GitHubService;
use Drupal\ood_software\Service\RepoSyncService;
use Drupal\node\NodeInterface;

/**
 * Processes Appverse App nodes to update GitHub data.
 *
 * @QueueWorker(
 *   id = "appverse_app_updater",
 *   title = @Translation("Appverse App Updater"),
 *   cron = {"time" = 60}
 * )
 */
final class AppverseAppUpdater extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The GitHub service.
   *
   * @var \Drupal\ood_software\Plugin\GitHubService
   */
  protected $githubService;

  /**
   * The Repo sync service.
   *
   * @var \Drupal\ood_software\Service\RepoSyncService
   */
  protected $repoSync;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * Constructs a new AppverseAppUpdater object.
   *
   * @param array<string, mixed> $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\ood_software\Plugin\GitHubService $github_service
   *   The GitHub service.
   * @param \Drupal\ood_software\Service\RepoSyncService $repo_sync
   *   The Repo sync service.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The ood_software logger channel.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, GitHubService $github_service, RepoSyncService $repo_sync, LoggerChannelInterface $logger, TimeInterface $time) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->githubService = $github_service;
    $this->repoSync = $repo_sync;
    $this->logger = $logger;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $configuration
   *   A configuration array containing information about the plugin instance.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('ood_software.gh'),
      $container->get('ood_software.repo_sync'),
      $container->get('logger.factory')->get('ood_software'),
      $container->get('datetime.time')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $nid = $data['nid'];
    $node = $this->entityTypeManager->getStorage('node')->load($nid);

    if (!$node) {
      return;
    }

    $github_url = $node->get('field_appverse_github_url')->first()?->getValue()['uri'] ?? NULL;

    $validUrl = $this->githubService->parseUrl($github_url);
    if ($validUrl) {
      $this->githubService->getData();
      $lastupdated = $node->get('field_appverse_lastupdated')->value;
      $needsSave = FALSE;

      // Auto-archive when the app's own GitHub repo is archived. RepoSyncService
      // cascades archive from a Repo to its member apps, but that only covers
      // apps synced under a declared Repo. A standalone/legacy app node (its own
      // field_appverse_github_url, not a member under a synced declared Repo)
      // is never visited by that cascade, so an archived-upstream app would stay
      // published indefinitely. This worker runs on every app node, so enforce
      // the same one-way archive contract here: archived on GitHub → archived in
      // the catalog. One-way — a later un-archive on GitHub does NOT auto-restore;
      // re-submit is the recovery path (mirrors RepoSyncService).
      if ($this->githubService->getIsArchived()
        && $node->hasField('moderation_state')
        && $node->get('moderation_state')->value !== 'archived') {
        $node->set('moderation_state', 'archived');
        $needsSave = TRUE;
        $this->logger->info('Auto-archiving App @nid — repo @url is archived on GitHub.', [
          '@nid' => $nid,
          '@url' => $github_url,
        ]);
      }

      $appverseYmlText = $this->githubService->getAppverseYmlText();
      $repoMetadata = [
        'name' => $this->githubService->getRepoName(),
        'description' => $this->githubService->getRepoDescription(),
        'organization' => $this->githubService->getOrganization(),
        'stars' => $this->githubService->getStars(),
        'lastCommittedDate' => $this->githubService->getLastComittedDate(),
        'readme' => $this->githubService->getReadme(),
      ];

      // A SHAPE CHANGE is when the repo's stored shape no longer matches the
      // repo's current state on GitHub — EITHER direction:
      //   inferred (registered without appverse.yml) → now HAS one, or
      //   declared (registered with appverse.yml)    → now LOST it.
      // Cron must NOT transform shape unattended in either direction — doing so
      // is inherently hazardous (a transient GitHub failure would degrade the
      // whole repo; reshaping orphans or deletes live members). Detect it and
      // flag for the attended Resync instead.
      $existingRepo = $this->repoSync->findRepoByUrl($this->githubService->getRepoUrl());
      $storedShape = $existingRepo ? ($existingRepo->get('field_repo_shape')->value ?? NULL) : NULL;
      $nowDeclared = $this->githubService->isDeclaredRepo();
      $shapeChangePending = $existingRepo && (
        ($storedShape === 'inferred' && $nowDeclared)
        || ($storedShape === 'declared' && !$nowDeclared)
      );

      if ($shapeChangePending) {
        // Do NOT resolveRepo with the appverse.yml (that would reshape/half-
        // apply). Leave the repo as-is and flag it so the maintainer/admin runs
        // the attended Resync, which transforms it safely with a progress UI.
        $repo = $existingRepo;
        $this->flagShapeChange($repo);
      }
      else {
        // Normal path: refresh the repo (metadata, and the shape it already is).
        $repo = $this->repoSync->resolveRepo(
          $this->githubService->getRepoUrl(),
          $appverseYmlText,
          $repoMetadata
        );
      }

      $currentRepoId = $node->get('field_appverse_repo')->target_id;
      if ((int) $currentRepoId !== (int) $repo->id()) {
        $node->set('field_appverse_repo', $repo->id());
        $needsSave = TRUE;
      }

      // Always update stars.
      $currentStars = $node->get('field_appverse_stars')->value;
      $newStars = $this->githubService->getStars();
      if ($currentStars === NULL || $currentStars !== $newStars) {
        $node->set('field_appverse_stars', $newStars);
        $needsSave = TRUE;
      }

      // Update root-derived content fields if the repo has new commits — but
      // ONLY for apps whose content comes from the repo root. A monorepo
      // member app (non-empty field_appverse_app_subpath) gets its body,
      // README, and app_type from its OWN subpath via RepoSyncService, which
      // owns those fields. This worker reads root-level data only, so syncing
      // it here would clobber the member's per-subpath values with root (often
      // empty) content. Skip the block for member apps; stars and the repo
      // link above still apply to every app.
      $isMemberApp = !$node->get('field_appverse_app_subpath')->isEmpty();
      if (!$isMemberApp && $lastupdated != $this->githubService->getLastComittedDate()) {
        // A repo that lost (or never had) a root manifest.yml returns an empty
        // description/README; guard the writes so an empty source can't blank a
        // good catalog value.
        $newDescription = $this->githubService->getDescription();
        if ($newDescription !== NULL && $newDescription !== '') {
          $node->set('body', [['format' => 'markdown', 'value' => $newDescription]]);
        }
        $newReadme = $this->githubService->getReadme();
        if ($newReadme !== NULL && $newReadme !== '') {
          $node->set('field_appverse_readme', [['format' => 'markdown', 'value' => $newReadme]]);
        }
        $node->set('field_appverse_lastupdated', [['value' => $this->githubService->getLastComittedDate()]]);
        $needsSave = TRUE;

        // Sync auto-detected app types, preserving manually-added terms.
        $autoDetectedIds = $this->githubService->getAppTypeIds();
        $currentValues = $node->get('field_appverse_app_type')->getValue();
        $currentIds = array_map(fn($v) => (int) $v['target_id'], $currentValues);

        // Determine which current IDs are auto-assignable (managed by sync).
        $autoAssignableNames = GitHubService::getAutoAssignableTermNames();
        $autoAssignableIds = [];
        if (!empty($autoAssignableNames)) {
          $terms = $this->entityTypeManager->getStorage('taxonomy_term')
            ->loadByProperties(['name' => $autoAssignableNames, 'vid' => 'appverse_app_type']);
          foreach ($terms as $term) {
            $autoAssignableIds[] = (int) $term->id();
          }
        }

        // Manual IDs = current IDs NOT in the auto-assignable set.
        $manualIds = array_diff($currentIds, $autoAssignableIds);

        // New set = manual + newly auto-detected.
        $newIds = array_values(array_unique(array_merge($manualIds, $autoDetectedIds)));
        sort($newIds);

        $sortedCurrentIds = $currentIds;
        sort($sortedCurrentIds);
        if ($newIds !== $sortedCurrentIds) {
          $node->set('field_appverse_app_type', array_map(fn($id) => ['target_id' => $id], $newIds));
        }
      }

      if ($needsSave) {
        $node->save();
      }
    }
    else {
      // parseUrl() failed: the repo is gone, private, rate-limited, or its
      // URL no longer parses. Previously the app was skipped silently, hiding
      // the fact that a catalogued app's upstream is unreachable. Log it so
      // it's visible; leave the node untouched (a transient rate-limit
      // shouldn't mutate catalog state).
      $this->logger->warning('Cron app update skipped: could not fetch @url for app @nid (repo deleted, private, rate-limited, or invalid URL).', [
        '@url' => $github_url,
        '@nid' => $nid,
      ]);
    }
  }

  /**
   * Flag a repo whose shape changed (either direction) for attended Resync.
   *
   * Cron never transforms shape itself. It marks the repo degraded with a
   * message so the hub surfaces it and the maintainer/admin runs Resync, which
   * rebuilds the members safely with a progress UI. Idempotent — re-flagging an
   * already-flagged repo is a no-op save-wise (same values).
   *
   * @param \Drupal\node\NodeInterface $repo
   *   The repo node to flag.
   */
  protected function flagShapeChange(NodeInterface $repo): void {
    // field_repo_validation_st + _er are PUBLISHED into the public catalog
    // (AppverseCacheService::buildReposData), so the note must be neutral — no
    // admin-only "click Resync" instruction. The admin sees the 'degraded'
    // state in the hub and knows to resync; the detail goes to the log.
    $note = "The repo's app structure changed and needs to be re-synced.";
    $currentSt = $repo->get('field_repo_validation_st')->value ?? NULL;
    $existing = array_column($repo->get('field_repo_validation_er')->getValue(), 'value');

    // Idempotent: already flagged with this note → no churn.
    if ($currentSt === 'degraded' && in_array($note, $existing, TRUE)) {
      return;
    }

    $repo->set('field_repo_validation_st', 'degraded');
    // APPEND — don't clobber pre-existing errors (rejected child apps, prior
    // failures) that the maintainer still needs to see.
    if (!in_array($note, $existing, TRUE)) {
      $existing[] = $note;
    }
    $repo->set('field_repo_validation_er', $existing);
    // Match every other validation-state write in the codebase, which stamps
    // last_synced so the hub reflects when the state was last evaluated.
    $repo->set('field_repo_last_synced', $this->time->getCurrentTime());
    $repo->save();
    $this->logger->notice('Repo @nid appverse.yml shape changed since registration; flagged degraded for attended Resync.', ['@nid' => $repo->id()]);
  }

}
