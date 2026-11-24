<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;

/**
 * Tests the Appverse Contributor badge (D8-3133).
 *
 * @group ood_software
 */
class AppverseContributorBadgeTest extends KernelTestBase {

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
    'access_badges',
    'ood_software',
  ];

  /**
   * The badge term.
   */
  protected ?Term $badge = NULL;

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
    // Read by access_badges' user hooks on every user save.
    \Drupal::moduleHandler()->loadInclude('access_badges', 'install');
    \Drupal::database()->schema()->createTable('access_badges_pending', _access_badges_pending_schema());
    $this->installConfig(['system', 'filter', 'user', 'node', 'taxonomy']);
    $this->importProdConfig([
      'node.type.appverse_app',
      'taxonomy.vocabulary.badges',
      'taxonomy.vocabulary.open_ondemand_badges',
      // access_badges sorts field_user_badges on every user save.
      'field.storage.user.field_user_badges',
      'field.field.user.user.field_user_badges',
      'field.storage.user.field_open_ondemand_badges',
      'field.field.user.user.field_open_ondemand_badges',
    ]);
    Role::create(['id' => 'appverse_pm', 'label' => 'Appverse PM'])->save();
    // Most tests want the badge term; the deploy backfill test creates none.
    if ($this->name() !== 'testDeployBackfill') {
      $this->badge = $this->createBadge();
    }
  }

  /**
   * Creates the badge term.
   */
  private function createBadge(): Term {
    $term = Term::create([
      'vid' => 'open_ondemand_badges',
      'name' => 'Appverse Contributor',
    ]);
    $term->save();
    return $term;
  }

  /**
   * Creates an appverse_app.
   */
  private function app(UserInterface $owner, bool $published = TRUE): NodeInterface {
    $node = Node::create([
      'type' => 'appverse_app',
      'title' => 'App of ' . $owner->getAccountName(),
      'uid' => $owner->id(),
      'status' => $published ? 1 : 0,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Counts how many times a user holds the badge (reloaded from storage).
   */
  private function badgeCount(UserInterface $user, ?int $tid = NULL): int {
    $tid ??= (int) $this->badge->id();
    $fresh = \Drupal::entityTypeManager()->getStorage('user')->loadUnchanged($user->id());
    $count = 0;
    foreach ($fresh->get('field_open_ondemand_badges')->getValue() as $item) {
      if ((int) $item['target_id'] === $tid) {
        $count++;
      }
    }
    return $count;
  }

  /**
   * Runs the deploy hook.
   */
  private function runDeploy(): string {
    require_once \Drupal::service('extension.list.module')->getPath('ood_software') . '/ood_software.deploy.php';
    return (string) \ood_software_deploy_10020_appverse_contributor_badge();
  }

  /**
   * Counts terms named like the badge.
   */
  private function termCount(): int {
    return count(\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
      'vid' => 'open_ondemand_badges',
      'name' => 'Appverse Contributor',
    ]));
  }

  /**
   * Publishing an app awards its owner; a draft does not.
   */
  public function testPublishedAwardsDraftDoesNot(): void {
    $published_author = $this->createUser([], 'published_author');
    $draft_author = $this->createUser([], 'draft_author');
    $this->app($published_author);
    $this->app($draft_author, FALSE);

    $this->assertSame(1, $this->badgeCount($published_author));
    $this->assertSame(0, $this->badgeCount($draft_author));
  }

  /**
   * A draft that is published later awards the badge.
   */
  public function testDraftLaterPublishedAwards(): void {
    $author = $this->createUser([], 'late_author');
    $node = $this->app($author, FALSE);
    $this->assertSame(0, $this->badgeCount($author));

    $node->setPublished()->save();

    $this->assertSame(1, $this->badgeCount($author));
  }

  /**
   * More apps and re-saves never duplicate the badge.
   */
  public function testNoDuplicates(): void {
    $author = $this->createUser([], 'busy_author');
    $first = $this->app($author);
    $this->app($author);
    $first->setTitle('Renamed')->save();
    $first->save();

    $this->assertSame(1, $this->badgeCount($author));
  }

  /**
   * Handing an app to a new owner awards them; the old owner keeps it.
   */
  public function testOwnerChange(): void {
    $old = $this->createUser([], 'old_owner');
    $new = $this->createUser([], 'new_owner');
    $node = $this->app($old);

    $node->setOwnerId((int) $new->id())->save();

    $this->assertSame(1, $this->badgeCount($new));
    $this->assertSame(1, $this->badgeCount($old));
  }

  /**
   * A project manager without an app of their own is not a contributor.
   */
  public function testPmWithoutAppGetsNoBadge(): void {
    $pm = $this->createUser([], 'pm_no_app');
    $pm->addRole('appverse_pm');
    $pm->save();
    $this->app($this->createUser([], 'someone_else'));

    $this->assertSame(0, $this->badgeCount($pm));
  }

  /**
   * The deploy hook backfills existing authors and is idempotent.
   */
  public function testDeployBackfill(): void {
    // Apps published while the badge term did not exist: nothing is awarded
    // (setUp() creates no term for this test).
    $a = $this->createUser([], 'early_a');
    $b = $this->createUser([], 'early_b');
    $draft = $this->createUser([], 'early_draft');
    $this->app($a);
    $this->app($b);
    $this->app($draft, FALSE);
    $this->assertSame(0, $this->termCount());

    // Creates the term when it is missing.
    $this->runDeploy();
    $this->assertSame(1, $this->termCount());
    $tid = (int) \Drupal::service('access_badges.badgeTools')
      ->getBadgeTid('Appverse Contributor', 'open_ondemand_badges');

    $holders = fn(): array => array_map('intval', array_values(
      \Drupal::entityQuery('user')
        ->condition('field_open_ondemand_badges', $tid)
        ->accessCheck(FALSE)
        ->execute()
    ));
    $expected = \Drupal::service('ood_software.appverse_contributors')->getAuthorUids();
    $actual = $holders();
    sort($expected);
    sort($actual);
    $this->assertSame($expected, $actual);
    $this->assertSame([(int) $a->id(), (int) $b->id()], $actual);

    // A second run changes nothing and creates no duplicate term.
    $this->runDeploy();
    $this->assertSame(1, $this->termCount());
    $again = $holders();
    sort($again);
    $this->assertSame($actual, $again);
    $this->assertSame(1, $this->badgeCount($a, $tid));
  }

  /**
   * The deploy hook reuses a term that already exists.
   */
  public function testDeployReusesExistingTerm(): void {
    $this->app($this->createUser([], 'existing_author'));

    $this->runDeploy();

    $this->assertSame(1, $this->termCount());
    $this->assertSame((int) $this->badge->id(), (int) \Drupal::service('access_badges.badgeTools')
      ->getBadgeTid('Appverse Contributor', 'open_ondemand_badges'));
  }

}
