<?php

namespace Drupal\ood_software\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\node\NodeInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Dispatches AI reviews via GitHub Actions when repos enter ready_for_review.
 *
 * Triggered from ood_software_node_update() alongside RepoNotificationService.
 * Fires a workflow_dispatch on Sweet-and-Fizzy/appverse-review, which runs the
 * Claude-based review pipeline and produces a PDF report + JSON summary.
 *
 * This is fire-and-forget: a dispatch failure never blocks the Drupal
 * moderation transition. Errors are logged for admin visibility.
 */
class AppverseReviewService {

  /**
   * The GitHub owner/repo where the review workflow lives.
   */
  const REVIEW_REPO = 'Sweet-and-Fizzy/appverse-review';

  /**
   * The workflow filename (GitHub API accepts filename or numeric ID).
   */
  const WORKFLOW_FILE = 'appverse-review.yaml';

  /**
   * The branch to run the workflow on.
   */
  const WORKFLOW_REF = 'main';

  /**
   * Drupal Key module key ID for the GitHub token.
   */
  const GITHUB_KEY_ID = 'appverse_review_github';

  /**
   * Fallback key ID if the dedicated key doesn't exist.
   */
  const GITHUB_KEY_FALLBACK = 'appverse_github';

  protected LoggerInterface $logger;

  public function __construct(
    protected ClientInterface $httpClient,
    protected KeyRepositoryInterface $keyRepository,
    LoggerChannelFactoryInterface $loggerFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
  ) {
    $this->logger = $loggerFactory->get('ood_software');
  }

  /**
   * React to a moderation state transition on an appverse_repo node.
   *
   * Only dispatches a review when entering ready_for_review from a
   * different state. The review_to_review self-transition is allowed
   * so admins can explicitly re-trigger a review.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The appverse_repo node (post-save).
   * @param string|null $previousState
   *   The moderation_state before this save, or NULL if unknown.
   */
  public function onTransition(NodeInterface $node, ?string $previousState): void {
    if ($node->bundle() !== 'appverse_repo') {
      return;
    }

    $newState = $node->get('moderation_state')->value;
    if ($newState !== 'ready_for_review') {
      return;
    }

    // Allow re-review on review_to_review self-transition, but skip if
    // the previous state is unknown (NULL) to avoid false triggers on
    // bulk operations.
    if ($previousState === NULL) {
      return;
    }

    $repoUrl = $this->extractRepoUrl($node);
    if ($repoUrl === NULL) {
      $this->logger->warning('Cannot dispatch review for repo node @nid: no field_repo_url value.', [
        '@nid' => $node->id(),
      ]);
      return;
    }

    $ownerRepo = $this->parseOwnerRepo($repoUrl);
    if ($ownerRepo === NULL) {
      $this->logger->warning('Cannot dispatch review for repo node @nid: could not parse owner/repo from URL @url.', [
        '@nid' => $node->id(),
        '@url' => $repoUrl,
      ]);
      return;
    }

    if ($this->dispatch($ownerRepo)) {
      $this->recordDispatch($node);
    }
  }

