<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Tests the Discourse Participants block ordering and filtering (D8-2901).
 *
 * @coversDefaultClass \Drupal\ood_software\Plugin\Block\DiscourseParticipantsBlock
 * @group ood_software
 */
class DiscourseParticipantsOrderTest extends KernelTestBase {

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
    'ood_contributions',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('ood_contributions', ['ood_disc_contrib']);
  }

  /**
   * Links a user to Discourse with the given last post time.
   */
  private function link(int $uid, ?int $last_posted_at): void {
    \Drupal::database()->insert('ood_disc_contrib')
      ->fields([
        'uid' => $uid,
        'last_posted_at' => $last_posted_at,
      ])
      ->execute();
  }

  /**
   * Builds the block and returns the listed names, in order.
   *
   * @return array{0: string[], 1: array<string, mixed>}
   *   The listed user names and the full render array.
   */
  private function build(): array {
    $block = \Drupal::service('plugin.manager.block')
      ->createInstance('discourse_participants_block');
    $build = $block->build();
    return [array_column($build['#items'], 'name'), $build];
  }

  /**
   * Orders by most recent post, NULLs last, and skips blocked users.
   */
  public function testOrderingAndFiltering(): void {
    $now = \Drupal::time()->getRequestTime();
    // Create in an order that differs from the expected output order.
    $never = $this->createUser([], 'never_posted');
    $old = $this->createUser([], 'last_month');
    $recent = $this->createUser([], 'yesterday');
    $blocked = $this->createUser([], 'blocked_user', FALSE, ['status' => 0]);
    $never2 = $this->createUser([], 'never_posted_two');

    $this->link((int) $never->id(), NULL);
    $this->link((int) $old->id(), $now - 30 * 86400);
    $this->link((int) $recent->id(), $now - 86400);
    $this->link((int) $blocked->id(), $now - 60);
    $this->link((int) $never2->id(), NULL);

    [$names, $build] = $this->build();

    $this->assertSame(
      ['yesterday', 'last_month', 'never_posted', 'never_posted_two'],
      $names,
      'Most recent first, never-posted last (by uid), blocked user excluded.'
    );
    $this->assertNotContains('blocked_user', $names);

    $this->assertContains('ood_disc_contrib', $build['#cache']['tags']);
    $this->assertContains('user_list', $build['#cache']['tags']);
    $this->assertSame(3600, $build['#cache']['max-age']);
    $this->assertArrayNotHasKey('#shuffle', $build, 'Discourse list is ordered, not shuffled.');
  }

}
