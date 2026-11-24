<?php

/**
 * @file
 * Fails when custom package versions differ from the base branch.
 *
 * Usage: php check-composer-pins.php <pr-composer.json> <base-composer.json>
 * <base-branch-name>.
 */

declare(strict_types=1);

use Mih\ComposerPins\PinChecker;

require __DIR__ . '/../../robo/src/ComposerPins/PinChecker.php';

/**
 * Reads and decodes a composer.json file, exiting with code 2 on failure.
 *
 * @return array<mixed>
 *   The decoded data.
 */
function load_composer_file(string $path): array {
  if (!is_file($path) || !is_readable($path)) {
    fwrite(STDERR, "Cannot read file: $path\n");
    exit(2);
  }
  $data = json_decode((string) file_get_contents($path), TRUE);
  if (!is_array($data)) {
    fwrite(STDERR, "Invalid JSON in file: $path\n");
    exit(2);
  }
  return $data;
}

/**
 * Escapes a message for a GitHub Actions workflow command.
 */
function escape_annotation(string $message): string {
  return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $message);
}

if ($argc !== 4) {
  fwrite(STDERR, "Usage: php check-composer-pins.php <pr-composer.json> <base-composer.json> <base-branch-name>\n");
  exit(2);
}

[, $prFile, $baseFile, $baseName] = $argv;
if ($baseName === '') {
  fwrite(STDERR, "Base branch name must not be empty.\n");
  exit(2);
}

$mismatches = PinChecker::findMismatches(load_composer_file($prFile), load_composer_file($baseFile));

if ($mismatches === []) {
  echo "OK: custom package versions match $baseName.\n";
  exit(0);
}

echo "Custom package versions differ from $baseName:\n";
foreach ($mismatches as $mismatch) {
  $message = PinChecker::formatMismatch($mismatch, $baseName);
  echo "  $message\n";
  echo '::error file=composer.json::' . escape_annotation($message) . "\n";
}
echo "\nMerge the module PR(s) first, then run `vendor/bin/robo gh:unpin --base=$baseName` and commit composer.json and composer.lock. If the pin change is intentional, add the `pin-change-ok` label to the PR.\n";
exit(1);
