<?php

namespace Drupal\ood_software\Service;

/**
 * Where a repo is in review: the progress line's steps, from stored facts.
 *
 * The one place that answers "which step, in what state" for the review
 * redesign (appverse-planning#27, #28). Pure: callers gather the facts, this
 * maps them to steps, so the progress line (#31) and the decision email (#32)
 * agree. The eleven situations of appverse-planning
 * review-system/REVIEW-STATES.md are its unit tests.
 *
 * Reviewers see five steps (Submitted, AI report, Review, Decision, Live);
 * contributors see four, with the AI report folded into "In review", so no
 * AI state (queued, running, failed) ever reaches them.
 */
final class ReviewProgress {

  /**
   * Step states, as the progress line draws them.
   */
  const DONE = 'done';
  const CURRENT = 'current';
  const WAITING = 'waiting';
  const FAILED = 'failed';
  const NOT_REACHED = 'not_reached';

  /**
   * Badge modifiers for the card chip, one per step state.
   */
  const CHIP_MODIFIERS = [
    self::DONE => 'success',
    self::CURRENT => 'warning',
    self::WAITING => 'warning',
    self::FAILED => 'danger',
    self::NOT_REACHED => 'secondary',
  ];

  /**
   * Decisions from mildest to strictest.
   */
  const DECISIONS = ['accept', 'accept_with_suggestions', 'request_changes', 'reject'];

  /**
   * The English source strings, which is what a constant can hold.
   *
   * Read these through decisionLabel() rather than directly: a constant
   * cannot call t(), so using it as a label ships English whatever the site's
   * language (appverse-planning#49). Kept because the keys are the stored
   * decision values and several places need the set.
   */
  const DECISION_LABELS = [
    'accept' => 'Accepted',
    'accept_with_suggestions' => 'Accepted with suggestions',
    'request_changes' => 'Changes requested',
    'reject' => 'Declined',
  ];

  /**
   * A decision's label, translated.
   *
   * The literal t() calls are what the extractor finds; the match picks one.
   */
  public static function decisionLabel(?string $decision): string {
    return (string) match ($decision) {
      'accept' => t('Accepted'),
      'accept_with_suggestions' => t('Accepted with suggestions'),
      'request_changes' => t('Changes requested'),
      'reject' => t('Declined'),
      default => '',
    };
  }

  const SUGGESTION_LABELS = [
    'accept' => 'Accept',
    'accept_with_suggestions' => 'Accept with suggestions',
    'request_changes' => 'Request changes',
    'reject' => 'Reject',
  ];

  /**
   * The overall decision of a review: its strictest per-app decision.
   *
   * The rubric's monorepo rule (appverse-review#70): the overall
   * recommendation is the strictest per-app one. NULL when no app has a
   * decision yet; unknown values are ignored.
   */
  public static function strictestDecision(array $decisions): ?string {
    $strictest = NULL;
    foreach ($decisions as $decision) {
      $rank = array_search($decision, self::DECISIONS, TRUE);
      if ($rank !== FALSE && ($strictest === NULL || $rank > array_search($strictest, self::DECISIONS, TRUE))) {
        $strictest = $decision;
      }
    }
    return $strictest;
  }

