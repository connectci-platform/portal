<?php

namespace Drupal\ood_software\Service;

use Drupal\Core\Cache\Cache;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\node\NodeInterface;

/**
 * Resolves an appverse_software node's logo to a root-relative URL.
 *
 * Single source of truth for the app -> logo media -> file URL traversal,
 * shared by AppverseCacheService (static JSON feed) and ood_general's
 * OocsAppsResolver (rendered "Apps Used" logo rows). Guards every hop and
 * returns a root-relative URL so no host leaks into cached / multi-domain
 * output.
 */
class AppverseLogoUrl {

  /**
   * Constructs a new AppverseLogoUrl object.
   */
  public function __construct(
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Resolves a software node's logo URL, optionally accumulating cache tags.
   *
   * @param \Drupal\node\NodeInterface $software
   *   The appverse_software node.
   * @param array|null $cache_tags
   *   When passed by reference, the media and file cache tags are merged into
   *   it. Pass NULL for callers that do not build render cacheability (e.g. the
   *   static JSON feed).
   *
   * @return string|null
   *   The root-relative logo URL, or NULL if it cannot be produced.
   */
  public function get(NodeInterface $software, ?array &$cache_tags = NULL): ?string {
    if (!$software->hasField('field_appverse_logo') || $software->get('field_appverse_logo')->isEmpty()) {
      return NULL;
    }
    $media = $software->get('field_appverse_logo')->entity;
    if (!$media) {
      return NULL;
    }
    if ($cache_tags !== NULL) {
      $cache_tags = Cache::mergeTags($cache_tags, $media->getCacheTags());
    }

    $source_field = $media->getSource()->getConfiguration()['source_field'];
    if (!$source_field || !$media->hasField($source_field) || $media->get($source_field)->isEmpty()) {
      return NULL;
    }
    $file = $media->get($source_field)->entity;
    if (!$file) {
      return NULL;
    }
    if ($cache_tags !== NULL) {
      $cache_tags = Cache::mergeTags($cache_tags, $file->getCacheTags());
    }

    return $this->fileUrlGenerator->generateString($file->getFileUri());
  }

}
