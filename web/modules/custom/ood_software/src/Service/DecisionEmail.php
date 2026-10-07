<?php

namespace Drupal\ood_software\Service;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * The contributor's decision email, and the "now live" email after Publish.
 *
 * Pure. One email per decision, for the whole repo (appverse-planning#32):
 * it replaces the generic "changes requested" and "published" emails on the
 * review flow. It greets the contributor, names the reviewer, carries the
 * reviewer's response and, for a monorepo, each app's decision, then says
 * what to do next and that a reply reaches the reviewer (#48). render() turns
 * the blocks into the mail in the recipient's language, for hook_mail() and
 * for the confirm page's preview (#52); RepoNotificationService sends them to
 * the repo's owner with the reviewer as Reply-To.
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
   *   Display names; an empty reviewer reads as the Appverse team.
   * @param array{review: string, hub: string, catalog: string} $links
   *   Absolute URLs of the review page, the contributor's Appverse page and
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
        : new TranslatableMarkup('[@site] Accepted: @repo is in the Appverse', $args),
      'accept_with_suggestions' => new TranslatableMarkup('[@site] Accepted with suggestions: @repo', $args),
      'request_changes' => new TranslatableMarkup('[@site] Changes requested on @repo', $args),
      'reject' => new TranslatableMarkup('[@site] @repo was not accepted', $args),
      default => new TranslatableMarkup('[@site] Review decisions for @repo', $args),
    };

    $blocks = [self::greeting($people)];
    $blocks[] = ['p', match ($decision) {
      'accept' => $wasLive
        ? new TranslatableMarkup('@reviewer reviewed your repo "@repo" again and accepted it. It stays listed in the Appverse catalog.', $args)
        : new TranslatableMarkup('@reviewer reviewed your repo "@repo" for the Appverse catalog and accepted it. It is now listed in the catalog.', $args),
      'accept_with_suggestions' => $wasLive
        ? new TranslatableMarkup('@reviewer reviewed your repo "@repo" again and accepted it, with suggestions below. It stays listed in the Appverse catalog, and the suggestions are worth a look when you next update it.', $args)
        : new TranslatableMarkup('@reviewer reviewed your repo "@repo" for the Appverse catalog and accepted it, with suggestions below. None of them block it being listed, but it is not in the catalog yet, so there is time to act on any you want to fix before it goes public. Tell @reviewer when you are ready, or say nothing and it will be listed as it is.', $args),
      'request_changes' => $wasLive
        ? new TranslatableMarkup('@reviewer reviewed your repo "@repo" again and is asking for changes. It is out of the Appverse catalog until they are made.', $args)
        : new TranslatableMarkup('@reviewer reviewed your repo "@repo" for the Appverse catalog and is asking for changes before it can be listed.', $args),
      'reject' => $wasLive
        ? new TranslatableMarkup('@reviewer reviewed your repo "@repo" again and did not accept it, so it has been removed from the Appverse catalog.', $args)
        : new TranslatableMarkup('@reviewer reviewed your repo "@repo" for the Appverse catalog and did not accept it.', $args),
      default => $wasLive
        ? new TranslatableMarkup('@reviewer reviewed your repo "@repo" again and decided on each app:', $args)
        : new TranslatableMarkup('@reviewer reviewed your repo "@repo" for the Appverse catalog and decided on each app:', $args),
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
        $blocks[] = ['p', new TranslatableMarkup('The accepted apps are not in the catalog yet, so there is time to act on the suggestions before they go public. Tell @reviewer when you are ready, or say nothing and they will be listed as they are.', $args)];
      }
    }

    // The next step comes before the response, which can be long, so it is
    // read first and the response reads as its details.
    if ($any('request_changes')) {
      // One commit gives one AI report, so the contributor re-submits the
      // whole repo once, never app by app.
      // Only an accepted app is live; one accepted with suggestions waits for
      // the reviewer's Publish, so "stay listed" would be untrue for it.
      $blocks[] = ['p', match (TRUE) {
        $mixed && ($wasLive || $any('accept')) => new TranslatableMarkup('When you have made the changes on GitHub, click Re-submit on your Appverse page. The whole repo is reviewed again, and the apps already accepted stay listed.'),
        $mixed && $plan['repo'] !== 'needs_adjustment' => new TranslatableMarkup('When you have made the changes on GitHub, click Re-submit on your Appverse page. The whole repo is reviewed again.'),
        default => new TranslatableMarkup('When you have made the changes on GitHub, click Re-submit on your Appverse page and a new review will start.'),
      }];
    }

    if (trim($response) !== '') {
      $blocks[] = ['p', new TranslatableMarkup('Their response:')];
      $blocks[] = ['response', trim($response)];
    }
    if ($decision === 'reject') {
      $blocks[] = ['p', new TranslatableMarkup('The review is visible only to you and the Appverse reviewers.')];
    }
    $blocks[] = ['p', new TranslatableMarkup('Questions about the review? Reply to this email and it goes to the reviewer.')];

    if ($any('request_changes')) {
      $blocks[] = ['link', new TranslatableMarkup('Your Appverse page'), $links['hub']];
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
    $blocks[] = ['p', new TranslatableMarkup('@reviewer published your repo "@repo". It is now listed in the Appverse catalog.', $args)];
    if ($monorepo && $apps !== []) {
      $blocks[] = ['p', new TranslatableMarkup('Published now:')];
      $blocks[] = ['apps', array_map(static fn ($app) => [$app, new TranslatableMarkup('Published')], $apps)];
    }
    $blocks[] = ['p', new TranslatableMarkup('Questions? Reply to this email and it goes to @reviewer.', $args)];
    $blocks[] = ['link', new TranslatableMarkup('The repo in the catalog'), $links['catalog']];
    $blocks[] = ['link', new TranslatableMarkup('The full review'), $links['review']];
    return ['subject' => new TranslatableMarkup('[@site] @repo is now in the Appverse', $args), 'blocks' => $blocks];
  }

  /**
   * An email's subject and body, in the recipient's language.
   *
   * Used by hook_mail() and by the confirm page's preview, so the preview is
   * what goes out.
   *
   * @param array<string, mixed> $email
   *   As decision() or nowLive() builds it.
   * @param string $langcode
   *   The recipient's language.
   *
   * @return array{subject: string, body: array<int, \Drupal\Component\Render\MarkupInterface|string>}
   */
  public static function render(array $email, string $langcode): array {
    $body = [];
    foreach ($email['blocks'] ?? [] as $block) {
      $html = match ($block[0]) {
        'p' => '<p>' . self::text($block[1], $langcode) . '</p>',
        'response' => '<blockquote>' . self::markdown($block[1]) . '</blockquote>',
        'apps' => '<ul>' . implode('', array_map(
          static fn (array $app) => '<li>' . Html::escape((string) $app[0]) . ': <strong>' . self::text($app[1], $langcode) . '</strong></li>',
          $block[1],
        )) . '</ul>',
        'link' => '<p>' . self::text($block[1], $langcode) . ': <a href="' . Html::escape($block[2]) . '">' . Html::escape($block[2]) . '</a></p>',
        default => '',
      };
      if ($html !== '') {
        $body[] = Markup::create($html);
      }
    }
    return [
      'subject' => PlainTextOutput::renderFromHtml(self::text($email['subject'] ?? '', $langcode)),
      'body' => $body,
    ];
  }

  /**
   * Email text in the recipient's language, as safe HTML.
   *
   * The blocks hold TranslatableMarkup built without knowing who the email
   * goes to; this translates it into the mail's language. Its placeholders
   * (repo and reviewer names) are escaped by the markup itself. A plain
   * string is escaped.
   */
  protected static function text(mixed $text, string $langcode): string {
    if ($text instanceof TranslatableMarkup) {
      // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
      return (string) new TranslatableMarkup($text->getUntranslatedString(), $text->getArguments(), ['langcode' => $langcode] + $text->getOptions());
    }
    return Html::escape((string) $text);
  }

  /**
   * The reviewer's response, Markdown rendered as safe HTML.
   *
   * The AI's draft and reviewers write Markdown (bold labels, file names in
   * backticks). Raw HTML is escaped, so it shows as written rather than
   * vanishing, unsafe links are dropped, and line breaks are kept as typed.
   */
  protected static function markdown(string $markdown): string {
    $converter = new GithubFlavoredMarkdownConverter([
      'html_input' => 'escape',
      'allow_unsafe_links' => FALSE,
      'renderer' => ['soft_break' => "<br>\n"],
    ]);
    return (string) $converter->convert($markdown);
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
    return $people['reviewer'] !== '' ? $people['reviewer'] : new TranslatableMarkup('The Appverse team');
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
