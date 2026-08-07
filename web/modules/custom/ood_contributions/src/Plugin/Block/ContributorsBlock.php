<?php

namespace Drupal\ood_contributions\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Code & Documentations Contributors' Block.
 *
 * @Block(
 *   id = "contributors_block",
 *   admin_label = @Translation("Code & Documentations Contributors"),
 *   category = @Translation("Custom"),
 * )
 */
class ContributorsBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
   * Constructs a new ContributorsBlock object.
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
  /**
   * @param array<string, mixed> $configuration
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
  /**
   * @param array<string, mixed> $configuration
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    // new static() is safe here: block plugins are only instantiated via the
    // plugin manager, never subclassed with an incompatible constructor.
    // @phpstan-ignore-next-line new.static
    return new static(
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
   *
   * @return array<string, mixed>
   */
  public function build() {
    $contributors = $this->getContributors();

    return [
      '#theme' => 'contributors_block',
      '#items' => $contributors,
      '#cache' => [
        'max-age' => 3600,
      ],
    ];
  }

  /**
   * Get list of contributors from the database.
   *
   * @return array<int, array<string, mixed>>
   *   Array of contributor data.
   */
  protected function getContributors() {
    // Order contributors by their most recent commit (newest first) so the
    // most-recently-active people lead the grid. Group by uid and sort on
    // MAX(commit_date); loadMultiple() ignores the id order it's given, so we
    // re-apply the query order when building the list below.
    $query = $this->database->select('ood_user_commits', 'ouc');
    $query->addField('ouc', 'uid');
    $query->addExpression('MAX(ouc.commit_date)', 'last_commit');
    $query->condition('ouc.uid', 0, '>');
    $query->groupBy('ouc.uid');
    $query->orderBy('last_commit', 'DESC');
    $uids = $query->execute()->fetchCol();

    if (empty($uids)) {
      return [];
    }

    $users = $this->entityTypeManager->getStorage('user')->loadMultiple($uids);
    $contributors = [];

    // Iterate the ordered uids (not $users) to preserve the most-recent sort.
    foreach ($uids as $uid) {
      if (empty($users[$uid])) {
        continue;
      }
      $user = $users[$uid];
      $photo_url = NULL;

      if ($user->hasField('user_picture') && !$user->get('user_picture')->isEmpty()) {
        $file = $user->get('user_picture')->entity;
        if ($file instanceof \Drupal\file\FileInterface) {
          $photo_url = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
        }
      }

      $contributors[] = [
        'uid' => $user->id(),
        'name' => $user->getDisplayName(),
        'photo_url' => $photo_url,
      ];
    }

    return $contributors;
  }

}
