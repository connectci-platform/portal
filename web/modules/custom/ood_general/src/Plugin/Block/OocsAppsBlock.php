<?php

namespace Drupal\ood_general\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Drupal\ood_general\OocsAppsResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the OOCS Apps Used block.
 *
 * Renders, on open_ondemand_classroom_stories nodes, the items referenced by
 * field_oocs_apps_used as a row of software logos, each deep-linking into the
 * decoupled Appverse SPA. Returns nothing when not on a story node page.
 *
 * @Block(
 *   id = "oocs_apps_block",
 *   admin_label = @Translation("OOCS Apps Used"),
 *   category = @Translation("Custom"),
 * )
 */
class OocsAppsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The OOCS apps resolver.
   *
   * @var \Drupal\ood_general\OocsAppsResolver
   */
  protected $appsResolver;

  /**
   * Constructs a new OocsAppsBlock object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteMatchInterface $route_match, OocsAppsResolver $apps_resolver) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->routeMatch = $route_match;
    $this->appsResolver = $apps_resolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
      $container->get('ood_general.oocs_apps_resolver')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $node = $this->routeMatch->getParameter('node');

    // Guard: only render on story node pages. Protects the Layout Builder /
    // layout page, which would otherwise render the block out of context.
    if (!$node instanceof NodeInterface || $node->bundle() !== 'open_ondemand_classroom_stories') {
      return [];
    }

    if (!$node->hasField('field_oocs_apps_used') || $node->get('field_oocs_apps_used')->isEmpty()) {
      return [];
    }

    // Story node tag invalidates the block when its references change.
    $cache_tags = $node->getCacheTags();

    $apps = $this->appsResolver->buildItems($node->get('field_oocs_apps_used')->referencedEntities(), $cache_tags);
    if (!$apps) {
      return [];
    }

    return [
      '#theme' => 'oocs_apps_block',
      '#apps' => $apps,
      '#cache' => [
        'tags' => $cache_tags,
        'contexts' => ['route'],
      ],
    ];
  }

}
