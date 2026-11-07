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
   *
   * @param array<int, string> $args
   *   The multidev name, as the first argument.
   */
  public function check(array $args): void {
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
   *
   * @param array<int, string> $args
   *   The multidev name, as the first argument.
   */
  public function create(array $args): void {
    $md_exists = shell_exec("composer robo pmd:check $args[0]");
    // pmd:check prints TRUE or FALSE.
    if (str_contains((string) $md_exists, 'TRUE')) {
      $this->say("This multidev environment already exists.");
    }
    else {
      $this->_exec("terminus multidev:create -- accessmatch.test $args[0]");
      $result = $this->_exec("terminus remote:drush accessmatch.$args[0] -- deploy -y");
      if (!$result->wasSuccessful()) {
        throw new \Exception("drush deploy failed on accessmatch.$args[0].");
      }
      $this->say("This multidev environment has been created and deployed.");
    }
  }

}
