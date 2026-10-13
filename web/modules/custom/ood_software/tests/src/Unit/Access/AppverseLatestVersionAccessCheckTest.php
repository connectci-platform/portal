<?php

namespace Drupal\Tests\ood_software\Unit\Access;

use Drupal\content_moderation\Access\LatestRevisionCheck;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ood_software\Access\AppverseLatestVersionAccessCheck;
use Symfony\Component\Routing\Route;

/**
 * Who may open a node's "Latest version" tab.
 *
 * appverse_pm no longer has the site-wide "view any unpublished content"
 * (appverse-planning#36), so the site's own check refuses a reviewer the
 * pending revision of a re-submitted repo or app; this check adds that back
 * for Appverse content only.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Access\AppverseLatestVersionAccessCheck
 */
class AppverseLatestVersionAccessCheckTest extends UnitTestCase {

  protected function setUp(): void {
    parent::setUp();
    // AccessResult cache contexts are validated through the container.
    $contexts = $this->createMock(CacheContextsManager::class);
    $contexts->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $contexts);
    \Drupal::setContainer($container);
  }

  /**
   * @covers ::access
   * @dataProvider cases
   */
  public function testAccess(string $bundle, array $roles, bool $hasPermission, bool $pending, bool $siteAllows, bool $expected): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn($bundle);
    $node->method('getCacheContexts')->willReturn([]);
    $node->method('getCacheTags')->willReturn(['node:1']);
    $node->method('getCacheMaxAge')->willReturn(-1);
    $routeMatch = $this->createMock(RouteMatchInterface::class);
    $routeMatch->method('getParameter')->with('node')->willReturn($node);
    $account = $this->createMock(AccountInterface::class);
    $account->method('getRoles')->willReturn($roles);
    $account->method('hasPermission')->with('view latest version')->willReturn($hasPermission);
    // As core's check (and access_events' copy) answers: forbidden with no
    // pending revision; with one, allowed for a permitted user and neutral
    // for the rest, which the reviewer allowance can then turn to allowed.
    $site = $this->createMock(LatestRevisionCheck::class);
    $site->method('access')->willReturn(match (TRUE) {
      !$pending => AccessResult::forbidden(),
      $siteAllows => AccessResult::allowed(),
      default => AccessResult::neutral(),
    });
    $moderation = $this->createMock(ModerationInformationInterface::class);
    $moderation->method('hasPendingRevision')->willReturn($pending);

    $check = new AppverseLatestVersionAccessCheck($site, $moderation);
    $result = $check->access(new Route('/node/{node}/latest'), $routeMatch, $account);

    $this->assertSame($expected, $result->isAllowed());
  }

  public static function cases(): array {
    $pm = ['authenticated', 'appverse_pm'];
    return [
      'reviewer, re-submitted repo' => ['appverse_repo', $pm, TRUE, TRUE, FALSE, TRUE],
      'reviewer, pending app' => ['appverse_app', $pm, TRUE, TRUE, FALSE, TRUE],
      'reviewer, pending review' => ['appverse_review', $pm, TRUE, TRUE, FALSE, TRUE],
      'reviewer, no pending revision' => ['appverse_repo', $pm, TRUE, FALSE, FALSE, FALSE],
      'reviewer without view latest version' => ['appverse_repo', $pm, FALSE, TRUE, FALSE, FALSE],
      'reviewer, not Appverse content' => ['page', $pm, TRUE, TRUE, FALSE, FALSE],
      'other user, Appverse content' => ['appverse_repo', ['authenticated'], TRUE, TRUE, FALSE, FALSE],
      'site check allows (e.g. the owner)' => ['appverse_repo', ['authenticated'], FALSE, TRUE, TRUE, TRUE],
      'site check allows, not Appverse' => ['page', ['authenticated'], FALSE, TRUE, TRUE, TRUE],
      'no pending revision: the tab stays hidden' => ['appverse_repo', $pm, TRUE, FALSE, TRUE, FALSE],
    ];
  }

}
