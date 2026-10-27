<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_general\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Url;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;

/**
 * Regression guard for D8-2878: inline images with a space in the filename.
 *
 * CKEditor 5 saves an uploaded image as an already-encoded root-relative src
 * ("classroom%20story%20image.png"). The contrib rel_to_abs filter encoded it a
 * second time ("%2520"), so the image 404'd. The custom rel_to_abs module that
 * replaced contrib (D8-2922) leaves the path as saved, and this test fails if a
 * change to it starts re-encoding paths again.
 *
 * @group ood_general
 */
class RelToAbsEncodedPathTest extends KernelTestBase {

  /**
   * Text formats that render Classroom Story bodies.
   *
   * The body field has no allowed_formats restriction, so editors use the
   * site's general HTML formats.
   */
  protected const STORY_FORMATS = ['basic_html', 'full_html'];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'filter',
    'rel_to_abs',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'filter']);

    FilterFormat::create([
      'format' => 'rel_to_abs_only',
      'name' => 'rel_to_abs only',
      'filters' => [
        'rel_to_abs' => ['status' => TRUE],
      ],
    ])->save();
  }

  /**
   * Root-relative paths are made absolute and encoded exactly once.
   *
   * @dataProvider encodedPathProvider
   */
  public function testEncodedPathIsNotDoubleEncoded(string $input, string $expected): void {
    $base = rtrim(Url::fromRoute('<front>')->setAbsolute()->toString(), '/');

    $output = (string) check_markup('<img src="' . $input . '">', 'rel_to_abs_only');

    $this->assertStringNotContainsString('%2520', $output);
    $this->assertSame('<img src="' . $base . $expected . '">', $output);
  }

  /**
   * Data provider for testEncodedPathIsNotDoubleEncoded().
   */
  public static function encodedPathProvider(): array {
    return [
      'CKEditor inline image with spaces' => [
        '/sites/default/files/inline-images/classroom%20story%20image.png',
        '/sites/default/files/inline-images/classroom%20story%20image.png',
      ],
      'literal percent sign round trips' => [
        '/files/100%25.jpg',
        '/files/100%25.jpg',
      ],
      'unencoded space is left as-is' => [
        '/files/a b.jpg',
        '/files/a b.jpg',
      ],
      'encoded query is left as-is' => [
        '/node/1?q=a%26b',
        '/node/1?q=a%26b',
      ],
      'encoded fragment is left as-is' => [
        '/node/1#sec%20one',
        '/node/1#sec%20one',
      ],
    ];
  }

  /**
   * Prod config keeps rel_to_abs enabled where Classroom Stories render.
   *
   * Without this, disabling the filter or the module would leave the test
   * above passing against its own test-only format while prod changed.
   */
  public function testProdConfigEnablesRelToAbs(): void {
    $source = new FileStorage(DRUPAL_ROOT . '/sites/default/config/default');

    $extension = $source->read('core.extension');
    $this->assertIsArray($extension);
    $this->assertArrayHasKey('rel_to_abs', $extension['module'], 'rel_to_abs module is enabled in prod config.');

    foreach (self::STORY_FORMATS as $format) {
      $data = $source->read('filter.format.' . $format);
      $this->assertIsArray($data, "Prod config has filter.format.$format.");
      $this->assertTrue(
        $data['filters']['rel_to_abs']['status'] ?? FALSE,
        "rel_to_abs is enabled in the $format text format."
      );
    }
  }

}
