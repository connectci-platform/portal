<?php

namespace Drupal\ood_software\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Puts MyAppverseHubAccessCheck on the my_appverse user hub route.
 *
 * Adds _ood_software_my_appverse_hub alongside the view's own requirements
 * rather than replacing them, so the hub still needs "access content" and
 * the owner-or-admin rule on top. Runs after views has built its routes.
 */
final class MyAppverseHubRouteSubscriber extends RouteSubscriberBase {

  /**
   * Adds the hub access requirement to view.my_appverse.page_user.
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $route = $collection->get('view.my_appverse.page_user');
    if ($route === NULL) {
      return;
    }
    $route->setRequirement('_ood_software_my_appverse_hub', 'TRUE');
  }

}
