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
   *   Verdict id => its decision (accept, accept_with_suggestions,
   *   request_changes, reject), or NULL when not decided.
   * @param string $response
   *   The response to the contributor.
   * @param array<string, string> $names
   *   Verdict id => the app's name; the key stands in without one.
   *
   * @return array<int, string>
   */
  public static function problems(array $appDecisions, string $response, array $names = []): array {
    $problems = [];
    if ($appDecisions === []) {
      $problems[] = 'The review has no apps to decide.';
    }
    foreach ($appDecisions as $app => $decision) {
      if (!in_array($decision, ReviewProgress::DECISIONS, TRUE)) {
        $problems[] = sprintf('Choose a decision for %s.', $names[$app] ?? $app);
      }
    }
    $overall = ReviewProgress::strictestDecision(array_values($appDecisions));
    if ($overall !== NULL && $overall !== 'accept' && trim($response) === '') {
      $problems[] = 'Write the response to the contributor: it is required for every decision except Accept.';
    }
    return $problems;
  }

  /**
   * What sending moves, from each app's decision (appverse-planning#30).
   *
   * Each app gets its own outcome, and the repo is published when any app is:
   * an accepted app is published with the repo and the review at once; an app
   * accepted with suggestions waits for the review page's Publish; an app sent
   * back or declined leaves the catalog. With no app accepted, the repo waits
   * for Publish when any app was accepted with suggestions, goes back to the
   * contributor when any app can be fixed, and is declined only when every app
   * is. Every app makes its own move, also when it goes where the repo goes:
   * the repo's cascade only takes live apps, and only to draft, which left a
   * submitted app in ready_for_review under a repo sent back
   * (appverse-planning#50).
   *
   * @param array<string, string> $appDecisions
   *   App key => its decision.
   *
   * @return array{repo: ?string, apps: array<string, ?string>, review: ?string}
   *   repo and each app: publish, needs_adjustment, declined, or NULL to stay
   *   as it is; review: publish or NULL.
   */
  public static function plan(array $appDecisions): array {
    $decisions = array_values($appDecisions);
    $any = static fn (string $d): bool => in_array($d, $decisions, TRUE);
    $repo = match (TRUE) {
      $any('accept') => 'publish',
      $any('accept_with_suggestions') => NULL,
      $any('request_changes') => 'needs_adjustment',
      $decisions !== [] => 'declined',
      default => NULL,
    };
    $apps = [];
    foreach ($appDecisions as $app => $decision) {
      $apps[$app] = self::APP_MOVES[$decision] ?? NULL;
    }
    return ['repo' => $repo, 'apps' => $apps, 'review' => $any('accept') ? 'publish' : NULL];
  }

  const APP_MOVES = ['accept' => 'publish', 'request_changes' => 'needs_adjustment', 'reject' => 'declined'];

  /**
   * The "When you confirm" rows for each app's decision.
   *
   * One decision for every app reads as effects() does; a mix adds an Apps
   * row and follows plan().
   *
   * @param array<string, string> $appDecisions
   *   Verdict id => its decision.
   * @param bool $repoPublished
   *   Whether the repo is live.
   * @param array<string, string> $names
   *   Verdict id => the app's name; the key stands in without one.
   *
   * @return array<int, array{0: string, 1: string}>
   */
  public static function effectsFor(array $appDecisions, bool $repoPublished, array $names = []): array {
    $distinct = array_values(array_unique(array_values($appDecisions)));
    if (count($distinct) === 1) {
      return self::effects($distinct[0], $repoPublished);
    }
    $plan = self::plan($appDecisions);
    $any = static fn (string $d): bool => in_array($d, $appDecisions, TRUE);

    $apps = [];
    foreach ($appDecisions as $app => $decision) {
      $apps[] = ($names[$app] ?? $app) . ': ' . match ($decision) {
        'accept' => 'published',
        'accept_with_suggestions' => 'published when you use Publish',
        'request_changes' => 'unpublished, back to the contributor as Needs changes',
        default => 'unpublished and declined',
      };
    }
    $next = [];
    if ($any('accept_with_suggestions')) {
      $next[] = 'A Publish button stays at the top of this review until you use it.';
    }
    if ($any('request_changes')) {
      $next[] = $plan['repo'] === 'needs_adjustment'
        ? 'Wait for the re-submission.'
        : 'When the contributor re-submits the apps sent back, run Re-review on the repo for the next round.';
    }

    return [
      ['Repo', match ($plan['repo']) {
        'publish' => $repoPublished ? 'Stays live in the AppVerse catalog.' : 'Published in the AppVerse catalog, with the accepted apps only.',
        'needs_adjustment' => $repoPublished ? 'Unpublished, with its apps, and back to the contributor as Needs changes.' : 'Back to the contributor as Needs changes.',
        'declined' => $repoPublished ? 'Unpublished, with its apps, and declined.' : 'Declined; it does not enter the catalog.',
        default => $repoPublished ? 'Stays live; nothing changes in the catalog until you publish this review.' : 'Stays in the queue as Ready to publish. A new app is not public yet.',
      }],
      ['Apps', implode('; ', $apps) . '.'],
      ['Review', match (TRUE) {
        $plan['review'] === 'publish' => "Published with the accepted apps: the public sees its summary, with every app's decision.",
        $any('accept_with_suggestions') => 'Not public yet; it is published when you use Publish.',
        default => 'Not public.',
      }],
      ['Email', 'One email to the contributor, listing each app\'s decision, with your response.'
        . ($any('accept_with_suggestions') && !$repoPublished ? ' Another goes out when you publish.' : '')],
      ['Contributor', 'Can read the review and your response.' . ($any('request_changes') ? ' Fixes the apps sent back and re-submits them.' : '')],
      ['Next step', $next !== [] ? implode(' ', $next) : 'None.'],
    ];
  }

  /**
   * The "When you confirm" rows when every app has the same decision.
   *
   * @param bool $repoPublished
   *   Whether the repo is live now.
   *
   * @return array<int, array{0: string, 1: string}>
   *   [heading, what happens] for Repo, Review, Email, Contributor, Next step.
   */
  public static function effects(string $decision, bool $repoPublished): array {
    return match ($decision) {
      // Every decision sends one email for the repo (#32; DecisionEmail).
      'accept' => [
        ['Repo', $repoPublished
          ? 'Stays live in the AppVerse catalog.'
          : 'Published, with its apps, in the AppVerse catalog.'],
        ['Review', 'Published: the public sees its summary.'],
        ['Email', $repoPublished
          ? 'The contributor is told it is accepted and stays live.'
          : 'The contributor is told the repo is accepted and published.'],
        ['Contributor', 'Can read the review and your response.'],
        ['Next step', 'None: the repo is live.'],
      ],
      'accept_with_suggestions' => [
        ['Repo', $repoPublished
          ? 'Stays live; nothing changes in the catalog until you publish this review.'
          : 'Stays in the queue as Ready to publish. A new app is not public yet.'],
        ['Review', 'Not public yet.'],
        ['Email', $repoPublished
          ? 'The contributor gets your suggestions and is told it is accepted.'
          : 'The contributor gets your suggestions and is told it is accepted. Another goes out when you publish.'],
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
