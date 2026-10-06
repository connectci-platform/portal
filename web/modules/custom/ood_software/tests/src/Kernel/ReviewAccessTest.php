<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;

/**
 * Who can read an AppVerse review (ood_software_node_access()).
 *
 * The rules, from REVIEW-STATES.md "Who can read the review": reviewers
 * (appverse_pm) always; the repo's owner once a decision is sent; the public
 * only while both the review and its repo are published
 * (appverse-planning#43).
 *
 * @group ood_software
 */
class ReviewAccessTest extends KernelTestBase {

  use ProdConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'options',
    'datetime', 'link', 'taxonomy', 'path', 'path_alias',
    'content_moderation', 'workflows', 'key', 'flag', 'file', 'ood_software',
  ];

  /**
   * A reviewer (appverse_pm).
   */
  protected UserInterface $reviewer;

  /**
   * The repo's owner, the contributor.
   */
  protected UserInterface $owner;

  /**
   * Another signed-in user.
   */
  protected UserInterface $other;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'user', 'node']);
    $this->importProdConfig([
      'node.type.appverse_repo',
      'node.type.appverse_review',
      'field.storage.node.field_arv_repo',
      'field.field.node.appverse_review.field_arv_repo',
      'field.storage.node.field_arv_decision_sent_at',
      'field.field.node.appverse_review.field_arv_decision_sent_at',
    ]);

    // Everyone may view published content; contributors also hold "view own
    // unpublished content", as on the site.
    foreach ([RoleInterface::ANONYMOUS_ID, RoleInterface::AUTHENTICATED_ID] as $rid) {
      Role::load($rid)?->grantPermission('access content')->save();
    }
    Role::load(RoleInterface::AUTHENTICATED_ID)?->grantPermission('view own unpublished content')->save();
    Role::create(['id' => 'appverse_pm', 'label' => 'Appverse PM'])->save();

    // User 1 bypasses access checks, so take that id first.
    User::create(['name' => 'admin'])->save();
    $this->reviewer = $this->makeUser('reviewer', ['appverse_pm']);
    $this->owner = $this->makeUser('owner');
    $this->other = $this->makeUser('other');
  }

  /**
   * A published review of a published repo is public.
   */
  public function testPublishedReviewOfPublishedRepoIsPublic(): void {
    $review = $this->makeReview($this->makeRepo(TRUE), TRUE, TRUE);
    $this->assertCanView($review, User::getAnonymousUser(), TRUE, 'anonymous');
    $this->assertCanView($review, $this->other, TRUE, 'another user');
    $this->assertCanView($review, $this->owner, TRUE, 'the owner');
    $this->assertCanView($review, $this->reviewer, TRUE, 'a reviewer');
  }

  /**
   * A published review of an unpublished repo is hidden from the public.
   */
  public function testPublishedReviewOfUnpublishedRepoIsHidden(): void {
    $review = $this->makeReview($this->makeRepo(FALSE), TRUE, TRUE);
    $this->assertCanView($review, User::getAnonymousUser(), FALSE, 'anonymous');
    $this->assertCanView($review, $this->other, FALSE, 'another user');
    $this->assertCanView($review, $this->owner, TRUE, 'the owner');
    $this->assertCanView($review, $this->reviewer, TRUE, 'a reviewer');
  }

  /**
   * The review is public again once its repo is published.
   */
  public function testReviewReturnsWhenRepoIsPublishedAgain(): void {
    $repo = $this->makeRepo(FALSE);
    $review = $this->makeReview($repo, TRUE, TRUE);
    $this->assertCanView($review, User::getAnonymousUser(), FALSE, 'anonymous, repo unpublished');
    $repo->setPublished()->save();
    // A new request loads the review, and the repo it references, fresh.
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache();
    $review = $storage->load($review->id());
    $this->assertInstanceOf(NodeInterface::class, $review);
    $this->assertCanView($review, User::getAnonymousUser(), TRUE, 'anonymous, repo published again');
  }

  /**
   * An unpublished review: the owner reads it only once a decision is sent.
   */
  public function testUnpublishedReviewOpensToOwnerAfterDecision(): void {
    $repo = $this->makeRepo(TRUE);
    // The review is authored by the contributor, as when their
    // send-for-review starts the run; "view own unpublished" must not leak it.
    $review = $this->makeReview($repo, FALSE, FALSE, $this->owner);
    $this->assertCanView($review, $this->owner, FALSE, 'the owner before a decision');
    $this->assertCanView($review, $this->other, FALSE, 'another user');
    $this->assertCanView($review, User::getAnonymousUser(), FALSE, 'anonymous');
    $this->assertCanView($review, $this->reviewer, TRUE, 'a reviewer');

    $review->set('field_arv_decision_sent_at', \Drupal::time()->getRequestTime())->save();
    $this->assertCanView($review, $this->owner, TRUE, 'the owner after a decision');
    $this->assertCanView($review, $this->other, FALSE, 'another user after a decision');
  }

  /**
   * Asserts whether an account may view a node.
   */
  protected function assertCanView(NodeInterface $node, mixed $account, bool $expected, string $who): void {
    \Drupal::entityTypeManager()->getAccessControlHandler('node')->resetCache();
    $this->assertSame($expected, $node->access('view', $account), sprintf('%s %s view the review.', $who, $expected ? 'can' : 'cannot'));
  }

  /**
   * Creates a user with the given roles.
   *
   * @param string $name
   *   The user name.
   * @param array<int, string> $roles
   *   Role ids besides authenticated.
   */
  protected function makeUser(string $name, array $roles = []): UserInterface {
    $user = User::create(['name' => $name, 'status' => 1, 'roles' => $roles]);
    $user->save();
    return $user;
  }

  /**
   * Creates a repo owned by the contributor.
   */
  protected function makeRepo(bool $published): NodeInterface {
    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => 'example/repo',
      'uid' => $this->owner->id(),
      'status' => $published ? 1 : 0,
    ]);
    $repo->save();
    return $repo;
  }

  /**
   * Creates a review of a repo.
   */
  protected function makeReview(NodeInterface $repo, bool $published, bool $decided, ?UserInterface $author = NULL): NodeInterface {
    $review = Node::create([
      'type' => 'appverse_review',
      'title' => 'Review: example/repo',
      'uid' => ($author ?? $this->reviewer)->id(),
      'status' => $published ? 1 : 0,
      'field_arv_repo' => $repo->id(),
      'field_arv_decision_sent_at' => $decided ? \Drupal::time()->getRequestTime() : NULL,
    ]);
    $review->save();
    return $review;
  }

}
