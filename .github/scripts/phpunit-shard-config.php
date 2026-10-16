<?php

/**
 * @file
 * Writes a PHPUnit config that runs exactly the test files in a list.
 *
 * Usage: php phpunit-shard-config.php <base-config> <output-config> <list>
 *
 * PHPUnit 9 runs only the FIRST path given on the command line and drops the
 * rest without a warning, so a shard cannot be run as `phpunit a/ b/ c/`. This
 * copies the base config (core's phpunit.xml.dist, so the bootstrap, env vars
 * and listeners are unchanged) and replaces its test suites with one suite,
 * "shard", that names every file in the list. Write the output next to the
 * base config so its relative paths, such as the bootstrap, still resolve.
 *
 * The list is one path per line, relative to the current directory. Paths are
 * written as absolute paths so they do not depend on where the config lives.
 */

if ($argc !== 4) {
  fwrite(STDERR, "Usage: php phpunit-shard-config.php <base-config> <output-config> <list>\n");
  exit(1);
}
[, $base, $output, $list] = $argv;

$files = array_filter(array_map('trim', file($list)));
if (!$files) {
  fwrite(STDERR, "No test files in $list.\n");
  exit(1);
}

$doc = new DOMDocument();
$doc->preserveWhiteSpace = FALSE;
$doc->formatOutput = TRUE;
if (!$doc->load($base)) {
  fwrite(STDERR, "Could not parse $base.\n");
  exit(1);
}
$root = $doc->documentElement;

foreach (iterator_to_array($root->getElementsByTagName('testsuites')) as $existing) {
  $root->removeChild($existing);
}

$suites = $doc->createElement('testsuites');
$suite = $doc->createElement('testsuite');
$suite->setAttribute('name', 'shard');
foreach ($files as $file) {
  $path = realpath($file);
  if ($path === FALSE) {
    fwrite(STDERR, "Test file $file does not exist.\n");
    exit(1);
  }
  $suite->appendChild($doc->createElement('file', $path));
}
$suites->appendChild($suite);

// Keep the schema's element order: testsuites goes before listeners.
$listeners = $root->getElementsByTagName('listeners')->item(0);
$root->insertBefore($suites, $listeners);

if ($doc->save($output) === FALSE) {
  fwrite(STDERR, "Could not write $output.\n");
  exit(1);
}
echo 'Wrote ' . count($files) . " test files to $output.\n";
