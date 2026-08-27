<?php

/**
 * Capture raw GitHub API responses to fixture files for Cypress.
 *
 * Run via: ddev drush php:script scripts/capture-github-fixtures.php
 *
 * Hits the same GH endpoints GitHubService uses (GraphQL + REST), then
 * writes the JSON bodies to tests/cypress/cypress/fixtures/github/<repo>/.
 * Cypress' cy.intercept stubs reads these so CI never hits live GitHub.
 */

use Drupal\key\KeyRepositoryInterface;

/** @var KeyRepositoryInterface $keyRepo */
$keyRepo = \Drupal::service('key.repository');
$token = $keyRepo->getKey('appverse_github')->getKeyValue();
if (!$token) {
  throw new \RuntimeException('appverse_github key not configured.');
}

/** @var \GuzzleHttp\ClientInterface $http */
$http = \Drupal::httpClient();
$fixturesDir = DRUPAL_ROOT . '/../tests/cypress/cypress/fixtures/github';

$repos = [
  'appverse-example-collection' => [
    'owner' => 'Sweet-and-Fizzy',
    'name' => 'appverse-example-collection',
    'subpaths' => ['jupyter_example', 'rstudio_example'],
  ],
  'appverse-example-singleapp' => [
    'owner' => 'OSC',
    'name' => 'bc_osc_abaqus',
    'subpaths' => [],
  ],
  'empty-repo' => [
    'owner' => 'github',
    'name' => 'docs',
    'subpaths' => [],
  ],
];

$repoQuery = <<<'GRAPHQL'
query($owner: String!, $name: String!) {
  repository(owner: $owner, name: $name) {
    isArchived
    stargazerCount
    forkCount
    description
    pushedAt
    owner {
      login
      ... on Organization { name }
      ... on User { name }
    }
    defaultBranchRef {
      name
      target { ... on Commit { committedDate } }
    }
    manifestYml: object(expression: "HEAD:manifest.yml") { ... on Blob { text } }
    appverseYml: object(expression: "HEAD:appverse.yml") { ... on Blob { text } }
    readme: object(expression: "HEAD:README.md") { ... on Blob { text } }
    licenseInfo { name spdxId url }
    releases(first: 5, orderBy: {field: CREATED_AT, direction: DESC}) {
      nodes { tagName publishedAt }
    }
  }
}
GRAPHQL;

foreach ($repos as $fixtureDir => $config) {
  $dir = $fixturesDir . '/' . $fixtureDir;
  if (!is_dir($dir)) {
    mkdir($dir, 0755, TRUE);
  }
  print "Capturing $fixtureDir ({$config['owner']}/{$config['name']})...\n";

  // 1. Repo-level GraphQL.
  $response = $http->post('https://api.github.com/graphql', [
    'headers' => [
      'Authorization' => 'Bearer ' . $token,
      'Content-Type' => 'application/json',
    ],
    'json' => [
      'query' => $repoQuery,
      'variables' => ['owner' => $config['owner'], 'name' => $config['name']],
    ],
    'http_errors' => FALSE,
  ]);
  $body = (string) $response->getBody();
  file_put_contents($dir . '/repo-graphql.json', $body);
  print "  wrote repo-graphql.json (" . strlen($body) . " bytes)\n";

  // 2. Per-subpath GraphQL (only for collections).
  if (!empty($config['subpaths'])) {
    $blobFields = [];
    foreach (array_values($config['subpaths']) as $i => $path) {
      $alias = 'p' . $i;
      $cleanPath = trim($path, '/');
      $blobFields[] = sprintf(
        "%s_manifest: object(expression: \"HEAD:%s/manifest.yml\") { ... on Blob { text } }\n        %s_appverse: object(expression: \"HEAD:%s/appverse.yml\") { ... on Blob { text } }\n        %s_readme: object(expression: \"HEAD:%s/README.md\") { ... on Blob { text } }\n        %s_form: object(expression: \"HEAD:%s/form.yml\") { ... on Blob { text } }",
        $alias, $cleanPath, $alias, $cleanPath, $alias, $cleanPath, $alias, $cleanPath
      );
    }
    $blobBlock = implode("\n        ", $blobFields);
    $subpathQuery = "query(\$owner: String!, \$name: String!) {\n  repository(owner: \$owner, name: \$name) {\n        $blobBlock\n  }\n}";

    $response = $http->post('https://api.github.com/graphql', [
      'headers' => [
        'Authorization' => 'Bearer ' . $token,
        'Content-Type' => 'application/json',
      ],
      'json' => [
        'query' => $subpathQuery,
        'variables' => ['owner' => $config['owner'], 'name' => $config['name']],
      ],
      'http_errors' => FALSE,
    ]);
    $body = (string) $response->getBody();
    file_put_contents($dir . '/subpaths-graphql.json', $body);
    print "  wrote subpaths-graphql.json (" . strlen($body) . " bytes)\n";
  }
}

print "\nFixtures captured to $fixturesDir\n";
