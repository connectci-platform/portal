<?php

namespace Drupal\ood_software\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'The Appverse Contributors & Moderators' Block.
 *
 * @Block(
 *   id = "appverse_contributors_block",
 *   admin_label = @Translation("The Appverse Contributors & Moderators"),
 *   category = @Translation("Custom"),
 * )
 */
class AppverseContributorsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * Constructs a new AppverseContributorsBlock object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Connection $database, EntityTypeManagerInterface $entity_type_manager, FileUrlGeneratorInterface $file_url_generator) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileUrlGenerator = $file_url_generator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('file_url_generator')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $contributors = $this->getContributors();

    return [
      '#theme' => 'appverse_contributors_block',
      '#items' => $contributors,
      '#cache' => [
        'tags' => ['node_list:appverse_app', 'user_list'],
        'max-age' => 3600,
      ],
    ];
  }

  /**
   * Get list of contributors, most recent app first.
   *
   * The appverse_pm role group is pinned first, in recency order of each PM's
   * most recent app (PMs with no apps last, by uid). Everyone else follows,
   * also by most recent app.
   *
   * @return array<int, array<string, mixed>>
   *   Array of contributor data.
   */
  protected function getContributors() {
    $latest = $this->getLatestAppByAuthor();

    $pm_users = $this->getUsersByRole('appverse_pm');
    // Order PMs: those with apps by recency (the query order), then the rest
    // by uid.
    $pm_ordered = [];
    foreach (array_keys($latest) as $uid) {
      if (isset($pm_users[$uid])) {
        $pm_ordered[] = $uid;
      }
    }
    $pm_without_apps = array_diff(array_keys($pm_users), $pm_ordered);
    sort($pm_without_apps);
    $pm_ordered = array_merge($pm_ordered, $pm_without_apps);

    $other_uids = array_diff(array_keys($latest), $pm_ordered);

    $user_storage = $this->entityTypeManager->getStorage('user');
    $others = $user_storage->loadMultiple($other_uids);

    $contributors = [];
    foreach ($pm_ordered as $uid) {
      $data = $this->getUserData($pm_users[$uid]);
      if ($data) {
        $contributors[] = $data;
      }
    }
    // Iterate the ordered uids: loadMultiple() ignores the order it's given.
    foreach ($other_uids as $uid) {
      if (empty($others[$uid])) {
        continue;
      }
      $data = $this->getUserData($others[$uid]);
      if ($data) {
        $contributors[] = $data;
      }
    }

    return $contributors;
  }

  /**
   * Get users by role.
   *
   * @param string $role_id
   *   The role ID.
   *
   * @return \Drupal\user\UserInterface[]
   *   Array of user entities.
   */
  protected function getUsersByRole($role_id) {
    $query = $this->entityTypeManager->getStorage('user')->getQuery()
      ->condition('status', 1)
      ->condition('roles', $role_id)
      ->accessCheck(FALSE);
    $uids = $query->execute();

    if (empty($uids)) {
      return [];
    }

    return $this->entityTypeManager->getStorage('user')->loadMultiple($uids);
  }

  /**
   * Get published appverse_app authors by their most recent app.
   *
   * @return array<int, int>
   *   Most recent app created timestamp keyed by author uid, newest first.
   */
  protected function getLatestAppByAuthor(): array {
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
   * Get user data including photo URL.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   *
   * @return array|null
   *   Array of user data or NULL if user is anonymous.
   */
  protected function getUserData($user) {
    if (!$user || $user->isAnonymous()) {
      return NULL;
    }

    $photo_url = NULL;

    if ($user->hasField('user_picture') && !$user->get('user_picture')->isEmpty()) {
      $file = $user->get('user_picture')->entity;
      if ($file) {
        $photo_url = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
      }
    }

    return [
      'uid' => $user->id(),
      'name' => $user->getDisplayName(),
      'photo_url' => $photo_url,
    ];
  }

}