  /**
   * Record a successful dispatch on the node's review tracking fields.
   *
   * Re-loads the node to avoid saving stale state from the in-flight
   * hook_node_update() context. Sets dispatched_at, status=pending,
   * and clears prior recommendation/report/run_id.
   */
  protected function recordDispatch(NodeInterface $node): void {
    try {
      $storage = $this->entityTypeManager->getStorage('node');
      $fresh = $storage->loadUnchanged($node->id());
      if (!$fresh) {
        return;
      }

      $now = $this->time->getRequestTime();
      if ($fresh->hasField('field_review_dispatched_at')) {
        $fresh->set('field_review_dispatched_at', $now);
      }
      if ($fresh->hasField('field_review_status')) {
        $fresh->set('field_review_status', 'pending');
      }
      if ($fresh->hasField('field_review_recommendation')) {
        $fresh->set('field_review_recommendation', NULL);
      }
      if ($fresh->hasField('field_review_run_id')) {
        $fresh->set('field_review_run_id', NULL);
      }

      $fresh->_ood_software_suppress_notifications = TRUE;
      if (method_exists($fresh, 'setValidationRequired')) {
        $fresh->setValidationRequired(FALSE);
      }
      $fresh->save();
    }
    catch (\Throwable $e) {
      $this->logger->warning('Failed to record review dispatch on node @nid: @msg', [
        '@nid' => $node->id(),
        '@msg' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Trigger a workflow_dispatch on the appverse-review GitHub Actions workflow.
   *
   * @param string $targetRepo
   *   The target repo in "owner/name" format (e.g. "OSC/bc_osc_jupyter").
   * @param string $model
   *   Claude model to use. Defaults to "sonnet".
   *
   * @return bool
   *   TRUE if the dispatch succeeded (HTTP 204), FALSE otherwise.
   */
  public function dispatch(string $targetRepo, string $model = 'sonnet'): bool {
    $token = $this->getToken();
    if ($token === NULL) {
      $this->logger->error('Cannot dispatch review: no GitHub token found (tried keys @primary, @fallback).', [
        '@primary' => self::GITHUB_KEY_ID,
        '@fallback' => self::GITHUB_KEY_FALLBACK,
      ]);
      return FALSE;
    }

    $url = sprintf(
      'https://api.github.com/repos/%s/actions/workflows/%s/dispatches',
      self::REVIEW_REPO,
      self::WORKFLOW_FILE,
    );

    try {
      $response = $this->httpClient->post($url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Accept' => 'application/vnd.github+json',
          'Content-Type' => 'application/json',
          'X-GitHub-Api-Version' => '2022-11-28',
        ],
        'json' => [
          'ref' => self::WORKFLOW_REF,
          'inputs' => [
            'target_repo' => $targetRepo,
            'target_branch' => '',
            'review_aspects' => 'all',
            'model' => $model,
          ],
        ],
      ]);

      $status = $response->getStatusCode();
      if ($status === 204) {
        $this->logger->info('Dispatched AppVerse review for @repo.', [
          '@repo' => $targetRepo,
        ]);
        return TRUE;
      }

      $this->logger->warning('Unexpected status @status dispatching review for @repo.', [
        '@status' => $status,
        '@repo' => $targetRepo,
      ]);
      return FALSE;
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to dispatch review for @repo: @message', [
        '@repo' => $targetRepo,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Extract the GitHub repo URL from an appverse_repo node.
   *
   * @return string|null
   *   The raw URL string, or NULL if the field is empty/missing.
   */
  protected function extractRepoUrl(NodeInterface $node): ?string {
    if (!$node->hasField('field_repo_url') || $node->get('field_repo_url')->isEmpty()) {
      return NULL;
    }
    $value = $node->get('field_repo_url')->first()->getValue();
    $uri = $value['uri'] ?? NULL;
    return ($uri !== NULL && $uri !== '') ? $uri : NULL;
  }

  /**
   * Parse a GitHub URL into "owner/repo" format.
   *
   * @param string $url
   *   A GitHub repo URL (e.g. "https://github.com/OSC/bc_osc_jupyter").
   *
   * @return string|null
   *   "owner/repo" or NULL if the URL doesn't match.
   */
  protected function parseOwnerRepo(string $url): ?string {
    $parsed = parse_url($url);
    if (!isset($parsed['host']) || $parsed['host'] !== 'github.com') {
      return NULL;
    }
    $parts = explode('/', trim($parsed['path'] ?? '', '/'));
    if (count($parts) < 2) {
      return NULL;
    }
    $owner = $parts[0];
    $repo = preg_replace('/\.git$/', '', $parts[1]);
    // Enforce GitHub's naming rules: alphanumeric, hyphens, dots, underscores.
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $owner) || !preg_match('/^[A-Za-z0-9._-]+$/', $repo)) {
      return NULL;
    }
    return $owner . '/' . $repo;
  }

  /**
   * Retrieve the GitHub token from the Key module.
   *
   * Tries the dedicated review key first, falls back to the shared key.
   */
  protected function getToken(): ?string {
    $key = $this->keyRepository->getKey(self::GITHUB_KEY_ID);
    if ($key) {
      $value = $key->getKeyValue();
      if ($value !== NULL && $value !== '') {
        return $value;
      }
    }

    $key = $this->keyRepository->getKey(self::GITHUB_KEY_FALLBACK);
    if ($key) {
      $value = $key->getKeyValue();
      if ($value !== NULL && $value !== '') {
        return $value;
      }
    }

    return NULL;
  }

}
