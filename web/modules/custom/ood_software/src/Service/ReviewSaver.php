<?php

namespace Drupal\ood_software\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Saves an Appverse review on the revision it has.
 *
 * A review's verdicts and findings are composite paragraphs, so a new
 * revision of the review makes entity_reference_revisions copy every one of
 * them: minutes on a large monorepo review, longer than a web request may run
 * (D8-3136). Its paragraphs are already saved in place, so its revisions held
 * no history of them anyway.
 *
 * The save syncs, because content moderation otherwise forces a new revision
 * on every save of a moderated entity; a syncing save still moves the
 * moderation state and the published status. Syncing also leaves `changed`
 * alone, so it is set here, strictly increasing, for the review page's
 * stale-page check. What the save was for goes to the log instead of a
 * revision log message.
 */
class ReviewSaver {

  public function __construct(
    protected TimeInterface $time,
    protected AccountInterface $currentUser,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Saves the review in place.
   *
   * @param \Drupal\node\NodeInterface $review
   *   The review, with its changes set.
   * @param string $message
   *   What the save was for, for the log.
   */
  public function save(NodeInterface $review, string $message): void {
    $review->setNewRevision(FALSE);
    $review->setChangedTime(max($this->time->getCurrentTime(), (int) $review->getChangedTime() + 1));
    if (method_exists($review, 'setValidationRequired')) {
      $review->setValidationRequired(FALSE);
    }
    $review->setSyncing(TRUE);
    try {
      $review->save();
    }
    finally {
      $review->setSyncing(FALSE);
    }
    if ($message !== '') {
      $this->loggerFactory->get('ood_software')->info('Review @nid: @message (user @uid).', [
        '@nid' => $review->id(),
        '@message' => $message,
        '@uid' => $this->currentUser->id(),
      ]);
    }
  }

}
