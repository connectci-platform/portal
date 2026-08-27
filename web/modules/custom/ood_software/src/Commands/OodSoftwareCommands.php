<?php

namespace Drupal\ood_software\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\ood_software\Plugin\GitHubService;
use Drupal\ood_software\Service\AppverseCacheService;
use Drupal\ood_software\Service\RepoSyncService;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Drush commands for OOD Software / Appverse.
 */
class OodSoftwareCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The Repo sync service.
   *
   * @var \Drupal\ood_software\Service\RepoSyncService
   */
  protected $repoSync;

  /**
   * The GitHub service.
   *
   * @var \Drupal\ood_software\Plugin\GitHubService
   */
  protected $githubService;

  /**
   * The Appverse cache service.
   *
   * @var \Drupal\ood_software\Service\AppverseCacheService
   */
  protected $appverseCache;

  /**
   * Constructs an OodSoftwareCommands object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\ood_software\Service\RepoSyncService $repo_sync
   *   The Repo sync service.
   * @param \Drupal\ood_software\Plugin\GitHubService $github_service
   *   The GitHub service.
   * @param \Drupal\ood_software\Service\AppverseCacheService $appverse_cache
   *   The Appverse cache service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, KeyRepositoryInterface $key_repository, ClientInterface $http_client, RepoSyncService $repo_sync, GitHubService $github_service, AppverseCacheService $appverse_cache) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
    $this->keyRepository = $key_repository;
    $this->httpClient = $http_client;
    $this->repoSync = $repo_sync;
    $this->githubService = $github_service;
    $this->appverseCache = $appverse_cache;
  }

  /**
   * Sync the Repo for a single GitHub repo URL.
   *
   * @param string $repoUrl
   *   GitHub repo URL (e.g., https://github.com/owner/name).
   *
   * @command appverse:sync-repo
   * @aliases avsc
   * @usage drush appverse:sync-repo https://github.com/owner/name
   *   Refresh the Repo node for the given repo.
   */
  public function syncRepo(string $repoUrl): void {
    if (!preg_match('@^https://github\.com/([^/]+)/([^/]+?)(?:\.git)?/?$@', rtrim($repoUrl, '/'), $m)) {
      throw new \InvalidArgumentException('Repo URL must be of the form https://github.com/owner/name');
    }
    $owner = $m[1];
    $name = $m[2];

    // Bypass parseUrl()'s manifest.yml requirement — declared repos are
    // repo-based, not necessarily app-manifest-based.
    $this->githubService->setOwnerAndName($owner, $name);
    try {
      $this->githubService->fetchRepoData();
    }
    catch (\Throwable $e) {
      throw new \RuntimeException(sprintf(
        'Failed to fetch repo data for %s/%s — check repo accessibility, token, or rate limits. (%s)',
        $owner, $name, $e->getMessage()
      ), 0, $e);
    }

    // Wrap the sync body in try/finally so the cache flush always runs — even
    // when the subpath-fetch error path returns early. resolveRepo() below may
    // already have saved (and dirtied) the Repo node; a one-shot drush command
    // won't dispatch kernel.terminate, so without the finally a mid-sync
    // failure would leave the cache dirty-but-unflushed until cron.
    try {
      $repoMetadata = [
        'name' => $this->githubService->getRepoName() ?? $name,
        'description' => $this->githubService->getRepoDescription(),
        'organization' => $this->githubService->getOrganization(),
        'stars' => $this->githubService->getStars(),
        'lastCommittedDate' => $this->githubService->getLastComittedDate(),
        'readme' => $this->githubService->getReadme(),
      ];
      $repo = $this->repoSync->resolveRepo(
        $this->githubService->getRepoUrl(),
        $this->githubService->getAppverseYmlText(),
        $repoMetadata
      );

      $this->logger()->success(sprintf(
        'Repo synced: %s (nid: %d, status: %s, slug: %s)',
        $repo->getTitle(),
        $repo->id(),
        $repo->get('field_repo_validation_st')->value,
        basename($repo->toUrl()->toString())
      ));

      // Walk apps[] from the root appverse.yml (when declared) and create
      // per-subpath app nodes. Per-app metadata is sourced from
      // <path>/appverse.yml + <path>/manifest.yml.
      $rootYml = $this->githubService->getAppverseYmlText();
      if ($rootYml !== NULL) {
        try {
          $parsed = \Symfony\Component\Yaml\Yaml::parse($rootYml);
        }
        catch (\Throwable $e) {
          $parsed = NULL;
        }
        $apps = is_array($parsed) ? ($parsed['apps'] ?? []) : [];
        if (is_array($apps) && $apps) {
          $subpaths = [];
          foreach ($apps as $entry) {
            if (is_array($entry) && !empty($entry['path'])) {
              $cleanPath = trim((string) $entry['path'], '/');
              if ($cleanPath !== '') {
                $subpaths[] = $cleanPath;
              }
            }
          }
          if ($subpaths) {
            try {
              $subpathFiles = $this->githubService->fetchAppSubpaths($subpaths);
            }
            catch (\Throwable $e) {
              $this->logger()->error(sprintf(
                'Failed to fetch app subpaths for %s: %s',
                $this->githubService->getRepoUrl(),
                $e->getMessage()
              ));
              return;
            }
            $appNodes = $this->repoSync->applyDeclaredApps(
              $repo,
              $parsed,
              $subpathFiles,
              $this->githubService->getRepoUrl(),
              $repoMetadata
            );
            $this->logger()->success(sprintf('Synced %d apps from declared apps[]', count($appNodes)));
          }
        }
      }
    }
    finally {
      // One-shot drush invocations may not dispatch kernel.terminate, so the
      // hook-marked dirty flag could otherwise sit until cron. Flush now —
      // in finally so the early-return error path above is also covered.
      $this->appverseCache->flushIfDirty();
    }
  }

  /**
   * Assign appverse_app node ownership based on top GitHub contributor.
   *
   * For each app, fetches the top GitHub contributor, resolves their
   * identity to a Drupal user (via field_github_username or email),
   * and optionally updates the node author.
   *
   * @command ood:assign-owners
   * @option apply Actually apply changes (default is dry-run).
   * @option github-owner Only process apps from this GitHub owner/org.
   * @usage ood:assign-owners
   *   Dry-run: show what would change.
   * @usage ood:assign-owners --github-owner=roguephysicist
   *   Dry-run for a specific GitHub owner only.
   * @usage ood:assign-owners --apply
   *   Apply ownership changes.
   */
  public function assignOwners(array $options = ['apply' => FALSE, 'github-owner' => NULL]) {
    $apply = $options['apply'];
    $githubOwnerFilter = $options['github-owner'];
    $token = $this->keyRepository->getKey('appverse_github')->getKeyValue();

    if (!$token) {
      $this->logger()->error('Could not load appverse_github key.');
      return;
    }

    $headers = [
      'Authorization' => 'token ' . $token,
      'Accept' => 'application/vnd.github.v3+json',
      'User-Agent' => 'Drupal-OodSoftware',
    ];

    // Load all appverse_app nodes.
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'appverse_app')
      ->accessCheck(FALSE)
      ->execute();

    if (empty($nids)) {
      $this->io()->warning('No appverse_app nodes found.');
      return;
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    $this->io()->title($apply ? 'Assigning Appverse App Owners' : 'Dry Run: Appverse App Owners');
    $this->output()->writeln(count($nodes) . " apps found.\n");

    $matched = 0;
    $unmatched = 0;
    $errors = 0;
    $skipped = 0;

    foreach ($nodes as $node) {
      $title = $node->getTitle();

      // Extract GitHub URL.
      $github_url = $node->get('field_appverse_github_url')->uri ?? NULL;
      if (empty($github_url)) {
        $this->output()->writeln("  SKIP: \"$title\" → no GitHub URL");
        $skipped++;
        continue;
      }

      // Parse owner/repo from URL.
      $parsed = $this->parseGitHubUrl($github_url);
      if (!$parsed) {
        $this->output()->writeln("  ERROR: \"$title\" → could not parse URL: $github_url");
        $errors++;
        continue;
      }

      [$owner, $repo] = $parsed;

      // Filter by GitHub owner if specified (case-insensitive).
      if ($githubOwnerFilter && strcasecmp($githubOwnerFilter, $owner) !== 0) {
        continue;
      }

      // Fetch top contributor.
      try {
        $response = $this->httpClient->request('GET', "https://api.github.com/repos/$owner/$repo/contributors", [
          'headers' => $headers,
          'query' => ['per_page' => 1],
        ]);
        $contributors = json_decode($response->getBody()->getContents(), TRUE);
      }
      catch (GuzzleException $e) {
        $this->output()->writeln("  ERROR: \"$title\" → contributors API failed: " . $e->getMessage());
        $errors++;
        sleep(1);
        continue;
      }

      if (empty($contributors) || !isset($contributors[0]['login'])) {
        $this->output()->writeln("  ERROR: \"$title\" → no contributors returned");
        $errors++;
        sleep(1);
        continue;
      }

      $login = $contributors[0]['login'];

      // Try matching by field_github_username first.
      $drupal_user = $this->findUserByGitHubUsername($login);
      $match_method = 'github_username';

      if (!$drupal_user) {
        // Fetch commit email and profile email.
        $emails = $this->fetchGitHubEmails($owner, $repo, $login, $headers);

        if (!empty($emails)) {
          $drupal_user = $this->findUserByEmail($emails);
          $match_method = 'email';
        }
      }

      if ($drupal_user) {
        $uid = $drupal_user->id();
        $mail = $drupal_user->getEmail();
        $this->output()->writeln("  MATCH: \"$title\" → $mail (uid $uid) via $match_method");

        if ($apply) {
          $node->setOwnerId($uid);
          $node->save();
        }

        $matched++;
      }
      else {
        $email_str = !empty($emails) ? implode(', ', $emails) : 'no email found';
        $this->output()->writeln("  NO MATCH: \"$title\" → $login ($email_str) - no Drupal user found");
        $unmatched++;
      }

      // Rate limit: 1 second between apps.
      sleep(1);
    }

    $this->output()->writeln('');
    $this->io()->section('Summary');
    $this->output()->writeln("  Matched:   $matched");
    $this->output()->writeln("  Unmatched: $unmatched");
    $this->output()->writeln("  Errors:    $errors");
    $this->output()->writeln("  Skipped:   $skipped");

    if (!$apply && $matched > 0) {
      $this->output()->writeln("\nRun with --apply to save changes.");
    }
  }

  /**
   * Parse a GitHub URL into [owner, repo].
   *
   * @param string $url
   *   The GitHub URL.
   *
   * @return array|null
   *   Array of [owner, repo] or NULL if parsing fails.
   */
  protected function parseGitHubUrl(string $url): ?array {
    $parsed = parse_url($url);
    if (empty($parsed['host']) || $parsed['host'] !== 'github.com') {
      return NULL;
    }
    $path_parts = explode('/', trim($parsed['path'] ?? '', '/'));
    if (count($path_parts) < 2) {
      return NULL;
    }
    return [$path_parts[0], $path_parts[1]];
  }

  /**
   * Find a Drupal user by GitHub username (case-insensitive).
   *
   * @param string $login
   *   The GitHub login.
   *
   * @return \Drupal\user\UserInterface|null
   *   The matched user, or NULL.
   */
  protected function findUserByGitHubUsername(string $login) {
    $uids = $this->entityTypeManager->getStorage('user')->getQuery()
      ->condition('field_github_username', $login, 'LIKE')
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();

    if (!empty($uids)) {
      return $this->entityTypeManager->getStorage('user')->load(reset($uids));
    }
    return NULL;
  }

  /**
   * Fetch emails associated with a GitHub user for a repo.
   *
   * @param string $owner
   *   Repo owner.
   * @param string $repo
   *   Repo name.
   * @param string $login
   *   GitHub login.
   * @param array $headers
   *   HTTP headers with auth.
   *
   * @return array
   *   Array of unique email addresses found.
   */
  protected function fetchGitHubEmails(string $owner, string $repo, string $login, array $headers): array {
    $emails = [];

    // Get commit email.
    try {
      $response = $this->httpClient->request('GET', "https://api.github.com/repos/$owner/$repo/commits", [
        'headers' => $headers,
        'query' => ['author' => $login, 'per_page' => 1],
      ]);
      $commits = json_decode($response->getBody()->getContents(), TRUE);
      if (!empty($commits[0]['commit']['author']['email'])) {
        $email = $commits[0]['commit']['author']['email'];
        // Skip noreply GitHub addresses.
        if (!str_contains($email, 'noreply.github.com')) {
          $emails[] = $email;
        }
      }
    }
    catch (GuzzleException $e) {
      // Non-fatal, continue.
    }

    // Get profile email.
    try {
      $response = $this->httpClient->request('GET', "https://api.github.com/users/$login", [
        'headers' => $headers,
      ]);
      $profile = json_decode($response->getBody()->getContents(), TRUE);
      if (!empty($profile['email']) && !str_contains($profile['email'], 'noreply.github.com')) {
        $emails[] = $profile['email'];
      }
    }
    catch (GuzzleException $e) {
      // Non-fatal, continue.
    }

    return array_unique($emails);
  }

  /**
   * Find a Drupal user by email address.
   *
   * @param array $emails
   *   Email addresses to search for.
   *
   * @return \Drupal\user\UserInterface|null
   *   The matched user, or NULL.
   */
  protected function findUserByEmail(array $emails) {
    foreach ($emails as $email) {
      $uids = $this->entityTypeManager->getStorage('user')->getQuery()
        ->condition('mail', $email)
        ->condition('status', 1)
        ->accessCheck(FALSE)
        ->range(0, 1)
        ->execute();

      if (!empty($uids)) {
        return $this->entityTypeManager->getStorage('user')->load(reset($uids));
      }
    }
    return NULL;
  }

}
