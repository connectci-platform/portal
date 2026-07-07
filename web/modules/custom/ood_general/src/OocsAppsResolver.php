<?php

namespace Drupal\ood_general;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Service\AppverseLogoUrl;

/**
 * Resolves appverse_app / appverse_software nodes into "Apps Used" logo items.
 *
 * Shared by OocsAppsBlock (story node pages) and OocsAppsUsedFormatter (the
 * classroom-story listing/slideshow views) so both render the same logo row
 * from the same traversal: app -> related software -> logo media -> file URL,
 * with a deep link into the decoupled Appverse SPA.
 */
class OocsAppsResolver {

  /**
   * The shared appverse logo URL resolver.
   *
   * @var \Drupal\ood_software\Service\AppverseLogoUrl
   */
  protected $logoUrl;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a new OocsAppsResolver object.
   */
  public function __construct(AppverseLogoUrl $logo_url, AccountInterface $current_user) {
    $this->logoUrl = $logo_url;
    $this->currentUser = $current_user;
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
      // Never disclose apps/software the current user cannot view (e.g. drafts
      // referenced by a story). Callers add the matching
      // 'user.node_grants:view' cache context.
      if (!$node->access('view', $this->currentUser)) {
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
      $link = '/appverse#/' . $this->softwareSlug($software);
    }
    elseif ($bundle === 'appverse_app') {
      // The app's logo and route segment come from its related software.
      $software = $entity->hasField('field_appverse_software_implemen')
        ? $entity->get('field_appverse_software_implemen')->entity
        : NULL;
      // Suppress the item when the related software is missing or not viewable
      // by the current user, matching the access gate applied to the app.
      if (!$software || !$software->access('view', $this->currentUser)) {
        return NULL;
      }
      $cache_tags = Cache::mergeTags($cache_tags, $software->getCacheTags());

      $alt = $entity->getTitle();

      // Organization term name (may be empty).
      $org = '';
      if ($entity->hasField('field_appverse_organization') && !$entity->get('field_appverse_organization')->isEmpty()) {
        $term = $entity->get('field_appverse_organization')->entity;
        if ($term && $term->access('view', $this->currentUser)) {
          $org = $term->label();
          $cache_tags = Cache::mergeTags($cache_tags, $term->getCacheTags());
        }
      }

      $app_param = $org
        ? $this->slugify($org) . '--' . $this->slugify($entity->getTitle())
        : $this->slugify($entity->getTitle());
      $link = '/appverse#/' . $this->softwareSlug($software) . '?app=' . $app_param;
    }
    else {
      return NULL;
    }

    $logo_url = $this->logoUrl->get($software, $cache_tags);
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
   * Resolves a software node's SPA route slug from its path alias.
   *
   * The Appverse feed (AppverseCacheService) keys each software item by
   * basename() of its entity URL, so the SPA routes on the pathauto alias.
   * Deriving the deep-link slug from the same source keeps logo links in step
   * with the feed instead of re-slugifying the title by hand, which can diverge
   * when an alias was deduplicated or transliterated differently.
   *
   * @param \Drupal\node\NodeInterface $software
   *   The appverse_software node.
   *
   * @return string
   *   The route slug (the last segment of the node's URL).
   */
  public function softwareSlug(NodeInterface $software): string {
    return basename($software->toUrl()->toString());
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
