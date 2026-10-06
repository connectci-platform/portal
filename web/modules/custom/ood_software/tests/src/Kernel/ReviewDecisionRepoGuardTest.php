<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Service\ReviewDecisionApplier;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;

/**
 * A decision acts only on a repo awaiting review or live for a re-review.
 *
 * A contributor can move a submitted repo back to draft from its edit form.
 * Publishing it then put edits made after the review into the catalog
 * (appverse-planning#41).
 *
 * @group ood_software
 */
class ReviewDecisionRepoGuardTest extends KernelTestBase {

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
      'field.storage.node.field_arv_repo',
      'field.field.node.appverse_review.field_arv_repo',
      'field.storage.node.field_arv_decision_sent_at',
      'field.field.node.appverse_review.field_arv_decision_sent_at',
    ]);
  }

  /**
   * The repo states a decision may act on, and those it refuses.
   */
  public function testRepoBlocker(): void {
    foreach (['ready_for_review' => TRUE, 'published' => TRUE, 'draft' => FALSE, 'needs_adjustment' => FALSE] as $state => $allowed) {
      $review = $this->makeReview($this->makeRepo($state));
      $blocker = $this->applier()->repoBlocker($review);
      $this->assertSame($allowed, $blocker === NULL, "A $state repo is " . ($allowed ? 'allowed' : 'refused') . '.');
    }
  }

  /**
   * Publish and Send decision change nothing on a repo moved back to draft.
   */
  public function testDraftRepoIsLeftAlone(): void {
    $repo = $this->makeRepo('draft');
    $review = $this->makeReview($repo);

    $this->assertFalse($this->applier()->publish($review));
    $this->assertFalse($this->applier()->send($review, ''));

    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache();
    $repo = $storage->load($repo->id());
    $review = $storage->load($review->id());
    $this->assertInstanceOf(NodeInterface::class, $repo);
    $this->assertInstanceOf(NodeInterface::class, $review);
    $this->assertSame('draft', $repo->get('moderation_state')->value);
    $this->assertFalse($repo->isPublished());
    $this->assertTrue($review->get('field_arv_decision_sent_at')->isEmpty(), 'No decision is recorded.');
  }

  /**
   * The decision applier.
   */
  protected function applier(): ReviewDecisionApplier {
    $applier = \Drupal::service('ood_software.review_decision_applier');
    assert($applier instanceof ReviewDecisionApplier);
    return $applier;
  }

  /**
   * Creates a repo in a moderation state.
   */
  protected function makeRepo(string $state): NodeInterface {
    $repo = Node::create(['type' => 'appverse_repo', 'title' => "example/$state", 'moderation_state' => $state]);
    $repo->save();
    return $repo;
  }

  /**
   * Creates an undecided review of a repo.
   */
  protected function makeReview(NodeInterface $repo): NodeInterface {
    $review = Node::create([
      'type' => 'appverse_review',
      'title' => 'Review: ' . $repo->label(),
      'field_arv_repo' => $repo->id(),
    ]);
    $review->save();
    return $review;
  }

}
