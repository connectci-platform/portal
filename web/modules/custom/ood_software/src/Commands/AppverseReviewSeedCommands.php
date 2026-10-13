<?php

namespace Drupal\ood_software\Commands;

use Drupal\node\NodeInterface;
use Drupal\ood_software\Service\AppverseReviewSeeder;
use Drush\Commands\DrushCommands;

/**
 * Drush command to seed an appverse_review from a review artifact file.
 */
class AppverseReviewSeedCommands extends DrushCommands {

  protected AppverseReviewSeeder $seeder;

  public function __construct(AppverseReviewSeeder $seeder) {
    parent::__construct();
    $this->seeder = $seeder;
  }

  /**
   * Seed a Draft appverse_review node from a review artifact JSON file.
   *
   * @param string $artifactPath
   *   Path to the artifact JSON (the appverse-review plugin envelope).
   *
   * @command appverse:seed-review
   * @option repo Repo URL to resolve the appverse_repo node when it differs
   *   from the artifact's reviewed.repo_url (or the artifact has none).
   * @option reports-dir Directory holding the report files named in the
   *   artifact; when present they are attached to the review.
   * @option force Seed even if a review for this repo + SHA already exists.
   * @usage drush appverse:seed-review review-owner-app.artifact.json
   *   Seed from an artifact whose reviewed.repo_url matches a catalog repo.
   * @usage drush appverse:seed-review a.json --repo=https://github.com/o/r --reports-dir=/tmp/reports
   *   Seed against an explicit repo and attach the MD/PDF/HTML reports.
   */
  public function seedReview(string $artifactPath, array $options = ['repo' => NULL, 'reports-dir' => NULL, 'force' => FALSE]): void {
    if (!is_readable($artifactPath)) {
      throw new \InvalidArgumentException("Cannot read artifact file: $artifactPath");
    }
    $artifact = json_decode(file_get_contents($artifactPath), TRUE);
    if (!is_array($artifact)) {
      throw new \InvalidArgumentException("Not valid JSON: $artifactPath");
    }

    $repo = NULL;
    if (!empty($options['repo'])) {
      $artifact['reviewed']['repo_url'] = $options['repo'];
    }

    $review = $this->seeder->seedFromArtifact(
      $artifact,
      $repo,
      $options['reports-dir'] ?? NULL,
      (bool) $options['force'],
    );

    $this->io()->success(sprintf(
      'Seeded review node %d ("%s") in Draft. Edit: /node/%d/edit',
      $review->id(),
      $review->label(),
      $review->id(),
    ));
  }

}
