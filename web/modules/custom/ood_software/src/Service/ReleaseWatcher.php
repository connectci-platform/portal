<?php

namespace Drupal\ood_software\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;

/**
 * Re-reviews a listed repo when it cuts a new release (appverse-planning#34).
 *
 * Run daily (CronManager::releaseCheck(), or drush appverse:release-check):
 * for each published repo, GitHub's latest release is compared with the one
 * last seen (field_repo_last_release); a new one starts a review at its tag.
 * The first check of a repo only records what is there, so turning this on
 * does not review every listed repo at once.
 */
final class ReleaseWatcher {

  const FIELD = 'field_repo_last_release';

  /**
   * Stored when a repo was checked and had no release, so its first release
   * is still news; an empty field means never checked.
   */
  const NO_RELEASE = '-';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AppverseReviewService $reviews,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * What to do with one repo, from the stored and the latest release.
   *
   * Pure.
   *
   * @param string|null $stored
   *   field_repo_last_release: NULL or '' (never checked), NO_RELEASE, or a
   *   tag.
   * @param string|null $latest
   *   The latest release's tag, or NULL when there is none.
   *
   * @return array{store: ?string, review: bool}
   *   store: the value to save, or NULL to leave it; review: start one.
   */
  public static function decide(?string $stored, ?string $latest): array {
    $seen = $latest ?? self::NO_RELEASE;
    if ($stored === NULL || $stored === '') {
      // First check: a baseline, not news.
      return ['store' => $seen, 'review' => FALSE];
    }
    if ($seen === $stored) {
      return ['store' => NULL, 'review' => FALSE];
    }
    // A release that disappeared (deleted) is recorded but not reviewed.
    return ['store' => $seen, 'review' => $latest !== NULL];
  }

  /**
   * Checks every published repo; returns what happened, for logs and drush.
   *
   * @return array{checked: int, baselined: int, reviewed: string[], skipped: int}
   */
  public function check(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appverse_repo')
      ->condition('status', 1)
      ->execute();
    $summary = ['checked' => 0, 'baselined' => 0, 'reviewed' => [], 'skipped' => 0];
    $logger = $this->loggerFactory->get('ood_software');

    foreach ($storage->loadMultiple($ids) as $repo) {
      if (!$repo instanceof NodeInterface || !$repo->hasField(self::FIELD)) {
        continue;
      }
      // A run in flight already reviews the repo; look again tomorrow.
      if (in_array($repo->get('field_review_status')->value ?? NULL, ['pending', 'in_progress'], TRUE)) {
        $summary['skipped']++;
        continue;
      }
      $latest = $this->reviews->latestRelease($repo);
      if ($latest === FALSE) {
        $summary['skipped']++;
        continue;
      }
      $summary['checked']++;
      $stored = $repo->get(self::FIELD)->value;
      $do = self::decide($stored, $latest);
      if ($do['review']) {
        // Recorded only once the review is started, so a failed dispatch is
        // tried again on the next check.
        if (!$this->reviews->dispatchForNode($repo, NULL, NULL, $latest)) {
          $summary['skipped']++;
          continue;
        }
        $summary['reviewed'][] = $repo->label() . ' @ ' . $latest;
        $logger->info('Release check: @repo has a new release @tag; review started.', ['@repo' => $repo->label(), '@tag' => $latest]);
      }
      if ($do['store'] !== NULL) {
        $this->store($repo, $do['store']);
        if ($stored === NULL || $stored === '') {
          $summary['baselined']++;
        }
      }
    }
    $logger->info('Release check: @checked repos checked, @base baselined, @n reviews started, @skipped skipped.', [
      '@checked' => $summary['checked'],
      '@base' => $summary['baselined'],
      '@n' => count($summary['reviewed']),
      '@skipped' => $summary['skipped'],
    ]);
    return $summary;
  }

  /**
   * Records the release seen, outside the editorial flow (no revision, no
   * moderation move, no transition hooks; see ReviewAssignment::assign()).
   */
  protected function store(NodeInterface $repo, string $value): void {
    $fresh = $this->entityTypeManager->getStorage('node')->loadUnchanged($repo->id());
    if (!$fresh instanceof NodeInterface) {
      return;
    }
    $fresh->set(self::FIELD, $value);
    $fresh->setSyncing(TRUE);
    $fresh->setNewRevision(FALSE);
    $fresh->_ood_software_suppress_notifications = TRUE;
    $fresh->save();
  }

}
