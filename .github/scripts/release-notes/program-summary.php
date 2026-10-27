<?php

/**
 * @file
 * Writes one program's release note lines over a date range.
 *
 * Usage:
 *   php program-summary.php --program=ACCESS --from=YYYY-MM-DD --to=YYYY-MM-DD --out=FILE
 *
 * Env: GH_TOKEN, GITHUB_REPOSITORY.
 */

require __DIR__ . '/bootstrap.php';

$opts = getopt('', ['program:', 'from:', 'to:', 'out:']);
foreach (['program', 'from', 'to', 'out'] as $required) {
  if (!isset($opts[$required])) {
    fwrite(STDERR, "Usage: php program-summary.php --program=NAME --from=YYYY-MM-DD --to=YYYY-MM-DD --out=FILE\n");
    exit(1);
  }
}

$http = new Http();
$repo = getenv('GITHUB_REPOSITORY') ?: 'connectci-platform/portal';
$releases = [];
try {
  // Newest first; stop once a page reaches releases older than the range.
  for ($page = 1;; $page++) {
    $batch = $http->json('GET', "https://api.github.com/repos/$repo/releases?per_page=100&page=$page", [
      'Authorization' => 'Bearer ' . getenv('GH_TOKEN'),
      'Accept' => 'application/vnd.github+json',
      'X-GitHub-Api-Version' => '2022-11-28',
    ]);
    if (!$batch) {
      break;
    }
    $releases = [...$releases, ...$batch];
    if (substr((string) end($batch)['published_at'], 0, 10) < $opts['from']) {
      break;
    }
  }
}
catch (RuntimeException $e) {
  fwrite(STDERR, 'GitHub API error: ' . $e->getMessage() . "\n");
  exit(1);
}

file_put_contents($opts['out'], ProgramSummary::summarize($releases, $opts['program'], $opts['from'], $opts['to']) . "\n");
