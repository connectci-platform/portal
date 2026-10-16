<?php

namespace Drupal\ood_contributions\Commands;

use Drupal\ood_contributions\Plugin\CronManager;
use Drupal\ood_contributions\Service\DiscourseStatsService;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for OOD Contributions module.
 */
class OodContributionsCommands extends DrushCommands {

  /**
   * The Discourse stats service.
   *
   * @var \Drupal\ood_contributions\Service\DiscourseStatsService
   */
  protected $discourseStats;

  /**
   * Constructs an OodContributionsCommands object.
   *
   * @param \Drupal\ood_contributions\Service\DiscourseStatsService $discourse_stats
   *   The Discourse stats service.
   */
  public function __construct(DiscourseStatsService $discourse_stats) {
    parent::__construct();
    $this->discourseStats = $discourse_stats;
  }

  /**
   * Updates Discourse daily post statistics.
   *
   * @param array $options
   *   The command options.
   *
   * @option since
   *   Re-fetch from this date (YYYY-MM-DD) instead of the trailing window.
   *
   * @command ood:discourse-stats
   * @aliases ood-disc-stats
   * @usage ood:discourse-stats
   *   Fetch and store daily post statistics from Discourse.
   * @usage ood:discourse-stats --since=2025-10-01
   *   Re-fetch and re-bucket every day from 2025-10-01 onward.
   */
  public function discourseStats(array $options = ['since' => NULL]): int {
    $since = $options['since'] ?? NULL;
    if ($since !== NULL && (!is_string($since) || !DiscourseStatsService::isValidDate($since))) {
      $this->io()->error('The --since option must be a valid date in YYYY-MM-DD format.');
      return self::EXIT_FAILURE;
    }

    $this->io()->writeln('Fetching Discourse daily post statistics...');

    // Calls the service directly: manual runs never send alerts.
    $result = $this->discourseStats->updateDailyPostStats($since);
    if (!$result['success']) {
      $this->io()->error('Update failed: ' . $result['message']);
      return self::EXIT_FAILURE;
    }
    $this->io()->success($result['message']);

    $stats = $this->discourseStats->getLastYearStats();

    $this->io()->writeln(sprintf('Total records in database: %d', count($stats)));

    if (!empty($stats)) {
      $first = reset($stats);
      $last = end($stats);
      $total_posts = array_sum(array_column($stats, 'post_count'));

      $this->io()->writeln(sprintf('Date range: %s to %s', $first['post_date'], $last['post_date']));
      $this->io()->writeln(sprintf('Total posts: %d', $total_posts));
    }

    // Update the block with the contribution graph.
    if ($this->discourseStats->updateBlock()) {
      $this->io()->writeln('Updated Discourse contribution graph block.');
    }
    else {
      $this->io()->warning('Could not update the Discourse contribution graph block.');
    }

    $stall = $this->discourseStats->checkStall();
    if ($stall !== NULL) {
      $this->io()->warning($stall);
    }
    else {
      $this->io()->writeln('Stall check: OK.');
    }

    $this->io()->writeln('Done!');
    return self::EXIT_SUCCESS;
  }

  /**
   * Store GitHub statistics (existing command).
   *
   * @command ood:store-stats
   * @aliases ood-stats
   * @usage ood:store-stats
   *   Store GitHub contribution statistics.
   */
  public function storeStats() {
    $this->output()->writeln('Storing GitHub statistics...');
    CronManager::storeStats();
    $this->output()->writeln('Done!');
  }

  /**
   * Store Discourse user contributions (existing command).
   *
   * @command ood:discourse-contrib
   * @aliases ood-disc-contrib
   * @usage ood:discourse-contrib
   *   Store Discourse user contribution statistics.
   */
  public function discourseContrib() {
    $this->output()->writeln('Storing Discourse user contributions...');
    $summary = CronManager::discourseStoreContrib();
    $this->output()->writeln(sprintf('Updated %d users, %d failed.', $summary['updated'], $summary['failed']));
    $this->output()->writeln('Done!');
  }

}
