<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\Routing\Route;

/**
 * Covers who may load a user's My Appverse hub (view.my_appverse.page_user).
 *
 * The page_user display inherits "access content" from the view's default
 * display, so before this check any authenticated user could load another
 * user's hub at /user/<uid>/my-appverse. The route now also requires
 * ood_software's hub access check: a user may load only their own hub, and
 * "administer appverse content" may load any hub. Anonymous is always denied.
 *
 * @group ood_software
 */
class MyAppverseHubAccessTest extends KernelTestBase {

  use ProdConfigTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'options',
    'datetime', 'link', 'taxonomy', 'path', 'path_alias',
    'content_moderation', 'workflows', 'key', 'flag', 'views', 'file', 'ood_software',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig([
      'system', 'filter', 'user', 'node',
      'content_moderation', 'workflows',
    ]);

    $this->importProdConfig([
      'node.type.appverse_repo',
      'node.type.appverse_app',
      'workflows.workflow.appverse_editorial',
      'field.storage.node.field_appverse_repo',
      'field.field.node.appverse_app.field_appverse_repo',
    ]);
    $this->importViewConfigRaw('views.view.my_appverse');
    // The route is built from the view config written above, so rebuild the
    // router before the route is looked up.
    \Drupal::service('router.builder')->rebuild();

    // Burn uid 1, which bypasses every access check.
    $this->createUser();
  }

  /**
   * Writes a view config object directly to storage, bypassing entity save.
   *
   * Same approach as ManageReposFilterDefaultsTest: the page_user display has
   * config schema gaps that trip ConfigSchemaChecker on Config::save(), but
   * the view loads and routes normally once written to storage.
   */
  private function importViewConfigRaw(string $name): void {
    $source = new FileStorage(DRUPAL_ROOT . '/sites/default/config/default');
    $data = $source->read($name);
    if ($data === FALSE) {
      throw new \RuntimeException("Missing prod config: $name");
    }
    \Drupal::service('config.storage')->write($name, $data);
  }

  /**
   * Checks whether an account may load the hub of the given user.
   */
  private function canLoadHub(AccountInterface $account, int $hubUid): bool {
    return \Drupal::service('access_manager')->checkNamedRoute(
      'view.my_appverse.page_user',
      ['user' => $hubUid],
      $account,
    );
  }

  /**
   * Asserts the page_user route exists, so the access checks are not vacuous.
   */
  public function testRouteExists(): void {
    $route = \Drupal::service('router.route_provider')->getRouteByName('view.my_appverse.page_user');
    $this->assertInstanceOf(Route::class, $route);
    $this->assertSame('/user/{user}/my-appverse', $route->getPath());
  }

  /**
   * A non-admin authenticated user may not load another user's hub.
   */
  public function testNonAdminCannotLoadAnotherUsersHub(): void {
    $owner = $this->createUser(['access content']);
    $viewer = $this->createUser(['access content']);
    $this->assertFalse($this->canLoadHub($viewer, (int) $owner->id()));
  }

  /**
   * A non-admin authenticated user may load their own hub.
   */
  public function testNonAdminCanLoadOwnHub(): void {
    $viewer = $this->createUser(['access content']);
    $this->assertTrue($this->canLoadHub($viewer, (int) $viewer->id()));
  }

  /**
   * An admin may load any user's hub.
   */
  public function testAdminCanLoadAnotherUsersHub(): void {
    $owner = $this->createUser(['access content']);
    $admin = $this->createUser(['access content', 'administer appverse content']);
    $this->assertTrue($this->canLoadHub($admin, (int) $owner->id()));
  }

  /**
   * An anonymous visitor may not load any hub.
   */
  public function testAnonymousCannotLoadHub(): void {
    $owner = $this->createUser(['access content']);
    $this->assertFalse($this->canLoadHub(new AnonymousUserSession(), (int) $owner->id()));
  }

  /**
   * A uid that is not plain digits does not make the viewer the hub's owner.
   *
   * @dataProvider nonDigitUidProvider
   */
  public function testNonDigitUidDoesNotGrantOwnerAccess(string $uid): void {
    $viewer = $this->createUser(['access content']);
    $route = \Drupal::service('router.route_provider')->getRouteByName('view.my_appverse.page_user');
    $match = new RouteMatch('view.my_appverse.page_user', $route, [], ['user' => $uid]);
    $check = \Drupal::service('ood_software.my_appverse_hub_access');
    $this->assertFalse($check->access($route, $match, $viewer)->isAllowed());
  }

  /**
   * Values that numeric-looking checks accept but are not uids.
   */
  public static function nonDigitUidProvider(): array {
    return [
      'float' => ['1.0'],
      'exponent' => ['1e1'],
      'leading space' => [' 1'],
      'negative' => ['-1'],
    ];
  }

  /**
   * The hub restriction is bypassed by AJAX views, so use_ajax must stay off.
   */
  public function testViewUsesNoAjax(): void {
    $view = \Drupal::entityTypeManager()->getStorage('view')->load('my_appverse');
    $this->assertNotNull($view);
    $default = $view->getDisplay('default');
    $this->assertFalse(!empty($default['display_options']['use_ajax']));
    $page = $view->getDisplay('page_user');
    $this->assertFalse(!empty($page['display_options']['use_ajax']));
  }

}
