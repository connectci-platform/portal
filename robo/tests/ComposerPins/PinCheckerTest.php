<?php

/**
 * @file
 * Dependency-free tests for PinChecker. Run: php PinCheckerTest.php.
 */

declare(strict_types=1);

use Mih\ComposerPins\PinChecker;

require __DIR__ . '/../../src/ComposerPins/PinChecker.php';

/**
 * Gets the shared pass and fail counters by reference.
 *
 * @return array{total: int, failures: int}
 *   The counters.
 */
function &counts(): array {
  static $counts = ['total' => 0, 'failures' => 0];
  return $counts;
}

/**
 * Asserts that two values are identical and prints PASS or FAIL.
 */
function check(string $label, mixed $expected, mixed $actual): void {
  $counts = &counts();
  $counts['total']++;
  if ($expected === $actual) {
    echo "PASS: $label\n";
    return;
  }
  $counts['failures']++;
  echo "FAIL: $label\n  expected: " . json_encode($expected) . "\n  actual:   " . json_encode($actual) . "\n";
}

/**
 * Builds a composer.json fixture.
 *
 * @param array<string, string> $require
 *   Require entries.
 * @param array<string, string> $requireDev
 *   Require-dev entries.
 *
 * @return array<mixed>
 *   The fixture.
 */
function fixture(array $require, array $requireDev = []): array {
  return [
    'repositories' => [
      'drupal' => ['type' => 'composer', 'url' => 'https://packages.drupal.org/8'],
      'access' => ['type' => 'git', 'url' => 'https://github.com/connectci-platform/access.git'],
      'amp_dev' => ['type' => 'git', 'url' => 'https://github.com/connectci-platform/amp_dev.git'],
      'asp-theme' => ['type' => 'git', 'url' => 'git@github.com:connectci-platform/aspTheme.git'],
      'access/infrastructure_news' => ['type' => 'vcs', 'url' => 'https://github.com/access-ci-org/infrastructure_news'],
      'drupalorg' => ['type' => 'vcs', 'url' => 'https://git.drupalcode.org/issue/foo-1.git'],
    ],
    'require' => $require,
    'require-dev' => $requireDev,
  ];
}

// (a) Mismatch.
$base = fixture(['connectci/access' => '3.0.x-dev', 'drupal/core' => '^10']);
$pr = fixture(['connectci/access' => 'dev-d8-9999', 'drupal/core' => '^10']);
$mismatches = PinChecker::findMismatches($pr, $base);
check('mismatch: one found', [['package' => 'connectci/access', 'current' => 'dev-d8-9999', 'expected' => '3.0.x-dev']], $mismatches);
check('mismatch: format', 'connectci/access is "dev-d8-9999"; main uses "3.0.x-dev".', PinChecker::formatMismatch($mismatches[0], 'main'));

// (b) Identical.
check('identical: none', [], PinChecker::findMismatches($base, $base));

// (c) Non-custom change.
$pr = fixture(['connectci/access' => '3.0.x-dev', 'drupal/core' => '^11']);
check('non-custom change: none', [], PinChecker::findMismatches($pr, $base));

// (d) Key matching and require-dev.
$composer = fixture(
  [
    'access/infrastructure_news' => 'dev-x',
    'connectci/asp-theme' => 'dev-y',
    'connectci/access' => '3.0.x-dev',
    'drupal/core' => '^10',
    'drupal/other' => '^1',
  ],
  ['connectci/amp_dev' => 'dev-z', 'phpunit/phpunit' => '^9'],
);
check('customPackages: full-name key, suffix key, require-dev', [
  'access/infrastructure_news' => 'dev-x',
  'connectci/access' => '3.0.x-dev',
  'connectci/amp_dev' => 'dev-z',
  'connectci/asp-theme' => 'dev-y',
], PinChecker::customPackages($composer));
check('customPackages: list repositories match nothing', [], PinChecker::customPackages([
  'repositories' => [['type' => 'git', 'url' => 'https://github.com/a/access.git']],
  'require' => ['connectci/access' => 'dev-x'],
]));
$devPr = fixture([], ['connectci/amp_dev' => 'dev-q']);
$devBase = fixture([], ['connectci/amp_dev' => '1.x-dev']);
check('require-dev mismatch detected', 'connectci/amp_dev', PinChecker::findMismatches($devPr, $devBase)[0]['package'] ?? NULL);

// (e) New and removed packages.
$withAccess = fixture(['connectci/access' => 'dev-x']);
$without = fixture([]);
check('new package only in PR: none', [], PinChecker::findMismatches($withAccess, $without));
check('removed package: none', [], PinChecker::findMismatches($without, $withAccess));

