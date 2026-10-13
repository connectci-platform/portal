<?php

namespace Drupal\ood_software\Access;

use Drupal\content_moderation\Access\LatestRevisionCheck;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\Routing\Route;

/**
 * Access to a node's "Latest version" tab, plus Appverse reviewers.
 *
 * Content moderation lets a user see another user's pending revision only
 * with "view any unpublished content", a site-wide permission appverse_pm
 * no longer carries (appverse-planning#36). A re-submitted published repo or
 * app is exactly such a pending revision (ready_for_review is a forward
 * state), and reviewers must see it. So this keeps the site's own check
 * (core's, as decorated by access_events) and adds one allowance: an
 * appverse_pm with "view latest version" may view the latest version of an
 * Appverse repo, app or review. Every other node is decided by the site's
 * check alone. AppverseLatestVersionRouteSubscriber puts this check on
 * entity.node.latest_version in place of _content_moderation_latest_version.
 */
final class AppverseLatestVersionAccessCheck implements AccessInterface {

  const BUNDLES = ['appverse_repo', 'appverse_app', 'appverse_review'];

  public function __construct(
    protected LatestRevisionCheck $latestRevisionCheck,
    protected ModerationInformationInterface $moderationInfo,
  ) {}

  public function access(Route $route, RouteMatchInterface $route_match, AccountInterface $account): AccessResultInterface {
    $result = $this->latestRevisionCheck->access($route, $route_match, $account);
    $node = $route_match->getParameter('node');
    if (!$node instanceof NodeInterface || !in_array($node->bundle(), self::BUNDLES, TRUE)) {
      return $result;
    }
    $reviewer = AccessResult::allowedIf(
      in_array('appverse_pm', $account->getRoles(), TRUE)
      && $account->hasPermission('view latest version')
      && $this->moderationInfo->hasPendingRevision($node)
    )
      ->addCacheContexts(['user.roles'])
      ->cachePerPermissions()
      ->addCacheableDependency($node);
    return $result->orIf($reviewer);
  }

}
