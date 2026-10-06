<?php

namespace Drupal\ood_software\Service;

/**
 * What sending a review's decision means: the checks and the wording.
 *
 * Pure. The decision is made on the review page and moves the repo and the
 * review together (appverse-planning#29; REVIEW-STATES.md). Before
 * confirming, the reviewer sees every effect the choice causes: these are
 * the "When you confirm" rows of the decision panel mock. ReviewDecisionApplier
 * carries the decision out.
 */
final class ReviewDecision {

  /**
   * Why the decision cannot be sent yet; [] when it can.
   *
   * Every app needs a decision, and the response to the contributor is
   * required for every overall decision except Accept.
   *
   * @param array<string, string|null> $appDecisions
   *   App name => its decision (accept, accept_with_suggestions,
   *   request_changes, reject), or NULL when not decided.
   */
  public static function problems(array $appDecisions, string $response): array {
    $problems = [];
    if ($appDecisions === []) {
      $problems[] = 'The review has no apps to decide.';
    }
    foreach ($appDecisions as $app => $decision) {
      if (!in_array($decision, ReviewProgress::DECISIONS, TRUE)) {
        $problems[] = sprintf('Choose a decision for %s.', $app);
      }
    }
    $overall = ReviewProgress::strictestDecision(array_values($appDecisions));
    if ($overall !== NULL && $overall !== 'accept' && trim($response) === '') {
      $problems[] = 'Write the response to the contributor: it is required for every decision except Accept.';
    }
    return $problems;
  }

  /**
   * The "When you confirm" rows for an overall decision.
   *
   * @param bool $repoPublished
   *   Whether the repo is live now.
   *
   * @return array<int, array{0: string, 1: string}>
   *   [heading, what happens] for Repo, Review, Email, Contributor, Next step.
   */
  public static function effects(string $decision, bool $repoPublished): array {
    return match ($decision) {
      // Email goes out only when the repo changes state (the notifier on
      // repo transitions); decision emails of their own are #32.
      'accept' => [
        ['Repo', $repoPublished
          ? 'Stays live in the AppVerse catalog.'
          : 'Published, with its apps, in the AppVerse catalog.'],
        ['Review', 'Published: the public sees its summary.'],
        ['Email', $repoPublished
          ? 'None: the repo was already live. The contributor sees the review here.'
          : 'The contributor is told the repo is published.'],
        ['Contributor', 'Can read the review and your response.'],
        ['Next step', 'None: the repo is live.'],
      ],
      'accept_with_suggestions' => [
        ['Repo', $repoPublished
          ? 'Stays live; nothing changes in the catalog until you publish this review.'
          : 'Stays in the queue as Ready to publish. A new app is not public yet.'],
        ['Review', 'Not public yet.'],
        ['Email', 'None yet. The contributor sees the review and your suggestions here.'],
        ['Contributor', 'Can read the review and your suggestions. The public cannot yet.'],
        ['Next step', 'A Publish button stays at the top of this review until you use it.'],
      ],
      'request_changes' => [
        ['Repo', $repoPublished
          ? 'Unpublished, with its apps, and back to the contributor as Needs changes.'
          : 'Back to the contributor as Needs changes.'],
        ['Review', 'Not public.'],
        ['Email', 'The contributor gets your requested changes.'],
        ['Contributor', 'Can read the review and your response, fix the repo, and re-submit (a new review round).'],
        ['Next step', 'Wait for the re-submission.'],
      ],
      'reject' => [
        ['Repo', $repoPublished
          ? 'Unpublished, with its apps, and declined.'
          : 'Declined; it does not enter the catalog.'],
        ['Review', 'Never public.'],
        ['Email', 'The contributor is told it is declined, with your response.'],
        ['Contributor', 'Can read the review and your response.'],
        ['Next step', 'None.'],
      ],
      default => [],
    };
  }

}
