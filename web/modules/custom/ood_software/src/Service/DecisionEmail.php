<?php

namespace Drupal\ood_software\Service;

/**
 * The contributor's decision email, and the "now live" email after Publish.
 *
 * Pure. One email per decision, for the whole repo (appverse-planning#32):
 * it replaces the generic "changes requested" and "published" emails on the
 * review flow. It carries the reviewer's response and, for a monorepo, each
 * app's decision. hook_mail() renders the blocks; RepoNotificationService
 * sends them to the repo's owner.
 *
 * A block is one of:
 * - ['p', text]: a paragraph.
 * - ['apps', [name => label]]: the per-app decisions.
 * - ['response', text]: the reviewer's response, line breaks kept.
 * - ['link', label, url].
 */
final class DecisionEmail {

  /**
   * The decision email.
   *
   * @param array<string, string> $appDecisions
   *   App name => its decision.
   * @param bool $wasLive
   *   Whether the repo was in the catalog before the decision.
   * @param array{review: string, hub: string, catalog: string} $links
   *   Absolute URLs of the review page, the contributor's AppVerse page and
   *   the repo in the catalog.
   *
   * @return array{subject: string, blocks: array<int, array>}
   */
  public static function decision(string $site, string $repo, array $appDecisions, string $response, bool $wasLive, array $links): array {
    $distinct = array_values(array_unique(array_values($appDecisions)));
    $mixed = count($distinct) > 1;
    $decision = $mixed ? NULL : ($distinct[0] ?? NULL);
    $plan = ReviewDecision::plan($appDecisions);
    $any = static fn (string $d): bool => in_array($d, $appDecisions, TRUE);

    $subject = match ($decision) {
      'accept' => $wasLive ? "[$site] Review complete: $repo is accepted" : "[$site] Accepted: $repo is in the AppVerse",
      'accept_with_suggestions' => "[$site] Accepted with suggestions: $repo",
      'request_changes' => "[$site] Changes requested on $repo",
      'reject' => "[$site] $repo was not accepted",
      default => "[$site] Review decisions for $repo",
    };

    $blocks = [];
    $blocks[] = ['p', match ($decision) {
      'accept' => $wasLive
        ? "A reviewer reviewed your repo \"$repo\" again and accepted it. It stays in the AppVerse catalog."
        : "A reviewer accepted your repo \"$repo\". It is now published in the AppVerse catalog.",
      'accept_with_suggestions' => $wasLive
        ? "A reviewer reviewed your repo \"$repo\" again and accepted it, with suggestions below. It stays in the AppVerse catalog; the suggestions are worth a look when you next update it."
        : "A reviewer accepted your repo \"$repo\", with suggestions below. A reviewer will publish it in the AppVerse catalog; you are welcome to act on the suggestions first, but you do not have to.",
      'request_changes' => $wasLive
        ? "A reviewer is asking for changes to your repo \"$repo\". It is out of the AppVerse catalog until they are made."
        : "A reviewer is asking for changes to your repo \"$repo\" before it can be published in the AppVerse catalog.",
      'reject' => $wasLive
        ? "A reviewer did not accept your repo \"$repo\", and it has been removed from the AppVerse catalog."
        : "A reviewer did not accept your repo \"$repo\" into the AppVerse catalog.",
      default => "A reviewer has decided on each app in your repo \"$repo\":",
    }];

    if (count($appDecisions) > 1) {
      $blocks[] = ['apps', array_map(static fn ($d) => ReviewProgress::DECISION_LABELS[$d] ?? (string) $d, $appDecisions)];
    }
    if ($mixed) {
      if ($plan['repo'] === 'publish' && !$wasLive) {
        $blocks[] = ['p', 'The accepted apps are now published in the AppVerse catalog.'];
      }
      elseif ($any('accept_with_suggestions') && !$any('accept')) {
        $blocks[] = ['p', 'A reviewer will publish the accepted apps in the AppVerse catalog.'];
      }
    }

    if (trim($response) !== '') {
      $blocks[] = ['p', "The reviewer's response:"];
      $blocks[] = ['response', trim($response)];
    }

    if ($any('request_changes')) {
      $blocks[] = ['p', $mixed && $plan['repo'] !== 'needs_adjustment'
        ? 'Fix the apps marked Changes requested, then re-submit them from your AppVerse page. They are reviewed again in the next round.'
        : 'Make the changes, then re-submit the repo from your AppVerse page. The next review starts when you do.'];
      $blocks[] = ['link', 'Your AppVerse page', $links['hub']];
    }
    if ($plan['repo'] === 'publish') {
      $blocks[] = ['link', 'View it in the catalog', $links['catalog']];
    }
    $blocks[] = ['link', 'Read the full review', $links['review']];

    return ['subject' => $subject, 'blocks' => $blocks];
  }

  /**
   * The "now live" email after "Publish app and review".
   *
   * @param string[] $apps
   *   The names of the apps published now; listed for a monorepo.
   * @param array{review: string, catalog: string} $links
   */
  public static function nowLive(string $site, string $repo, array $apps, bool $monorepo, array $links): array {
    $blocks = [['p', "Your repo \"$repo\" is now published in the AppVerse catalog."]];
    if ($monorepo && $apps !== []) {
      $blocks[] = ['p', 'Published now:'];
      $blocks[] = ['apps', array_fill_keys($apps, 'Published')];
    }
    $blocks[] = ['link', 'View it in the catalog', $links['catalog']];
    $blocks[] = ['link', 'Read the review', $links['review']];
    return ['subject' => "[$site] $repo is now in the AppVerse", 'blocks' => $blocks];
  }

}