// (f) branchFromVersion.
$branchCases = [
  'dev-d8-2865-login-lock' => 'd8-2865-login-lock',
  '3.0.x-dev' => '3.0.x',
  'main-dev' => 'main',
  'D8-2850-dev' => 'D8-2850',
  'dev-x#abc' => 'x',
  '^1.0' => '',
];
foreach ($branchCases as $input => $want) {
  check("branchFromVersion($input)", $want, PinChecker::branchFromVersion((string) $input));
}

// (g) githubRepo.
$repos = [
  'repositories' => [
    'a' => ['type' => 'git', 'url' => 'https://github.com/owner/with-git.git'],
    'b' => ['type' => 'git', 'url' => 'https://github.com/owner/no-git'],
    'c' => ['type' => 'git', 'url' => 'git@github.com:owner/ssh-form.git'],
    'd' => ['type' => 'vcs', 'url' => 'https://git.drupalcode.org/issue/foo-1.git'],
  ],
];
check('githubRepo https .git', 'owner/with-git', PinChecker::githubRepo($repos, 'x/a'));
check('githubRepo https no .git', 'owner/no-git', PinChecker::githubRepo($repos, 'x/b'));
check('githubRepo git@ form', 'owner/ssh-form', PinChecker::githubRepo($repos, 'x/c'));
check('githubRepo non-GitHub', NULL, PinChecker::githubRepo($repos, 'x/d'));
check('githubRepo unknown package', NULL, PinChecker::githubRepo($repos, 'x/unknown'));

// Real repo composer.json.
$realPath = __DIR__ . '/../../../composer.json';
$real = json_decode((string) file_get_contents($realPath), TRUE);
check('real composer.json vs itself: none', [], PinChecker::findMismatches($real, $real));
$custom = PinChecker::customPackages($real);
foreach (['connectci/access', 'connectci/asp-theme', 'connectci/amp_dev', 'access/infrastructure_news'] as $name) {
  check("real composer.json custom includes $name", TRUE, isset($custom[$name]));
}
check('real composer.json custom excludes drupal/core', FALSE, isset($custom['drupal/core']));

// (h) CLI end to end.
$script = __DIR__ . '/../../../.github/scripts/check-composer-pins.php';
$dir = sys_get_temp_dir() . '/pincheck-' . bin2hex(random_bytes(6));
mkdir($dir);
$files = [
  'base' => $dir . '/base.json',
  'pr' => $dir . '/pr.json',
  'bad' => $dir . '/bad.json',
];
file_put_contents($files['base'], json_encode(fixture(['connectci/access' => '3.0.x-dev'])));
file_put_contents($files['pr'], json_encode(fixture(['connectci/access' => 'dev-d8-9999'])));
file_put_contents($files['bad'], '{not json');

/**
 * Runs the CLI script and returns its exit code and output.
 *
 * @param list<string> $args
 *   Script arguments.
 *
 * @return array{0: int, 1: string}
 *   Exit code and combined output.
 */
function run_cli(string $script, array $args): array {
  $cmd = array_merge([PHP_BINARY, $script], $args);
  $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  return [proc_close($proc), $out];
}

[$code, $out] = run_cli($script, [$files['pr'], $files['base'], 'main']);
check('cli mismatch: exit 1', 1, $code);
check('cli mismatch: annotation printed', TRUE, str_contains($out, '::error file=composer.json::connectci/access is "dev-d8-9999"; main uses "3.0.x-dev".'));
check('cli mismatch: hint printed', TRUE, str_contains($out, 'robo gh:unpin --base=main') && str_contains($out, 'pin-change-ok'));
[$code] = run_cli($script, [$files['base'], $files['base'], 'main']);
check('cli clean: exit 0', 0, $code);
[$code] = run_cli($script, [$files['pr']]);
check('cli missing args: exit 2', 2, $code);
[$code] = run_cli($script, [$files['bad'], $files['base'], 'main']);
check('cli bad JSON: exit 2', 2, $code);
[$code] = run_cli($script, [$dir . '/missing.json', $files['base'], 'main']);
check('cli missing file: exit 2', 2, $code);

foreach ($files as $file) {
  @unlink($file);
}
@rmdir($dir);

$counts = counts();
echo "\n" . ($counts['total'] - $counts['failures']) . '/' . $counts['total'] . " passed.\n";
exit($counts['failures'] === 0 ? 0 : 1);
