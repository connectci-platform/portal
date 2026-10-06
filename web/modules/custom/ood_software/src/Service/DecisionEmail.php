<?php

namespace Drupal\ood_software\Service;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The contributor's decision email, and the "now live" email after Publish.
 *
 * Pure. One email per decision, for the whole repo (appverse-planning#32):
 * it replaces the generic "changes requested" and "published" emails on the
 * review flow. It greets the contributor, names the reviewer, carries the
 * reviewer's response and, for a monorepo, each app's decision, then says
 * what to do next and that a reply reaches the reviewer (#48). hook_mail()
 * renders the blocks in the recipient's language; RepoNotificationService
 * sends them to the repo's owner with the reviewer as Reply-To.
 *
 * Text is TranslatableMarkup; app names, the repo name and the response are
 * the contributor's own words and stay as they are. A block is one of:
 * - ['p', text]: a paragraph.
 * - ['apps', [[name, label], ...]]: the per-app decisions, in order. A list,
 *   not keyed by name, since two apps can share a name.
 * - ['response', text]: the reviewer's response, line breaks kept.
 * - ['link', label, url].
 */
final class DecisionEmail {

  /**
   * The decision email.
   *
   * @param string $site
   *   The site name.
   * @param string $repo
   *   The repo's name.
   * @param array<string, string> $appDecisions
   *   Verdict id => its decision. Keyed by verdict, since app names can
   *   collide.
   * @param array<string, string> $names
   *   Verdict id => the app's name.
   * @param string $response
   *   The reviewer's response to the contributor.
   * @param bool $wasLive
   *   Whether the repo was in the catalog before the decision.
   * @param array{contributor: string, reviewer: string} $people
   *   Display names; an empty reviewer reads as the AppVerse team.
   * @param array{review: string, hub: string, catalog: string} $links
   *   Absolute URLs of the review page, the contributor's AppVerse page and
   *   the repo in the catalog.
   *
   * @return array{subject: \Drupal\Core\StringTranslation\TranslatableMarkup, blocks: array<int, array<int, mixed>>}
   */
  public static function decision(string $site, string $repo, array $appDecisions, array $names, string $response, bool $wasLive, array $people, array $links): array {
    $distinct = array_values(array_unique(array_values($appDecisions)));
    $mixed = count($distinct) > 1;
    $decision = $mixed ? NULL : ($distinct[0] ?? NULL);
    $plan = ReviewDecision::plan($appDecisions);
    $any = static fn (string $d): bool => in_array($d, $appDecisions, TRUE);
    $args = ['@site' => $site, '@repo' => $repo, '@reviewer' => self::reviewer($people)];

    $subject = match ($decision) {
      'accept' => $wasLive
        ? new TranslatableMarkup('[@site] Review complete: @repo is accepted', $args)
        : new TranslatableMarkup('[@site] Accepted: @repo is in the AppVerse', $args),
      'accept_with_suggestions' => new TranslatableMarkup('[@site] Accepted with suggestions: @repo', $args),
      'request_changes' => new TranslatableMarkup('[@site] Changes requested on @repo', $args),
      'reject' => new TranslatableMarkup('[@site] @repo was not accepted', $args),
      default => new TranslatableMarkup('[@site] Review decisions for @repo', $args),
    };

    $blocks = [self::greeting($people)];
    $blocks[] = ['p', match ($decision) {
      'accept' => $wasLive
        ? new TranslatableMarkup('@reviewer reviewed your repo "@repo" again and accepted it. It stays listed in the AppVerse catalog.', $args)
        : new TranslatableMarkup('@reviewer reviewed your repo "@repo" for the AppVerse catalog and accepted it. It is now listed in the catalog.', $args),
      'accept_with_suggestions' => $wasLive
        ? new TranslatableMarkup('@reviewer reviewed your repo "@repo" again and accepted it, with suggestions below. It stays listed in the AppVerse catalog, and the suggestions are worth a look when you next update it.', $args)
        : new TranslatableMarkup('@reviewer reviewed your repo "@repo" for the AppVerse catalog and accepted it, with suggestions below. @reviewer will publish it in the catalog. You are welcome to act on the suggestions first, but you do not have to.', $args),
      'request_changes' => $wasLive
        ? new TranslatableMarkup('@reviewer reviewed your repo "@repo" again and is asking for changes. It is out of the AppVerse catalog until they are made.', $args)
        : new TranslatableMarkup('@reviewer reviewed your repo "@repo" for the AppVerse catalog and is asking for changes before it can be listed.', $args),
      'reject' => $wasLive
        ? new TranslatableMarkup('@reviewer reviewed your repo "@repo" again and did not accept it, so it has been removed from the AppVerse catalog.', $args)
        : new TranslatableMarkup('@reviewer reviewed your repo "@repo" for the AppVerse catalog and did not accept it.', $args),
      default => $wasLive
        ? new TranslatableMarkup('@reviewer reviewed your repo "@repo" again and decided on each app:', $args)
        : new TranslatableMarkup('@reviewer reviewed your repo "@repo" for the AppVerse catalog and decided on each app:', $args),
    }];

    if (count($appDecisions) > 1) {
      $apps = [];
      foreach ($appDecisions as $id => $d) {
        $apps[] = [$names[$id] ?? (string) $id, self::label($d)];
      }
      $blocks[] = ['apps', $apps];
    }
    if ($mixed) {
      if ($plan['repo'] === 'publish' && !$wasLive) {
        $blocks[] = ['p', new TranslatableMarkup('The accepted apps are now listed in the catalog.')];
      }
      elseif ($any('accept_with_suggestions') && !$any('accept')) {
        $blocks[] = ['p', new TranslatableMarkup('@reviewer will publish the accepted apps in the catalog.', $args)];
      }
    }

    // The next step comes before the response, which can be long, so it is
    // read first and the response reads as its details.
    if ($any('request_changes')) {
      // One commit gives one AI report, so the contributor re-submits the
      // whole repo once, never app by app.
      $blocks[] = ['p', $mixed && $plan['repo'] !== 'needs_adjustment'
        ? new TranslatableMarkup('When you have made the changes on GitHub, click Re-submit on your AppVerse page. The whole repo is reviewed again, and the apps already accepted stay listed.')
        : new TranslatableMarkup('When you have made the changes on GitHub, click Re-submit on your AppVerse page and a new review will start.')];
    }

    if (trim($response) !== '') {
      $blocks[] = ['p', new TranslatableMarkup("@reviewer's response:", $args)];
      $blocks[] = ['response', trim($response)];
    }
    if ($decision === 'reject') {
      $blocks[] = ['p', new TranslatableMarkup('The review is visible only to you and the AppVerse reviewers.')];
    }
    $blocks[] = ['p', new TranslatableMarkup('Questions about the review? Reply to this email and it goes to @reviewer.', $args)];

    if ($any('request_changes')) {
      $blocks[] = ['link', new TranslatableMarkup('Your AppVerse page'), $links['hub']];
    }
    if ($plan['repo'] === 'publish') {
      $blocks[] = ['link', new TranslatableMarkup('The repo in the catalog'), $links['catalog']];
    }
    $blocks[] = ['link', new TranslatableMarkup('The full review'), $links['review']];

    return ['subject' => $subject, 'blocks' => $blocks];
  }

  /**
   * The "now live" email after "Publish app and review".
   *
   * @param string $site
   *   The site name.
   * @param string $repo
   *   The repo's name.
   * @param string[] $apps
   *   The names of the apps published now; listed for a monorepo.
   * @param bool $monorepo
   *   Whether the repo has more than one app.
   * @param array{contributor: string, reviewer: string} $people
   *   Display names.
   * @param array{review: string, catalog: string} $links
   *   Absolute URLs of the review page and the repo in the catalog.
   *
   * @return array{subject: \Drupal\Core\StringTranslation\TranslatableMarkup, blocks: array<int, array<int, mixed>>}
   */
  public static function nowLive(string $site, string $repo, array $apps, bool $monorepo, array $people, array $links): array {
    $args = ['@site' => $site, '@repo' => $repo, '@reviewer' => self::reviewer($people)];
    $blocks = [self::greeting($people)];
    $blocks[] = ['p', new TranslatableMarkup('@reviewer published your repo "@repo". It is now listed in the AppVerse catalog.', $args)];
    if ($monorepo && $apps !== []) {
      $blocks[] = ['p', new TranslatableMarkup('Published now:')];
      $blocks[] = ['apps', array_map(static fn ($app) => [$app, new TranslatableMarkup('Published')], $apps)];
    }
    $blocks[] = ['p', new TranslatableMarkup('Questions? Reply to this email and it goes to @reviewer.', $args)];
    $blocks[] = ['link', new TranslatableMarkup('The repo in the catalog'), $links['catalog']];
    $blocks[] = ['link', new TranslatableMarkup('The full review'), $links['review']];
    return ['subject' => new TranslatableMarkup('[@site] @repo is now in the AppVerse', $args), 'blocks' => $blocks];
  }

  /**
   * The opening line, by the contributor's name when there is one.
   *
   * @param array{contributor: string, reviewer: string} $people
   *   Display names.
   *
   * @return array{0: string, 1: \Drupal\Core\StringTranslation\TranslatableMarkup}
   */
  protected static function greeting(array $people): array {
    return ['p', $people['contributor'] !== ''
      ? new TranslatableMarkup('Hi @name,', ['@name' => $people['contributor']])
      : new TranslatableMarkup('Hello,')];
  }

  /**
   * Who the email names as the reviewer.
   *
   * @param array{contributor: string, reviewer: string} $people
   *   Display names.
   */
  protected static function reviewer(array $people): string|TranslatableMarkup {
    return $people['reviewer'] !== '' ? $people['reviewer'] : new TranslatableMarkup('The AppVerse team');
  }

  /**
   * A decision's label, as the email lists it per app.
   */
  protected static function label(string $decision): TranslatableMarkup {
    return match ($decision) {
      'accept' => new TranslatableMarkup('Accepted'),
      'accept_with_suggestions' => new TranslatableMarkup('Accepted with suggestions'),
      'request_changes' => new TranslatableMarkup('Changes requested'),
      default => new TranslatableMarkup('Not accepted'),
    };
  }

}
