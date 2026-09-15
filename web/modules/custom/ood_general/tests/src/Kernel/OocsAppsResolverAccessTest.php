<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_general\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Regression guard for OocsAppsResolver's node-access gating.
 *
 * This is the access gate that was wrong in July (D8-2723): buildItems() must
 * never disclose an appverse_app the current user cannot view, and buildItem()
 * must never render a "half item" for an app whose related software the
 * viewer cannot see.
 *
 * @group ood_general
 */
class OocsAppsResolverAccessTest extends KernelTestBase {

  use ProdConfigTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'key',
    'flag',
    'ood_software',
    'ood_general',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'user', 'node']);

    $this->importProdConfig([
      'node.type.appverse_app',
      'node.type.appverse_software',
      'field.storage.node.field_appverse_software_implemen',
      'field.field.node.appverse_app.field_appverse_software_implemen',
    ]);

    user_role_grant_permissions('anonymous', ['access content']);

    // Burn uid 1: it bypasses every permission check regardless of role, so
    // any admin-ish user created below must NOT land on it or the "bypass
    // node access" assertion would pass for the wrong reason.
    $this->createUser();
  }

  /**
   * The apps-used access gate: empty for anonymous, one item for bypass.
   *
   * This is the exact scenario that regressed in July.
   */
  public function testUnpublishedAppDeniesAnonymousAllowsBypass(): void {
    $software = Node::create([
      'type' => 'appverse_software',
      'title' => 'Jupyter',
      'status' => 1,
    ]);
    $software->save();

    $app = Node::create([
      'type' => 'appverse_app',
      'title' => 'Jupyter App',
      'field_appverse_software_implemen' => $software->id(),
      'status' => 0,
    ]);
    $app->save();

    $resolver = \Drupal::service('ood_general.oocs_apps_resolver');

    \Drupal::currentUser()->setAccount(new AnonymousUserSession());
    $tags = [];
    $this->assertSame([], $resolver->buildItems([$app], $tags), 'Anonymous must not see an unpublished app.');

    $bypassUser = $this->createUser(['bypass node access']);
    \Drupal::currentUser()->setAccount($bypassUser);
    $tags = [];
    $this->assertCount(1, $resolver->buildItems([$app], $tags), 'A user with bypass node access must see the unpublished app.');
  }

  /**
   * Related-software gating: unpublished software hides the (published) app.
   *
   * No half-item with a missing logo — the app is dropped entirely.
   */
  public function testUnpublishedRelatedSoftwareHidesAppForAnonymous(): void {
    $software = Node::create([
      'type' => 'appverse_software',
      'title' => 'Jupyter',
      'status' => 0,
    ]);
    $software->save();

    $app = Node::create([
      'type' => 'appverse_app',
      'title' => 'Jupyter App',
      'field_appverse_software_implemen' => $software->id(),
      'status' => 1,
    ]);
    $app->save();

    \Drupal::currentUser()->setAccount(new AnonymousUserSession());
    $resolver = \Drupal::service('ood_general.oocs_apps_resolver');
    $tags = [];
    $this->assertSame(
      [],
      $resolver->buildItems([$app], $tags),
      'An app whose related software is unpublished must not be rendered, not rendered half-built.'
    );
  }

}
