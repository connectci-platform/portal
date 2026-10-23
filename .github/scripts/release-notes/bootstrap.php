<?php

/**
 * @file
 * Loads the release-notes classes for the CLI and the tests.
 */

foreach (glob(__DIR__ . '/src/*.php') as $file) {
  require_once $file;
}
