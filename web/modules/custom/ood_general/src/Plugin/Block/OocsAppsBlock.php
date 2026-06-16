<?php

namespace Drupal\ood_general\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
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
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * Constructs a new OocsAppsBlock object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, RouteMatchInterface $route_match, FileUrlGeneratorInterface $file_url_generator) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->routeMatch = $route_match;
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
      $container->get('current_route_match'),
      $container->get('file_url_generator')
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

    $apps = [];
    foreach ($node->get('field_oocs_apps_used')->referencedEntities() as $entity) {
      $item = $this->buildItem($entity, $cache_tags);
      if ($item) {
        $apps[] = $item;
      }
    }

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

  /**
   * Builds a single rendered app/software item.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The referenced appverse_app or appverse_software node.
   * @param array $cache_tags
   *   Running list of cache tags, merged into by reference for every entity
   *   touched (app, software, media, file).
   *
   * @return array|null
   *   ['logo_url' => ..., 'alt' => ..., 'link' => ...], or NULL to skip.
   */
  private function buildItem(NodeInterface $entity, array &$cache_tags) {
    $bundle = $entity->bundle();

    if ($bundle === 'appverse_software') {
      $software = $entity;
      $alt = $entity->getTitle();
      $link = '/appverse#/' . $this->slugify($software->getTitle());
    }
    elseif ($bundle === 'appverse_app') {
      // The app's logo and route segment come from its related software.
      $software = $entity->hasField('field_appverse_software_implemen')
        ? $entity->get('field_appverse_software_implemen')->entity
        : NULL;
      if (!$software) {
        return NULL;
      }
      $cache_tags = Cache::mergeTags($cache_tags, $software->getCacheTags());

      $alt = $entity->getTitle();

      // Organization term name (may be empty).
      $org = '';
      if ($entity->hasField('field_appverse_organization') && !$entity->get('field_appverse_organization')->isEmpty()) {
        $term = $entity->get('field_appverse_organization')->entity;
        if ($term) {
          $org = $term->label();
          $cache_tags = Cache::mergeTags($cache_tags, $term->getCacheTags());
        }
      }

      $app_param = $org
        ? $this->slugify($org) . '--' . $this->slugify($entity->getTitle())
        : $this->slugify($entity->getTitle());
      $link = '/appverse#/' . $this->slugify($software->getTitle()) . '?app=' . $app_param;
    }
    else {
      return NULL;
    }

    $logo_url = $this->getLogoUrl($software, $cache_tags);
    if (!$logo_url) {
      return NULL;
    }

    return [
      'logo_url' => $logo_url,
      'alt' => $alt,
      'link' => $link,
    ];
  }

  /**
   * Resolves a software node's logo URL, accumulating cache tags.
   *
   * Mirrors AppverseCacheService::getLogoUrl(); guards every hop and returns
   * a root-relative URL.
   *
   * @param \Drupal\node\NodeInterface $software
   *   The appverse_software node.
   * @param array $cache_tags
   *   Running list of cache tags, merged into by reference for the media and
   *   file entities.
   *
   * @return string|null
   *   The root-relative logo URL, or NULL if it cannot be produced.
   */
  private function getLogoUrl(NodeInterface $software, array &$cache_tags) {
    if (!$software->hasField('field_appverse_logo') || $software->get('field_appverse_logo')->isEmpty()) {
      return NULL;
    }
    $media = $software->get('field_appverse_logo')->entity;
    if (!$media) {
      return NULL;
    }
    $cache_tags = Cache::mergeTags($cache_tags, $media->getCacheTags());

    $source_field = $media->getSource()->getConfiguration()['source_field'];
    if (!$source_field || !$media->hasField($source_field) || $media->get($source_field)->isEmpty()) {
      return NULL;
    }
    $file = $media->get($source_field)->entity;
    if (!$file) {
      return NULL;
    }
    $cache_tags = Cache::mergeTags($cache_tags, $file->getCacheTags());

    return $this->fileUrlGenerator->generateString($file->getFileUri());
  }

  /**
   * Slugifies a value to match the Appverse SPA's ji() function.
   *
   * Lowercases, strips punctuation (keeping word chars, whitespace, hyphens),
   * converts whitespace runs to single hyphens, collapses repeated hyphens and
   * trims leading/trailing hyphens. JS \w keeps underscores, so /u + \w here
   * does too.
   */
  private function slugify(string $value): string {
    $value = mb_strtolower(trim($value));
    $value = preg_replace('/[^\w\s-]/u', '', $value);
    $value = preg_replace('/\s+/u', '-', $value);
    $value = preg_replace('/-+/', '-', $value);
    return trim($value, '-');
  }

}
