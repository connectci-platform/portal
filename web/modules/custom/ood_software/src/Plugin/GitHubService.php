<?php

namespace Drupal\ood_software\Plugin;

use Drupal\Component\Utility\Xss;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Pull Software from github.
 */
class GitHubService {

  use StringTranslationTrait;

  /**
   * Manifest role → taxonomy term name mapping.
   *
   * Keys are manifest 'role' values; sub-keys are 'subcategory' values
   * (use '*' as default). Values are arrays of term names.
   */
  const APP_TYPE_MANIFEST_MAP = [
    'batch_connect' => [
      'GUIs' => ['batch-connect-VNC'],
      '*' => ['batch-connect-basic'],
    ],
    'dashboard' => [
      '*' => ['dashboard'],
    ],
    'passenger_app' => [
      '*' => ['companion_app'],
    ],
    'widget' => [
      '*' => ['widget'],
    ],
  ];

  /**
   * Github owner.
   *
   * @var string
   */
  protected $owner;

  /**
   * Github name.
   *
   * @var string
   */
  protected $name;

  /**
   * Github data.
   *
   * @var array<string, mixed>|null
   */
  protected $data;

  /**
   * Manifest data.
   *
   * @var array<string, mixed>|false|null
   */
  protected $manifestData;

  /**
   * Raw text of appverse.yml at repo root, or NULL if not present.
   *
   * @var string|null
   */
  protected ?string $appverseYmlText = NULL;

  /**
   * Per-subpath files keyed by path.
   *
   * Shape: ['jupyter_example' => ['manifestYml' => '...', 'appverseYml' => '...']].
   *
   * @var array<string, array<string, string|null>>
   */
  protected array $appSubpathFiles = [];

  /**
   * Is Archived.
   *
   * @var bool|null
   */
  protected $isArchived;

  /**
   * Stars for repo.
   *
   * @var int|null
   */
  protected $stars;

  /**
   * Repo readme.
   *
   * @var string|null
   */
  protected $readme;

  /**
   * Repo name.
   *
   * @var string|null
   */
  protected $repoName;

  /**
   * Repo description.
   *
   * @var string|null
   */
  protected $description;

  /**
   * Last commited unix timestamp.
   *
   * @var int|null
   */
  protected $lastComittedDate;

  /**
   * Repo license.
   *
   * @var string|null
   */
  protected $license;

  /**
   * Repo license link.
   *
   * @var string|null
   */
  protected $licenseLink;

  /**
   * Organization name.
   *
   * @var string|null
   */
  protected $organization;

  /**
   * Role - app type.
   *
   * @var string|null
   */
  protected $role;

