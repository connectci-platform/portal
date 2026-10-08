<?php

namespace Drupal\Tests\ood_software\Unit\EventSubscriber;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\node\NodeInterface;
use Drupal\ood_software\EventSubscriber\ReviewNodePageRedirectSubscriber;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A review's node pages go to the review page (appverse-planning#42).
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\EventSubscriber\ReviewNodePageRedirectSubscriber
 */
class ReviewNodePageRedirectSubscriberTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $generator = $this->createMock(UrlGeneratorInterface::class);
    $generator->method('generateFromRoute')->willReturnCallback(
      fn (string $route, array $params = [], array $options = [], bool $collect = FALSE) => $collect
        ? (new GeneratedUrl())->setGeneratedUrl('/appverse/review/' . $params['node'])
        : '/appverse/review/' . $params['node']
    );
    $container = new ContainerBuilder();
    $container->set('url_generator', $generator);
    \Drupal::setContainer($container);
  }

  /**
   * Dispatches a main request for $route with $bundle as the node.
   */
  protected function dispatch(string $route, string $bundle): RequestEvent {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn($bundle);
    $node->method('id')->willReturn(42);
    $request = new Request();
    $request->attributes->set('_route', $route);
    $request->attributes->set('node', $node);
    $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    (new ReviewNodePageRedirectSubscriber())->onRequest($event);
    return $event;
  }

  /**
   * @covers ::onRequest
   */
  public function testAReviewsNodePagesRedirect(): void {
    foreach (ReviewNodePageRedirectSubscriber::ROUTES as $route) {
      $response = $this->dispatch($route, 'appverse_review')->getResponse();
      $this->assertInstanceOf(RedirectResponse::class, $response, $route);
      $this->assertSame('/appverse/review/42', $response->getTargetUrl(), $route);
    }
  }

  /**
   * @covers ::onRequest
   */
  public function testOtherNodesAndRoutesAreLeftAlone(): void {
    $this->assertNull($this->dispatch('entity.node.canonical', 'appverse_app')->getResponse(), 'another bundle');
    $this->assertNull($this->dispatch('entity.node.edit_form', 'appverse_review')->getResponse(), 'the edit form');
  }

}
