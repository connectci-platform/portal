<?php

namespace Drupal\ood_software\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Puts AppverseLatestVersionAccessCheck on the node "Latest version" route.
 *
 * Replaces the route's _content_moderation_latest_version requirement with
 * _ood_software_latest_version, whose check runs the site's own
 * latest-revision check and adds the Appverse reviewer allowance. A second
 * check on the same requirement would be ANDed with it and could only
 * narrow access, so the requirement itself is swapped. Only nodes are
 * touched; other entity types keep content moderation's check.
 */
final class AppverseLatestVersionRouteSubscriber extends RouteSubscriberBase {

  protected function alterRoutes(RouteCollection $collection): void {
    $route = $collection->get('entity.node.latest_version');
    if ($route === NULL || !$route->hasRequirement('_content_moderation_latest_version')) {
      return;
    }
    $requirements = $route->getRequirements();
    unset($requirements['_content_moderation_latest_version']);
    $requirements['_ood_software_latest_version'] = 'TRUE';
    $route->setRequirements($requirements);
  }

}
