<?php

declare(strict_types=1);

namespace Mih\ComposerPins;

/**
 * Detects custom-module version pins that differ between two composer.json.
 *
 * Dependency-free so it can run in CI without installing vendor packages.
 */
final class PinChecker {

  /**
   * Gets the custom packages of a composer.json.
   *
   * Custom packages are require and require-dev entries whose name matches a
   * repositories entry of type git or vcs. A repositories entry matches when
   * its key equals the full package name or the part after the slash.
   *
   * @param array<mixed> $composer
   *   Decoded composer.json.
   *
   * @return array<string, string>
   *   Package name => version constraint, sorted by package.
   */
  public static function customPackages(array $composer): array {
    $repos = self::vcsRepositories($composer);
    $packages = [];
    foreach (['require', 'require-dev'] as $section) {
      $entries = $composer[$section] ?? [];
      if (!is_array($entries)) {
        continue;
      }
      foreach ($entries as $name => $version) {
        $name = (string) $name;
        if (!is_string($version) || self::matchingRepository($repos, $name) === NULL) {
          continue;
        }
        $packages[$name] = $version;
      }
    }
    ksort($packages);
    return $packages;
  }

  /**
   * Finds custom packages whose version differs between two composer.json.
   *
   * Packages present in only one of the files are not mismatches.
   *
   * @param array<mixed> $prComposer
   *   Decoded composer.json from the pull request.
   * @param array<mixed> $baseComposer
   *   Decoded composer.json from the base branch.
   *
   * @return list<array{package: string, current: string, expected: string}>
   *   Mismatches sorted by package.
   */
  public static function findMismatches(array $prComposer, array $baseComposer): array {
    $pr = self::customPackages($prComposer);
    $base = self::customPackages($baseComposer);
    $mismatches = [];
    foreach ($pr as $package => $version) {
      if (isset($base[$package]) && $base[$package] !== $version) {
        $mismatches[] = [
          'package' => $package,
          'current' => $version,
          'expected' => $base[$package],
        ];
      }
    }
    usort($mismatches, static fn(array $a, array $b): int => strcmp($a['package'], $b['package']));
    return $mismatches;
  }

  /**
   * Gets the branch name a composer version points at.
   *
   * @param string $version
   *   A composer version constraint.
   *
   * @return string
   *   The branch name, or an empty string for non-branch constraints.
   */
  public static function branchFromVersion(string $version): string {
    $version = trim($version);
    $hash = strpos($version, '#');
    if ($hash !== FALSE) {
      $version = substr($version, 0, $hash);
    }
    if (str_starts_with($version, 'dev-')) {
      return substr($version, 4);
    }
    if (str_ends_with($version, '-dev')) {
      return substr($version, 0, -4);
    }
    return '';
  }

  /**
   * Gets the GitHub "owner/repo" for a custom package.
   *
   * @param array<mixed> $composer
   *   Decoded composer.json.
   * @param string $package
   *   Full package name.
   *
   * @return string|null
   *   The "owner/repo" string, or NULL when not a custom GitHub package.
   */
  public static function githubRepo(array $composer, string $package): ?string {
    $repo = self::matchingRepository(self::vcsRepositories($composer), $package);
    if ($repo === NULL) {
      return NULL;
    }
    $url = $repo['url'] ?? NULL;
    if (!is_string($url)) {
      return NULL;
    }
    if (preg_match('#^(?:https?://(?:[^@/]+@)?|git@|ssh://git@)github\.com[/:]([^/\s]+)/([^/\s]+?)(?:\.git)?/?$#i', trim($url), $m) !== 1) {
      return NULL;
    }
    return $m[1] . '/' . $m[2];
  }

  /**
   * Formats a mismatch as one human-readable line.
   *
   * @param array{package: string, current: string, expected: string} $mismatch
   *   A mismatch from findMismatches().
   * @param string $baseName
   *   Name of the base branch.
   *
   * @return string
   *   The message.
   */
  public static function formatMismatch(array $mismatch, string $baseName): string {
    return sprintf(
      '%s is "%s"; %s uses "%s".',
      $mismatch['package'],
      $mismatch['current'],
      $baseName,
      $mismatch['expected'],
    );
  }

  /**
   * Gets repositories of type git or vcs.
   *
   * @param array<mixed> $composer
   *   Decoded composer.json.
   *
   * @return array<string, array<mixed>>
   *   Repository definitions keyed by their string key.
   */
  private static function vcsRepositories(array $composer): array {
    $repos = [];
    $all = $composer['repositories'] ?? [];
    if (!is_array($all)) {
      return $repos;
    }
    foreach ($all as $key => $repo) {
      if (!is_string($key) || !is_array($repo)) {
        continue;
      }
      if (in_array($repo['type'] ?? NULL, ['git', 'vcs'], TRUE)) {
        $repos[$key] = $repo;
      }
    }
    return $repos;
  }

  /**
   * Finds the repository matching a package name.
   *
   * @param array<string, array<mixed>> $repos
   *   Repositories from vcsRepositories().
   * @param string $package
   *   Full package name.
   *
   * @return array<mixed>|null
   *   The repository definition or NULL.
   */
  private static function matchingRepository(array $repos, string $package): ?array {
    if (isset($repos[$package])) {
      return $repos[$package];
    }
    $slash = strpos($package, '/');
    if ($slash !== FALSE) {
      $short = substr($package, $slash + 1);
      if ($short !== '' && isset($repos[$short])) {
        return $repos[$short];
      }
    }
    return NULL;
  }

}
