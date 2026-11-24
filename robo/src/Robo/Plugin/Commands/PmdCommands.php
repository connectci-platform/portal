<?php

namespace Mih\Robo\Plugin\Commands;

use Robo\Exception\TaskException;
use Robo\Result;
use Robo\Robo;
use Robo\Tasks;

/**
 * Defines commands in the "pmd" namespace.
 */
class PmdCommands extends Tasks {

  /**
   * Command to check for multidev environment.
   *
   * @command pmd:check
   * @description Checks for MD environment.
   */
  public function check(array $args) {
    $sites = shell_exec("terminus multidev:list --format csv --fields id -- accessmatch");
    $sites = substr($sites, strpos($sites, "\n") + 1);
    $sites = str_replace("\n", "", $sites);
    if (strpos($sites, $args[0]) !== FALSE) {
      $this->say("TRUE");
    }
    else {
      $this->say("FALSE");
    }
  }

  /**
   * Command to create a multidev environment.
   *
   * @command pmd:create
   * @description Create multidev environment.
   */
  public function create(array $args) {
    $md_exists = shell_exec("composer robo pmd:check $args[0]");
    if ($md_exists === TRUE) {
      $this->say("This multidev environment already exists.");
    }
    else {
      // D8-3145: non-live environments can lag behind security releases, so
      // lock the new environment before deploying. A failed deploy then never
      // leaves an open environment. The credentials are checked before the
      // multidev is created: a multidev created but not locked would be
      // skipped by later runs (pmd:check sees it and md_create never runs).
      $lock_user = getenv('PANTHEON_LOCK_USERNAME');
      $lock_pass = getenv('PANTHEON_LOCK_PASSWORD');
      if (empty($lock_user) || empty($lock_pass)) {
        throw new \Exception("PANTHEON_LOCK_USERNAME and PANTHEON_LOCK_PASSWORD must be set; refusing to create an unlocked accessmatch.$args[0].");
      }
      $this->_exec("terminus multidev:create -- accessmatch.test $args[0]");
      // Not using _exec() here because it echoes the command line, which
      // would leak the password into the logs.
      $lock_cmd = 'terminus lock:enable -- '
        . escapeshellarg("accessmatch.$args[0]") . ' '
        . escapeshellarg($lock_user) . ' '
        . escapeshellarg($lock_pass);
      exec($lock_cmd . ' 2>&1', $lock_output, $lock_code);
      foreach ($lock_output as $line) {
        $this->say($line);
      }
      if ($lock_code !== 0) {
        throw new \Exception("Failed to lock accessmatch.$args[0].");
      }
      $this->say("Locked accessmatch.$args[0].");
      $result = $this->_exec("terminus remote:drush accessmatch.$args[0] -- deploy -y");
      if (!$result->wasSuccessful()) {
        throw new \Exception("drush deploy failed on accessmatch.$args[0].");
      }
      $this->say("This multidev environment has been created and deployed.");
    }
  }

}
