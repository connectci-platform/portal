<?php

namespace Drupal\ood_contributions\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for storing Discourse contributions in the database.
 */
class DiscourseStorageService {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a DiscourseStorageService object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(Connection $database, LoggerChannelFactoryInterface $logger_factory) {
    $this->database = $database;
    $this->logger = $logger_factory->get('ood_contributions');
  }

  /**
   * Stores Discourse contributions for a user.
   *
   * @param int $uid
   *   The Drupal user ID.
   * @param array $data
   *   The contribution data to store.
   *
   * @return bool
   *   TRUE if stored successfully, FALSE otherwise.
   */
  public function storeContributions(int $uid, array $data): bool {
    try {
      // Check if a record already exists.
      $existing = $this->database->select('ood_disc_contrib', 'odc')
        ->fields('odc', ['id'])
        ->condition('uid', $uid)
        ->execute()
        ->fetchField();

      if ($existing) {
        // Update existing record.
        $this->database->update('ood_disc_contrib')
          ->fields([
            'post_count' => $data['post_count'] ?? 0,
            'topic_count' => $data['topic_count'] ?? 0,
            'likes_given' => $data['likes_given'] ?? 0,
            'likes_received' => $data['likes_received'] ?? 0,
            'days_visited' => $data['days_visited'] ?? 0,
            'time_read' => $data['time_read'] ?? 0,
            'badges' => $data['badges'] ?? '',
            'solved_count' => $data['solutions'] ?? 0,
            'last_posted_at' => $data['last_posted_at'] ?? NULL,
          ])
          ->condition('uid', $uid)
          ->execute();

        $this->logger->info('Updated Discourse contributions for user @uid', [
          '@uid' => $uid,
        ]);
      }
      else {
        // Insert new record.
        $this->database->insert('ood_disc_contrib')
          ->fields([
            'uid' => $uid,
            'post_count' => $data['post_count'] ?? 0,
            'topic_count' => $data['topic_count'] ?? 0,
            'likes_given' => $data['likes_given'] ?? 0,
            'likes_received' => $data['likes_received'] ?? 0,
            'days_visited' => $data['days_visited'] ?? 0,
            'time_read' => $data['time_read'] ?? 0,
            'badges' => $data['badges'] ?? '',
            'solved_count' => $data['solutions'] ?? 0,
            'last_posted_at' => $data['last_posted_at'] ?? NULL,
          ])
          ->execute();

        $this->logger->info('Stored new Discourse contributions for user @uid', [
          '@uid' => $uid,
        ]);
      }

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to store Discourse contributions for user @uid: @message', [
        '@uid' => $uid,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
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
  public function getContributions(int $uid): ?array {
    $result = $this->database->select('ood_disc_contrib', 'odc')
      ->fields('odc')
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();

    return $result ?: NULL;
  }

  /**
   * Deletes Discourse contributions for a user.
   *
   * @param int $uid
   *   The Drupal user ID.
   *
   * @return bool
   *   TRUE if deleted successfully, FALSE otherwise.
   */
  public function deleteContributions(int $uid): bool {
    try {
      $this->database->delete('ood_disc_contrib')
        ->condition('uid', $uid)
        ->execute();

      $this->logger->info('Deleted Discourse contributions for user @uid', [
        '@uid' => $uid,
      ]);

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to delete Discourse contributions for user @uid: @message', [
        '@uid' => $uid,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Gets all users with Discourse contributions.
   *
   * @return array
   *   Array of user IDs.
   */
  public function getAllUserIds(): array {
    return $this->database->select('ood_disc_contrib', 'odc')
      ->fields('odc', ['uid'])
      ->execute()
      ->fetchCol();
  }

}
