<?php

namespace Drupal\ood_contributions\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for fetching Discourse contributions.
 */
class DiscourseContributionService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The Discourse API service.
   *
   * @var \Drupal\ood_contributions\Service\DiscourseApiService
   */
  protected $discourseApi;

  /**
   * The Discourse storage service.
   *
   * @var \Drupal\ood_contributions\Service\DiscourseStorageService
   */
  protected $discourseStorage;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a DiscourseContributionService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\ood_contributions\Service\DiscourseApiService $discourse_api
   *   The Discourse API service.
   * @param \Drupal\ood_contributions\Service\DiscourseStorageService $discourse_storage
   *   The Discourse storage service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, DiscourseApiService $discourse_api, DiscourseStorageService $discourse_storage, LoggerChannelFactoryInterface $logger_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->discourseApi = $discourse_api;
    $this->discourseStorage = $discourse_storage;
    $this->logger = $logger_factory->get('ood_contributions');
  }

  /**
   * Fetches and stores Discourse contributions for a user.
   *
   * @param int $uid
   *   The Drupal user ID.
   *
   * @return array
   *   An array containing success status and any messages.
   */
  public function updateUserContributions(int $uid): array {
    try {
      // Load the user.
      $user_storage = $this->entityTypeManager->getStorage('user');
      $user = $user_storage->load($uid);

      if (!$user) {
        return [
          'success' => FALSE,
          'message' => 'User not found.',
        ];
      }

      // Get the Discourse username from the user field.
      if (!$user->hasField('field_discourse_openondemand_org') || $user->get('field_discourse_openondemand_org')->isEmpty()) {
        return [
          'success' => FALSE,
          'message' => 'User does not have a Discourse username configured.',
        ];
      }

      $discourse_username = $user->get('field_discourse_openondemand_org')->value;

      // Fetch user data from Discourse API.
      $result = $this->discourseApi->getUserByUsername($discourse_username);

      if ($result['error']) {
        return [
          'success' => FALSE,
          'message' => 'Failed to fetch Discourse data: ' . $result['message'],
        ];
      }

      $user_data = $result['data'];

      // The counts live only in /u/{name}/summary.json, not /u/{name}.json.
      // A failed summary fetch must not store zeros over real numbers.
      $summary = $this->discourseApi->getUserSummary($discourse_username);

      if ($summary['error']) {
        return [
          'success' => FALSE,
          'message' => 'Failed to fetch Discourse summary: ' . $summary['message'],
        ];
      }

      // Extract contribution data.
      $contribution_data = $this->extractContributionData($user_data, $summary['data']);

      // Store the data using the storage service.
      $stored = $this->discourseStorage->storeContributions($uid, $contribution_data);

      if (!$stored) {
        return [
          'success' => FALSE,
          'message' => 'Failed to store Discourse contributions.',
        ];
      }

      return [
        'success' => TRUE,
        'message' => 'Discourse contributions updated successfully.',
        'data' => $contribution_data,
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to update Discourse contributions for user @uid: @message', [
        '@uid' => $uid,
        '@message' => $e->getMessage(),
      ]);

      return [
        'success' => FALSE,
        'message' => 'An error occurred: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * Extracts contribution data from Discourse API response.
   *
   * @param array $user_data
   *   The /u/{name}.json response (badges and the user's last_posted_at).
   * @param array $summary_data
   *   The /u/{name}/summary.json response (the user_summary counts).
   *
   * @return array
   *   The extracted contribution data.
   */
  protected function extractContributionData(array $user_data, array $summary_data = []): array {
    $post_count = $summary_data['user_summary']['post_count'] ?? 0;
    $topic_count = $summary_data['user_summary']['topic_count'] ?? 0;
    $likes_given = $summary_data['user_summary']['likes_given'] ?? 0;
    $likes_received = $summary_data['user_summary']['likes_received'] ?? 0;
    $days_visited = $summary_data['user_summary']['days_visited'] ?? 0;
    $time_read = $summary_data['user_summary']['time_read'] ?? 0;
    $solved_count = $summary_data['user_summary']['solved_count'] ?? 0;

    // Build badges string.
    $badges = '';
    if (!empty($user_data['badges']) && is_array($user_data['badges'])) {
      $badge_names = [];
      foreach ($user_data['badges'] as $badge) {
        if (!empty($badge['name'])) {
          $badge_names[] = $badge['name'];
        }
      }
      $badges = implode(', ', $badge_names);
    }

    // ISO 8601 string to a timestamp; NULL if missing or unparseable.
    $last_posted_at = NULL;
    if (!empty($user_data['user']['last_posted_at']) && is_string($user_data['user']['last_posted_at'])) {
      $parsed = strtotime($user_data['user']['last_posted_at']);
      $last_posted_at = $parsed === FALSE ? NULL : $parsed;
    }

    return [
      'last_posted_at' => $last_posted_at,
      'post_count' => $post_count,
      'topic_count' => $topic_count,
      'likes_given' => $likes_given,
      'likes_received' => $likes_received,
      'days_visited' => $days_visited,
      'time_read' => $time_read,
      'badges' => $badges,
      'solutions' => $solved_count,
    ];
  }

  /**
   * Gets Discourse contributions for a user.
   *
   * @param int $uid
   *   The Drupal user ID.
   *
   * @return array|null
   *   The contribution data or NULL if not found.
   */
  public function getUserContributions(int $uid): ?array {
    return $this->discourseStorage->getContributions($uid);
  }

}
