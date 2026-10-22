<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel\Traits;

use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

/**
 * Helpers for kernel tests of appverse_repo moderation state and revisions.
 *
 * The using test provides $this->owner, the repo's author.
 */
trait AppverseModerationTestTrait {

  /**
   * Creates a repo owned by the contributor, in a moderation state.
   */
  protected function makeRepo(string $state): NodeInterface {
    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => 'example/app',
      // The using test provides the owner.
      // @phpstan-ignore-next-line
      'uid' => $this->owner->id(),
      'field_repo_url' => 'https://github.com/example/app',
      'moderation_state' => $state,
    ]);
    $repo->save();
    return $repo;
  }

  /**
   * A published repo with a forward revision in $state.
   *
   * Content moderation keeps the published revision as the default.
   */
  protected function publishedRepoWithForwardRevision(string $state): NodeInterface {
    return $this->addForwardRevision($this->makeRepo('published'), $state);
  }

  /**
   * Publishes an existing repo, then adds a forward revision in $state.
   *
   * For tests that need the published repo to be one the code under test
   * finds itself, such as a repo a sync created.
   */
  protected function addForwardRevision(NodeInterface $repo, string $state): NodeInterface {
    $repo = Node::load($repo->id());
    if ($repo->get('moderation_state')->value !== 'published') {
      $repo->set('moderation_state', 'published')->save();
      $repo = Node::load($repo->id());
    }
    $repo->set('moderation_state', $state)->save();
    return $repo;
  }

  /**
   * The node's latest revision, loaded fresh.
   */
  protected function latest(NodeInterface $node): NodeInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([$node->id()]);
    $latest = $storage->loadRevision($storage->getLatestRevisionId($node->id()));
    $this->assertInstanceOf(NodeInterface::class, $latest);
    return $latest;
  }

  /**
   * The node's default revision, loaded fresh.
   */
  protected function defaultRevision(NodeInterface $node): NodeInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([$node->id()]);
    $default = $storage->load($node->id());
    $this->assertInstanceOf(NodeInterface::class, $default);
    return $default;
  }

  /**
   * Asserts the moderation state of a node's latest revision.
   */
  protected function assertLatestState(string $expected, NodeInterface $node): void {
    $this->assertSame($expected, $this->latest($node)->get('moderation_state')->value, $node->bundle() . ' ' . $node->label());
  }

  /**
   * How many content_moderation_state entities track the node.
   */
  protected function moderationStateEntityCount(NodeInterface $node): int {
    $ids = \Drupal::entityTypeManager()->getStorage('content_moderation_state')
      ->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('content_entity_type_id', 'node')
      ->condition('content_entity_id', $node->id())
      ->execute();
    return count(array_unique($ids));
  }

  /**
   * How many revisions the node has.
   */
  protected function revisionCount(NodeInterface $node): int {
    return (int) \Drupal::entityTypeManager()->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('nid', $node->id())
      ->count()
      ->execute();
  }

}
