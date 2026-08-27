<?php

namespace Drupal\ood_software\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ood_software\Plugin\GitHubService;
use Drupal\ood_software\Service\RepoSyncService;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for backfilling legacy Apps with inferred Repos.
 */
class AppverseBackfillCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The Repo sync service.
   *
   * @var \Drupal\ood_software\Service\RepoSyncService
   */
  protected $repoSync;

  /**
   * The GitHub service.
   *
   * @var \Drupal\ood_software\Plugin\GitHubService
   */
  protected $github;

  /**
   * The ood_software logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $oodLogger;

  /**
   * Constructs an AppverseBackfillCommands object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\ood_software\Service\RepoSyncService $repo_sync
   *   The Repo sync service.
   * @param \Drupal\ood_software\Plugin\GitHubService $github
   *   The GitHub service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    RepoSyncService $repo_sync,
    GitHubService $github,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
    $this->repoSync = $repo_sync;
    $this->github = $github;
    // Drush asserts that $this->logger stays under its LoggerManager
    // control, so we keep the module's logger channel under a separate
    // property name. Use $this->logger() for command-line output and
    // $this->oodLogger for structured module logging if needed.
    $this->oodLogger = $logger_factory->get('ood_software');
  }

  /**
   * Create inferred Repos for legacy Apps with no parent.
   *
   * @param array $options
   *   Command options.
   *
   * @command appverse:backfill-inferred-repos
   * @aliases appverse-backfill
   * @option chunk Number of Apps to process per batch operation. Default 25.
   * @usage drush appverse:backfill-inferred-repos
   *   Process all Apps with NULL field_appverse_repo.
   */
  public function backfill(array $options = ['chunk' => 25]): void {
    $chunk = (int) ($options['chunk'] ?? 25);
    if ($chunk < 1) {
      $chunk = 25;
    }

    // Backfill ALL Apps regardless of status (published or unpublished).
    // Every App should have a parent Repo per the spec's "every
    // App belongs to a Repo" rule. Unpublished legacy Apps with
    // no parent Repo would be invisible in the hub today; the
    // backfill makes them visible.
    $appIds = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appverse_app')
      ->notExists('field_appverse_repo')
      ->execute();

    $total = count($appIds);
    if ($total === 0) {
      $this->logger()->success('No Apps need backfill. All appverse_app nodes already have field_appverse_repo set.');
      $this->oodLogger->info('Backfill skipped: no Apps need backfilling.');
      return;
    }

    $this->logger()->notice("Backfilling $total Apps in chunks of $chunk.");
    $this->oodLogger->info('Backfill started: @total Apps in chunks of @chunk.', ['@total' => $total, '@chunk' => $chunk]);

    $chunks = array_chunk(array_values($appIds), $chunk);
    $operations = [];
    foreach ($chunks as $chunkOfIds) {
      $operations[] = [
        [self::class, 'processChunk'],
        [$chunkOfIds],
      ];
    }

    batch_set([
      'title' => 'Backfilling inferred Repos',
      'operations' => $operations,
      'finished' => [self::class, 'finishedCallback'],
    ]);

    drush_backend_batch_process();
  }

  /**
   * Batch operation: process one chunk of App IDs.
   *
   * @param int[] $appIds
   *   App node IDs to process in this chunk.
   * @param array $context
   *   The batch context array.
   */
  public static function processChunk(array $appIds, array &$context): void {
    $em = \Drupal::entityTypeManager();
    $sync = \Drupal::service('ood_software.repo_sync');
    $github = \Drupal::service('ood_software.gh');
    $logger = \Drupal::logger('ood_software');

    foreach ($em->getStorage('node')->loadMultiple($appIds) as $app) {
      try {
        // Link fields store {uri, title, options}. Match the module's
        // convention of reading getValue()[0]['uri'].
        $linkValues = $app->get('field_appverse_github_url')->getValue();
        $repoUrl = $linkValues[0]['uri'] ?? NULL;
        if (empty($repoUrl)) {
          $logger->warning('Skipping App @nid: no repo URL.', ['@nid' => $app->id()]);
          $context['results']['skipped'][] = $app->id();
          continue;
        }

        // Fetch repo metadata so resolveRepo can populate the
        // new Repo with stars / last commit / org / isArchived.
        // If the fetch fails (404, private), still create a thin
        // Repo so the App has a parent — re-syncs later will
        // populate.
        $repoMetadata = [];
        try {
          if ($github->parseUrl($repoUrl)) {
            // parseUrl() already calls fetchRepoData() internally — calling it
            // again here would double the GitHub API request volume during
            // backfill.
            $repoMetadata = [
              'stars' => $github->getStars(),
              'lastCommittedDate' => $github->getLastComittedDate(),
              'organization' => $github->getOrganization(),
              'description' => $github->getDescription(),
              'readme' => $github->getReadme(),
              'license' => $github->getLicense(),
              'licenseLink' => $github->getLicenseLink(),
              // If the legacy app's repo is already archived on GitHub,
              // sync should auto-archive the new Repo (cascading
              // to this App). Without this, archived-repo apps would
              // backfill into 'draft' Repos, which is wrong.
              'isArchived' => $github->getIsArchived(),
            ];
          }
          else {
            $logger->info('Backfill: parseUrl rejected @url, proceeding with empty metadata.', ['@url' => $repoUrl]);
          }
        }
        catch (\Throwable $e) {
          $logger->info('Backfill: could not fetch metadata for @url (@msg) — proceeding with empty metadata.', [
            '@url' => $repoUrl,
            '@msg' => $e->getMessage(),
          ]);
        }

        // resolveRepo with NULL appverse.yml -> applyInferred path.
        $repo = $sync->resolveRepo($repoUrl, NULL, $repoMetadata);

        // Suppress publish/transition notifications for backfilled repos:
        // this is a data migration, not a contributor-facing publish event,
        // and emailing every legacy app's owner would be noise (and, on envs
        // with no working SMTP, the per-repo send stalls the whole run). The
        // flag is read in ood_software_node_update(); it's a runtime property
        // on this same $repo object, so it covers every save below.
        $repo->_ood_software_suppress_notifications = TRUE;

        // Inherit the app's owner so the contributor's maintenance hub
        // surfaces the new Repo. First-app-wins for monorepo cases —
        // siblings from earlier iterations keep their existing owner.
        if ((int) $repo->getOwnerId() === 0) {
          $repo->setOwnerId((int) $app->getOwnerId());
          $repo->save();
        }

        // The default moderation state for newly-created Repos is
        // 'draft' (set by RepoSyncService). Legacy apps being
        // backfilled are already published; if we leave the parent
        // Repo in draft, the cache cascade hides the app. Override
        // to 'published' so visibility stays aligned with the app's
        // existing state. Don't override if applyInferred already set
        // 'archived' (i.e. the upstream repo is archived) — that state
        // should propagate.
        if ($app->isPublished() && $repo->get('moderation_state')->value !== 'archived') {
          $repo->set('moderation_state', 'published');
          $repo->save();
        }

        $app->set('field_appverse_repo', $repo->id());
        $app->save();

        $context['results']['linked'][] = $app->id();
      }
      catch (\Throwable $e) {
        $logger->error('Backfill failed for App @nid: @msg', [
          '@nid' => $app->id(),
          '@msg' => $e->getMessage(),
        ]);
        $context['results']['failed'][] = $app->id();
      }
    }
  }

  /**
   * Batch finished callback: report counts to the messenger.
   *
   * @param bool $success
   *   Whether the batch completed without fatal errors.
   * @param array $results
   *   The accumulated $context['results'] from processChunk.
   * @param array $operations
   *   Operations that did not complete (only set if $success is FALSE).
   */
  public static function finishedCallback(bool $success, array $results, array $operations): void {
    $linked = count($results['linked'] ?? []);
    $skipped = count($results['skipped'] ?? []);
    $failed = count($results['failed'] ?? []);
    \Drupal::messenger()->addStatus("Backfill complete. Linked: $linked. Skipped: $skipped. Failed: $failed.");

    // The per-item saves above each marked the cache dirty via hook. Batch
    // operations may not dispatch kernel.terminate the way a web request does,
    // so flush explicitly here — once, after the whole backfill settles.
    \Drupal::service('ood_software.appverse_cache')->flushIfDirty();
  }

}
