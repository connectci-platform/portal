<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;

/**
 * Tests the badge-driven people blocks and their shared base (D8-2901).
 *
 * Covers BadgePeopleBlockBase through AppReviewersBlock, plus one existing
 * block (Committee Members) that was refactored onto the base.
 *
 * @coversDefaultClass \Drupal\ood_software\Plugin\Block\BadgePeopleBlockBase
 * @group ood_software
 */
class BadgePeopleBlocksTest extends KernelTestBase {

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
    $this->installEntitySchema('path_alias');
    $this->installConfig(['system', 'filter', 'user', 'taxonomy']);
    $this->importProdConfig([
      'taxonomy.vocabulary.open_ondemand_badges',
      'field.storage.user.field_open_ondemand_badges',
      'field.field.user.user.field_open_ondemand_badges',
    ]);
  }

  /**
   * Creates a badge term.
   */
  private function term(string $name): Term {
    $term = Term::create(['vid' => 'open_ondemand_badges', 'name' => $name]);
    $term->save();
    return $term;
  }

  /**
   * Creates a user flagged with the given terms.
   *
   * @param string $name
   *   The user name.
   * @param \Drupal\taxonomy\Entity\Term[] $terms
   *   The badge terms.
   * @param bool $active
   *   Whether the user is active.
   *
   * @return \Drupal\user\UserInterface
   *   The saved user.
   */
  private function flagged(string $name, array $terms, bool $active = TRUE): UserInterface {
    $user = $this->createUser([], $name, FALSE, ['status' => $active ? 1 : 0]);
    $user->set('field_open_ondemand_badges', array_map(fn(Term $t) => ['target_id' => $t->id()], $terms));
    $user->save();
    return $user;
  }

  /**
   * Builds a block by plugin id.
   *
   * @return array{0: string[], 1: array<string, mixed>}
   *   The listed user names and the full render array.
   */
  private function build(string $plugin_id): array {
    $block = \Drupal::service('plugin.manager.block')->createInstance($plugin_id);
    $build = $block->build();
    return [array_column($build['#items'], 'name'), $build];
  }

  /**
   * Flagged active users appear; unflagged and blocked ones do not.
   */
  public function testReviewersListedByBadge(): void {
    $reviewer = $this->term('Appverse App Reviewer');
    $this->flagged('reviewer_one', [$reviewer]);
    $this->flagged('blocked_reviewer', [$reviewer], FALSE);
    $this->createUser([], 'unflagged');

    [$names, $build] = $this->build('app_reviewers_block');

    $this->assertSame(['reviewer_one'], $names);
    $this->assertNotContains('unflagged', $names);
    $this->assertNotContains('blocked_reviewer', $names);
    $this->assertSame('app_reviewers_block', $build['#theme']);
    $this->assertContains('user_list', $build['#cache']['tags']);
    $this->assertContains('taxonomy_term_list', $build['#cache']['tags']);
    $this->assertSame(3600, $build['#cache']['max-age']);
    $this->assertTrue($build['#shuffle']);
  }

  /**
   * An existing block refactored onto the base behaves the same way.
   */
  public function testCommitteeBlockUsesBase(): void {
    $committee = $this->term('Committee member');
    $reviewer = $this->term('Appverse App Reviewer');
    $this->flagged('committee_person', [$committee]);
    $this->flagged('reviewer_person', [$reviewer]);

    [$names, $build] = $this->build('committee_members_block');

    $this->assertSame(['committee_person'], $names, 'Only the Committee member badge is listed.');
    $this->assertSame('committee_members_block', $build['#theme']);
    $this->assertContains('user_list', $build['#cache']['tags']);
    $this->assertContains('taxonomy_term_list', $build['#cache']['tags']);
    $this->assertTrue($build['#shuffle']);
  }

  /**
   * After unflagging and saving, the user no longer appears.
   */
  public function testUnflaggingRemovesUser(): void {
    $reviewer = $this->term('Appverse App Reviewer');
    $user = $this->flagged('temp_reviewer', [$reviewer]);
    [$names] = $this->build('app_reviewers_block');
    $this->assertSame(['temp_reviewer'], $names);

    $user->set('field_open_ondemand_badges', []);
    $user->save();

    [$names] = $this->build('app_reviewers_block');
    $this->assertSame([], $names);
  }

  /**
   * A missing term yields an empty but still cacheable build.
   */
  public function testMissingTermIsCacheable(): void {
    [$names, $build] = $this->build('app_reviewers_block');

    $this->assertSame([], $names);
    $this->assertContains('taxonomy_term_list', $build['#cache']['tags']);
    $this->assertContains('user_list', $build['#cache']['tags']);
  }

  /**
   * The shared template passes the shuffle flag through to the markup.
   */
  public function testShuffleMarkupRendered(): void {
    $reviewer = $this->term('Appverse App Reviewer');
    $this->flagged('reviewer_one', [$reviewer]);

    $block = \Drupal::service('plugin.manager.block')->createInstance('app_reviewers_block');
    $build = $block->build();
    $html = (string) \Drupal::service('renderer')->renderInIsolation($build);

    $this->assertStringContainsString('data-shuffle', $html);
    $this->assertStringContainsString('app-reviewers-block', $html);
    $this->assertStringContainsString('reviewer_one', $html);
  }

}
