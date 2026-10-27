<?php

namespace Drupal\ood_software\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\file\FileInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for people-grid blocks driven by an Open OnDemand badge.
 *
 * Lists active users flagged with a term of the open_ondemand_badges
 * vocabulary (via field_open_ondemand_badges on the user). Subclasses only
 * declare the badge name and the theme hook to render with.
 */
abstract class BadgePeopleBlockBase extends BlockBase implements ContainerFactoryPluginInterface {

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
   * Constructs a new badge people block.
   *
   * @param array<string, mixed> $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, FileUrlGeneratorInterface $file_url_generator) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->fileUrlGenerator = $file_url_generator;
  }

  /**
   * Creates the block plugin.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   *
   * @return static
   *   The block plugin.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    // Block plugins are only instantiated via the plugin manager.
    // @phpstan-ignore-next-line new.static
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('file_url_generator')
    );
  }

  /**
   * The name of the badge term in open_ondemand_badges.
   */
  abstract protected function badgeName(): string;

  /**
   * The theme hook used to render the people grid.
   */
  abstract protected function themeHook(): string;

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The render array.
   */
  public function build() {
    return [
      '#theme' => $this->themeHook(),
      '#items' => $this->getBadgedUsers(),
      '#shuffle' => TRUE,
      '#cache' => [
        // taxonomy_term_list so the block appears once the term is created.
        'tags' => ['user_list', 'taxonomy_term_list'],
        'max-age' => 3600,
      ],
    ];
  }

  /**
   * Gets the active users flagged with this block's badge.
   *
   * @return array<int, array<string, mixed>>
   *   Array of user data with uid, name, and photo_url.
   */
  protected function getBadgedUsers(): array {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties([
        'name' => $this->badgeName(),
        'vid' => 'open_ondemand_badges',
      ]);
    $term = reset($terms);
    if (!$term) {
      return [];
    }

    $user_storage = $this->entityTypeManager->getStorage('user');
    $uids = $user_storage->getQuery()
      ->condition('status', 1)
      ->condition('field_open_ondemand_badges', $term->id())
      ->accessCheck(FALSE)
      ->execute();
    if (empty($uids)) {
      return [];
    }

    $items = [];
    foreach ($user_storage->loadMultiple($uids) as $user) {
      $user_data = $this->getUserData($user);
      if ($user_data) {
        $items[] = $user_data;
      }
    }
    return $items;
  }

  /**
   * Gets user data including the photo URL.
   *
   * @param \Drupal\user\UserInterface|null $user
   *   The user entity.
   *
   * @return array<string, mixed>|null
   *   Array of user data or NULL if user is anonymous/invalid.
   */
  protected function getUserData($user) {
    if (!$user instanceof UserInterface || $user->isAnonymous()) {
      return NULL;
    }

    $photo_url = NULL;
    if ($user->hasField('user_picture') && !$user->get('user_picture')->isEmpty()) {
      $file = $user->get('user_picture')->entity;
      if ($file instanceof FileInterface) {
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