  /**
   * The steps for one repo.
   *
   * @param array $facts
   *   - repo_state: the repo's moderation state (draft, ready_for_review,
   *     needs_adjustment, published, declined, archived).
   *   - run_status: the automated run (pending, in_progress, complete, error),
   *     or NULL when none was ever started.
   *   - review_state: the newest review's moderation state (draft, in_review,
   *     published), or NULL when there is none.
   *   - decision_sent: whether that review's decision has been sent.
   *   - decision: its overall decision (see strictestDecision()), or NULL.
   *   - suggestion: the tool's recommendation, or NULL.
   *   - round: 1 for a first submission, N for the Nth after changes requested.
   *
   * @return array{reviewer: array<int, array{step: string, state: string, label: string}>, contributor: array<int, array{step: string, state: string, label: string}>}
   */
  public static function steps(array $facts): array {
    $repoState = (string) ($facts['repo_state'] ?? 'draft');
    $run = $facts['run_status'] ?? NULL;
    $reviewState = $facts['review_state'] ?? NULL;
    $sent = (bool) ($facts['decision_sent'] ?? FALSE);
    $decision = $sent ? ($facts['decision'] ?? NULL) : NULL;
    $suggestion = $facts['suggestion'] ?? NULL;
    $round = max(1, (int) ($facts['round'] ?? 1));
    $appDecisions = is_array($facts['app_decisions'] ?? NULL) ? $facts['app_decisions'] : [];

    // 1. Submitted: not until the repo has left draft (or a run started).
    $submitted = !($repoState === 'draft' && $run === NULL);
    $submittedStep = $submitted
      ? self::step('Submitted', self::DONE, $round > 1 ? "Resubmitted · round $round" : 'Submitted')
      : self::step('Submitted', self::WAITING, 'Not submitted');

    // A repo with no run and no review outside the queue predates reviews
    // (published, or sent back from the hub, before the review system): its
    // review steps were never reached. In the queue with no run (a dispatch
    // that never happened), a reviewer has to start one.
    $neverReviewed = $run === NULL && $reviewState === NULL && $repoState !== 'ready_for_review';

    // 2. AI report.
    $aiDone = $submitted && $run === 'complete';
    if (!$submitted || $neverReviewed) {
      $ai = self::step('AI report', self::NOT_REACHED, '');
    }
    elseif ($run === NULL) {
      $ai = self::step('AI report', self::CURRENT, 'Not started');
    }
    else {
      $ai = match ($run) {
        'complete' => self::step('AI report', self::DONE, $suggestion && isset(self::SUGGESTION_LABELS[$suggestion]) ? 'Ready · suggests ' . self::SUGGESTION_LABELS[$suggestion] : 'Ready'),
        'error' => self::step('AI report', self::FAILED, 'Failed · rerun'),
        'in_progress' => self::step('AI report', self::CURRENT, 'Running'),
        default => self::step('AI report', self::CURRENT, 'Queued'),
      };
    }

    // 3. Review: started by the reviewer's first save (draft → in_review).
    if (!$aiDone) {
      $review = self::step('Review', self::NOT_REACHED, '');
    }
    elseif ($sent) {
      $review = self::step('Review', self::DONE, 'Done');
    }
    elseif ($reviewState === 'in_review') {
      $review = self::step('Review', self::CURRENT, 'In progress');
    }
    else {
      $review = self::step('Review', self::CURRENT, 'Not started');
    }

    // 4. Decision.
    $decisionStep = self::decisionStep($decision, $round, $appDecisions);

    // 5. Live.
    if ($repoState === 'published') {
      $live = self::step('Live', self::DONE, 'Live');
    }
    elseif (in_array($decision, ['accept', 'accept_with_suggestions'], TRUE)) {
      $live = self::step('Live', self::CURRENT, 'Ready to publish');
    }
    else {
      $live = self::step('Live', self::NOT_REACHED, '');
    }

    // Contributors: AI report and Review fold into "In review", which stays
    // current from submission until a decision is sent, whatever the run is
    // doing; a failed run is a reviewer's to rerun and is never shown.
    if (!$submitted || $neverReviewed) {
      $inReview = self::step('In review', self::NOT_REACHED, '');
    }
    elseif ($sent) {
      $inReview = self::step('In review', self::DONE, 'Done');
    }
    else {
      // A live repo being reviewed again stays published while its sent-back
      // apps are re-reviewed (appverse-planning#48), so "In review" alone read
      // as though it had come down. Say both (appverse-planning#49).
      $liveUpdate = $repoState === 'published';
      $inReview = self::step('In review', self::CURRENT, $liveUpdate ? 'Published, update in review' : 'In review')
        + ['live_update' => $liveUpdate];
    }

    return [
      'reviewer' => [$submittedStep, $ai, $review, $decisionStep, $live],
      'contributor' => [$submittedStep, $inReview, $decisionStep, $live],
    ];
  }

  /**
   * The sentence on a contributor's hub card, from their four steps.
   *
   * "In review. A reviewer will respond by email." for everything from
   * submission to a decision (the mock); the other steps say what the
   * contributor does next, if anything.
   */
  public static function contributorSentence(array $steps): string {
    [$submitted, $inReview, $decision, $live] = $steps;
    return (string) match (TRUE) {
      $submitted['state'] === self::WAITING => t('Not submitted yet.'),
      // A live repo's update is in review while the repo itself stays up, so
      // say so rather than implying it has come down (appverse-planning#49).
      $inReview['state'] === self::CURRENT && !empty($inReview['live_update'])
        => t('Live, and your update is in review. A reviewer will respond by email.'),
      $inReview['state'] === self::CURRENT => t('In review. A reviewer will respond by email.'),
      // Name the button and where to ask, so the contributor does not have to
      // work out what "re-submit" means here (appverse-planning#55).
      $decision['state'] === self::WAITING => t('Changes requested. Read the review, fix the repo on GitHub, then click Re-submit. Questions? Reply to the review email.'),
      $decision['state'] === self::FAILED => t('Declined. The review says why.'),
      $live['state'] === self::CURRENT => t('Accepted. A reviewer will publish it.'),
      $live['state'] === self::DONE => t('Live in the Appverse catalog.'),
      default => '',
    };
  }

