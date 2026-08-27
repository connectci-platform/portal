<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\node\Traits\NodeCreationTrait;

/**
 * Asserts ood_software_node_view() emits the doc-sidebar scaffolding +
 * library only for DOC_MAP documentation nodes, and nothing for others.
 *
 * @group ood_software
 */
class DocSidebarAttachTest extends KernelTestBase {

  use NodeCreationTrait;

  protected static $modules = ['system', 'user', 'field', 'text', 'node', 'filter', 'key', 'ood_software'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'filter']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    \Drupal::moduleHandler()->loadInclude('ood_software', 'module');
  }

  /**
   * Force-create a node, then re-key it to a DOC_MAP nid so the hook matches.
   */
  protected function makeDocNode(): Node {
    // DOC_MAP nids are large fixed integers; create a node and set its nid.
    $node = Node::create(['type' => 'page', 'title' => 'Contributor Guide']);
    $node->save();
    // Re-save under a DOC_MAP nid by direct assignment is not portable; instead
    // assert behavior via a node whose id IS a DOC_MAP value by enforcing it.
    return $node;
  }

  public function testDocNodeGetsSidebarScaffoldAndLibrary(): void {
    // Build a node and stub its id() to a DOC_MAP nid (11929).
    $node = $this->getMockBuilder(Node::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['id', 'bundle', 'isPublished', 'toUrl'])
      ->getMock();
    $node->method('id')->willReturn(11929);
    $node->method('bundle')->willReturn('page');
    $node->method('isPublished')->willReturn(TRUE);

    $build = ['#node' => $node];
    $view_mode = 'full';
    ood_software_node_view($build, $node, $this->container->get('entity_display.repository')->getViewDisplay('node', 'page', 'full'), $view_mode);

    self::assertArrayHasKey('#attached', $build, 'Build must declare attachments.');
    self::assertContains('ood_software/appverse_doc_sidebar', $build['#attached']['library'], 'Sidebar library must be attached for a DOC_MAP node.');
    self::assertSame(['appverse-doc-node'], $build['#attributes']['class'] ?? NULL, 'Doc node must be wrapped with the appverse-doc-node class.');
    self::assertArrayHasKey('appverse_doc_sidebar', $build, 'Build must include the rail container render element.');
  }

  public function testNonDocNodeGetsNoSidebar(): void {
    $node = $this->getMockBuilder(Node::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['id', 'bundle', 'isPublished'])
      ->getMock();
    $node->method('id')->willReturn(99999);
    $node->method('bundle')->willReturn('page');
    $node->method('isPublished')->willReturn(TRUE);

    $build = ['#node' => $node];
    $view_mode = 'full';
    ood_software_node_view($build, $node, $this->container->get('entity_display.repository')->getViewDisplay('node', 'page', 'full'), $view_mode);

    $libs = $build['#attached']['library'] ?? [];
    self::assertNotContains('ood_software/appverse_doc_sidebar', $libs, 'Non-doc node must not get the sidebar library.');
    self::assertArrayNotHasKey('appverse_doc_sidebar', $build, 'Non-doc node must not get the rail container.');
  }
}
