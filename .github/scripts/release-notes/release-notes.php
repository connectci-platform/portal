<?php

/**
 * @file
 * Writes the release notes and the version bump for one portal release.
 *
 * Usage:
 *   php release-notes.php --since=ISO --until=ISO --out=FILE --bump-out=FILE
 *
 * --since and --until are the commit times of the previous release's tag and
 * of the commit being released. Env: GH_TOKEN, GITHUB_REPOSITORY, JIRA_EMAIL,
 * JIRA_API_TOKEN, optional JIRA_SITE.
 *
 * A Jira problem never fails this script: items fall back to generic lines.
 * A GitHub problem exits 1, since without it nothing is known about what
 * shipped; the workflow then falls back to the git log notes.
 */

require __DIR__ . '/bootstrap.php';

$opts = getopt('', ['since:', 'until:', 'out:', 'bump-out:']);
foreach (['since', 'until', 'out', 'bump-out'] as $required) {
  if (!isset($opts[$required])) {
    fwrite(STDERR, "Usage: php release-notes.php --since=ISO --until=ISO --out=FILE --bump-out=FILE\n");
    exit(1);
  }
}

$http = new Http();
$programs = ProgramMapper::fromFile(__DIR__ . '/../../release-programs.json');
$github = new GitHubClient($http, getenv('GITHUB_REPOSITORY') ?: 'connectci-platform/portal', (string) getenv('GH_TOKEN'));

try {
  $mainPrs = $github->mergedInto('main', $opts['since'], $opts['until']);
  $anchor = $github->lastMdDevMergeAtOrBefore($opts['since']);
  $mdDevPrs = $github->mergedInto('md-dev', $anchor, $opts['until']);
}
catch (RuntimeException $e) {
  fwrite(STDERR, 'GitHub API error: ' . $e->getMessage() . "\n");
  exit(1);
}
$prs = ReleaseWindow::select($mainPrs, $mdDevPrs, $anchor);

$keys = array_values(array_unique(array_filter(array_map([TicketKeys::class, 'fromPr'], $prs))));
$tickets = NULL;
$email = getenv('JIRA_EMAIL');
$token = getenv('JIRA_API_TOKEN');
if (!$email || !$token) {
  echo "::warning::JIRA_EMAIL or JIRA_API_TOKEN is not set; ticketed items are listed generically.\n";
}
else {
  try {
    $tickets = (new JiraClient($http, getenv('JIRA_SITE') ?: 'https://cyberteamportal.atlassian.net', $email, $token))->fetch($keys);
  }
  catch (RuntimeException $e) {
    echo '::warning::Jira lookup failed (' . $e->getMessage() . "); ticketed items are listed generically.\n";
  }
}

// Point out sprints the mapping doesn't know, so release-programs.json can be
// extended. Date-only and Testing sprints are expected not to match.
foreach ($tickets ?? [] as $key => $ticket) {
  $sprints = $ticket['sprints'];
  usort($sprints, fn ($a, $b) => strcmp((string) $b['startDate'], (string) $a['startDate']));
  $latest = $sprints[0]['name'] ?? NULL;
  if ($latest !== NULL && $programs->match($latest) === NULL && !preg_match('/^(Testing|[A-Z][a-z]+ \d{1,2})$/', $latest)) {
    echo "::warning::$key sprint \"$latest\" matches no rule in release-programs.json\n";
  }
}

$items = (new NotesBuilder($programs))->build($prs, $tickets);
file_put_contents($opts['out'], Renderer::render($items, $tickets === NULL));
file_put_contents($opts['bump-out'], Renderer::bump($items) . "\n");
printf("%d PRs, %d tickets, %d items, bump %s\n", count($prs), count($keys), count($items), Renderer::bump($items));
