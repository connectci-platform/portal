<?php

namespace Drupal\Tests\ood_contributions\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\ood_contributions\Kernel\Traits\DiscourseTestTrait;
use Drupal\user\Entity\User;

/**
 * Covers storing per-user Discourse stats.
 *
 * @group ood_contributions
 */
class DiscourseContributionSyncTest extends KernelTestBase {

  use DiscourseTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'options', 'datetime',
    'link', 'taxonomy', 'path', 'path_alias', 'content_moderation', 'workflows',
    'key', 'flag', 'file', 'ood_software', 'ood_contributions',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('ood_contributions', ['ood_disc_contrib']);
    FieldStorageConfig::create([
      'field_name' => 'field_discourse_openondemand_org',
      'entity_type' => 'user',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_discourse_openondemand_org',
      'entity_type' => 'user',
      'bundle' => 'user',
    ])->save();
    $this->setUpDiscourseClock();
  }

  /**
   * Creates a user linked to a Discourse username.
   */
  protected function createLinkedUser(string $discourse_name): User {
    $user = User::create([
      'name' => 'drupal_' . $this->randomMachineName(),
      'mail' => $this->randomMachineName() . '@example.com',
      'status' => 1,
      'field_discourse_openondemand_org' => $discourse_name,
    ]);
    $user->save();
    return $user;
  }

  /**
   * Counts come from summary.json and last_posted_at from the user JSON.
   */
  public function testCountsAndLastPostedAtAreStored(): void {
    $user = $this->createLinkedUser('alice');
    $this->userJson['alice'] = [
      'user_badges' => [],
      'badges' => [['name' => 'Regular'], ['name' => 'Helper']],
      'user' => ['username' => 'alice', 'last_posted_at' => '2026-10-15T14:30:00.000Z'],
    ];
    $this->summaryJson['alice'] = [
      'user_summary' => [
        'post_count' => 42,
        'topic_count' => 7,
        'likes_given' => 11,
        'likes_received' => 23,
        'days_visited' => 90,
        'time_read' => 12345,
        'solved_count' => 5,
      ],
    ];

    $result = $this->contributionService()->updateUserContributions((int) $user->id());

    $this->assertTrue($result['success'], $result['message']);
    $row = $this->contributionService()->getUserContributions((int) $user->id());
    $this->assertSame(42, (int) $row['post_count']);
    $this->assertSame(7, (int) $row['topic_count']);
    $this->assertSame(23, (int) $row['likes_received']);
    $this->assertSame(5, (int) $row['solved_count']);
    $this->assertSame('Regular, Helper', $row['badges']);
    $this->assertSame(strtotime('2026-10-15T14:30:00Z'), (int) $row['last_posted_at']);
    $this->assertContains('/u/alice/summary.json', $this->requested);
    $this->assertContains('/u/alice.json', $this->requested);
  }

  /**
   * A user who has never posted stores a NULL last_posted_at.
   */
  public function testMissingLastPostedAtIsNull(): void {
    $user = $this->createLinkedUser('bob');
    $this->userJson['bob'] = ['user' => ['username' => 'bob']];
    $this->summaryJson['bob'] = ['user_summary' => ['post_count' => 0]];

    $this->assertTrue($this->contributionService()->updateUserContributions((int) $user->id())['success']);
    $row = $this->contributionService()->getUserContributions((int) $user->id());
    $this->assertNull($row['last_posted_at']);
  }

  /**
   * A failed summary fetch does not overwrite stored numbers with zeros.
   */
  public function testFailedFetchKeepsStoredValues(): void {
    $user = $this->createLinkedUser('carol');
    $this->userJson['carol'] = ['user' => ['last_posted_at' => '2026-10-15T14:30:00.000Z']];
    $this->summaryJson['carol'] = ['user_summary' => ['post_count' => 9]];
    $this->assertTrue($this->contributionService()->updateUserContributions((int) $user->id())['success']);

    $this->httpFails = TRUE;
    $result = $this->contributionService()->updateUserContributions((int) $user->id());
    $this->assertFalse($result['success']);
    $row = $this->contributionService()->getUserContributions((int) $user->id());
    $this->assertSame(9, (int) $row['post_count']);
  }

  /**
   * Usernames are URL-encoded in request paths.
   */
  public function testUsernameIsUrlEncoded(): void {
    $user = $this->createLinkedUser('a b/c');
    $this->userJson['a b/c'] = ['user' => []];
    $this->summaryJson['a b/c'] = ['user_summary' => ['post_count' => 1]];

    $this->contributionService()->updateUserContributions((int) $user->id());
    $this->assertContains('/u/a+b%2Fc/summary.json', $this->requested);
  }

}
