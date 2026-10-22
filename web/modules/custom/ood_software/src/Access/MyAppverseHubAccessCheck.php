<?php

namespace Drupal\ood_software\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;
use Symfony\Component\Routing\Route;

/**
 * Limits a My Appverse hub (user/%user/my-appverse) to its owner.
 *
 * The view's page_user display inherits "access content" from the default
 * display, which lets any authenticated user load another user's repos and
 * drafts by changing the uid in the path. This check adds the missing rule:
 * a user may load only their own hub, and "administer appverse content" may
 * load any hub. Anonymous is always denied. MyAppverseHubRouteSubscriber puts
 * this check on view.my_appverse.page_user, ANDed with the view's own
 * requirement.
 */
final class MyAppverseHubAccessCheck implements AccessInterface {

  /**
   * Allows the hub to its owner, or to anyone with administer appverse content.
   */
  public function access(Route $route, RouteMatchInterface $route_match, AccountInterface $account): AccessResultInterface {
    if ($account->isAnonymous()) {
      return AccessResult::forbidden('Anonymous users have no My Appverse hub.')
        ->cachePerUser();
    }
    $admin = AccessResult::allowedIf($account->hasPermission('administer appverse content'))
      ->cachePerPermissions();
    $owner = AccessResult::allowedIf($this->hubUid($route_match) === (int) $account->id())
      ->cachePerUser();
    return $admin->orIf($owner);
  }

  /**
   * Returns the uid of the hub being requested, or NULL if it is not known.
   */
  private function hubUid(RouteMatchInterface $route_match): ?int {
    $hub = $route_match->getParameter('user');
    if ($hub instanceof UserInterface) {
      return (int) $hub->id();
    }
    if (is_scalar($hub) && is_numeric($hub)) {
      return (int) $hub;
    }
    return NULL;
  }

}
