<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The JSON endpoint behind the "We use this App" button.
 *
 * @group ood_software
 */
class AppverseFlagEndpointTest extends KernelTestBase {

  use ProdConfigTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'options',
    'datetime', 'link', 'taxonomy', 'path', 'path_alias', 'file',
    'content_moderation', 'workflows', 'key', 'flag', 'ood_software',
  ];

  /**
   * An app.
   */
  protected NodeInterface $app;

  /**
   * A user with both flag permissions.
   */
  protected UserInterface $userA;

  /**
   * Another user with both flag permissions.
   */
  protected UserInterface $userB;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('flagging');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    $this->installSchema('flag', ['flag_counts']);
    $this->installConfig(['system', 'filter', 'user', 'node', 'content_moderation', 'workflows']);
    $this->importProdConfig([
      'node.type.appverse_app',
      'node.type.appverse_repo',
      'flag.flag.appverse_apps',
    ]);
    // User 1 bypasses access checks, so take that id first.
    $this->createUser([], 'admin');
    $permissions = ['access content', 'flag appverse_apps', 'unflag appverse_apps'];
    $this->userA = $this->createUser($permissions, 'user_a');
    $this->userB = $this->createUser($permissions, 'user_b');
    $this->app = $this->makeNode('appverse_app');
  }

  /**
   * Creates a published node of a bundle.
   */
  protected function makeNode(string $bundle): NodeInterface {
    $node = Node::create(['type' => $bundle, 'title' => 'Example', 'status' => 1, 'uid' => $this->userA?->id() ?? 0]);
    $node->save();
    return $node;
  }

  /**
   * Sends a request through the HTTP kernel as the current user.
   *
   * @param string $method
   *   The HTTP method.
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string|null $token
   *   The CSRF header value, or NULL to omit it.
   */
  protected function send(string $method, NodeInterface $node, ?string $token = NULL): Response {
    return $this->sendPath($method, '/appverse/api/flag/' . $node->id(), $token);
  }

  /**
   * Sends a request to a path through the HTTP kernel as the current user.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $path
   *   The path, without a query string.
   * @param string|null $token
   *   The CSRF header value, or NULL to omit it.
   */
  protected function sendPath(string $method, string $path, ?string $token = NULL): Response {
    // The JSON format keeps error responses from being rendered by an HTML
    // 403/404 subrequest, which reuses the method and can turn into a 405.
    $request = Request::create($path . '?_format=json', $method);
    // Core only enforces the CSRF header on requests carrying a session cookie.
    $request->cookies->set($this->container->get('session_configuration')->getOptions($request)['name'], 'test-session');
    if ($token !== NULL) {
      $request->headers->set('X-CSRF-Token', $token);
    }
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * Lists the current user's flagged apps.
   */
  protected function get(): Response {
    return $this->sendPath('GET', '/appverse/api/flags');
  }

  /**
   * A valid CSRF token, as core's request-header check expects it.
   */
  protected function token(): string {
    return $this->container->get('csrf_token')->get('X-CSRF-Token request header');
  }

  /**
   * Counts flaggings on the app, optionally for one user.
   */
  protected function flaggingCount(?UserInterface $user = NULL): int {
    $properties = ['flag_id' => 'appverse_apps', 'entity_id' => $this->app->id()];
    if ($user) {
      $properties['uid'] = $user->id();
    }
    $storage = $this->container->get('entity_type.manager')->getStorage('flagging');
    $storage->resetCache();
    return count($storage->loadByProperties($properties));
  }

  /**
   * Decodes a JSON response.
   *
   * @return array<string, mixed>
   *   The body.
   */
  protected function body(Response $response): array {
    return json_decode((string) $response->getContent(), TRUE);
  }

  /**
   * Flagging is idempotent and per user.
   */
  public function testFlagIsIdempotent(): void {
    $this->setCurrentUser($this->userA);
    $expected = ['flagged' => TRUE, 'nid' => (int) $this->app->id()];
    $response = $this->send('POST', $this->app, $this->token());
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame($expected, $this->body($response));
    $this->assertSame(1, $this->flaggingCount($this->userA));

    $response = $this->send('POST', $this->app, $this->token());
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame($expected, $this->body($response));
    $this->assertSame(1, $this->flaggingCount($this->userA));
  }

  /**
   * Unflagging is idempotent.
   */
  public function testUnflagIsIdempotent(): void {
    $this->setCurrentUser($this->userA);
    $this->send('POST', $this->app, $this->token());
    $expected = ['flagged' => FALSE, 'nid' => (int) $this->app->id()];
    $response = $this->send('DELETE', $this->app, $this->token());
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame($expected, $this->body($response));
    $this->assertSame(0, $this->flaggingCount());

    $response = $this->send('DELETE', $this->app, $this->token());
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame($expected, $this->body($response));
    $this->assertSame(0, $this->flaggingCount());
  }

  /**
   * Unflagging never touches another user's flagging.
   */
  public function testUnflagLeavesOtherUsersFlagging(): void {
    $this->setCurrentUser($this->userB);
    $this->send('POST', $this->app, $this->token());
    $this->assertSame(1, $this->flaggingCount($this->userB));

    $this->setCurrentUser($this->userA);
    $response = $this->send('DELETE', $this->app, $this->token());
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(1, $this->flaggingCount($this->userB));
    $this->assertSame(0, $this->flaggingCount($this->userA));
  }

  /**
   * Anonymous users are refused.
   */
  public function testAnonymousForbidden(): void {
    $this->setCurrentUser(new AnonymousUserSession());
    $this->assertSame(403, $this->send('POST', $this->app, $this->token())->getStatusCode());
    $this->assertSame(0, $this->flaggingCount());
  }

  /**
   * Users without the flag permissions are refused.
   */
  public function testMissingPermissionForbidden(): void {
    $this->setCurrentUser($this->createUser(['access content'], 'no_perms'));
    $this->assertSame(403, $this->send('POST', $this->app, $this->token())->getStatusCode());
    $this->assertSame(403, $this->send('DELETE', $this->app, $this->token())->getStatusCode());
    $this->assertSame(0, $this->flaggingCount());
  }

  /**
   * A missing or invalid CSRF header token is refused.
   */
  public function testCsrfTokenRequired(): void {
    $this->setCurrentUser($this->userA);
    $this->assertSame(403, $this->send('POST', $this->app)->getStatusCode());
    $this->assertSame(403, $this->send('POST', $this->app, 'bogus')->getStatusCode());
    $this->assertSame(403, $this->send('DELETE', $this->app, 'bogus')->getStatusCode());
    $this->assertSame(0, $this->flaggingCount());
  }

  /**
   * Nodes of other bundles are a 404.
   */
  public function testOtherBundleNotFound(): void {
    $this->setCurrentUser($this->userA);
    $repo = $this->makeNode('appverse_repo');
    $this->assertSame(404, $this->send('POST', $repo, $this->token())->getStatusCode());
    $this->assertSame(404, $this->send('DELETE', $repo, $this->token())->getStatusCode());
  }

  /**
   * The list contains only the current user's own flagged apps.
   */
  public function testFlagsListsOwnFlagsOnly(): void {
    $app2 = $this->makeNode('appverse_app');
    $this->setCurrentUser($this->userA);
    $this->send('POST', $this->app, $this->token());
    $this->setCurrentUser($this->userB);
    $this->send('POST', $app2, $this->token());

    $this->setCurrentUser($this->userA);
    $response = $this->get();
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['flagged' => [['uuid' => $this->app->uuid(), 'nid' => (int) $this->app->id()]]], $this->body($response));

    $this->setCurrentUser($this->userB);
    $response = $this->get();
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['flagged' => [['uuid' => $app2->uuid(), 'nid' => (int) $app2->id()]]], $this->body($response));
  }

  /**
   * A user with nothing flagged gets an empty list.
   */
  public function testFlagsEmpty(): void {
    $this->setCurrentUser($this->userA);
    $response = $this->get();
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['flagged' => []], $this->body($response));
  }

  /**
   * Unflagging removes the app from the list.
   */
  public function testFlagsReflectsUnflag(): void {
    $this->setCurrentUser($this->userA);
    $this->send('POST', $this->app, $this->token());
    $this->assertCount(1, $this->body($this->get())['flagged']);
    $this->send('DELETE', $this->app, $this->token());
    $response = $this->get();
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['flagged' => []], $this->body($response));
  }

  /**
   * Anonymous users cannot read the list.
   */
  public function testFlagsAnonymousForbidden(): void {
    $this->setCurrentUser(new AnonymousUserSession());
    $this->assertSame(403, $this->get()->getStatusCode());
  }

  /**
   * Users without the flag permission cannot read the list.
   */
  public function testFlagsMissingPermissionForbidden(): void {
    $this->setCurrentUser($this->createUser(['access content'], 'no_perms'));
    $this->assertSame(403, $this->get()->getStatusCode());
  }

  /**
   * Apps the user can no longer view are not listed.
   */
  public function testFlagsExcludesUnpublishedApp(): void {
    // Owned by another user so "view own unpublished content" cannot apply.
    $app = Node::create(['type' => 'appverse_app', 'title' => 'Other', 'status' => 1, 'uid' => $this->userB->id()]);
    $app->save();
    $this->setCurrentUser($this->userA);
    $this->send('POST', $app, $this->token());
    $this->assertCount(1, $this->body($this->get())['flagged']);

    $app->setUnpublished()->save();
    // Within one process the access handler caches the earlier "allowed".
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $response = $this->get();
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['flagged' => []], $this->body($response));
  }

}
