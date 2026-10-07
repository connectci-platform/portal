<?php

namespace Drupal\ood_software\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_moderation\ContentModerationState;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Carries out a review's decision on the review, its repo and its apps.
 *
 * The decision moves the repo and the review together (appverse-planning#29;
 * REVIEW-STATES.md), app by app (#30; ReviewDecision::plan()):
 * - An accepted app is published, with the repo and the review.
 * - An app accepted with suggestions waits: the review page offers "Publish
 *   app and review" (publish()).
 * - An app sent back goes to needs_adjustment, a declined one to declined.
 * - With no app accepted, the repo goes back to the contributor or, when every
 *   app is declined, is declined; a live repo takes its apps with it.
 * Every decision records decision_sent_at / _by, which also opens the review
 * to the repo's owner. The repo's own transition emails are suppressed: the
 * owner gets one decision email for the whole repo (#32; DecisionEmail), and
 * a "now live" one when Publish puts something in the catalog.
 */
final class ReviewDecisionApplier {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
    protected TimeInterface $time,
    protected MessengerInterface $messenger,
    protected RepoMemberApps $repoMemberApps,
    protected ModerationInformationInterface $moderationInformation,
    protected RepoNotificationService $notifier,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected Connection $database,
    protected LockBackendInterface $lock,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * What was sent to the contributor, or NULL before a decision is sent.
   *
   * Fixed at send time (field_arv_sent_decisions), so editing the page later
   * cannot change what Publish, the progress line or the page take the
   * decision to be (appverse-planning#51). A review sent before the field
   * existed falls back to its current conclusions and response.
   *
   * @return array{apps: array<string, string>, response: string, email: array<string, mixed>|null, was_live: bool|null, history: array<int, array<string, mixed>>}|null
   *   apps: verdict paragraph id => decision; email: the decision email as
   *   sent, rendered (subject and html, see renderSent()), NULL for a review
   *   sent before it was stored; was_live: whether the repo was live before the
   *   round's first decision; history: earlier decisions an update replaced,
   *   oldest first, each with apps, response, at and by.
   */
  public static function sent(NodeInterface $review): ?array {
    if (!$review->hasField('field_arv_decision_sent_at') || $review->get('field_arv_decision_sent_at')->isEmpty()) {
      return NULL;
    }
    $stored = $review->hasField('field_arv_sent_decisions')
      ? json_decode((string) ($review->get('field_arv_sent_decisions')->value ?? ''), TRUE) : NULL;
    if (is_array($stored) && is_array($stored['apps'] ?? NULL)) {
      return [
        'apps' => array_map('strval', $stored['apps']),
        'response' => (string) ($stored['response'] ?? ''),
        'email' => is_array($stored['email'] ?? NULL) ? $stored['email'] : NULL,
        'was_live' => isset($stored['was_live']) ? (bool) $stored['was_live'] : NULL,
        'history' => is_array($stored['history'] ?? NULL) ? $stored['history'] : [],
      ];
    }
    $apps = [];
    foreach ($review->hasField('field_arv_verdicts') ? $review->get('field_arv_verdicts')->referencedEntities() : [] as $verdict) {
      $apps[(string) $verdict->id()] = (string) $verdict->get('field_rvv_conclusion')->value;
    }
    $response = $review->hasField('field_arv_contributor_response') ? (string) ($review->get('field_arv_contributor_response')->value ?? '') : '';
    return ['apps' => $apps, 'response' => $response, 'email' => NULL, 'was_live' => NULL, 'history' => []];
  }

  /**
   * Why a decision cannot be sent on this review now, or NULL when it can.
   *
   * The review page offers Send decision only on a current, undecided review.
   * The confirm route and send() check again, since either can be reached by
   * URL or by a second submit (appverse-planning#50).
   */
  public function decisionBlocker(NodeInterface $review): ?TranslatableMarkup {
    if (!$review->get('field_arv_decision_sent_at')->isEmpty()) {
      return $this->t('The decision on this review has already been sent.');
    }
    if ($review->hasField('field_arv_withdrawn_at') && !$review->get('field_arv_withdrawn_at')->isEmpty()) {
      return $this->t('The contributor withdrew this submission; there is nothing to decide unless they re-submit.');
    }
    if ($review->hasField('moderation_state') && ($review->get('moderation_state')->value ?? '') === 'published') {
      return $this->t('This review is already published.');
    }
    if ($this->isSuperseded($review)) {
      return $this->t('A newer review of this repo exists, so this one is out of date. Send the decision on the newer review.');
    }
    return $this->repoBlocker($review);
  }

  /**
   * Whether a newer review of the same repo exists, as the review page reads it.
   */
  protected function isSuperseded(NodeInterface $review): bool {
    $repo = $review->get('field_arv_repo')->target_id;
    if (!$repo) {
      return FALSE;
    }
    return (bool) $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appverse_review')
      ->condition('field_arv_repo', $repo)
      ->condition('created', $review->getCreatedTime(), '>')
      ->range(0, 1)
      ->count()
      ->execute();
  }

  /**
   * Why a decision cannot act on the review's repo now, or NULL when it can.
   *
   * A decision acts on what was submitted: a repo awaiting review, or a live
   * repo under re-review. A contributor can still move a submitted repo back
   * to draft from its edit form, and publishing it then would put edits made
   * after the review into the catalog (appverse-planning#41). Nothing is
   * applied until they re-submit.
   */
  public function repoBlocker(NodeInterface $review): ?TranslatableMarkup {
    $ref = $review->get('field_arv_repo')->target_id;
    $repo = $ref ? $this->entityTypeManager->getStorage('node')->loadUnchanged($ref) : NULL;
    if (!$repo instanceof NodeInterface) {
      return NULL;
    }
    $state = (string) ($repo->get('moderation_state')->value ?? '');
    if (in_array($state, ['ready_for_review', 'published'], TRUE)) {
      return NULL;
    }
    return $this->t('%repo is @state, not awaiting review, so nothing was changed. The contributor needs to re-submit it first.', [
      '%repo' => $repo->label(),
      '@state' => str_replace('_', ' ', $state),
    ]);
  }

  /**
   * Sends the decision: records it and moves the apps, the repo and the review.
   *
   * One send at a time per review, re-checked once the lock is held, so a
   * double submit sends one decision. The moves run in one transaction, so a
   * failure part way leaves the review undecided rather than decided with the
   * repo unmoved, and the email goes only once they are committed.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   Why nothing was sent, or NULL once the decision is sent.
   */
  public function send(NodeInterface $review, string $response): ?TranslatableMarkup {
    $lock = 'ood_software_review_decision:' . $review->id();
    if (!$this->lock->acquire($lock)) {
      return $this->t('A decision on this review is already being sent. Reload the review to see it.');
    }
    $email = NULL;
    try {
      $fresh = $this->entityTypeManager->getStorage('node')->loadUnchanged($review->id());
      if (!$fresh instanceof NodeInterface) {
        return $this->t('The review no longer exists.');
      }
      if (($blocker = $this->decisionBlocker($fresh)) !== NULL) {
        return $blocker;
      }
      $transaction = $this->database->startTransaction();
      try {
        $email = $this->applyDecision($fresh, $response);
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
      // Commits.
      unset($transaction);
    }
    finally {
      $this->lock->release($lock);
    }
    if ($email !== NULL) {
      [$repo, $message] = $email;
      $this->email($fresh, $repo, 'review_decision', $message);
    }
    return NULL;
  }

  /**
   * Records the decision and makes its moves.
   *
   * @return array{0: \Drupal\node\NodeInterface, 1: array<string, mixed>}|null
   *   The repo and the decision email to send it, or NULL without a repo.
   */
  protected function applyDecision(NodeInterface $review, string $response): ?array {
    $decisions = $this->appDecisions($review);
    $overall = ReviewProgress::strictestDecision(array_values($decisions));
    $repo = $review->get('field_arv_repo')->entity;
    $wasLive = $repo instanceof NodeInterface && $repo->isPublished();
    $review->set('field_arv_decision_sent_at', $this->time->getCurrentTime());
    $review->set('field_arv_decision_sent_by', $this->currentUser->id());
    // The email is composed before anything moves, so what is stored is
    // exactly what is sent (appverse-planning#56).
    $email = $repo instanceof NodeInterface ? $this->composeDecision($review, $repo, $response, $wasLive) : NULL;
    // What was sent, fixed: later edits to the page do not change it, and the
    // decision-sent view shows the email from here (appverse-planning#51, #56).
    if ($review->hasField('field_arv_sent_decisions')) {
      $review->set('field_arv_sent_decisions', json_encode([
        'apps' => $decisions,
        'response' => $response,
        'email' => $repo instanceof NodeInterface && $email !== NULL ? $this->renderSent($email, $repo) : NULL,
        'was_live' => $wasLive,
      ]));
    }
    if (($review->get('moderation_state')->value ?? '') === 'draft') {
      $review->set('moderation_state', 'in_review');
    }
    $this->saveRevision($review, sprintf('Decision sent (%s) by %s', $overall, $this->currentUser->getDisplayName()));

    $plan = ReviewDecision::plan($decisions);
    $apps = $this->appNodes($review);
    // Apps first, so a repo leaving the catalog takes only the apps still
    // live with it.
    foreach ($plan['apps'] as $pid => $move) {
      $app = $apps[$pid] ?? NULL;
      if ($move === NULL || $app === NULL) {
        continue;
      }
      if ($move === 'publish') {
        $this->publishNode($app, 'Published: accepted on the review.');
      }
      else {
        $this->moveNode($app, $move, $move === 'declined' ? 'Declined on the review.' : 'Changes requested on the review.');
      }
    }

    if ($repo instanceof NodeInterface) {
      match ($plan['repo']) {
        'publish' => $this->publishNode($repo, $response !== '' ? $response : 'Published: accepted on the review.'),
        'needs_adjustment' => $this->moveRepo($repo, 'needs_adjustment', $response, 'Auto-unpublished: changes requested on the review.'),
        'declined' => $this->moveRepo($repo, 'declined', $response, 'Auto-unpublished: the review declined the repo.'),
        default => NULL,
      };
    }
    if ($plan['review'] === 'publish') {
      $this->publishReview($review);
    }

    return $repo instanceof NodeInterface && $email !== NULL ? [$repo, $email] : NULL;
  }

  /**
   * Why the sent decision cannot be updated now, or NULL when it can
   * (appverse-planning#56).
   *
   * An update replaces the decision of the round it was sent in, so only on
   * the repo's newest review, not after a withdrawal, and only until the
   * contributor re-submits: once a new run is dispatched, the decision
   * belongs to the new round.
   */
  public function updateBlocker(NodeInterface $review): ?TranslatableMarkup {
    if (self::sent($review) === NULL) {
      return $this->t('No decision has been sent on this review yet.');
    }
    if ($review->hasField('field_arv_withdrawn_at') && !$review->get('field_arv_withdrawn_at')->isEmpty()) {
      return $this->t('The contributor withdrew this submission.');
    }
    if ($this->isSuperseded($review)) {
      return $this->t('A newer review of this repo exists, so this decision belongs to an earlier round.');
    }
    $ref = $review->get('field_arv_repo')->target_id;
    $repo = $ref ? $this->entityTypeManager->getStorage('node')->loadUnchanged($ref) : NULL;
    if (!$repo instanceof NodeInterface) {
      return $this->t('The review has no repo to update.');
    }
    $dispatched = $repo->hasField('field_review_dispatched_at') ? (int) ($repo->get('field_review_dispatched_at')->value ?? 0) : 0;
    if ($dispatched > (int) $review->get('field_arv_decision_sent_at')->value) {
      return $this->t('%repo has been re-submitted since the decision, so it belongs to the new round.', ['%repo' => $repo->label()]);
    }
    $state = (string) ($repo->get('moderation_state')->value ?? '');
    if (!in_array($state, ['ready_for_review', 'needs_adjustment', 'declined', 'published'], TRUE)) {
      return $this->t('%repo is @state, so the decision cannot be updated.', ['%repo' => $repo->label(), '@state' => str_replace('_', ' ', $state)]);
    }
    return NULL;
  }

  /**
   * Updates the sent decision: the contributor is told it replaces the
   * earlier one, and the repo, its apps and the review move to match
   * (appverse-planning#56). Locked and transactional, as send() is.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   Why nothing was updated, or NULL once the update is sent.
   */
  public function update(NodeInterface $review, string $response): ?TranslatableMarkup {
    $lock = 'ood_software_review_decision:' . $review->id();
    if (!$this->lock->acquire($lock)) {
      return $this->t('A decision on this review is already being sent. Reload the review to see it.');
    }
    $email = NULL;
    try {
      $fresh = $this->entityTypeManager->getStorage('node')->loadUnchanged($review->id());
      if (!$fresh instanceof NodeInterface) {
        return $this->t('The review no longer exists.');
      }
      if (($blocker = $this->updateBlocker($fresh)) !== NULL) {
        return $blocker;
      }
      $transaction = $this->database->startTransaction();
      try {
        $email = $this->applyUpdate($fresh, $response);
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
      // Commits.
      unset($transaction);
    }
    finally {
      $this->lock->release($lock);
    }
    if ($email !== NULL) {
      [$repo, $message] = $email;
      $this->email($fresh, $repo, 'review_decision', $message);
    }
    return NULL;
  }

  /**
   * Records the updated decision, keeping the one it replaces, and moves the
   * repo, its apps and the review to where the new decision leaves them.
   *
   * @return array{0: \Drupal\node\NodeInterface, 1: array<string, mixed>}|null
   *   The repo and the email, or NULL without a repo.
   */
  protected function applyUpdate(NodeInterface $review, string $response): ?array {
    $old = self::sent($review);
    $decisions = $this->appDecisions($review);
    $overall = ReviewProgress::strictestDecision(array_values($decisions));
    $repo = $review->get('field_arv_repo')->entity;
    $wasLive = $old['was_live'] ?? ($repo instanceof NodeInterface && $repo->isPublished());
    $oldAt = (int) $review->get('field_arv_decision_sent_at')->value;
    $oldLabel = (string) (ReviewProgress::DECISION_LABELS[ReviewProgress::strictestDecision(array_values($old['apps']))] ?? '');

    $email = $repo instanceof NodeInterface ? $this->composeUpdate($review, $repo, $response, $wasLive) : NULL;

    $history = $old['history'];
    $history[] = [
      'apps' => $old['apps'],
      'response' => $old['response'],
      'at' => $oldAt,
      'by' => (int) $review->get('field_arv_decision_sent_by')->target_id,
    ];
    $review->set('field_arv_decision_sent_at', $this->time->getCurrentTime());
    $review->set('field_arv_decision_sent_by', $this->currentUser->id());
    $review->set('field_arv_sent_decisions', json_encode([
      'apps' => $decisions,
      'response' => $response,
      'email' => $repo instanceof NodeInterface && $email !== NULL ? $this->renderSent($email, $repo) : NULL,
      'was_live' => $wasLive,
      'history' => $history,
    ]));
    $this->saveRevision($review, sprintf('Decision updated from %s to %s by %s', $oldLabel, $overall, $this->currentUser->getDisplayName()));

    $apps = $this->appNodes($review);
    $live = array_map(static fn (NodeInterface $app): bool => $app->isPublished(), $apps);
    $targets = ReviewDecision::updateTargets($decisions, $live, $wasLive);
    // Apps first, as on send.
    foreach ($targets['apps'] as $pid => $target) {
      if ($target !== NULL && isset($apps[$pid])) {
        $this->moveTo($apps[$pid], $target, 'Decision updated on the review.');
      }
    }
    if ($repo instanceof NodeInterface) {
      $this->moveTo($repo, $targets['repo'], $response !== '' ? $response : 'Decision updated on the review.');
    }
    $this->moveTo($review, $targets['review'], sprintf('Review page: decision updated by %s', $this->currentUser->getDisplayName()));

    return $repo instanceof NodeInterface && $email !== NULL ? [$repo, $email] : NULL;
  }

  /**
   * Moves a node to a moderation state along the workflow's transitions,
   * through intermediate states where there is no direct one (needs
   * adjustment to declined goes by ready for review). Every save is silent:
   * the update email replaces the transitions' own, and a pass through
   * ready_for_review must not start an AI run.
   */
  protected function moveTo(NodeInterface $node, string $target, string $log): void {
    $storage = $this->entityTypeManager->getStorage('node');
    $fresh = $storage->loadUnchanged($node->id());
    $workflow = $fresh instanceof NodeInterface ? $this->moderationInformation->getWorkflowForEntity($fresh)?->getTypePlugin() : NULL;
    if (!$workflow || !$workflow->hasState($target)) {
      return;
    }
    $from = (string) ($fresh->get('moderation_state')->value ?? '');
    if ($from === $target || !$workflow->hasState($from)) {
      return;
    }
    // Breadth-first over the transitions, for the shortest path.
    $previous = [$from => NULL];
    $queue = [$from];
    while ($queue && !array_key_exists($target, $previous)) {
      $state = array_shift($queue);
      foreach ($workflow->getState($state)->getTransitions() as $transition) {
        $next = $transition->to()->id();
        if (!array_key_exists($next, $previous)) {
          $previous[$next] = $state;
          $queue[] = $next;
        }
      }
    }
    if (!array_key_exists($target, $previous)) {
      $this->messenger->addWarning($this->t('@title could not be moved from @from to @to.', ['@title' => $fresh->label(), '@from' => $from, '@to' => $target]));
      return;
    }
    $path = [];
    for ($state = $target; $state !== $from; $state = $previous[$state]) {
      array_unshift($path, $state);
    }
    foreach ($path as $state) {
      $latest = $storage->getLatestRevisionId($node->id());
      $published = $workflow->getState($state);
      $step = $published instanceof ContentModerationState && $published->isPublishedState() && $latest
        ? $storage->loadRevision($latest)
        : $storage->loadUnchanged($node->id());
      if (!$step instanceof NodeInterface) {
        return;
      }
      $step->set('moderation_state', $state);
      // Read at runtime by hook_node_update(); it is not a field.
      // @phpstan-ignore-next-line
      $step->_ood_software_suppress_notifications = TRUE;
      $this->saveRevision($step, $log);
    }
    $this->messenger->addStatus($this->t('Moved @title to @state.', ['@title' => $fresh->label(), '@state' => str_replace('_', ' ', $target)]));
  }

  /**
   * "Publish app and review": publishes every accepted app not yet live, the
   * repo, and the review.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   Why nothing was published, or NULL once it is done.
   */
  public function publish(NodeInterface $review): ?TranslatableMarkup {
    if (($blocker = $this->repoBlocker($review)) !== NULL) {
      return $blocker;
    }
    // What was sent, not what the page says now (appverse-planning#51). Only
    // a sent Accept with suggestions waits for this step: Accept publishes on
    // send, and nothing else publishes at all.
    $decisions = self::sent($review)['apps'] ?? [];
    if (!in_array('accept_with_suggestions', $decisions, TRUE)) {
      return $this->t('Nothing was published: the decision sent on this review did not accept any app with suggestions.');
    }
    $published = [];
    foreach ($this->appNodes($review) as $pid => $app) {
      if (in_array($decisions[$pid] ?? NULL, ['accept', 'accept_with_suggestions'], TRUE) && !$app->isPublished()
        && $this->publishNode($app, 'Published from the review.')) {
        $published[] = $app->label();
      }
    }
    $repo = $review->get('field_arv_repo')->entity;
    // What actually went live, not what was attempted: the email says so.
    $repoWentLive = $repo instanceof NodeInterface && !$repo->isPublished()
      && $this->publishNode($repo, 'Published from the review.');
    $this->publishReview($review);

    // Only when something entered the catalog: publishing just the review of
    // a live repo is not news to the contributor.
    if ($repo instanceof NodeInterface && ($repoWentLive || $published !== [])) {
      $this->email($review, $repo, 'review_published', DecisionEmail::nowLive(
        $this->siteName(), (string) $repo->label(), $published, count($decisions) > 1, $this->people($review, $repo), $this->links($review, $repo),
      ));
    }
    return NULL;
  }

  /**
   * Verdict paragraph id => the app's name.
   *
   * @return array<string, string>
   */
  public function appNames(NodeInterface $review): array {
    $names = [];
    foreach ($review->get('field_arv_verdicts')->referencedEntities() as $verdict) {
      $app = $verdict->get('field_rvv_app_ref')->entity;
      $names[(string) $verdict->id()] = $app instanceof NodeInterface ? (string) $app->label() : (string) ($verdict->get('field_rvv_app_id')->value ?? 'App');
    }
    return $names;
  }

  /**
   * Sends a review email to the repo's owner and records that it went.
   *
   * @param array<string, mixed> $email
   *   As DecisionEmail builds it, with at least a subject.
   */
  protected function email(NodeInterface $review, NodeInterface $repo, string $key, array $email): void {
    // A reply starts a conversation with the reviewer, not the site.
    $reviewer = $this->reviewer($review);
    $sent = $this->notifier->sendReviewEmail($repo, $key, $email, $reviewer?->getEmail() ?: NULL);
    $owner = $repo->getOwner();
    $this->loggerFactory->get('ood_software')->info('Review @rid: @key email "@subject" @result to the owner of repo @repo.', [
      '@rid' => $review->id(),
      '@key' => $key,
      '@subject' => (string) $email['subject'],
      '@result' => $sent ? 'sent' : 'NOT sent',
      '@repo' => $repo->id(),
    ]);
    if ($sent) {
      // A repo whose owner was deleted belongs to the anonymous user.
      $this->messenger->addStatus($this->t('Emailed @name.', ['@name' => $repo->getOwnerId() ? $owner->getDisplayName() : $this->t('the contributor')]));
    }
    else {
      $this->messenger->addWarning($this->t('The email to the contributor could not be sent; see the site log.'));
    }
  }

  /**
   * The decision email as it would go out now, for the confirm page.
   *
   * Built from the same parts as send(), or update() when $update, and
   * rendered as hook_mail() renders it, so the preview is what the
   * contributor gets (appverse-planning#52, #56).
   *
   * @return array{to: string, reply_to: string, subject: string, body: array<int, \Drupal\Component\Render\MarkupInterface|string>, contributor: string}|null
   *   NULL without a repo.
   */
  public function previewDecision(NodeInterface $review, bool $update = FALSE): ?array {
    $repo = $review->get('field_arv_repo')->entity;
    if (!$repo instanceof NodeInterface) {
      return NULL;
    }
    $response = (string) ($review->get('field_arv_contributor_response')->value ?? '');
    // An update keeps the round's first was-live, as applyUpdate() does.
    $wasLive = $update ? (self::sent($review)['was_live'] ?? $repo->isPublished()) : $repo->isPublished();
    $email = $update
      ? $this->composeUpdate($review, $repo, $response, $wasLive)
      : $this->composeDecision($review, $repo, $response, $wasLive);
    // A repo whose owner was deleted belongs to the anonymous user.
    $owner = $repo->getOwnerId() ? $repo->getOwner() : NULL;
    $rendered = DecisionEmail::render($email, $owner ? $owner->getPreferredLangcode() : 'en');
    return [
      'to' => $owner ? (string) $owner->getEmail() : '',
      // Without the reviewer's address, replies go to the site's.
      'reply_to' => (string) ($this->reviewer($review)?->getEmail() ?: $this->configFactory->get('system.site')->get('mail')),
      'subject' => $rendered['subject'],
      'body' => $rendered['body'],
      'contributor' => $this->people($review, $repo)['contributor'],
    ];
  }

  /**
   * The decision email for a review, before anything moves.
   *
   * @return array<string, mixed>
   *   As DecisionEmail::decision() builds it.
   */
  protected function composeDecision(NodeInterface $review, NodeInterface $repo, string $response, bool $wasLive): array {
    return DecisionEmail::decision(
      $this->siteName(), (string) $repo->label(), $this->appDecisions($review), $this->appNames($review), $response, $wasLive,
      $this->people($review, $repo), $this->links($review, $repo),
    );
  }

  /**
   * The updated decision's email: the decision email, said as an update so
   * the contributor is not left with two emails that seem to disagree
   * (appverse-planning#56).
   *
   * @return array<string, mixed>
   *   As DecisionEmail::decision() builds it.
   */
  protected function composeUpdate(NodeInterface $review, NodeInterface $repo, string $response, bool $wasLive): array {
    $email = $this->composeDecision($review, $repo, $response, $wasLive);
    $sent = self::sent($review);
    $oldAt = (int) $review->get('field_arv_decision_sent_at')->value;
    $email['subject'] = $this->t('Updated: @subject', ['@subject' => $email['subject']]);
    // After the greeting, before the decision.
    array_splice($email['blocks'], 1, 0, [['p', $this->t('This updates the decision sent on @date (@decision) and replaces it.', [
      '@date' => $this->dateFormatter->format($oldAt, 'custom', 'M j, Y'),
      '@decision' => ReviewProgress::DECISION_LABELS[ReviewProgress::strictestDecision(array_values($sent['apps'] ?? []))] ?? '',
    ])]]);
    return $email;
  }

  /**
   * The email as sent, rendered in the contributor's language, to store.
   *
   * Its blocks hold translatable markup, which does not survive json_encode,
   * so what is stored is the rendered subject and body (appverse-planning#56).
   *
   * @param array<string, mixed> $email
   *   As DecisionEmail::decision() builds it.
   *
   * @return array{subject: string, html: string}
   */
  protected function renderSent(array $email, NodeInterface $repo): array {
    // A repo whose owner was deleted belongs to the anonymous user.
    $owner = $repo->getOwnerId() ? $repo->getOwner() : NULL;
    $rendered = DecisionEmail::render($email, $owner ? $owner->getPreferredLangcode() : 'en');
    return ['subject' => $rendered['subject'], 'html' => implode('', array_map('strval', $rendered['body']))];
  }

  /**
   * The reviewer the email names and replies go to: whoever sent the
   * decision, or the current user before one is recorded.
   */
  protected function reviewer(NodeInterface $review): ?UserInterface {
    $sender = $review->hasField('field_arv_decision_sent_by') ? $review->get('field_arv_decision_sent_by')->entity : NULL;
    if ($sender instanceof UserInterface) {
      return $sender;
    }
    $current = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    return $current instanceof UserInterface && !$current->isAnonymous() ? $current : NULL;
  }

  /**
   * The names the email uses for the contributor and the reviewer.
   *
   * @return array{contributor: string, reviewer: string}
   */
  protected function people(NodeInterface $review, NodeInterface $repo): array {
    // A repo whose owner was deleted belongs to the anonymous user.
    return [
      'contributor' => $repo->getOwnerId() ? (string) $repo->getOwner()->getDisplayName() : '',
      'reviewer' => (string) ($this->reviewer($review)?->getDisplayName() ?? ''),
    ];
  }

  protected function siteName(): string {
    return (string) $this->configFactory->get('system.site')->get('name');
  }

  /**
   * The links a review email carries.
   *
   * @return array<string, string>
   */
  protected function links(NodeInterface $review, NodeInterface $repo): array {
    return [
      'review' => Url::fromRoute('ood_software.review_page', ['node' => $review->id()], ['absolute' => TRUE])->toString(),
      'hub' => Url::fromUserInput('/user/' . $repo->getOwnerId() . '/my-appverse', ['absolute' => TRUE])->toString(),
      'catalog' => _ood_software_repo_catalog_url($repo),
    ];
  }

  /**
   * Verdict paragraph id => its duplicate-check outcome (DuplicateCheck), or
   * NULL where none is recorded.
   *
   * @return array<string, string|null>
   */
  public function appDuplicateChecks(NodeInterface $review): array {
    $outcomes = [];
    foreach ($review->get('field_arv_verdicts')->referencedEntities() as $verdict) {
      $outcomes[(string) $verdict->id()] = $verdict->hasField('field_rvv_duplicate') ? $verdict->get('field_rvv_duplicate')->value : NULL;
    }
    return $outcomes;
  }

  /**
   * Verdict paragraph id => its decision.
   *
   * @return array<string, string|null>
   */
  public function appDecisions(NodeInterface $review): array {
    $decisions = [];
    foreach ($review->get('field_arv_verdicts')->referencedEntities() as $verdict) {
      $decisions[(string) $verdict->id()] = $verdict->get('field_rvv_conclusion')->value;
    }
    return $decisions;
  }

  /**
   * Verdict paragraph id => the app it decides, where it names one.
   *
   * @return array<string, \Drupal\node\NodeInterface>
   */
  public function appNodes(NodeInterface $review): array {
    $apps = [];
    foreach ($review->hasField('field_arv_verdicts') ? $review->get('field_arv_verdicts')->referencedEntities() : [] as $verdict) {
      $app = $verdict->get('field_rvv_app_ref')->entity;
      if ($app instanceof NodeInterface) {
        $apps[(string) $verdict->id()] = $app;
      }
    }
    return $apps;
  }

  protected function publishReview(NodeInterface $review): void {
    $storage = $this->entityTypeManager->getStorage('node');
    foreach (['draft' => ['in_review', 'published'], 'in_review' => ['published']][$review->get('moderation_state')->value ?? ''] ?? [] as $state) {
      $fresh = $storage->loadUnchanged($review->id());
      $fresh->set('moderation_state', $state);
      $this->saveRevision($fresh, sprintf('Review page: moved to %s by %s', $state, $this->currentUser->getDisplayName()));
    }
  }

  /**
   * Publishes a repo or an app from its latest revision, as the hub's publish
   * does, so in-flight edits are what go live.
   */
  protected function publishNode(NodeInterface $node, string $log): bool {
    // Already live: nothing to move, and a published-to-published save of a
    // repo would send the publish email again.
    if ($node->isPublished()) {
      return FALSE;
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $latest = $storage->getLatestRevisionId($node->id());
    $fresh = $latest ? $storage->loadRevision($latest) : $storage->loadUnchanged($node->id());
    if (!$fresh instanceof NodeInterface || !$this->canMove($fresh, 'published')) {
      return FALSE;
    }
    $fresh->set('moderation_state', 'published');
    // The decision email replaces the repo's "published" email. The flag is
    // read at runtime by hook_node_update(); it is not a field.
    // @phpstan-ignore-next-line
    $fresh->_ood_software_suppress_notifications = TRUE;
    $this->saveRevision($fresh, $log);
    $this->messenger->addStatus($this->t('Published @title.', ['@title' => $fresh->label()]));
    return TRUE;
  }

  /**
   * Moves an app to needs_adjustment or declined; one that cannot move from
   * where it is (a draft app is not live) stays.
   */
  protected function moveNode(NodeInterface $node, string $state, string $log): void {
    $fresh = $this->entityTypeManager->getStorage('node')->loadUnchanged($node->id());
    if (!$fresh instanceof NodeInterface || !$this->canMove($fresh, $state)) {
      return;
    }
    $fresh->set('moderation_state', $state);
    $this->saveRevision($fresh, $log);
    $this->messenger->addStatus($state === 'declined'
      ? $this->t('Declined @title.', ['@title' => $fresh->label()])
      : $this->t('Sent @title back for changes.', ['@title' => $fresh->label()]));
  }

  /**
   * Moves the repo to needs_adjustment or declined, as the hub's request
   * changes does: the response becomes the revision log (the hub card's
   * feedback), and a live repo's apps are unpublished with it. The decision
   * email replaces the transition's own.
   */
  protected function moveRepo(NodeInterface $repo, string $state, string $response, string $cascadeLog): void {
    // save() does not validate the transition, so check it here: a repo
    // already sent back (needs_adjustment) cannot move again until it is
    // re-submitted.
    if (!$this->canMove($repo, $state)) {
      $this->messenger->addWarning($this->t('The decision was recorded, but @title stays @from: it cannot move to @to from there.', [
        '@title' => $repo->label(),
        '@from' => $repo->get('moderation_state')->value,
        '@to' => $state,
      ]));
      return;
    }
    $wasPublished = $repo->isPublished();
    $repo->set('moderation_state', $state);
    $repo->setRevisionLogMessage($response);
    $repo->setNewRevision(TRUE);
    // A runtime flag hook_node_update() reads; not a field.
    // @phpstan-ignore-next-line
    $repo->_ood_software_suppress_notifications = TRUE;
    $repo->save();
    if ($wasPublished) {
      $count = $this->repoMemberApps->cascadeModeration($repo, 'draft', [], $cascadeLog, TRUE);
      if ($count > 0) {
        $this->messenger->addStatus($this->t('Also unpublished @count member apps under @title.', ['@count' => $count, '@title' => $repo->label()]));
      }
    }
  }

  /**
   * Whether the node's workflow allows moving it to $state from where it is.
   */
  protected function canMove(NodeInterface $node, string $state): bool {
    $workflow = $this->moderationInformation->getWorkflowForEntity($node)?->getTypePlugin();
    $from = (string) ($node->get('moderation_state')->value ?? '');
    return $workflow && $workflow->hasState($from) && $workflow->hasState($state)
      && $workflow->getState($from)->canTransitionTo($state);
  }

  protected function saveRevision(NodeInterface $node, string $message): void {
    $node->setNewRevision(TRUE);
    $node->setRevisionUserId((int) $this->currentUser->id());
    $node->setRevisionCreationTime($this->time->getCurrentTime());
    $node->setRevisionLogMessage($message);
    if (method_exists($node, 'setValidationRequired')) {
      $node->setValidationRequired(FALSE);
    }
    $node->save();
  }

}
