<?php

namespace Drupal\ood_software\Service;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * One monorepo app's Appverse metadata, as the sync and the preview read it.
 *
 * The root appverse.yml's apps[] entry is merged over the app's own
 * <path>/appverse.yml, the entry winning, so the Add Repo preview shows what
 * the sync will catalog.
 */
final class AppverseLayer {

  /**
   * Merges an app's own appverse.yml with its root apps[] entry.
   *
   * The app's own file may give its title as `title:`, as a single-app root
   * appverse.yml may. A root entry's `name` that only repeats the app's
   * folder identifies the entry rather than naming the app, so it gives way
   * when the app's own file or manifest.yml has a title (D8-3138: EESSI lists
   * `name: bc_eessi_bokeh` with the same path); with nothing else to name the
   * app, it stays the title.
   *
   * @param array<string, mixed> $perSubpath
   *   The parsed <path>/appverse.yml, or [].
   * @param array<string, mixed> $rootInline
   *   The root apps[] entry, or [].
   * @param string $subpath
   *   The app's repo-relative folder.
   * @param mixed $manifestName
   *   The `name` from the app's manifest.yml, if any.
   *
   * @return array<string, mixed>
   *   The merged layer, without the structural `path` key.
   */
  public static function merge(array $perSubpath, array $rootInline, string $subpath, mixed $manifestName = NULL): array {
    if (isset($perSubpath['title']) && !isset($perSubpath['name'])) {
      $perSubpath['name'] = $perSubpath['title'];
    }
    unset($perSubpath['title']);
    if (self::isFolderName($rootInline['name'] ?? NULL, $subpath)
      && (self::isTitle($perSubpath['name'] ?? NULL) || self::isTitle($manifestName))) {
      unset($rootInline['name']);
    }
    $layer = array_replace($perSubpath, $rootInline);
    unset($layer['path']);
    return $layer;
  }

  /**
   * The `name` from a manifest.yml's text, or NULL.
   */
  public static function manifestName(?string $yaml): mixed {
    if ($yaml === NULL || $yaml === '') {
      return NULL;
    }
    try {
      $parsed = Yaml::parse($yaml);
    }
    catch (ParseException $e) {
      return NULL;
    }
    return is_array($parsed) ? ($parsed['name'] ?? NULL) : NULL;
  }

  /**
   * Whether a name only repeats the app's folder, or its last segment.
   */
  public static function isFolderName(mixed $name, string $subpath): bool {
    if (!self::isTitle($name)) {
      return FALSE;
    }
    $name = trim((string) $name, ' /');
    $path = trim($subpath, ' /');
    return $path !== '' && ($name === $path || $name === basename($path));
  }

  /**
   * Whether a value can be a title: a non-empty string or number.
   */
  protected static function isTitle(mixed $value): bool {
    return is_scalar($value) && !is_bool($value) && trim((string) $value) !== '';
  }

}
