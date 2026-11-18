<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\ood_software\Service\AppverseLayer;
use Drupal\Tests\UnitTestCase;

/**
 * A monorepo app's title, from its root apps[] entry and its own files.
 *
 * @coversDefaultClass \Drupal\ood_software\Service\AppverseLayer
 * @group ood_software
 */
class AppverseLayerTest extends UnitTestCase {

  /**
   * Where the title comes from.
   *
   * @param array<string, mixed> $perSubpath
   *   The app's own appverse.yml.
   * @param array<string, mixed> $rootInline
   *   The root apps[] entry.
   * @param string $subpath
   *   The app's folder.
   * @param mixed $manifestName
   *   The manifest.yml name.
   * @param mixed $expected
   *   The merged layer's name.
   *
   * @covers ::merge
   * @dataProvider titleCases
   */
  public function testTitle(array $perSubpath, array $rootInline, string $subpath, mixed $manifestName, mixed $expected): void {
    $layer = AppverseLayer::merge($perSubpath, $rootInline, $subpath, $manifestName);
    $this->assertSame($expected, $layer['name'] ?? NULL);
    $this->assertArrayNotHasKey('title', $layer);
    $this->assertArrayNotHasKey('path', $layer);
  }

  /**
   * Per-app file, root entry, subpath, manifest name => the title.
   *
   * @return array<string, array<int, mixed>>
   *   The cases.
   */
  public static function titleCases(): array {
    return [
      // D8-3138: the EESSI layout.
      'folder name gives way to the app file title' => [
        ['title' => 'Bokeh - EESSI'],
        ['name' => 'bc_eessi_bokeh', 'path' => 'bc_eessi_bokeh'],
        'bc_eessi_bokeh',
        'Bokeh (manifest)',
        'Bokeh - EESSI',
      ],
      // The layer has no name, so the manifest's is used after it.
      'folder name gives way to the manifest name' => [
        [],
        ['name' => 'bc_gmsh', 'path' => 'bc_gmsh'],
        'bc_gmsh',
        'Gmsh',
        NULL,
      ],
      'last segment of a nested path counts' => [
        ['name' => 'Bokeh'],
        ['name' => 'bokeh', 'path' => 'apps/bokeh'],
        'apps/bokeh',
        NULL,
        'Bokeh',
      ],
      'trailing slash in the name counts' => [
        ['name' => 'Bokeh'],
        ['name' => 'bokeh/', 'path' => 'bokeh'],
        'bokeh',
        NULL,
        'Bokeh',
      ],
      // Nothing else names the app: the folder stays its title, so the app
      // isn't rejected for having no name.
      'folder name stays with nothing else' => [
        [],
        ['name' => 'jup', 'path' => 'jup'],
        'jup',
        NULL,
        'jup',
      ],
      'an empty manifest name is nothing else' => [
        [],
        ['name' => 'jup', 'path' => 'jup'],
        'jup',
        '  ',
        'jup',
      ],
      'a real root name still wins' => [
        ['title' => 'Other'],
        ['name' => 'Jupyter (Example)', 'path' => 'jup'],
        'jup',
        'Manifest',
        'Jupyter (Example)',
      ],
      'a name differing only in case is a title' => [
        ['name' => 'Other'],
        ['name' => 'Jupyter', 'path' => 'jupyter'],
        'jupyter',
        NULL,
        'Jupyter',
      ],
      'the app file name beats its title' => [
        ['name' => 'Named', 'title' => 'Titled'],
        [],
        'jup',
        NULL,
        'Named',
      ],
      'the app file title alone' => [
        ['title' => 'Titled'],
        ['path' => 'jup'],
        'jup',
        NULL,
        'Titled',
      ],
      'a single-app root never matches' => [
        [],
        ['name' => 'App'],
        '',
        'Manifest',
        'App',
      ],
    ];
  }

  /**
   * The manifest name, read leniently.
   *
   * @covers ::manifestName
   */
  public function testManifestName(): void {
    $this->assertSame('Gmsh', AppverseLayer::manifestName("name: \"Gmsh\"\nrole: batch_connect\n"));
    $this->assertNull(AppverseLayer::manifestName(NULL));
    $this->assertNull(AppverseLayer::manifestName("name: [unclosed\n"));
    $this->assertNull(AppverseLayer::manifestName("just text\n"));
  }

}
