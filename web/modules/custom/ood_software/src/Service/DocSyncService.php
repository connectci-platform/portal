<?php

namespace Drupal\ood_software\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Syncs markdown documentation from GitHub to Drupal nodes.
 */
class DocSyncService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Map of GitHub raw doc URLs to Drupal node IDs.
   *
   * Each doc is synced from its canonical repo. Review-skill docs (the
   * reviewer checklist and the security rubric) are maintained in the
   * appverse-review plugin repo under references/; broader Appverse docs
   * live in ood-appverse under docs/. The URL is the full raw.githubusercontent
   * path so a single map can span repos and subpaths.
   */
  public const DOC_MAP = [
    'https://raw.githubusercontent.com/Sweet-and-Fizzy/appverse-review/main/references/review-checklist.md' => 11932,
    'https://raw.githubusercontent.com/Sweet-and-Fizzy/appverse-review/main/references/security-rubric.md' => 12246,
    'https://raw.githubusercontent.com/sweet-and-fizzy/ood-appverse/main/docs/appverse-contributor-guide.md' => 11929,
    'https://raw.githubusercontent.com/sweet-and-fizzy/ood-appverse/main/docs/app-best-practices.md' => 11933,
  ];

  /**
   * Constructs a DocSyncService object.
   */
  public function __construct(ClientInterface $http_client, EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory) {
    $this->httpClient = $http_client;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('ood_software');
  }

  /**
   * Sync all mapped docs from GitHub to Drupal nodes.
   */
  public function syncAll(): void {
    foreach (self::DOC_MAP as $url => $nid) {
      $this->syncDoc($url, $nid);
    }
  }

  /**
   * Sync a single doc from GitHub to a Drupal node.
   *
   * @param string $url
   *   The full raw.githubusercontent URL of the markdown source.
   * @param int $nid
   *   The Drupal node ID to update.
   */
  protected function syncDoc($url, $nid): void {
    // A nid of 0 marks a doc whose Drupal node has not been created yet.
    // Skip it so the sync does not error until the node exists.
    if (empty($nid)) {
      return;
    }

    $filename = basename($url);

    try {
      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 15,
      ]);
      $markdown = $response->getBody()->getContents();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to fetch @file from GitHub: @error', [
        '@file' => $filename,
        '@error' => $e->getMessage(),
      ]);
      return;
    }

    if (empty($markdown)) {
      $this->logger->warning('Empty content fetched for @file, skipping.', [
        '@file' => $filename,
      ]);
      return;
    }

    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node) {
      $this->logger->error('Node @nid not found for @file.', [
        '@nid' => $nid,
        '@file' => $filename,
      ]);
      return;
    }

    // Strip the first H1 heading since the page title handles that.
    $markdown = preg_replace('/\A#\s+.+\n*/', '', $markdown, 1);

    // Phase 1.7: substitute vocab-listing tokens. Authors writing
    // contributor docs can drop `{{ APPVERSE_IMPLEMENTATION_TAGS }}` (or
    // any other supported token) into their markdown to embed the
    // current term list at sync time. Keeps the docs and the live
    // catalog in sync without a separate discovery API endpoint.
    $markdown = $this->substituteVocabularyTokens($markdown);

    $body_item = $node->get('body')->first();
    $current_body = $body_item?->getValue()['value'] ?? NULL;
    $current_format = $body_item?->getValue()['format'] ?? NULL;

    // Only save if content actually changed.
    if ($current_body === $markdown && $current_format === 'markdown') {
      return;
    }

    $node->set('body', [
      'value' => $markdown,
      'format' => 'markdown',
    ]);
    $node->save();

    $this->logger->notice('Synced @file to node @nid.', [
      '@file' => $filename,
      '@nid' => $nid,
    ]);
  }

  /**
   * Map of supported tokens to taxonomy vocabulary machine names.
   *
   * Markdown authors can use any of these tokens in their docs source
   * to embed the current vocabulary listing at sync time. Adding a new
   * token: just add a row here.
   */
  protected const VOCABULARY_TOKEN_MAP = [
    '{{ APPVERSE_IMPLEMENTATION_TAGS }}' => 'appverse_implementation_tags',
    '{{ APPVERSE_SCIENCE_DOMAINS }}' => 'appverse_science_domains',
    '{{ APPVERSE_APP_TYPES }}' => 'appverse_app_type',
    '{{ APPVERSE_LICENSES }}' => 'appverse_license',
  ];

  /**
   * Substitute vocab-listing tokens in markdown with the current term list.
   *
   * Each token in VOCABULARY_TOKEN_MAP gets replaced with a markdown
   * bullet list of the vocabulary's current term names, sorted
   * alphabetically. Empty vocabularies emit an italicized "(no terms yet)"
   * placeholder so missing tokens are visible rather than silently empty.
   *
   * @param string $markdown
   *   Raw markdown from GitHub.
   *
   * @return string
   *   Markdown with tokens substituted.
   */
  protected function substituteVocabularyTokens(string $markdown): string {
    foreach (self::VOCABULARY_TOKEN_MAP as $token => $vocabularyId) {
      if (strpos($markdown, $token) === FALSE) {
        continue;
      }
      $listing = $this->renderVocabularyListing($vocabularyId);
      $markdown = str_replace($token, $listing, $markdown);
    }
    return $markdown;
  }

  /**
   * Render a taxonomy vocabulary's current term list as a markdown bullet list.
   *
   * @param string $vocabularyId
   *   The taxonomy vocabulary machine name.
   *
   * @return string
   *   Markdown — "- Term name\n- Term name\n..." or italicized empty notice.
   */
  protected function renderVocabularyListing(string $vocabularyId): string {
    try {
      $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
      $tids = $termStorage->getQuery()
        ->condition('vid', $vocabularyId)
        ->sort('name')
        ->accessCheck(FALSE)
        ->execute();
      if (empty($tids)) {
        return '*(no terms defined yet in vocabulary `' . $vocabularyId . '`)*';
      }
      $names = [];
      foreach ($termStorage->loadMultiple($tids) as $term) {
        $names[] = '- ' . $term->getName();
      }
      return implode("\n", $names);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Failed to render vocabulary listing for @vocab: @msg', [
        '@vocab' => $vocabularyId,
        '@msg' => $e->getMessage(),
      ]);
      return '*(could not load `' . $vocabularyId . '` — see watchdog)*';
    }
  }

}
