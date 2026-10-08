<?php

namespace Drupal\ood_software\Commands;

use Drupal\ood_software\Service\ReleaseWatcher;
use Drush\Commands\DrushCommands;

/**
 * Drush command to run the daily release check now (appverse-planning#34).
 */
class AppverseReleaseCheckCommands extends DrushCommands {

  public function __construct(protected ReleaseWatcher $watcher) {
    parent::__construct();
  }

  /**
   * Check each published repo for a new GitHub release; review new ones.
   *
   * The first check of a repo only records its current release. Reviews go
   * out as this environment allows (dry-run off live; see
   * AppverseReviewService::fullReviewsAllowed()).
   *
   * @command appverse:release-check
   * @usage drush appverse:release-check
   *   Run the check the daily cron job runs on live.
   */
  public function releaseCheck(): void {
    $summary = $this->watcher->check();
    $this->io()->success(sprintf('%d repos checked, %d baselined, %d skipped, %d reviews started.',
      $summary['checked'], $summary['baselined'], $summary['skipped'], count($summary['reviewed'])));
    foreach ($summary['reviewed'] as $line) {
      $this->io()->writeln('  ' . $line);
    }
  }

}
