<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The hub card's reviewer picker endpoint (D8-2931).
 *
 * @group ood_software
 */
class AssignReviewerTest extends KernelTestBase {

  use ProdConfigTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'options',
    'datetime', 'link', 'taxonomy', 'path', 'path_alias', 'file',
    'content_moderation', 'workflows', 'key', 'flag', 'ood_software', 'dblog',
  ];

  /**
   * The acting reviewer.
   */
  protected UserInterface $alice;

  /**
   * A second reviewer.
   */
  protected UserInterface $bob;

  /**
   * The repo's owner, a contributor.
   */
  protected UserInterface $owner;

  /**
   * A user who is not a reviewer.
   */
  protected UserInterface $outsider;

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
    $this->installSchema('user', ['users_data']);
    $this->installSchema('dblog', ['watchdog']);
    $this->installConfig(['system', 'filter', 'user', 'node', 'content_moderation', 'workflows']);
    $this->importProdConfig([
      'node.type.appverse_repo',
      'node.type.appverse_app',
      'node.type.appverse_review',
      'workflows.workflow.appverse_editorial',
      'field.storage.node.field_repo_assigned_reviewer',
      'field.field.node.appverse_repo.field_repo_assigned_reviewer',
    ]);
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('system.site')->set('name', 'Appverse')->set('mail', 'site@example.org')->save();
    Role::create(['id' => 'appverse_pm', 'label' => 'Appverse PM'])
      ->grantPermission('administer appverse content')->grantPermission('access content')->save();
    // User 1 bypasses access checks, so take that id first.
    $this->createUser([], 'admin');
    $this->alice = $this->createUser([], 'alice', FALSE, ['mail' => 'alice@example.com', 'roles' => ['appverse_pm']]);
    $this->bob = $this->createUser([], 'bob', FALSE, ['mail' => 'bob@example.com', 'roles' => ['appverse_pm']]);
    $this->owner = $this->createUser(['access content'], 'owner');
    $this->outsider = $this->createUser(['access content'], 'outsider');
    $this->setCurrentUser($this->alice);
  }

  /**
   * Creates a repo, optionally assigned.
   */
  protected function repo(?UserInterface $assignee = NULL): NodeInterface {
    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => 'example/repo',
      'uid' => $this->owner->id(),
      'moderation_state' => 'ready_for_review',
      'field_repo_assigned_reviewer' => $assignee?->id(),
    ]);
    // A runtime flag ood_software_node_update() reads, not a field.
    // @phpstan-ignore-next-line
    $repo->_ood_software_suppress_notifications = TRUE;
    $repo->save();
    return $repo;
  }

  /**
   * The repo's current assignee uid.
   */
  protected function assigneeUid(NodeInterface $repo): ?int {
    $user = $this->container->get('ood_software.review_assignment')
      ->assignee(Node::load($repo->id()));
    return $user ? (int) $user->id() : NULL;
  }

  /**
   * POSTs the picker form as the current user.
   *
   * @param \Drupal\node\NodeInterface $repo
   *   The repo.
   * @param string|null $uid
   *   The posted uid, or NULL to post nothing.
   * @param bool $json
   *   Whether to ask for JSON, as the in-place script does.
   * @param bool $token
   *   Whether to send a valid CSRF token.
   * @param string $query
   *   Extra query string.
   */
  protected function post(NodeInterface $repo, ?string $uid, bool $json = TRUE, bool $token = TRUE, string $query = ''): Response {
    $path = 'appverse/repo/' . $repo->id() . '/assign';
    $qs = $token ? 'token=' . \Drupal::csrfToken()->get($path) : '';
    $qs .= $query !== '' ? ($qs !== '' ? '&' : '') . $query : '';
    $request = Request::create('/' . $path . ($qs !== '' ? '?' . $qs : ''), 'POST', $uid === NULL ? [] : ['uid' => $uid]);
    if ($json) {
      $request->headers->set('Accept', 'application/json');
    }
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * Decodes a JSON response.
   *
   * @return array<string, mixed>
   *   The body.
   */
  protected function body(Response $response): array {
    $this->assertInstanceOf(JsonResponse::class, $response);
    return json_decode((string) $response->getContent(), TRUE);
  }

  /**
   * An administrator assigns another reviewer.
   */
  public function testAdminAssignsAnotherReviewer(): void {
    $repo = $this->repo();
    $response = $this->post($repo, (string) $this->bob->id());
    $this->assertSame(200, $response->getStatusCode());
    $body = $this->body($response);
    $this->assertSame('assigned', $body['result']);
    $this->assertSame((int) $this->bob->id(), $body['assignee']['uid']);
    $this->assertSame('bob', $body['assignee']['name']);
    $this->assertNotSame('', $body['message']);
    $this->assertSame((int) $this->bob->id(), $this->assigneeUid($repo));
  }

  /**
   * A uid outside the reviewer list is refused and nothing changes.
   */
  public function testNonReviewerUidIsRefusedAndNothingChanges(): void {
    $repo = $this->repo($this->bob);
    $response = $this->post($repo, (string) $this->outsider->id());
    $this->assertSame(422, $response->getStatusCode());
    $body = $this->body($response);
    $this->assertSame('refused', $body['result']);
    $this->assertSame((int) $this->bob->id(), $body['assignee']['uid']);
    $this->assertSame((int) $this->bob->id(), $this->assigneeUid($repo));
  }

  /**
   * An empty uid unassigns.
   */
  public function testUnassignedClears(): void {
    $repo = $this->repo($this->bob);
    $response = $this->post($repo, '');
    $this->assertSame(200, $response->getStatusCode());
    $body = $this->body($response);
    $this->assertSame('assigned', $body['result']);
    $this->assertNull($body['assignee']);
    $this->assertNull($this->assigneeUid($repo));
  }

  /**
   * Choosing the current assignee changes nothing and says so.
   */
  public function testSameAssigneeIsUnchanged(): void {
    $repo = $this->repo($this->bob);
    $response = $this->post($repo, (string) $this->bob->id());
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('unchanged', $this->body($response)['result']);
    $this->assertSame((int) $this->bob->id(), $this->assigneeUid($repo));
  }

  /**
   * Unassigning an unassigned repo is unchanged too.
   */
  public function testUnassigningAnUnassignedRepoIsUnchanged(): void {
    $repo = $this->repo();
    $response = $this->post($repo, '');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('unchanged', $this->body($response)['result']);
  }

  /**
   * A missing or expired token is refused and nothing changes.
   */
  public function testMissingTokenIsRefused(): void {
    $repo = $this->repo();
    $this->assertSame(403, $this->post($repo, (string) $this->bob->id(), TRUE, FALSE)->getStatusCode());
    $this->assertNull($this->assigneeUid($repo));
  }

  /**
   * A contributor cannot assign.
   */
  public function testContributorIsRefused(): void {
    $repo = $this->repo();
    $this->setCurrentUser($this->owner);
    $this->assertSame(403, $this->post($repo, (string) $this->bob->id())->getStatusCode());
    $this->assertNull($this->assigneeUid($repo));
  }

  /**
   * A reviewer who lost the role mid-session is refused.
   */
  public function testReviewerWhoLostTheRoleIsRefused(): void {
    $repo = $this->repo();
    $this->alice->removeRole('appverse_pm')->save();
    $this->setCurrentUser($this->alice);
    $this->assertSame(403, $this->post($repo, (string) $this->bob->id())->getStatusCode());
    $this->assertNull($this->assigneeUid($repo));
  }

  /**
   * Only repos can be assigned.
   */
  public function testNonRepoNodeIsRefused(): void {
    $app = Node::create(['type' => 'appverse_app', 'title' => 'An app', 'uid' => $this->owner->id()]);
    $app->save();
    $this->assertSame(403, $this->post($app, (string) $this->bob->id())->getStatusCode());
  }

  /**
   * Without JavaScript it redirects back to the hub with its query.
   */
  public function testNonJsRequestRedirectsBackToHub(): void {
    $repo = $this->repo();
    $destination = '/appverse/manage-repos?title=x';
    $response = $this->post($repo, (string) $this->bob->id(), FALSE, TRUE, 'destination=' . urlencode($destination));
    $this->assertSame(302, $response->getStatusCode());
    $this->assertStringEndsWith($destination, (string) $response->headers->get('Location'));
    $this->assertSame((int) $this->bob->id(), $this->assigneeUid($repo));
  }

}
