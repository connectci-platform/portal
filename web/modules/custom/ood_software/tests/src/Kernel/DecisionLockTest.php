<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Form\ReviewPublishConfirmForm;
use Drupal\ood_software\Service\ReviewDecisionApplier;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * A sent decision is what Publish acts on, not the page's later edits
 * (appverse-planning#51).
 *
 * The case from the issue: Reject is sent, an app is then changed to Accept
 * with suggestions and saved. The Publish bar must not come back, and Publish
 * must not publish the review or the repo.
 *
 * @group ood_software
 */
class DecisionLockTest extends KernelTestBase {

  use Traits\ProdConfigTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'options',
    'datetime', 'link', 'taxonomy', 'path', 'path_alias',
    'content_moderation', 'workflows', 'key', 'flag', 'file', 'ood_software',
  ];

  protected ReviewDecisionApplier $applier;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'user', 'node', 'content_moderation', 'workflows']);
    $this->importProdConfig([
      'node.type.appverse_repo',
      'node.type.appverse_app',
      'node.type.appverse_review',
      'workflows.workflow.appverse_editorial',
      'workflows.workflow.appverse_review_workflow',
      'field.storage.node.field_arv_repo',
      'field.field.node.appverse_review.field_arv_repo',
      'field.storage.node.field_arv_decision_sent_at',
      'field.field.node.appverse_review.field_arv_decision_sent_at',
      'field.storage.node.field_arv_sent_decisions',
      'field.field.node.appverse_review.field_arv_sent_decisions',
    ]);
    \Drupal::service('router.builder')->rebuild();
    Role::create(['id' => 'appverse_pm', 'label' => 'Appverse PM'])
      ->grantPermission('administer appverse content')
      ->save();
    User::create(['name' => 'admin'])->save();
    $reviewer = User::create(['name' => 'reviewer', 'status' => 1, 'roles' => ['appverse_pm'], 'mail' => 'r@example.com']);
    $reviewer->save();
    $this->setCurrentUser($reviewer);
    $this->applier = $this->container->get('ood_software.review_decision_applier');
  }

  protected function repo(): NodeInterface {
    $owner = User::create(['name' => 'owner' . random_int(1, 99999), 'status' => 1, 'mail' => 'o@example.com']);
    $owner->save();
    $repo = Node::create(['type' => 'appverse_repo', 'title' => 'Repo', 'uid' => $owner->id(), 'moderation_state' => 'ready_for_review']);
    $repo->save();
    return $repo;
  }

  /**
   * A review of the repo whose sent decision is $apps.
   *
   * @param \Drupal\node\NodeInterface $repo
   *   The repo.
   * @param array<int|string, string> $apps
   *   Verdict id => the decision sent.
   */
  protected function sentReview(NodeInterface $repo, array $apps): NodeInterface {
    $review = Node::create([
      'type' => 'appverse_review',
      'title' => 'Review',
      'uid' => 1,
      'moderation_state' => 'in_review',
      'field_arv_repo' => $repo->id(),
      'field_arv_decision_sent_at' => 1790000000,
      'field_arv_sent_decisions' => json_encode(['apps' => $apps, 'response' => 'Thanks.']),
    ]);
    $review->save();
    return $review;
  }

  /**
   * sent() reads what was stored at send time.
   */
  public function testSentReadsTheStoredDecision(): void {
    $review = $this->sentReview($this->repo(), ['7' => 'reject']);
    // assertEquals: the stored verdict ids come back as integer keys.
    $this->assertEquals(['apps' => ['7' => 'reject'], 'response' => 'Thanks.', 'email' => NULL, 'was_live' => NULL, 'history' => []], ReviewDecisionApplier::sent($review));
    $unsent = Node::create(['type' => 'appverse_review', 'title' => 'Unsent', 'moderation_state' => 'draft']);
    $unsent->save();
    $this->assertNull(ReviewDecisionApplier::sent($unsent));
  }

  /**
   * A sent Reject, whatever the page says later: no Publish bar, no publish.
   */
  public function testASentRejectIsNeverPublished(): void {
    $repo = $this->repo();
    $review = $this->sentReview($repo, ['7' => 'reject']);
    $this->assertFalse(ReviewPublishConfirmForm::canPublish($review));
    $this->assertNotNull($this->applier->publish($review), 'publish() says why it published nothing');
    $this->assertFalse(Node::load($repo->id())->isPublished(), 'the repo stays out of the catalog');
    $this->assertFalse(Node::load($review->id())->isPublished(), 'the review stays private');
  }

  /**
   * A sent Accept with suggestions publishes the repo and the review, and
   * only from the repo's newest review.
   */
  public function testASentAcceptWithSuggestionsPublishes(): void {
    $repo = $this->repo();
    $older = $this->sentReview($repo, ['7' => 'accept_with_suggestions']);
    $newer = $this->sentReview($repo, ['8' => 'accept_with_suggestions']);
    $this->assertFalse(ReviewPublishConfirmForm::canPublish($older), 'not from a superseded review');
    $this->assertTrue(ReviewPublishConfirmForm::canPublish($newer));

    $this->assertNull($this->applier->publish($newer));
    $this->assertTrue(Node::load($repo->id())->isPublished());
    $newer = Node::load($newer->id());
    $this->assertTrue($newer->isPublished());
    $this->assertFalse(ReviewPublishConfirmForm::canPublish($newer), 'nothing left to publish');
  }

}