  /**
   * Subcategory from manifest.
   *
   * @var string|null
   */
  protected $subcategory;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The key repository service.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Construct object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(ClientInterface $http_client, KeyRepositoryInterface $key_repository, MessengerInterface $messenger, LoggerChannelFactoryInterface $logger_factory, EntityTypeManagerInterface $entity_type_manager) {
    $this->httpClient = $http_client;
    $this->keyRepository = $key_repository;
    $this->messenger = $messenger;
    $this->logger = $logger_factory->get('ood_software');
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Parse github repo url.
   */
  public function parseUrl(string $repo): bool {
    $parsed_url = parse_url(Xss::filter($repo));
    if (isset($parsed_url['host']) && $parsed_url['host'] === 'github.com') {
      $path_parts = explode('/', trim($parsed_url['path'], '/'));
      if (count($path_parts) >= 2) {
        $organization = $path_parts[0];
        // Strip a trailing .git so duplicate-detection downstream sees the same
        // canonical owner/name regardless of which variant the user pasted.
        $repository = preg_replace('/\.git$/', '', $path_parts[1]);
        $this->owner = $organization;
        $this->name = $repository;

        // If valid GitHub URL, fetch data. A FALSE return means the GraphQL
        // call failed (repo not found, private, rate-limited, auth issue);
        // surface as a parse failure so the form shows the standard
        // "could not parse" error rather than running on stale state.
        if ($this->fetchRepoData() === FALSE) {
          return FALSE;
        }

        // Don't short-circuit on shape — caller checks isDeclaredRepo() /
        // isSingleAppRepo() / isEmptyRepo() to decide what to do.
        return TRUE;

      }
      else {
        // Invalid GitHub URL format.
        $this->messenger->addError($this->t('Invalid GitHub repository URL. Please enter a valid URL.'));
        return FALSE;
      }
    }
    else {
      // Not a GitHub URL.
      $this->messenger->addError($this->t('The URL provided is not a valid GitHub repository URL.'));
      return FALSE;
    }
  }

  /**
   * Fetch github repo.
   */
  public function fetchRepoData(): bool {
    $token = $this->keyRepository->getKey('appverse_github')->getKeyValue();

    $query = <<<'GRAPHQL'
    query($owner: String!, $name: String!) {
      repository(owner: $owner, name: $name) {
        isArchived
        stargazerCount
        forkCount
        description
        pushedAt
        owner {
          login
          ... on Organization {
            name
          }
          ... on User {
            name
          }
        }
        defaultBranchRef {
          name
          target {
            ... on Commit {
              committedDate
            }
          }
        }
        manifestYml: object(expression: "HEAD:manifest.yml") {
          ... on Blob { text }
        }
        appverseYml: object(expression: "HEAD:appverse.yml") {
          ... on Blob { text }
        }
        readme: object(expression: "HEAD:README.md") {
          ... on Blob { text }
        }
        licenseInfo {
          name
          spdxId
          url
        }
        releases(first: 5, orderBy: {field: CREATED_AT, direction: DESC}) {
          nodes {
            tagName
            publishedAt
          }
        }
      }
    }
    GRAPHQL;

    $response = $this->httpClient->request('POST', 'https://api.github.com/graphql', [
      'headers' => [
        'Authorization' => 'Bearer ' . $token,
        'Content-Type' => 'application/json',
      ],
      'json' => [
        'query' => $query,
        'variables' => [
          'owner' => $this->owner,
          'name' => $this->name,
        ],
      ],
    ]);

    // Parse JSON first, then sanitize specific content fields.
    // Running Xss::filter() on raw JSON corrupts embedded HTML attributes.
    $decoded = json_decode($response->getBody()->getContents(), TRUE);

    // GitHub GraphQL returns 200 OK with {errors: [...]} for soft failures
    // (repo not found, private repo, rate limit, auth issues). Treat any
    // missing data.repository as a failed fetch so downstream getters
    // don't trip null-offset warnings and the form short-circuits to a
    // friendly "could not parse" error.
    if (!is_array($decoded) || empty($decoded['data']['repository'])) {
      $errMsg = is_array($decoded) && !empty($decoded['errors'][0]['message'])
        ? $decoded['errors'][0]['message']
        : 'no repository data returned';
      $this->logger->warning(
        'GitHub GraphQL did not return repository data for @owner/@name: @err',
        ['@owner' => $this->owner, '@name' => $this->name, '@err' => $errMsg]
      );
      $this->data = NULL;
      return FALSE;
    }
    $this->data = $decoded['data']['repository'];

    $this->isArchived = $this->data['isArchived'];
    $this->stars = $this->data['stargazerCount'];
    // Sanitize README content with filterAdmin to allow safe HTML while
    // filtering dangerous protocols (javascript:) and event handlers (onclick).
    $readme_raw = $this->data['readme']['text'] ?? NULL;
    $this->readme = $readme_raw ? Xss::filterAdmin($readme_raw) : NULL;
    $this->lastComittedDate = strtotime($this->data['defaultBranchRef']['target']['committedDate']);
    $this->licenseLink = $this->data['licenseInfo']['url'] ?? NULL;
    $this->license = $this->data['licenseInfo']['spdxId'] ?? NULL;
    $this->organization = $this->data['owner']['name'];
    $this->appverseYmlText = $this->data['appverseYml']['text'] ?? NULL;

    $manifest_text = $this->data['manifestYml']['text'] ?? NULL;

    // Phase 1.6: this layer no longer emits user-facing shape errors. The
    // AJAX callback in ood_software.module is the single place that decides
    // what to tell the user based on isDeclaredRepo() / isSingleAppRepo() /
    // isEmptyRepo(). We log shape gaps at info level for ops visibility but
    // do not block.
    //
    // Backwards-compatibility: the legacy queue worker (AppverseAppUpdater)
    // calls parseUrl() then reads getRepoName/getDescription/getReadme/
    // getLicense/getAppTypeIds on each existing app. Every existing app in
    // the catalog today points at a single-app repo with a root manifest.yml
    // (otherwise it wouldn't have been created), so manifestData IS
    // populated for them. If an admin re-pointed an existing app at a
    // declared-repo URL, manifestData would be FALSE here and the worker's
    // getters would return NULL/empty — the worker would gracefully no-op
    // the changed fields rather than crash.
    if ($manifest_text === NULL) {
      $this->manifestData = FALSE;
      $this->logger->info('Repository @repo has no root manifest.yml; caller will decide shape.', ['@repo' => $this->owner . '/' . $this->name]);
      return TRUE;
    }

    $this->manifestData = Yaml::decode($manifest_text);
    $this->repoName = $this->manifestData['name'] ?? NULL;
    // Sanitize description from manifest as it may contain HTML.
    $description_raw = $this->manifestData['description'] ?? '';
    $this->description = $description_raw ? Xss::filterAdmin($description_raw) : '';
    $this->role = $this->manifestData['role'] ?? NULL;
    $this->subcategory = $this->manifestData['subcategory'] ?? NULL;
    return TRUE;
  }

  /**
   * Whether this repo has a root appverse.yml (a declared repo).
   *
   * Call after fetchRepoData(). Returns FALSE if fetchRepoData hasn't
   * run successfully (i.e. URL was invalid).
   */
  public function isDeclaredRepo(): bool {
    return $this->appverseYmlText !== NULL && trim($this->appverseYmlText) !== '';
  }

  /**
   * Whether this repo has a root manifest.yml (a single-app inferred repo).
   *
   * A repo can have BOTH a root manifest.yml AND a root appverse.yml. In that
   * case isDeclaredRepo() takes precedence — appverse.yml's apps[] is the
   * authoritative app list.
   *
   * See isDeclaredRepo() for preconditions (call after fetchRepoData()).
   */
  public function isSingleAppRepo(): bool {
    return $this->data !== NULL
      && ($this->data['manifestYml']['text'] ?? NULL) !== NULL
      && !$this->isDeclaredRepo();
  }

  /**
   * Whether this repo lacks both appverse.yml and root manifest.yml.
   *
   * Such a repo cannot register as either a declared repo or a single app.
   *
   * See isDeclaredRepo() for preconditions (call after fetchRepoData()).
   * Exactly one of isDeclaredRepo(), isSingleAppRepo(), isEmptyRepo()
   * returns TRUE at a time.
   */
  public function isEmptyRepo(): bool {
    return !$this->isDeclaredRepo() && !$this->isSingleAppRepo();
  }

  /**
   * Whether a submitter-supplied repo subpath is safe to interpolate.
   *
   * The subpath is placed into a GraphQL expression string literal
   * ("HEAD:<path>/manifest.yml"). Restrict it to characters that occur in a
   * real repo path so a crafted value cannot break out of the literal (and,
   * absent Guzzle's json_encode, achieve injection) or produce a malformed
   * query. Empty is not a valid path.
   *
   * @param string $path
   *   The trimmed subpath.
   *
   * @return bool
   *   TRUE if the path contains only [A-Za-z0-9._/-] and is non-empty.
   */
  public static function isValidSubpath(string $path): bool {
    return $path !== '' && preg_match('#^[A-Za-z0-9._/-]+$#', $path) === 1;
  }

  /**
   * Fetch per-subpath manifest.yml + appverse.yml from the same repo.
   *
   * Call after setOwnerAndName()/parseUrl() and fetchRepoData(); pass the
   * subpaths parsed from the root appverse.yml's apps[] list.
   *
   * @param string[] $subpaths
   *   Repo-relative subpaths (e.g. ['jupyter_example', 'rstudio_example']).
   *
   * @return array<string, array{manifestYml: ?string, appverseYml: ?string, readme: ?string, form: ?string}>
   *   Per-subpath file contents, keyed by the original subpath string.
   */
  public function fetchAppSubpaths(array $subpaths): array {
    $this->appSubpathFiles = [];
    if (empty($subpaths)) {
      return [];
    }
    if (empty($this->owner) || empty($this->name)) {
      throw new \LogicException('fetchAppSubpaths called before owner/name set; call setOwnerAndName or parseUrl first.');
    }

    $token = $this->keyRepository->getKey('appverse_github')->getKeyValue();

    // Build GraphQL aliases for each subpath. Aliases must start with a
    // letter; using p0, p1, … sidesteps any odd chars in the path itself.
    $aliasMap = [];
    $blobFields = [];
    foreach (array_values($subpaths) as $i => $path) {
      $alias = 'p' . $i;
      $cleanPath = trim((string) $path, '/');
      if ($cleanPath === '') {
        continue;
      }
      // The subpath comes verbatim from a submitter-authored appverse.yml
      // (apps[].path) and is interpolated into the GraphQL expression string
      // literal below. Guzzle json_encodes the whole request body, so a stray
      // quote cannot achieve true structural injection — but it WOULD produce
      // a malformed query and fail the sync. Reject anything that isn't a
      // plain repo subpath so a bad path is skipped with a clear log rather
      // than silently breaking the whole repo's import.
      if (!self::isValidSubpath($cleanPath)) {
        $this->logger->warning('Skipping app with invalid subpath %path (must match [A-Za-z0-9._/-]).', ['%path' => $cleanPath]);
        continue;
      }
      $aliasMap[$alias] = $cleanPath;
      // The expression argument MUST be a literal string in the query —
      // GraphQL won't substitute variables into "HEAD:<path>/...", so sprintf
      // builds the query string itself. $cleanPath is validated above.
      $blobFields[] = sprintf(
        "%s_manifest: object(expression: \"HEAD:%s/manifest.yml\") { ... on Blob { text } }\n        %s_appverse: object(expression: \"HEAD:%s/appverse.yml\") { ... on Blob { text } }\n        %s_readme: object(expression: \"HEAD:%s/README.md\") { ... on Blob { text } }\n        %s_form: object(expression: \"HEAD:%s/form.yml\") { ... on Blob { text } }",
        $alias, $cleanPath, $alias, $cleanPath, $alias, $cleanPath, $alias, $cleanPath
      );
    }
    if (empty($blobFields)) {
      return [];
    }
    $blobBlock = implode("\n        ", $blobFields);

    $query = <<<GRAPHQL
    query(\$owner: String!, \$name: String!) {
      repository(owner: \$owner, name: \$name) {
        $blobBlock
      }
    }
    GRAPHQL;

    $response = $this->httpClient->request('POST', 'https://api.github.com/graphql', [
      'headers' => [
        'Authorization' => 'Bearer ' . $token,
        'Content-Type' => 'application/json',
      ],
      'json' => [
        'query' => $query,
        'variables' => [
          'owner' => $this->owner,
          'name' => $this->name,
        ],
      ],
    ]);
    $status = $response->getStatusCode();
    $body = json_decode($response->getBody()->getContents(), TRUE);

    // Distinguish a REAL fetch failure from "files genuinely absent". On a
    // non-2xx or a missing data.repository, every subpath would resolve to
    // all-NULL text — indistinguishable from an empty repo — which would let a
    // caller rewrite valid members to rejected/empty on a transient blip. Throw
    // so callers abort instead of degrading the catalog.
    //
    // A non-empty errors[] does NOT by itself mean failure: GitHub GraphQL can
    // return usable data.repository alongside a partial/non-fatal errors array
    // (a per-field error, a secondary-rate-limit warning). Keep the usable data
    // in that case; only log the errors for visibility. This is safe because
    // every caller of fetchAppSubpaths is now ATTENDED — the Resync batch
    // (per-subpath catch), the drush command, and the form preview
    // (catch+degrade). A single subpath that errors surfaces to the operator;
    // there is no unattended (cron) caller that could silently degrade the
    // catalog on a partial error.
    if ($status < 200 || $status >= 300 || !isset($body['data']['repository'])) {
      throw new \RuntimeException(sprintf(
        'fetchAppSubpaths failed for %s/%s (HTTP %d%s).',
        $this->owner,
        $this->name,
        $status,
        !empty($body['errors']) ? ', GraphQL errors' : ''
      ));
    }
    if (!empty($body['errors'])) {
      $this->logger->warning('fetchAppSubpaths for %o/%n returned partial data with GraphQL errors; using the data present.', [
        '%o' => $this->owner,
        '%n' => $this->name,
      ]);
    }
    $repoData = $body['data']['repository'];

    $result = [];
    foreach ($aliasMap as $alias => $path) {
      $manifestText = $repoData[$alias . '_manifest']['text'] ?? NULL;
      $appverseText = $repoData[$alias . '_appverse']['text'] ?? NULL;
      $readmeText = $repoData[$alias . '_readme']['text'] ?? NULL;
      $formText = $repoData[$alias . '_form']['text'] ?? NULL;
      $result[$path] = [
        'manifestYml' => $manifestText,
        'appverseYml' => $appverseText,
        'readme' => $readmeText,
        'form' => $formText,
      ];
    }
    $this->appSubpathFiles = $result;
    return $result;
  }

  /**
   * Get the per-subpath files map, as populated by fetchAppSubpaths().
   *
   * @return array<string, array{manifestYml: ?string, appverseYml: ?string, readme: ?string, form: ?string}>
   */
  public function getAppSubpathFiles(): array {
    return $this->appSubpathFiles;
  }

  /**
   * Parse an OoD form.yml into a structured preview-friendly array.
   *
   * The shape returned mirrors the three top-level keys an OoD Batch
   * Connect launcher form cares about:
   * - clusters: the values from the YAML's `cluster` list (e.g. which
   *   HPC clusters this app can submit to).
   * - formFields: the values from the YAML's `form` list (the IDs of
   *   the visible launcher form fields, e.g. `bc_num_hours`).
   * - attributes: the raw associative array under `attributes`, where
   *   each key is a form field ID mapping to its widget config
   *   (label, type, default value, options, …).
   *
   * Any missing / non-array top-level keys collapse to empty arrays so
   * callers can iterate without null-checks.
   *
   * @param string|null $yaml
   *   Raw form.yml text, or NULL if the file wasn't found in the repo.
   *
   * @return array{
   *   clusters: string[],
   *   formFields: string[],
   *   attributes: array<string, array>,
   * }
   *   Empty arrays at each key when $yaml is NULL, empty, or malformed.
   */
  /**
   * @return array<string, mixed>
   */
  public function parseFormYml(?string $yaml): array {
    $empty = ['clusters' => [], 'formFields' => [], 'attributes' => []];
    if ($yaml === NULL || trim($yaml) === '') {
      return $empty;
    }
    try {
      $parsed = Yaml::decode($yaml);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not parse form.yml: @message', ['@message' => $e->getMessage()]);
      return $empty;
    }
    if (!is_array($parsed)) {
      return $empty;
    }
    // Normalize cluster + form to lists: OoD's form.yml schema lets
    // authors write either a list (`cluster: [cluster_a, cluster_b]`)
    // or a single scalar (`cluster: cluster_a`). Wrap scalars so callers
    // always see an array.
    $cluster = $parsed['cluster'] ?? NULL;
    $form = $parsed['form'] ?? NULL;
    return [
      'clusters' => is_array($cluster)
        ? array_values($cluster)
        : (is_string($cluster) && $cluster !== '' ? [$cluster] : []),
      'formFields' => is_array($form)
        ? array_values($form)
        : (is_string($form) && $form !== '' ? [$form] : []),
      'attributes' => is_array($parsed['attributes'] ?? NULL) ? $parsed['attributes'] : [],
    ];
  }

  /**
   * Resolve a software: declaration to an appverse_software node title.
   *
   * @param string|null $declared
   *   The raw `software` value from appverse.yml's apps[] entry, or NULL
   *   if the author omitted it.
   *
   * @return array{
   *   declared: ?string,
   *   resolvedTitle: ?string,
   *   resolvedNid: ?int,
   *   suggestion: ?string,
   *   suggestionDistance: ?int,
   * }
   *   - declared: the raw input.
   *   - resolvedTitle / resolvedNid: the matched appverse_software title +
   *     nid if exact match found (case-insensitive); NULL otherwise.
   *   - suggestion: closest Software title by Levenshtein distance, if
   *     declared is non-empty and no exact match found and a candidate
   *     within distance <= 3 exists; NULL otherwise.
   *   - suggestionDistance: the Levenshtein distance to the suggestion.
   */
  // PUBLIC because RepoSyncService::applyDeclaredApp (Task 5) calls
  // this from another class. We could move resolution to a dedicated
  // SoftwareResolverService, but for Phase 1.7's single call site that's
  // over-engineered. If a third caller appears, extract to its own service.
  public function resolveSoftwareForApp(?string $declared): array {
    $result = [
      'declared' => $declared,
      'resolvedTitle' => NULL,
      'resolvedNid' => NULL,
      'suggestion' => NULL,
      'suggestionDistance' => NULL,
    ];
    if ($declared === NULL || trim($declared) === '') {
      return $result;
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $allSoftwareIds = $nodeStorage->getQuery()
      ->condition('type', 'appverse_software')
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->execute();
    if (empty($allSoftwareIds)) {
      return $result;
    }
    $software = $nodeStorage->loadMultiple($allSoftwareIds);
    $declaredLower = mb_strtolower(trim($declared));

    // Exact case-insensitive match wins.
    foreach ($software as $sw) {
      if (mb_strtolower($sw->getTitle()) === $declaredLower) {
        $result['resolvedTitle'] = $sw->getTitle();
        $result['resolvedNid'] = (int) $sw->id();
        return $result;
      }
    }

    // No exact match. Compute Levenshtein to find closest within threshold.
    $bestTitle = NULL;
    $bestDistance = PHP_INT_MAX;
    foreach ($software as $sw) {
      $distance = levenshtein($declaredLower, mb_strtolower($sw->getTitle()));
      if ($distance < $bestDistance) {
        $bestDistance = $distance;
        $bestTitle = $sw->getTitle();
      }
    }
    if ($bestTitle !== NULL && $bestDistance <= 3) {
      $result['suggestion'] = $bestTitle;
      $result['suggestionDistance'] = $bestDistance;
    }
    return $result;
  }

  /**
   * Resolve a list of declared taxonomy term names against a vocabulary.
   *
   * Generic version of resolveSoftwareForApp for list-shaped fields like
   * `tags:` in apps[] entries. Each declared value gets the same case-
   * insensitive exact match + Levenshtein ≤ 3 fuzzy-match-suggestion
   * treatment.
   *
   * @param string $vocabularyId
   *   The taxonomy vocabulary machine name (e.g. 'appverse_implementation_tags').
   * @param array|null $declared
   *   The raw list from appverse.yml (e.g. ['Container', 'GPU-required']),
   *   or NULL when the field is missing.
   *
   * @return array{
   *   declared: string[],
   *   resolved: array<int, array{name: string, tid: int}>,
   *   unresolved: array<int, array{declared: string, suggestion: ?string, suggestionDistance: ?int}>,
   * }
   *   - declared: the raw input strings.
   *   - resolved: terms that matched exactly (case-insensitive).
   *   - unresolved: declarations with no exact match; each carries an
   *     optional Levenshtein-≤-3 suggestion of the closest existing term.
   */
  /**
   * @param array<int, string>|null $declared
   *   Declared term names from appverse.yml.
   *
   * @return array<string, mixed>
   */
  public function resolveTaxonomyTermsFromAppverseYml(string $vocabularyId, ?array $declared): array {
    $result = ['declared' => [], 'resolved' => [], 'unresolved' => []];
    if ($declared === NULL || empty($declared)) {
      return $result;
    }
    // Normalize the input list: coerce scalars to strings, trim, drop
    // empties.
    $declaredStrings = [];
    foreach ($declared as $d) {
      if (!is_scalar($d)) {
        continue;
      }
      $s = trim((string) $d);
      if ($s !== '') {
        $declaredStrings[] = $s;
      }
    }
    if (empty($declaredStrings)) {
      return $result;
    }
    $result['declared'] = $declaredStrings;

    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $allTermIds = $termStorage->getQuery()
      ->condition('vid', $vocabularyId)
      ->accessCheck(FALSE)
      ->execute();
    $terms = $allTermIds ? $termStorage->loadMultiple($allTermIds) : [];

    foreach ($declaredStrings as $value) {
      $valueLower = mb_strtolower($value);

      // Exact case-insensitive match.
      $matched = NULL;
      foreach ($terms as $term) {
        if (mb_strtolower($term->getName()) === $valueLower) {
          $matched = $term;
          break;
        }
      }
      if ($matched !== NULL) {
        $result['resolved'][] = ['name' => $matched->getName(), 'tid' => (int) $matched->id()];
        continue;
      }

      // Fuzzy suggestion.
      $bestName = NULL;
      $bestDistance = PHP_INT_MAX;
      foreach ($terms as $term) {
        $distance = levenshtein($valueLower, mb_strtolower($term->getName()));
        if ($distance < $bestDistance) {
          $bestDistance = $distance;
          $bestName = $term->getName();
        }
      }
      $result['unresolved'][] = [
        'declared' => $value,
        'suggestion' => ($bestName !== NULL && $bestDistance <= 3) ? $bestName : NULL,
        'suggestionDistance' => ($bestName !== NULL && $bestDistance <= 3) ? $bestDistance : NULL,
      ];
    }

    return $result;
  }

  /**
   * Return per-app preview records for whatever shape this repo is.
   *
   * For a declared repo: walks apps[] in appverse.yml and returns
   * one record per subpath (fetching per-subpath manifest + form.yml +
   * README presence).
   *
   * For a single-app inferred repo: returns one record from the
   * root manifest, with subpath=''.
   *
   * For an empty repo: returns [].
   *
   * Call after fetchRepoData(). For declared repos, this method also calls
   * fetchAppSubpaths() internally — caller doesn't need to.
   *
   * @return array<int, array{
   *   subpath: string,
   *   name: ?string,
   *   category: ?string,
   *   subcategory: ?string,
   *   role: ?string,
   *   description: ?string,
   *   license: ?string,
   *   clusters: string[],
   *   formFields: string[],
   *   attributes: array,
   *   readmePresent: bool,
   *   readmeBytes: int,
   *   software: array{
   *     declared: ?string,
   *     resolvedTitle: ?string,
   *     resolvedNid: ?int,
   *     suggestion: ?string,
   *     suggestionDistance: ?int,
   *   },
   * }>
   */
  /**
   * @return array<int|string, mixed>
   */
  public function getAppPreviewData(): array {
    if ($this->isEmptyRepo()) {
      return [];
    }

    if ($this->isDeclaredRepo()) {
      // Walk apps[] in root appverse.yml.
      try {
        $appverse = Yaml::decode($this->appverseYmlText);
      }
      catch (\Throwable $e) {
        $this->logger->warning('appverse.yml at root is not parseable: @msg', ['@msg' => $e->getMessage()]);
        return [];
      }
      $subpaths = [];
      // Map of path => the full apps[] entry so pass 2 can read per-entry
      // data (e.g. software:) without losing it across fetchAppSubpaths().
      $entriesByPath = [];
      $skipped = 0;
      foreach (($appverse['apps'] ?? []) as $app) {
        if (isset($app['path']) && is_string($app['path']) && $app['path'] !== '') {
          $path = $app['path'];
          $subpaths[] = $path;
          $entriesByPath[$path] = $app;
        }
        else {
          $skipped++;
        }
      }
      if ($skipped > 0) {
        $this->logger->notice('Skipped @n appverse.yml apps[] entry/entries without a non-empty path.', ['@n' => $skipped]);
      }
      if (empty($subpaths)) {
        return [];
      }
      // fetchAppSubpaths() throws on a transport/GraphQL failure. This is a
      // PREVIEW — degrade gracefully (render whatever we can from the root
      // yaml, with empty per-subpath files) rather than WSOD the Add-Repo
      // form when GitHub is transiently unavailable.
      try {
        $this->fetchAppSubpaths($subpaths);
      }
      catch (\Throwable $e) {
        $this->logger->warning('App preview: per-subpath fetch failed, showing a degraded preview: @msg', ['@msg' => $e->getMessage()]);
        $this->appSubpathFiles = [];
      }

      $records = [];
      // Repo-level license falls through to apps that lack their own.
      $repoLicense = $this->license;
      foreach ($subpaths as $path) {
        $entry = $entriesByPath[$path] ?? [];
        $files = $this->appSubpathFiles[$path] ?? [];
        $manifest = [];
        if (!empty($files['manifestYml'])) {
          try {
            $manifest = Yaml::decode($files['manifestYml']) ?? [];
          }
          catch (\Throwable $e) {
            $this->logger->warning('Could not parse manifest.yml at @path: @msg', ['@path' => $path, '@msg' => $e->getMessage()]);
            $manifest = [];
          }
        }
        $formData = $this->parseFormYml($files['form'] ?? NULL);
        $readme = $files['readme'] ?? NULL;
        // Build the canonical appverse layer for this app: per-subpath
        // <path>/appverse.yml merged with the root-inline apps[] entry,
        // with root-inline winning. See spec §5 step 2.
        $perSubpathAppverse = [];
        if (!empty($files['appverseYml'])) {
          try {
            $parsed = Yaml::decode($files['appverseYml']);
            if (is_array($parsed)) {
              $perSubpathAppverse = $parsed;
            }
          }
          catch (\Throwable $e) {
            $this->logger->warning('Could not parse @path/appverse.yml: @msg', ['@path' => $path, '@msg' => $e->getMessage()]);
          }
        }
        $rootInline = is_array($entry) ? $entry : [];
        $appverseLayer = array_replace($perSubpathAppverse, $rootInline);
        unset($appverseLayer['path']);

        // appverse layer is canonical for the catalog; falls through to
        // manifest.yml when a field isn't declared in the layer.
        $name = $appverseLayer['name'] ?? $manifest['name'] ?? NULL;
        $description = $appverseLayer['description'] ?? $manifest['description'] ?? NULL;
        $role = $appverseLayer['role'] ?? $manifest['role'] ?? NULL;
        $category = $appverseLayer['category'] ?? $manifest['category'] ?? NULL;
        $subcategory = $appverseLayer['subcategory'] ?? $manifest['subcategory'] ?? NULL;
        $license = $appverseLayer['license'] ?? $manifest['license'] ?? $repoLicense;

        $softwareInfo = $this->resolveSoftwareForApp($appverseLayer['software'] ?? NULL);
        // Tags: per-app `tags:` declared in the appverse layer. Resolve
        // against the appverse_implementation_tags vocabulary with the
        // same case-insensitive + fuzzy-suggestion pattern as software.
        // Per-app tags come from `implementation_tags:`, NOT `tags:` — root
        // `tags:` is repo-level DISCOVERY tags (a different vocabulary). The
        // sync (RepoSyncService::applyDeclaredApp) reads implementation_tags;
        // the preview must read the same key or it misrepresents what will be
        // catalogued. (Repo-root shared_implementation_tags additively merged
        // by the sync are not shown in this per-app preview.)
        $declaredTags = $appverseLayer['implementation_tags'] ?? NULL;
        $tagsInfo = $this->resolveTaxonomyTermsFromAppverseYml('appverse_implementation_tags', is_array($declaredTags) ? $declaredTags : NULL);

        $records[] = [
          'subpath' => $path,
          'name' => $name,
          'category' => $category,
          'subcategory' => $subcategory,
          'role' => $role,
          'description' => $description,
          'license' => $license,
          'clusters' => $formData['clusters'],
          'formFields' => $formData['formFields'],
          'attributes' => $formData['attributes'],
          'readmePresent' => $readme !== NULL,
          'readmeBytes' => $readme !== NULL ? strlen($readme) : 0,
          'software' => $softwareInfo,
          'tags' => $tagsInfo,
        ];
      }
      return $records;
    }


    // Single-App Inferred: one record from the root manifest.
    // Harden against the edge case where flags say single-app but
    // manifestData is not an array (e.g. earlier parse failure leaving
    // it as FALSE — fetchRepoData sets manifestData = FALSE on shape
    // gaps). Treat that as "no metadata available."
    $manifest = is_array($this->manifestData) ? $this->manifestData : [];
    return [[
      'subpath' => '',
      'name' => $manifest['name'] ?? NULL,
      'category' => $manifest['category'] ?? NULL,
      'subcategory' => $manifest['subcategory'] ?? NULL,
      'role' => $this->role,
      'description' => $this->description,
      'license' => $this->license,
      // No form.yml fetch for the inferred case in this phase. If we
      // want this we can add a fetchRootFormYml() helper as a follow-up.
      'clusters' => [],
      'formFields' => [],
      'attributes' => [],
      'readmePresent' => $this->readme !== NULL,
      'readmeBytes' => $this->readme !== NULL ? strlen($this->readme) : 0,
      // Single-app inferred repos get Software via the form's
      // field_appverse_software_implemen autocomplete, not the manifest.
      // Tags get picked via the form's field_add_implementation_tags
      // widget. Both return empty resolution shapes so consumers can rely
      // on the keys being present.
      'software' => $this->resolveSoftwareForApp(NULL),
      'tags' => $this->resolveTaxonomyTermsFromAppverseYml('appverse_implementation_tags', NULL),
    ]];
  }

  /**
   * Get github data.
   */
  /**
   * @return array<string, mixed>|null
   */
  public function getData(): ?array {
    return $this->data;
  }

  /**
   * Get manifest.
   */
  /**
   * @return array<string, mixed>|false|null
   */
  public function getManifestData(): array|false|null {
    return $this->manifestData;
  }

  /**
   * Get is archived.
   */
  public function getIsArchived(): ?bool {
    return $this->isArchived;
  }

  /**
   * Get stars.
   */
  public function getStars(): ?int {
    return $this->stars;
  }

  /**
   * Get readme.
   */
  public function getReadme(): ?string {
    return $this->readme;
  }

  /**
   * Get repo name.
   */
  public function getRepoName(): ?string {
    return $this->repoName;
  }

  /**
   * Get description.
   */
  public function getDescription(): ?string {
    return $this->description;
  }

  /**
   * Get last committed date.
   */
  public function getLastComittedDate(): ?int {
    return $this->lastComittedDate;
  }

  /**
   * Get license.
   */
  public function getLicenseLink(): ?string {
    return $this->licenseLink;
  }

  /**
   * Get license.
   */
  public function getLicense(): ?string {
    return $this->license;
  }

  /**
   * Get Organization.
   */
  public function getOrganization(): ?string {
    return $this->organization;
  }

  /**
   * Get the raw text of appverse.yml at the repo root, if present.
   *
   * @return string|null
   *   The appverse.yml content as a string, or NULL if the file does not exist.
   */
  public function getAppverseYmlText(): ?string {
    return $this->appverseYmlText;
  }

  /**
   * Get the canonical GitHub repo URL (https://github.com/owner/name).
   *
   * @return string
   *   The repo URL derived from $this->owner and $this->name.
   */
  public function getRepoUrl(): string {
    return sprintf('https://github.com/%s/%s', $this->owner, $this->name);
  }

  /**
   * Set owner and repo name directly, bypassing parseUrl's manifest check.
   *
   * Useful for Declared repos that don't have a manifest.yml. After
   * calling this, invoke fetchRepoData() to populate the rest of the data.
   *
   * @param string $owner
   *   The GitHub repo owner (org or user login).
   * @param string $name
   *   The GitHub repo name.
   */
  public function setOwnerAndName(string $owner, string $name): void {
    $this->owner = $owner;
    $this->name = $name;
  }

  /**
   * Get the repo's GitHub description (from the GraphQL API).
   *
   * @return string
   *   The repo description, or empty string if not set.
   */
  public function getRepoDescription(): string {
    return $this->data['description'] ?? '';
  }

  /**
   * Get AppType label for form display.
   */
  public function getAppType(): ?string {
    $label = $this->role;
    if ($label && $this->subcategory) {
      $label .= ' (' . $this->subcategory . ')';
    }
    return $label;
  }

  /**
   * Get subcategory from manifest.
   */
  public function getSubcategory(): ?string {
    return $this->subcategory;
  }

  /**
   * Get AppTypeId based on set role (backward compat, returns first match).
   */
  public function getAppTypeId(): int|string|false|null {
    $ids = $this->getAppTypeIds();
    return $ids ? reset($ids) : NULL;
  }

  /**
   * Resolve role + subcategory to taxonomy term IDs.
   */
  /**
   * @return array<int, int>
   */
  public function getAppTypeIds(): array {
    if (empty($this->role)) {
      return [];
    }
    $role_map = self::APP_TYPE_MANIFEST_MAP[$this->role] ?? NULL;
    if ($role_map === NULL) {
      $this->logger->notice('No app type mapping for manifest role: @role', ['@role' => $this->role]);
      return [];
    }
    // The '*' fallback and the emptiness check are defensive: PHPStan resolves
    // APP_TYPE_MANIFEST_MAP as a constant and concludes '*' always exists and is
    // non-empty, but that holds only for the map's current contents. Keep both
    // guards so adding a role without a '*' default degrades to "no terms"
    // rather than a fatal.
    // @phpstan-ignore-next-line
    $term_names = $role_map[$this->subcategory] ?? $role_map['*'] ?? [];
    // @phpstan-ignore-next-line
    if (empty($term_names)) {
      return [];
    }
    $ids = [];
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    foreach ($term_names as $name) {
      $terms = $storage->loadByProperties(['name' => $name, 'vid' => 'appverse_app_type']);
      $term = reset($terms);
      if ($term) {
        $ids[] = (int) $term->id();
      }
      else {
        $this->logger->warning('App type term not found: @name', ['@name' => $name]);
      }
    }
    return $ids;
  }

  /**
   * Get all term names that can be auto-assigned from manifest data.
   */
  /**
   * @return array<int, string>
   */
  public static function getAutoAssignableTermNames(): array {
    $names = [];
    foreach (self::APP_TYPE_MANIFEST_MAP as $subcategories) {
      foreach ($subcategories as $termNames) {
        $names = array_merge($names, $termNames);
      }
    }
    return array_unique($names);
  }

  /**
   * Get license.
   */
  public function getLicenseInfo(): ?string {
    return $this->license;
  }

}
