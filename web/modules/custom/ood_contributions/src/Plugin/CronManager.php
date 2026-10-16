<?php

namespace Drupal\ood_contributions\Plugin;

use Drupal\ood_contributions\Service\DiscourseAlertNotifier;

/**
 * Manages cron job callbacks for the the contributions module.
 */
class CronManager {

  /**
   * Store github stats.
   */
  public static function storeStats() {
    // Lookup users in the Open OnDemand region who have a GitHub username.
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
      'name' => 'Open OnDemand',
      'vid' => 'region',
    ]);
    $term = reset($terms);
    if (!$term) {
      \Drupal::logger('ood_contributions')->error('Region term "Open OnDemand" not found.');
      return;
    }

    $entity_query = \Drupal::entityQuery('user')
      ->condition('status', 1)
      ->condition('field_region', $term->id())
      ->accessCheck(FALSE)
      ->exists('field_github_username');
    $uids = $entity_query->execute();

    $repos = [
      'ondemand',
      'ood_core',
      'ondemand-packaging',
      'ood-ansible',
      'puppet-module-openondemand',
      'ood-documentation',
    ];

    $commit_storage = \Drupal::service('ood_contributions.commit_storage');
    $gh_commits = \Drupal::service('ood_contributions.github_commits');

    foreach ($uids as $uid) {
      $user = \Drupal\user\Entity\User::load($uid);
      $github_username = $user->get('field_github_username')->value;

      // Skip users whose GitHub username doesn't exist on GitHub.
      if (!$gh_commits->isValidGitHubUser($github_username)) {
        continue;
      }

      foreach ($repos as $repo) {
        $full_repo = "OSC/$repo";
        // Use last commit date as cutoff to avoid re-fetching old commits.
        $since = $commit_storage->getLatestCommitDate($uid, $full_repo);
        if ($since) {
          // Add 1 second to avoid re-fetching the last known commit.
          $since = $since + 1;
        }
        $commits = $gh_commits->getCommits('OSC', $repo, $github_username, 100, TRUE, $since);
        if (isset($commits['error'])) {
          continue;
        }
        $commit_storage->storeCommits($uid, $full_repo, $commits);
      }

      $commits = $commit_storage->getCommits($uid);
      if ($commits) {
        $graph_service = \Drupal::service('ood_contributions.contribution_graph');
        // Generate graph for a user (last 52 weeks).
        $html = $graph_service->generateGraph($uid);
        // Save $html to 'field_github_graph' field.
        $user->set('field_github_graph', [
          'value' => $html,
          'format' => 'full_no_editor',
        ]);
        $user->save();

        // Fetch PR/issue stats for all repos in one API call.
        $full_repos = array_map(fn($r) => "OSC/$r", $repos);
        $stats_service = \Drupal::service('ood_contributions.user_stats');
        $stats_service->updateAllUserStats($uid, $github_username, $full_repos);
      }
    }

    // Update all users github graph.
    \Drupal::service('ood_contributions.block_contribution_graph')->updateBlock();
  }

  /**
   * Store Discourse contributions.
   *
   * @return array{updated: int, failed: int}
   *   How many users were updated and how many failed.
   */
  public static function discourseStoreContrib(): array {
    // Lookup users to store stats.
    $entity_query = \Drupal::entityQuery('user')
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->exists('field_discourse_openondemand_org');
    $uids = $entity_query->execute();

    $summary = ['updated' => 0, 'failed' => 0];
    $service = \Drupal::service('ood_contributions.discourse_contribution');
    foreach ($uids as $uid) {
      $result = $service->updateUserContributions((int) $uid);
      if (!empty($result['success'])) {
        $summary['updated']++;
      }
      else {
        $summary['failed']++;
      }
    }

    // The Discourse Participation block orders by last_posted_at and is
    // cached with this tag.
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['ood_disc_contrib']);

    return $summary;
  }

  /**
   * Update Discourse daily post statistics.
   *
   * Re-fetches a trailing window on every run. Failures and stalls raise
   * alerts through the notifier; this is cron only, drush never alerts.
   */
  public static function discourseUpdateDailyStats() {
    $service = \Drupal::service('ood_contributions.discourse_stats');
    $notifier = \Drupal::service('ood_contributions.discourse_alert');

    try {
      $result = $service->updateDailyPostStats();
    }
    catch (\Throwable $e) {
      $result = ['success' => FALSE, 'message' => $e->getMessage()];
    }

    if (!$result['success']) {
      \Drupal::logger('ood_contributions')->error('Failed to update Discourse daily stats: @message', [
        '@message' => $result['message'],
      ]);
      $notifier->notify('failure', 'Daily post stats could not be updated', (string) $result['message']);
      return;
    }

    \Drupal::logger('ood_contributions')->info('Discourse daily stats updated: @message', [
      '@message' => $result['message'],
    ]);
    // A success re-arms the failure alert.
    \Drupal::state()->delete(DiscourseAlertNotifier::STATE_PREFIX . DiscourseAlertNotifier::TYPE_FAILURE);

    // Update the block with the contribution graph.
    $service->updateBlock();

    try {
      $stall = $service->checkStall();
    }
    catch (\Throwable $e) {
      \Drupal::logger('ood_contributions')->error('Discourse stall check failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      $stall = NULL;
    }
    if ($stall !== NULL) {
      $notifier->notify('stall', 'Daily post counts have stopped tracking Discourse', $stall);
    }
  }

}
