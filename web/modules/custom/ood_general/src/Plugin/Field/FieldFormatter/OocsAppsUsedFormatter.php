<?php

namespace Drupal\ood_general\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\node\NodeInterface;
use Drupal\ood_general\OocsAppsResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders an "Apps Used" entity-reference field as a row of software logos.
 *
 * Used by the classroom-story listing and slideshow views: the field is added
 * as a real Views field with this formatter and referenced as a rewrite token,
 * replacing the earlier "OOCS_APPS_USED:" marker string that was re-parsed by
 * regex in a preprocess hook. Shares OocsAppsResolver with OocsAppsBlock so
 * both surfaces render the same logo row, including the access filtering that
 * hides unpublished apps.
 *
 * @FieldFormatter(
 *   id = "oocs_apps_used",
 *   label = @Translation("OOCS Apps Used logo row"),
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 */
class OocsAppsUsedFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  /**
   * The OOCS apps resolver.
   *
   * @var \Drupal\ood_general\OocsAppsResolver
   */
  protected $appsResolver;

  /**
   * {@inheritdoc}
   */
  public function __construct($plugin_id, $plugin_definition, $field_definition, array $settings, $label, $view_mode, array $third_party_settings, OocsAppsResolver $apps_resolver) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
    $this->appsResolver = $apps_resolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('ood_general.oocs_apps_resolver')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $nodes = [];
    foreach ($items->referencedEntities() as $entity) {
      if ($entity instanceof NodeInterface) {
        $nodes[] = $entity;
      }
    }

    $cache_tags = [];
    $apps = $this->appsResolver->buildItems($nodes, $cache_tags);
    if (!$apps) {
      return [];
    }

    // Single element for the whole field: one logo row.
    return [
      [
        '#theme' => 'oocs_apps_block',
        '#apps' => $apps,
        '#cache' => [
          'tags' => $cache_tags,
          // The resolver filters by view access, so vary on node grants.
          'contexts' => ['user.node_grants:view'],
        ],
      ],
    ];
  }

}
