<?php

namespace Drupal\ood_software\Commands;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Plugin\GitHubService;
use Drupal\ood_software\Service\RepoMemberApps;
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
   * The repo member app helper.
   *
   * @var \Drupal\ood_software\Service\RepoMemberApps
   */
  protected $memberApps;

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
   * @param \Drupal\ood_software\Service\RepoMemberApps $member_apps
   *   The repo member app helper.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    RepoSyncService $repo_sync,
    GitHubService $github,
    LoggerChannelFactoryInterface $logger_factory,
    RepoMemberApps $member_apps,
  ) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
    $this->repoSync = $repo_sync;
    $this->github = $github;
    $this->memberApps = $member_apps;
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
   * @phpstan-param array<string, mixed> $options
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
   * @param array<string, mixed> $context
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
        // @phpstan-ignore-next-line
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
   * @param array<string, mixed> $results
   *   The accumulated $context['results'] from processChunk.
   * @param array<int, mixed> $operations
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

  /**
   * Report nodes that lost their moderation state, and repair listed ones.
   *
   * Sending a repo for review used to re-save it inside its own save, which
   * gave the node a second content_moderation_state record in draft. The repo
   * fell back to draft and its member apps were never sent on. A node with
   * more than one record is a candidate. Dry run unless --apply is given.
   *
   * The lost_state column is the state of the node revision just before the
   * newest record began. member_apps lists the repo's apps (default
   * revisions, the ones the cascade acts on) as state:count pairs, sorted by
   * state name (for example draft:2,published:1).
   *
   * The action column is one of these. restore: the node is now in draft and
   * lost_state is ready_for_review or needs_adjustment. cascade: a repo is in
   * ready_for_review but has member apps in draft or needs_adjustment, because
   * the cascade was skipped when it was sent. skip: nothing to repair. After
   * --apply the repaired rows read restored or cascaded, and a listed nid that
   * is not a candidate at all gets a not-a-candidate row. A listed node that
   * is skipped is logged as a warning.
   *
   * Applying a restore saves a new revision in the lost state, attributed to
   * the current user (user 1 for drush without -u), without email or a new AI
   * review, and sends a restored repo's draft and needs_adjustment apps to
   * ready_for_review. Applying a cascade only moves the member apps and does
   * not save the repo. Moving the apps sends no email either, including the
   * "New Appverse App submitted" notification. The duplicate records stay,
   * since deleting them would erase the state history of older revisions.
   *
   * Check edits_since and last_edit before listing a nid for --apply.
   * edits_since counts the node revisions saved after the bad record began, so
   * 0 means nothing happened since. A higher number, or a last_edit by a
   * person, can mean someone moved the node to draft on purpose, and a restore
   * would undo that.
   *
   * Operator order: run the repo dry run, apply the repos you checked, then
   * run the app dry run (--bundle=appverse_app) for standalone apps.
   *
   * @param array $options
   *   Command options.
   *
   * @phpstan-param array<string, mixed> $options
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   One row per affected node.
   *
   * @command appverse:repair-moderation
   * @option bundle Content type to check, appverse_repo or appverse_app.
   * @option nids Comma-separated node IDs to repair. Required with --apply.
   * @option apply Repair the listed nodes instead of only reporting.
   * @field-labels
   *   nid: Nid
   *   title: Title
   *   current: Current
   *   lost_state: Lost state
   *   member_apps: Member apps
   *   edits_since: Edits since
   *   last_edit: Last edit
   *   action: Action
   * @default-fields nid,title,current,lost_state,member_apps,edits_since,last_edit,action
   * @usage drush appverse:repair-moderation
   *   List affected repos without changing anything.
   * @usage drush appverse:repair-moderation --nids=12,34 --apply
   *   Repair repos 12 and 34 and send their apps for review.
   */
  public function repairModeration(array $options = ['bundle' => 'appverse_repo', 'nids' => '', 'apply' => FALSE]): RowsOfFields {
    $bundle = (string) ($options['bundle'] ?? 'appverse_repo');
    if (!in_array($bundle, ['appverse_repo', 'appverse_app'], TRUE)) {
      throw new \InvalidArgumentException('--bundle must be appverse_repo or appverse_app.');
    }
    $listed = array_values(array_filter(array_map('intval', explode(',', (string) ($options['nids'] ?? '')))));
    $apply = !empty($options['apply']);
    if ($apply && !$listed) {
      throw new \InvalidArgumentException('--apply needs --nids with the nodes to restore. Run without --apply first to see them.');
    }

    $rows = [];
    foreach ($this->duplicateStateNids($bundle) as $nid) {
      $row = $this->describeAffectedNode($nid);
      if ($apply && in_array($nid, $listed, TRUE)) {
        if ($row['action'] === 'restore') {
          $this->restoreNode($nid, $row['lost_state']);
          $row['action'] = 'restored';
        }
        elseif ($row['action'] === 'cascade') {
          $this->cascadeToApps($nid);
          $row['action'] = 'cascaded';
        }
      }
      $rows[] = $row;
    }
    if ($apply) {
      $seen = array_column($rows, 'nid');
      foreach ($listed as $nid) {
        if (!in_array($nid, $seen, TRUE)) {
          $this->oodLogger->warning('Node @nid was listed in --nids but is not a @bundle with a duplicate moderation record, so it was left alone.', [
            '@nid' => $nid,
            '@bundle' => $bundle,
          ]);
          $rows[] = [
            'nid' => $nid,
            'title' => '',
            'current' => '',
            'lost_state' => '',
            'member_apps' => '',
            'edits_since' => '',
            'last_edit' => '',
            'action' => 'not-a-candidate',
          ];
        }
      }
      foreach ($rows as $row) {
        if (in_array($row['nid'], $listed, TRUE) && $row['action'] === 'skip') {
          $this->oodLogger->warning('Node @nid was listed in --nids but has nothing to repair, so it was left alone.', [
            '@nid' => $row['nid'],
          ]);
        }
      }
    }
    return new RowsOfFields($rows);
  }

  /**
   * IDs of nodes of a bundle that have more than one moderation record.
   *
   * @return int[]
   *   Node IDs, ascending.
   */
  protected function duplicateStateNids(string $bundle): array {
    $storage = $this->entityTypeManager->getStorage('content_moderation_state');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('content_entity_type_id', 'node')
      ->condition('workflow', 'appverse_editorial')
      ->execute();
    $perNode = [];
    foreach (array_chunk($ids, 200) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $state) {
        $perNode[(int) $state->get('content_entity_id')->value][] = (int) $state->id();
      }
    }
    $nids = array_keys(array_filter($perNode, fn (array $records) => count($records) > 1));
    if (!$nids) {
      return [];
    }
    $matching = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->condition('nid', $nids, 'IN')
      ->sort('nid')
      ->execute();
    return array_values(array_map('intval', $matching));
  }

  /**
   * Builds the report row for one affected node.
   *
   * @return array<string, mixed>
   *   The row fields.
   */
  protected function describeAffectedNode(int $nid): array {
    $node = $this->latestRevision($nid);
    $current = (string) $node->get('moderation_state')->value;
    $lost = $this->lostState($nid);
    $apps = '';
    $repairable = 0;
    if ($node->bundle() === 'appverse_repo') {
      $counts = [];
      // Default revisions, the ones cascadeModeration() loads and acts on.
      $appStorage = $this->entityTypeManager->getStorage('node');
      $appStorage->resetCache();
      foreach ($appStorage->loadMultiple($this->memberApps->queryMemberAppIds($node)) as $app) {
        $state = (string) $app->get('moderation_state')->value;
        $counts[$state] = ($counts[$state] ?? 0) + 1;
        if (in_array($state, ['draft', 'needs_adjustment'], TRUE)) {
          $repairable++;
        }
      }
      ksort($counts);
      $apps = implode(',', array_map(fn ($state, $count) => "$state:$count", array_keys($counts), $counts));
    }
    $action = 'skip';
    if ($current === 'draft' && in_array($lost, ['ready_for_review', 'needs_adjustment'], TRUE)) {
      $action = 'restore';
    }
    elseif ($current === 'ready_for_review' && $repairable > 0) {
      $action = 'cascade';
    }
    $start = $this->newestRecordStart($nid);
    $editsSince = '';
    if ($start !== NULL) {
      $editsSince = (int) $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->allRevisions()
        ->condition('nid', $nid)
        ->condition('vid', $start, '>')
        ->count()
        ->execute();
    }
    $log = (string) $node->getRevisionLogMessage();
    return [
      'nid' => $nid,
      'title' => (string) $node->label(),
      'current' => $current,
      'lost_state' => $lost,
      'member_apps' => $apps,
      'edits_since' => $editsSince,
      'last_edit' => $node->getRevisionUserId() . ': ' . mb_substr($log, 0, 60),
      'action' => $action,
    ];
  }

  /**
   * The node's latest revision.
   */
  protected function latestRevision(int $nid): NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $storage->resetCache([$nid]);
    $revision = $storage->loadRevision($storage->getLatestRevisionId($nid));
    assert($revision instanceof NodeInterface);
    return $revision;
  }

  /**
   * The node revision id at which the newest moderation record began.
   *
   * NULL when it cannot be worked out.
   */
  protected function newestRecordStart(int $nid): ?int {
    $stateStorage = $this->entityTypeManager->getStorage('content_moderation_state');
    $ids = $stateStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('content_entity_type_id', 'node')
      ->condition('content_entity_id', $nid)
      ->condition('workflow', 'appverse_editorial')
      ->execute();
    if (!$ids) {
      return NULL;
    }
    $newest = (int) max($ids);
    $revisionIds = $stateStorage->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('id', $newest)
      ->execute();
    $first = NULL;
    foreach (array_keys($revisionIds) as $revisionId) {
      $state = $stateStorage->loadRevision($revisionId);
      $vid = $state instanceof ContentEntityInterface ? (int) $state->get('content_entity_revision_id')->value : 0;
      if ($vid && ($first === NULL || $vid < $first)) {
        $first = $vid;
      }
    }
    return $first;
  }

  /**
   * The state the node had before its newest moderation record began.
   *
   * Empty when it cannot be worked out.
   */
  protected function lostState(int $nid): string {
    $firstNodeRevision = $this->newestRecordStart($nid);
    if ($firstNodeRevision === NULL) {
      return '';
    }
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $before = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('nid', $nid)
      ->condition('vid', $firstNodeRevision, '<')
      ->sort('vid', 'DESC')
      ->range(0, 1)
      ->execute();
    if (!$before) {
      return '';
    }
    $revision = $nodeStorage->loadRevision((int) array_key_first($before));
    return $revision instanceof NodeInterface ? (string) $revision->get('moderation_state')->value : '';
  }

  /**
   * Saves the node back into its lost state, and its apps along with a repo.
   */
  protected function restoreNode(int $nid, string $state): void {
    $node = $this->latestRevision($nid);
    $node->set('moderation_state', $state);
    $node->setNewRevision(TRUE);
    $node->setRevisionLogMessage('Restored moderation state lost to a duplicate moderation record.');
    // Attribute the restore: drush without -u runs as anonymous.
    // @phpstan-ignore-next-line
    $uid = (int) \Drupal::currentUser()->id();
    $node->setRevisionUserId($uid ?: 1);
    // @phpstan-ignore-next-line
    $node->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    // Not a contributor submitting: no email and no new AI review.
    // @phpstan-ignore-next-line
    $node->_ood_software_suppress_notifications = TRUE;
    if (method_exists($node, 'setValidationRequired')) {
      $node->setValidationRequired(FALSE);
    }
    $node->save();
    $this->oodLogger->notice('Restored node @nid to @state.', ['@nid' => $nid, '@state' => $state]);
    if ($node->bundle() === 'appverse_repo' && $state === 'ready_for_review') {
      $this->cascadeToApps($nid, $node);
    }
  }

  /**
   * Sends a repo's draft and needs_adjustment apps to ready_for_review.
   */
  protected function cascadeToApps(int $nid, ?NodeInterface $repo = NULL): void {
    $repo ??= $this->latestRevision($nid);
    $count = $this->memberApps->cascadeModeration($repo, 'ready_for_review', ['draft', 'needs_adjustment'], 'Restored with the repo.', FALSE, TRUE);
    $this->oodLogger->notice('Sent @count member apps of node @nid for review.', ['@count' => $count, '@nid' => $nid]);
  }

}
