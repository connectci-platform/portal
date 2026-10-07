<?php

namespace Drupal\ood_software\Service;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * What sending a review's decision means: the checks and the wording.
 *
 * Pure. The decision is made on the review page and moves the repo and the
 * review together (appverse-planning#29; REVIEW-STATES.md). The page's send
 * button names the decision and says why it is not ready yet; the confirm
 * page heads the email preview with the decision and what it causes
 * (appverse-planning#52). ReviewDecisionApplier carries the decision out.
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

  /**
   * Where an updated decision leaves the repo, each app and the review
   * (appverse-planning#56).
   *
   * Unlike plan(), which lists the moves a first decision makes from where a
   * submission stands, this names the state each should end in, so an update
   * can get there from wherever the first decision left it. Accepted apps are
   * live; an app accepted with suggestions waits, ready to publish, unless it
   * is already live; an app sent back or declined leaves the catalog. The repo
   * is live when any app is accepted, waits for Publish when only suggestions
   * remain (or stays live, when it was live before this round), goes back to
   * the contributor when any app can be fixed, and is declined only when
   * every app is. The review is public only after an Accept.
   *
   * @param array<string, string> $appDecisions
   *   App key => its new decision.
   * @param array<string, bool> $appLive
   *   App key => whether that app is live now.
   * @param bool $repoWasLive
   *   Whether the repo was live before this round's first decision.
   *
   * @return array{repo: string, apps: array<string, ?string>, review: string}
   *   Moderation states; an app's NULL means it stays as it is.
   */
  public static function updateTargets(array $appDecisions, array $appLive, bool $repoWasLive): array {
    $decisions = array_values($appDecisions);
    $any = static fn (string $d): bool => in_array($d, $decisions, TRUE);
    $repo = match (TRUE) {
      $any('accept') => 'published',
      $any('accept_with_suggestions') => $repoWasLive ? 'published' : 'ready_for_review',
      $any('request_changes') => 'needs_adjustment',
      default => 'declined',
    };
    $apps = [];
    foreach ($appDecisions as $app => $decision) {
      $apps[$app] = match ($decision) {
        'accept' => 'published',
        'accept_with_suggestions' => ($appLive[$app] ?? FALSE) ? NULL : 'ready_for_review',
        'request_changes' => 'needs_adjustment',
        'reject' => 'declined',
        default => NULL,
      };
    }
    return ['repo' => $repo, 'apps' => $apps, 'review' => $any('accept') ? 'published' : 'in_review'];
  }

  /**
   * Who the repo waits on once the decision is sent: the decision-sent view's
   * sentence (appverse-planning#56).
   *
   * @param array<string, string> $apps
   *   The sent decisions (ReviewDecisionApplier::sent()).
   * @param bool $publishPending
   *   Whether "Publish app and review" is still to do.
   * @param string $contributor
   *   The contributor's name.
   */
  public static function waitingOn(array $apps, bool $publishPending, string $contributor): string {
    $any = static fn (string $d): bool => in_array($d, $apps, TRUE);
    return match (TRUE) {
      $any('request_changes') => sprintf('Waiting on %s to re-submit. They were asked to fix the repo on GitHub and re-submit from their AppVerse page.', $contributor),
      $publishPending => sprintf('Waiting on a reviewer to publish. %s was told it is accepted with suggestions.', $contributor),
      $any('accept') || $any('accept_with_suggestions') => 'Nothing to wait on: it is in the AppVerse catalog.',
      default => 'Nothing to wait on: it was declined.',
    };
  }

  const APP_MOVES = ['accept' => 'publish', 'request_changes' => 'needs_adjustment', 'reject' => 'declined'];

  /**
   * The review page's send button: it names the decision it will send.
   *
   * @param array<string, string|null> $appDecisions
   *   Verdict id => its decision, or NULL when not decided.
   */
  public static function sendLabel(array $appDecisions): TranslatableMarkup {
    $decided = array_values(array_filter($appDecisions, static fn ($d) => in_array($d, ReviewProgress::DECISIONS, TRUE)));
    $distinct = array_values(array_unique($decided));
    if (count($distinct) === 1) {
      return match ($distinct[0]) {
        'accept' => new TranslatableMarkup('Accept and publish'),
        'accept_with_suggestions' => new TranslatableMarkup('Accept with suggestions'),
        'request_changes' => new TranslatableMarkup('Request changes'),
        default => new TranslatableMarkup('Decline'),
      };
    }
    if ($distinct === []) {
      return new TranslatableMarkup('Send decision');
    }
    $counts = array_count_values($decided);
    $parts = [];
    foreach (ReviewProgress::DECISIONS as $decision) {
      if (isset($counts[$decision])) {
        $args = ['@count' => $counts[$decision]];
        $parts[] = (string) match ($decision) {
          'accept' => new TranslatableMarkup('@count accepted', $args),
          'accept_with_suggestions' => new TranslatableMarkup('@count accepted with suggestions', $args),
          'request_changes' => new TranslatableMarkup('@count changes requested', $args),
          default => new TranslatableMarkup('@count declined', $args),
        };
      }
    }
    return new TranslatableMarkup('Send decisions (@summary)', ['@summary' => implode(', ', $parts)]);
  }

  /**
   * Why the send button is not ready yet, or NULL when it is.
   *
   * @param array<string, string|null> $appDecisions
   *   Verdict id => its decision, or NULL when not decided.
   * @param string $response
   *   The response to the contributor.
   */
  public static function sendBlocker(array $appDecisions, string $response): ?TranslatableMarkup {
    if ($appDecisions === []) {
      return new TranslatableMarkup('The review has no apps to decide.');
    }
    foreach ($appDecisions as $decision) {
      if (!in_array($decision, ReviewProgress::DECISIONS, TRUE)) {
        return new TranslatableMarkup('Choose a decision for every app first.');
      }
    }
    if (ReviewProgress::strictestDecision(array_values($appDecisions)) !== 'accept' && trim($response) === '') {
      return new TranslatableMarkup('Write the response to the contributor first.');
    }
    return NULL;
  }

  /**
   * The confirm page's heading: the decision, said whole.
   *
   * @param array<string, string> $appDecisions
   *   Verdict id => its decision.
   * @param string $repo
   *   The repo's name.
   */
  public static function headline(array $appDecisions, string $repo): TranslatableMarkup {
    $distinct = array_values(array_unique(array_values($appDecisions)));
    $args = ['@repo' => $repo];
    if (count($distinct) !== 1) {
      return new TranslatableMarkup('Send the decisions on @repo', $args);
    }
    return match ($distinct[0]) {
      'accept' => new TranslatableMarkup('Accept and publish @repo', $args),
      'accept_with_suggestions' => new TranslatableMarkup('Accept @repo with suggestions', $args),
      'request_changes' => new TranslatableMarkup('Request changes on @repo', $args),
      default => new TranslatableMarkup('Decline @repo', $args),
    };
  }

  /**
   * What sending causes, in a sentence or two, the public side stated plainly.
   *
   * @param array<string, string> $appDecisions
   *   Verdict id => its decision.
   * @param bool $repoPublished
   *   Whether the repo is live.
   * @param string $contributor
   *   The contributor's name.
   *
   * @return array<int, \Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  public static function consequences(array $appDecisions, bool $repoPublished, string $contributor): array {
    $distinct = array_values(array_unique(array_values($appDecisions)));
    $any = static fn (string $d): bool => in_array($d, $appDecisions, TRUE);
    $args = ['@name' => $contributor !== '' ? $contributor : new TranslatableMarkup('The contributor')];
    $out = [];
    if (count($distinct) === 1) {
      $out[] = match ($distinct[0]) {
        'accept' => $repoPublished
          ? new TranslatableMarkup('The repo stays live, and the review is published.')
          : new TranslatableMarkup('The repo, its apps and the review are published in the Appverse catalog.'),
        'accept_with_suggestions' => $repoPublished
          ? new TranslatableMarkup('The repo stays live. The review is published when you use Publish on this review.')
          : new TranslatableMarkup('Nothing is published yet. The repo waits as Ready to publish, and a Publish button stays on this review until you use it.'),
        'request_changes' => $repoPublished
          ? new TranslatableMarkup('The repo moves to Needs changes and leaves the catalog with its apps.')
          : new TranslatableMarkup('The repo moves to Needs changes.'),
        default => $repoPublished
          ? new TranslatableMarkup('The repo is declined and leaves the catalog with its apps. The review is never published.')
          : new TranslatableMarkup('The repo is declined and does not enter the catalog. The review is never published.'),
      };
    }
    else {
      if ($any('accept')) {
        $out[] = $repoPublished
          ? new TranslatableMarkup('The repo stays live, the accepted apps are published, and so is the review.')
          : new TranslatableMarkup('The repo is published in the Appverse catalog with the accepted apps, and so is the review.');
      }
      elseif ($any('accept_with_suggestions')) {
        $out[] = $repoPublished
          ? new TranslatableMarkup('The repo stays live. The apps accepted with suggestions and the review are published when you use Publish on this review.')
          : new TranslatableMarkup('Nothing is published yet. A Publish button stays on this review for the apps accepted with suggestions.');
      }
      elseif ($any('request_changes')) {
        $out[] = $repoPublished
          ? new TranslatableMarkup('The repo moves to Needs changes and leaves the catalog with its apps.')
          : new TranslatableMarkup('The repo moves to Needs changes.');
      }
      if ($any('reject')) {
        $out[] = new TranslatableMarkup('The declined apps stay out of the catalog.');
      }
    }
    if ($any('request_changes')) {
      $out[] = new TranslatableMarkup('@name can read the review and re-submit the whole repo, which starts the next round.', $args);
    }
    else {
      $out[] = new TranslatableMarkup('@name can read the review and your response.', $args);
    }
    return $out;
  }

}
