<?php

namespace Drupal\ood_software\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;

/**
 * Re-reviews a listed repo when it has new code to review.
 *
 * Run daily (CronManager::releaseCheck(), or drush appverse:release-check):
 * for each published repo, the newest release, or the newest tag when the
 * repo makes no releases, is compared with what was last seen
 * (field_repo_last_release). A tag counts as new only when its commit is
 * newer than both the one last seen and the repo's latest decided review, so
 * a deleted, re-created or older tag, or a release the last review already
 * covered, starts nothing. A repo whose newest review is still undecided is
 * left alone, so a reviewer's work is never superseded; the release is picked
 * up after the decision. The first check of a repo only records what is
 * there, so turning this on does not review every listed repo at once
 * (appverse-planning#34, #45).
 */
final class ReleaseWatcher {

  const FIELD = 'field_repo_last_release';

  /**
   * Stored when a repo was checked and had no release or tag, so its first
   * one is still news; an empty field means never checked.
   */
  const NO_RELEASE = '-';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AppverseReviewService $reviews,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected TimeInterface $time,
  ) {}

  /**
   * The stored value: "<tag>|<commit time>", NO_RELEASE, or a bare tag.
   *
   * Pure. A bare tag was stored before commit times were; it reads as time 0,
   * so the next newer tag still counts.
   *
   * @return array{tag: ?string, at: int}|null
   *   NULL when the repo was never checked.
   */
  public static function parse(?string $stored): ?array {
    if ($stored === NULL || $stored === '') {
      return NULL;
    }
    if ($stored === self::NO_RELEASE) {
      return ['tag' => NULL, 'at' => 0];
    }
    $pos = strrpos($stored, '|');
    if ($pos !== FALSE && ctype_digit(substr($stored, $pos + 1))) {
      return ['tag' => substr($stored, 0, $pos), 'at' => (int) substr($stored, $pos + 1)];
    }
    return ['tag' => $stored, 'at' => 0];
  }

  /**
   * What to do with one repo.
   *
   * Pure.
   *
   * @param string|null $stored
   *   field_repo_last_release, as parse() reads it.
   * @param array{tag: string, at: int}|null $latest
   *   The newest release or tag and its commit time, or NULL with neither.
   * @param int $coveredUntil
   *   When the repo's latest decided review ran; code committed before that
   *   was already reviewed. 0 without one.
   * @param int $now
   *   The current time. Commit dates are set by whoever commits, so one in
   *   the future is read as now; otherwise a single tag dated 2099 would
   *   make every later release look older.
   *
   * @return array{store: ?string, review: bool}
   *   store: the value to save, or NULL to leave it; review: start one.
   */
  public static function decide(?string $stored, ?array $latest, int $coveredUntil = 0, int $now = PHP_INT_MAX): array {
    $seen = self::parse($stored);
    if ($seen !== NULL) {
      $seen['at'] = min($seen['at'], $now);
    }
    if ($latest !== NULL) {
      $latest['at'] = min($latest['at'], $now);
    }
    $value = $latest !== NULL ? $latest['tag'] . '|' . $latest['at'] : self::NO_RELEASE;
    if ($seen === NULL) {
      // First check: a baseline, not news.
      return ['store' => $value, 'review' => FALSE];
    }
    if ($latest === NULL) {
      // Nothing to review. Keep what was seen, so a deleted release that
      // comes back is not news.
      return ['store' => NULL, 'review' => FALSE];
    }
    if ($latest['at'] <= $seen['at']) {
      // Not newer than what was seen: the same tag, or an older one after the
      // newest was deleted.
      return ['store' => NULL, 'review' => FALSE];
    }
    if ($latest['tag'] === $seen['tag'] && $seen['at'] === 0) {
      // The tag stored before commit times were: record its time, no review.
      return ['store' => $value, 'review' => FALSE];
    }
    // Newer than what was seen; news unless the last review already covered it.
    return ['store' => $value, 'review' => $latest['at'] > $coveredUntil];
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
      // A review nobody has decided yet: a new run would supersede it and
      // strand the reviewer's work. Leave the stored release alone so it is
      // picked up after the decision.
      $newest = $this->newestReview($repo);
      if ($newest !== NULL && !self::isDecided($newest)) {
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
      $coveredUntil = $newest !== NULL ? (int) ($newest->get('field_arv_reviewed_at')->value ?? $newest->getCreatedTime()) : 0;
      $do = self::decide($stored, $latest, $coveredUntil, $this->time->getRequestTime());
      if ($do['review'] && $latest !== NULL) {
        // Recorded only once the review is started, so a failed dispatch is
        // tried again on the next check.
        if (!$this->reviews->dispatchForNode($repo, NULL, NULL, $latest['tag'])) {
          $summary['skipped']++;
          continue;
        }
        $summary['reviewed'][] = $repo->label() . ' @ ' . $latest['tag'];
        $logger->info('Release check: @repo has new code at @tag; review started.', ['@repo' => $repo->label(), '@tag' => $latest['tag']]);
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
   * Whether a review has been decided: a decision sent, published (reviews
   * from before decisions were recorded), or withdrawn.
   */
  public static function isDecided(NodeInterface $review): bool {
    foreach (['field_arv_decision_sent_at', 'field_arv_withdrawn_at'] as $field) {
      if ($review->hasField($field) && !$review->get($field)->isEmpty()) {
        return TRUE;
      }
    }
    return $review->isPublished();
  }

  /**
   * The repo's newest review, whatever its state. Cron runs as anonymous, so
   * this does not check access.
   */
  protected function newestReview(NodeInterface $repo): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appverse_review')
      ->condition('field_arv_repo', $repo->id())
      ->sort('created', 'DESC')
      ->sort('nid', 'DESC')
      ->range(0, 1)
      ->execute();
    $review = $ids ? $storage->load(reset($ids)) : NULL;
    return $review instanceof NodeInterface ? $review : NULL;
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
    // A runtime flag ood_software_node_update() reads, not a field; the
    // Drupal PHPStan extension types every node property as a field list.
    // @phpstan-ignore-next-line
    $fresh->_ood_software_suppress_notifications = TRUE;
    $fresh->save();
  }

}
