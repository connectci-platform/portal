<?php

namespace Drupal\ood_software\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an 'Affinity Group Coordinators' Block.
 *
 * @Block(
 *   id = "ag_coordinators_block",
 *   admin_label = @Translation("Affinity Group Coordinators"),
 *   category = @Translation("Custom"),
 * )
 */
class AffinityGroupCoordinatorsBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
   * Constructs a new AffinityGroupCoordinatorsBlock object.
   *
   * @param array $configuration
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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('file_url_generator')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $coordinators = $this->getCoordinators();

    return [
      '#theme' => 'ag_coordinators_block',
      '#items' => $coordinators,
      '#shuffle' => TRUE,
      '#cache' => [
        'max-age' => 3600,
      ],
    ];
  }

  /**
   * Get unique coordinators from affinity group nodes on the OOD domain.
   *
   * @return array
   *   Array of coordinator data with uid, name, and photo_url.
   */
  protected function getCoordinators() {
    $coordinators = [];
    $seen_uids = [];

    // Query affinity_group nodes with domain access for openondemand.
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'affinity_group')
      ->condition('status', 1)
      ->condition('field_domain_access', 'openondemand_cyberinfrastructure_org')
      ->accessCheck(FALSE)
      ->execute();

    if (empty($nids)) {
      return $coordinators;
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

    foreach ($nodes as $node) {
      if (!$node->hasField('field_coordinator') || $node->get('field_coordinator')->isEmpty()) {
        continue;
      }

      foreach ($node->get('field_coordinator')->getValue() as $value) {
        $uid = $value['target_id'];
        if (isset($seen_uids[$uid])) {
          continue;
        }
        $seen_uids[$uid] = TRUE;

        $user = $this->entityTypeManager->getStorage('user')->load($uid);
        $user_data = $this->getUserData($user);
        if ($user_data) {
          $coordinators[] = $user_data;
        }
      }
    }

    return $coordinators;
  }

  /**
   * Get user data including photo URL.
   *
   * @param \Drupal\user\UserInterface|null $user
   *   The user entity.
   *
   * @return array|null
   *   Array of user data or NULL if user is anonymous/invalid.
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
