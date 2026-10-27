<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;

/**
 * Tests the Appverse Contributors block ordering (D8-2901).
 *
 * @coversDefaultClass \Drupal\ood_software\Plugin\Block\AppverseContributorsBlock
 * @group ood_software
 */
class AppverseContributorsOrderTest extends KernelTestBase {

  use ProdConfigTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'options',
    'datetime',
    'link',
    'taxonomy',
    'path',
    'path_alias',
    'content_moderation',
    'workflows',
    'key',
    'flag',
    'file',
    'ood_software',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('flag', ['flag_counts']);
    $this->installConfig(['system', 'filter', 'user', 'node']);
    $this->importProdConfig(['node.type.appverse_app']);
    Role::create(['id' => 'appverse_pm', 'label' => 'Appverse PM'])->save();
  }

  /**
   * Creates a published appverse_app authored by $uid.
   */
  private function app(int $uid, int $created): void {
    Node::create([
      'type' => 'appverse_app',
      'title' => 'App ' . $uid . '-' . $created,
      'uid' => $uid,
      'status' => 1,
      'created' => $created,
    ])->save();
  }

  /**
   * Builds the block and returns the listed names, in order.
   *
   * @return array{0: string[], 1: array<string, mixed>}
   *   The listed user names and the full render array.
   */
  private function build(): array {
    $block = \Drupal::service('plugin.manager.block')
      ->createInstance('appverse_contributors_block');
    $build = $block->build();
    return [array_column($build['#items'], 'name'), $build];
  }

  /**
   * Orders by most recent app, with appverse_pm users pinned first.
   */
  public function testOrderingAndPmPinning(): void {
    $now = \Drupal::time()->getRequestTime();

    // Created out of the expected order on purpose.
    $old_author = $this->createUser([], 'author_last_month');
    $pm_old = $this->createUser([], 'pm_older_app');
    $new_author = $this->createUser([], 'author_yesterday');
    $pm_new = $this->createUser([], 'pm_newer_app');
    $pm_no_app_b = $this->createUser([], 'pm_no_apps_b');
    $pm_no_app_a = $this->createUser([], 'pm_no_apps_a');
    foreach ([$pm_old, $pm_new, $pm_no_app_b, $pm_no_app_a] as $pm) {
      $pm->addRole('appverse_pm');
      $pm->save();
    }

    $this->app((int) $old_author->id(), $now - 30 * 86400);
    $this->app((int) $new_author->id(), $now - 86400);
    $this->app((int) $pm_old->id(), $now - 20 * 86400);
    $this->app((int) $pm_new->id(), $now - 3600);
    // A second, older app must not move an author down: MAX(created) counts.
    $this->app((int) $old_author->id(), $now - 60 * 86400);

    [$names, $build] = $this->build();

    $this->assertSame([
      // PMs first, newest app first, PMs without apps last (by uid).
      'pm_newer_app',
      'pm_older_app',
      'pm_no_apps_b',
      'pm_no_apps_a',
      // Then everyone else by most recent app.
      'author_yesterday',
      'author_last_month',
    ], $names);

    $this->assertContains('node_list:appverse_app', $build['#cache']['tags']);
    $this->assertContains('user_list', $build['#cache']['tags']);
    $this->assertSame(3600, $build['#cache']['max-age']);
    $this->assertArrayNotHasKey('#shuffle', $build, 'Appverse list is ordered, not shuffled.');
  }

  /**
   * A non-PM author whose latest app is newer is listed before an older one.
   */
  public function testNewerAppListedFirst(): void {
    $now = \Drupal::time()->getRequestTime();
    $last_month = $this->createUser([], 'last_month');
    $yesterday = $this->createUser([], 'yesterday');
    $this->app((int) $last_month->id(), $now - 30 * 86400);
    $this->app((int) $yesterday->id(), $now - 86400);

    [$names] = $this->build();

    $this->assertSame(['yesterday', 'last_month'], $names);
  }

}
