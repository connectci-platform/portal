<?php

declare(strict_types=1);

namespace Drupal\ood_software\EventSubscriber;

use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sends a review's node pages to the review page.
 *
 * The node's default display renders every field, the tool's recommendation
 * and the report files included, which the review page keeps from
 * contributors and the public (appverse-planning#42). /appverse/review/{nid}
 * applies the same access rules, so the review page is the only place a
 * review is shown. Runs after routing, so the node is already loaded and its
 * view access already checked.
 */
final class ReviewNodePageRedirectSubscriber implements EventSubscriberInterface {

  /**
   * Node routes that render a review with its default display.
   */
  const ROUTES = ['entity.node.canonical', 'entity.node.revision', 'entity.node.latest_version'];

  /**
   * Redirects a review's node page.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    if (!in_array($request->attributes->get('_route'), self::ROUTES, TRUE)) {
      return;
    }
    $node = $request->attributes->get('node');
    if (!$node instanceof NodeInterface || $node->bundle() !== 'appverse_review') {
      return;
    }
    $url = Url::fromRoute('ood_software.review_page', ['node' => $node->id()])->toString();
    $event->setResponse(new RedirectResponse($url));
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After the router (32), which sets _route and upcasts {node}.
    return [KernelEvents::REQUEST => [['onRequest', 30]]];
  }

}
