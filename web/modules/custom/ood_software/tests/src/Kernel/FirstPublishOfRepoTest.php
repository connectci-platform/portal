<?php

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Controller\AppverseHubController;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Whether a repo has ever been published, read from its revision log.
 *
 * @group ood_software
 */
class FirstPublishOfRepoTest extends KernelTestBase {

  use ProdConfigTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'options',
    'datetime', 'link', 'taxonomy', 'path', 'path_alias', 'file',
    'content_moderation', 'workflows', 'key', 'flag',
    'entity_reference_revisions', 'paragraphs', 'ood_software',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'user', 'node', 'field', 'content_moderation', 'workflows']);
    $this->importProdConfig([
      'node.type.appverse_repo',
      'node.type.appverse_app',
      'node.type.appverse_review',
      'workflows.workflow.appverse_editorial',
    ]);
    $this->createUser();
  }

  /**
   * A repo that was never published is on its first publish.
   */
  public function testNeverPublishedIsFirstPublish(): void {
    $repo = $this->repo('draft');
    $repo->set('moderation_state', 'ready_for_review');
    $repo->setNewRevision();
    $repo->save();
    $this->assertTrue($this->isFirstPublish($repo));
  }

  /**
   * A repo with an earlier published revision is not, even once unpublished.
   */
  public function testEarlierPublishedRevisionIsNotFirstPublish(): void {
    $repo = $this->repo('published');
    $repo->set('moderation_state', 'draft');
    $repo->setNewRevision();
    $repo->save();
    $this->assertSame('draft', $repo->get('moderation_state')->value);
    $this->assertFalse($this->isFirstPublish($repo));
  }

  /**
   * Creates a repo in the given moderation state.
   */
  protected function repo(string $state): NodeInterface {
    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => 'Example repo',
      'moderation_state' => $state,
    ]);
    $repo->save();
    return $repo;
  }

  /**
   * Calls the controller's protected check.
   */
  protected function isFirstPublish(NodeInterface $repo): bool {
    $controller = AppverseHubController::create($this->container);
    $method = new \ReflectionMethod($controller, 'isFirstPublishOfRepo');
    return $method->invoke($controller, $repo);
  }

}
