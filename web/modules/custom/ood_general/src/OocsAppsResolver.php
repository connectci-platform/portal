<?php

namespace Drupal\ood_general;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\node\NodeInterface;

/**
 * Resolves appverse_app / appverse_software nodes into "Apps Used" logo items.
 *
 * Shared by OocsAppsBlock (story node pages) and the views field preprocess
 * hook (slideshow "nothing" field) so both render the same logo row from the
 * same traversal: app -> related software -> logo media -> file URL, with a
 * deep link into the decoupled Appverse SPA.
 */
class OocsAppsResolver {

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
   * Constructs a new OocsAppsResolver object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, FileUrlGeneratorInterface $file_url_generator) {
    $this->entityTypeManager = $entity_type_manager;
    $this->fileUrlGenerator = $file_url_generator;
  }

  /**
   * Loads node IDs and builds their rendered app items, skipping any that fail.
   *
   * @param array $nids
   *   App/software node IDs.
   * @param array $cache_tags
   *   Running list of cache tags, merged into by reference for every entity
   *   touched (app, software, media, file).
   *
   * @return array
   *   A list of ['logo_url' => ..., 'alt' => ..., 'link' => ...] structs.
   */
  public function buildItemsFromIds(array $nids, array &$cache_tags): array {
    $nodes = $nids
      ? $this->entityTypeManager->getStorage('node')->loadMultiple($nids)
      : [];
    return $this->buildItems($nodes, $cache_tags);
  }

  /**
   * Builds rendered app items from already-loaded nodes, skipping any failures.
   *
   * @param \Drupal\node\NodeInterface[] $nodes
   *   App/software nodes, keyed however the caller likes.
   * @param array $cache_tags
   *   Running list of cache tags, merged into by reference.
   *
   * @return array
   *   A list of ['logo_url' => ..., 'alt' => ..., 'link' => ...] structs.
   */
  public function buildItems(array $nodes, array &$cache_tags): array {
    $apps = [];
    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $cache_tags = Cache::mergeTags($cache_tags, $node->getCacheTags());
      $item = $this->buildItem($node, $cache_tags);
      if ($item) {
        $apps[] = $item;
      }
    }
    return $apps;
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
  public function buildItem(NodeInterface $entity, array &$cache_tags) {
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
  public function getLogoUrl(NodeInterface $software, array &$cache_tags) {
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
  public function slugify(string $value): string {
    $value = mb_strtolower(trim($value));
    $value = preg_replace('/[^\w\s-]/u', '', $value);
    $value = preg_replace('/\s+/u', '-', $value);
    $value = preg_replace('/-+/', '-', $value);
    return trim($value, '-');
  }

}
