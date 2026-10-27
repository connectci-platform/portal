<?php

namespace Drupal\Tests\rel_to_abs\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\domain_source\DomainSourceElementManagerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\domain\Traits\DomainTestTrait;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests that routed links keep Domain Source's host and the path alias.
 *
 * @group rel_to_abs
 */
class RelToAbsDomainSourceTest extends KernelTestBase {

  use DomainTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'domain',
    'domain_source',
    'field',
    'filter',
    'node',
    'path_alias',
    'rel_to_abs',
    'system',
    'text',
    'user',
  ];

  /**
   * The filter plugin under test.
   *
   * @var \Drupal\filter\Plugin\FilterInterface
   */
  protected $filter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('domain');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $this->installConfig(['domain', 'domain_source', 'filter', 'system']);
    $this->installSchema('node', ['node_access']);

    // Creates example.com (the default domain) and one.example.com.
    $this->domainCreateTestDomains(2, 'example.com');

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->container->get('domain_source.helper')->confirmFields('node', 'page');

    $request = Request::create('http://example.com/some/page');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    // The URL generator reads the host from the request context, which a
    // real request sets up when it starts.
    $this->container->get('router.request_context')->fromRequest($request);

    $this->filter = $this->container->get('plugin.manager.filter')
      ->createInstance('rel_to_abs', ['status' => TRUE]);
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Before Drupal 11.4, Domain Source matches the path with its own route
    // provider, which extends "router.route_provider". A kernel test replaces
    // that service with one that takes no arguments, so extend the real one.
    $definition = $container->getDefinition('domain_source.route_provider');
    if ($definition instanceof ChildDefinition) {
      $definition->setParent('test.router.route_provider');
    }
  }

  /**
   * Creates a page node.
   *
   * @param string|null $source
   *   The machine name of the node's source domain, if any.
   */
  protected function createPage(?string $source = NULL): NodeInterface {
    $values = ['type' => 'page', 'title' => 'Page'];
    if ($source !== NULL) {
      $values[DomainSourceElementManagerInterface::DOMAIN_SOURCE_FIELD] = $source;
    }
    $node = Node::create($values);
    $node->save();
    return $node;
  }

  /**
   * A link to a node on another domain gets that domain's host.
   */
  public function testLinkToNodeOnAnotherDomainUsesItsHost(): void {
    $node = $this->createPage('one_example_com');
    $result = $this->filter->process('<a href="/node/' . $node->id() . '?x=1#top">a</a>', 'en');

    $this->assertStringContainsString('href="http://one.example.com/node/' . $node->id() . '?x=1#top"', (string) $result);
    // The host depends on the node, so a change to it must clear the cache.
    $this->assertContains('node:' . $node->id(), $result->getCacheTags());
    $this->assertContains('url.site', $result->getCacheContexts());
  }

  /**
   * A link to a node with no source domain keeps the current host.
   */
  public function testLinkToNodeWithoutSourceUsesCurrentHost(): void {
    $node = $this->createPage();
    $html = (string) $this->filter->process('<a href="/node/' . $node->id() . '">a</a>', 'en');
    $this->assertStringContainsString('href="http://example.com/node/' . $node->id() . '"', $html);
  }

  /**
   * A system path is swapped for its alias, and an alias is kept.
   */
  public function testLinkUsesPathAlias(): void {
    $node = $this->createPage('one_example_com');
    $this->container->get('entity_type.manager')->getStorage('path_alias')->create([
      'path' => '/node/' . $node->id(),
      'alias' => '/my-page',
    ])->save();

    $html = (string) $this->filter->process('<a href="/node/' . $node->id() . '">a</a><a href="/my-page">b</a>', 'en');
    $this->assertSame(2, substr_count($html, 'href="http://one.example.com/my-page"'));
  }

  /**
   * Files, srcset and unrouted links keep the current host.
   */
  public function testUnroutedUrlsUseCurrentHost(): void {
    $html = (string) $this->filter->process(
      '<a href="/no/such/path">a</a><a href="/node/999">b</a><img src="/sites/default/files/x.png" srcset="/files/s.png 1x">',
      'en'
    );
    $this->assertStringContainsString('href="http://example.com/no/such/path"', $html);
    // A node path for a missing node matches no route either.
    $this->assertStringContainsString('href="http://example.com/node/999"', $html);
    $this->assertStringContainsString('src="http://example.com/sites/default/files/x.png"', $html);
    $this->assertStringContainsString('srcset="http://example.com/files/s.png 1x"', $html);
  }

}
