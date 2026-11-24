<?php

namespace Drupal\ood_software\Service;

use Drupal\Core\Database\Connection;

/**
 * Who counts as an Appverse contributor (D8-3133).
 *
 * One definition shared by the contributors block and the Appverse Contributor
 * badge: an author of at least one published appverse_app.
 */
class AppverseContributors {

  /**
   * Name of the badge term awarded to contributors.
   */
  const BADGE_NAME = 'Appverse Contributor';

  /**
   * Vocabulary holding the badge term.
   */
  const BADGE_VOCABULARY = 'open_ondemand_badges';

  /**
   * Constructs an AppverseContributors object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    protected Connection $database,
  ) {}

  /**
   * Get published appverse_app authors by their most recent app.
   *
   * @return array<int, int>
   *   Most recent app created timestamp keyed by author uid, newest first.
   */
  public function getLatestAppByAuthor(): array {
    $query = $this->database->select('node_field_data', 'n');
    $query->fields('n', ['uid']);
    $query->addExpression('MAX(n.created)', 'latest_created');
    $query->condition('n.type', 'appverse_app');
    $query->condition('n.status', 1);
    $query->condition('n.uid', 0, '>');
    $query->groupBy('n.uid');
    $query->orderBy('latest_created', 'DESC');
    $query->orderBy('n.uid', 'ASC');

    $authors = [];
    foreach ($query->execute()->fetchAll() as $result) {
      $authors[(int) $result->uid] = (int) $result->latest_created;
    }
    return $authors;
  }

  /**
   * Get the uids of everyone with a published appverse_app.
   *
   * @return int[]
   *   The author uids, most recently active first.
   */
  public function getAuthorUids(): array {
    return array_map('intval', array_keys($this->getLatestAppByAuthor()));
  }

}