  /**
   * One chip for a hub card: where the repo is, in a few words.
   *
   * The five-step line is a lot to scan down a list, so the cards carry a
   * single chip and the review page keeps the full line
   * (appverse-planning#55). Built from the same steps the line draws, so the
   * two can never disagree.
   *
   * @param array $steps
   *   The reviewer or contributor steps from steps().
   *
   * @return array{label: string, modifier: string}
   *   A label and a badge modifier matching the hub's other chips.
   */
  public static function chip(array $steps): array {
    // Something in flight wins over a later step that is merely done. A live
    // repo being reviewed again is past Live and current at Review, and the
    // chip saying "Live" would hide the review the reviewer is looking for
    // (appverse-planning#49).
    foreach ($steps as $step) {
      if (in_array($step['state'], [self::CURRENT, self::FAILED], TRUE) && $step['label'] !== '') {
        return [
          'label' => $step['step'] === 'Review' ? 'In review' : $step['label'],
          'modifier' => self::CHIP_MODIFIERS[$step['state']] ?? 'secondary',
        ];
      }
    }
    // Otherwise walk back from the end: the furthest step that has been
    // reached is where the repo is now.
    foreach (array_reverse($steps) as $step) {
      if ($step['state'] === self::NOT_REACHED || $step['label'] === '') {
        continue;
      }
      // Nothing has happened yet, so nothing is in flight: read it as neutral
      // rather than as something needing attention.
      if ($step['step'] === 'Submitted' && $step['state'] === self::WAITING) {
        return ['label' => 'Not submitted', 'modifier' => 'secondary'];
      }
      return [
        // On a card "In review" says where the repo is; the reviewer step's
        // own "In progress"/"Not started" belong to the full line, which the
        // review page still shows.
        'label' => $step['step'] === 'Review' ? 'In review' : $step['label'],
        'modifier' => self::CHIP_MODIFIERS[$step['state']] ?? 'secondary',
      ];
    }
    return ['label' => 'Not submitted', 'modifier' => 'secondary'];
  }

  protected static function decisionStep(?string $decision, int $round, array $appDecisions = []): array {
    $step = match ($decision) {
      'accept', 'accept_with_suggestions' => self::step('Decision', self::DONE, self::decisionLabel($decision)),
      'request_changes' => self::step('Decision', self::WAITING, self::decisionLabel($decision) . " · round $round"),
      'reject' => self::step('Decision', self::FAILED, self::decisionLabel($decision)),
      default => self::step('Decision', self::NOT_REACHED, ''),
    };
    // A monorepo whose apps went different ways: the label above is the
    // strictest of them, which on its own said "Changes requested" next to a
    // Live step with no word on which apps went live (appverse-planning#49).
    // Only when they differ; all-the-same repeats the label per app.
    if ($appDecisions !== [] && count(array_unique($appDecisions)) > 1) {
      $step['apps'] = array_map(
        static fn (string $d): string => self::decisionLabel($d) ?: $d,
        $appDecisions
      );
    }
    return $step;
  }

  /**
   * One step: its key, its state, and the label under it.
   *
   * 'step' stays the English key, because the chip and the template compare
   * against it; 'name' is the same thing for reading. The template used to
   * apply |t to the key, which the extractor cannot see, so the step names
   * never reached a translation file (appverse-planning#49).
   *
   * @return array{step: string, name: string, state: string, label: string}
   */
  protected static function step(string $step, string $state, string $label): array {
    // Whether the label is worth showing is decided here, where the English
    // is, rather than in the template comparing rendered text: a label that
    // repeats its step's name ("Submitted" under Submitted) or is a bare
    // "Done" on a step already drawn as done says nothing, and once the
    // strings are translated the template could not tell (appverse-planning#49).
    $redundant = $label === '' || $label === $step || ($state === self::DONE && $label === 'Done');
    return [
      'step' => $step,
      'name' => self::stepName($step),
      'state' => $state,
      'label' => self::stepLabel($label),
      'show_label' => !$redundant,
    ];
  }

  /**
   * A step's label, translated. Literal t() calls for the extractor.
   */
  protected static function stepLabel(string $label): string {
    return (string) match ($label) {
      '' => '',
      'Not submitted' => t('Not submitted'),
      'Submitted' => t('Submitted'),
      'Not started' => t('Not started'),
      'Queued' => t('Queued'),
      'Running' => t('Running'),
      'Failed · rerun' => t('Failed · rerun'),
      'Ready' => t('Ready'),
      'In progress' => t('In progress'),
      'In review' => t('In review'),
      'Published, update in review' => t('Published, update in review'),
      'Done' => t('Done'),
      'Live' => t('Live'),
      'Ready to publish' => t('Ready to publish'),
      // Composed at the call site (a round number, a suggestion, a decision
      // label): already built from translated parts.
      default => $label,
    };
  }

  /**
   * A step's name, translated. Literal t() calls for the extractor.
   */
  protected static function stepName(string $step): string {
    return (string) match ($step) {
      'Submitted' => t('Submitted'),
      'AI report' => t('AI report'),
      'Review' => t('Review'),
      'In review' => t('In review'),
      'Decision' => t('Decision'),
      'Live' => t('Live'),
      default => $step,
    };
  }

}
