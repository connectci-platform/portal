<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\ood_software\Kernel\Traits\AppverseModerationTestTrait;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The reviewer-only review-status endpoint and the card's in-flight rule.
 *
 * @group ood_software
 */
class ReviewStatusEndpointTest extends KernelTestBase {

  use AppverseModerationTestTrait;
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
   * The repo's owner, the contributor.
   */
  protected UserInterface $owner;

  /**
   * A reviewer.
   */
  protected UserInterface $reviewer;

  /**
   * The review service, with GitHub replaced.
   */
  protected StubDispatchReviewService $dispatcher;

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
    $this->installConfig(['system', 'filter', 'user', 'node', 'content_moderation', 'workflows']);
    $this->importProdConfig([
      'node.type.appverse_repo',
      'node.type.appverse_app',
      'node.type.appverse_review',
      'workflows.workflow.appverse_editorial',
      'field.storage.node.field_repo_url',
      'field.field.node.appverse_repo.field_repo_url',
      'field.storage.node.field_review_status',
      'field.field.node.appverse_repo.field_review_status',
      'field.storage.node.field_review_dispatched_at',
      'field.field.node.appverse_repo.field_review_dispatched_at',
      'field.storage.node.field_review_dispatched_by',
      'field.field.node.appverse_repo.field_review_dispatched_by',
      'field.storage.node.field_review_recommendation',
      'field.field.node.appverse_repo.field_review_recommendation',
      'field.storage.node.field_review_run_id',
      'field.field.node.appverse_repo.field_review_run_id',
      'field.storage.node.field_arv_repo',
      'field.field.node.appverse_review.field_arv_repo',
      'field.storage.node.field_arv_decision_sent_at',
      'field.field.node.appverse_review.field_arv_decision_sent_at',
      'field.storage.node.field_arv_recommendation',
      'field.field.node.appverse_review.field_arv_recommendation',
      'field.storage.node.field_arv_withdrawn_at',
      'field.field.node.appverse_review.field_arv_withdrawn_at',
    ]);
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('system.site')->set('name', 'Appverse')->set('mail', 'site@example.org')->save();
    // User 1 bypasses access checks, so take that id first.
    $this->createUser([], 'admin');
    $this->owner = $this->createUser(['access content'], 'owner');
    $this->reviewer = $this->createUser(['access content', 'administer appverse content'], 'reviewer');
    $this->setCurrentUser($this->owner);

    $this->useDispatcher();
  }

  /**
   * Swaps in a stub review service with a real lock.
   */
  protected function useDispatcher(): void {
    $this->dispatcher = new StubDispatchReviewService(
      $this->container->get('http_client'),
      $this->container->get('key.repository'),
      $this->container->get('logger.factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('datetime.time'),
      $this->container->get('file_system'),
      $this->container->get('ood_software.review_seeder'),
      $this->container->get('current_user'),
      $this->container->get('ood_software.repo_notifier'),
      $this->container->get('ood_software.in_place_saver'),
      new DatabaseLockBackend(\Drupal::database()),
      $this->container->get('keyvalue'),
      $this->container->get('keyvalue.expirable'),
    );
    $this->container->set('ood_software.review_dispatcher', $this->dispatcher);
  }

  /**
   * A repo with a pending run, dispatched now.
   */
  protected function repoInFlight(): NodeInterface {
    $repo = $this->makeRepo('draft');
    $repo->set('moderation_state', 'ready_for_review')->save();
    $this->assertTrue($this->dispatcher->dispatchForNode(Node::load($repo->id())));
    $repo = $this->latest(Node::load($repo->id()));
    $this->assertSame('pending', $repo->get('field_review_status')->value);
    return $repo;
  }

  /**
   * Sets fields on the repo's latest revision in place.
   *
   * @param \Drupal\node\NodeInterface $repo
   *   The repo.
   * @param array<string, mixed> $values
   *   Field values by name.
   */
  protected function setOnRepo(NodeInterface $repo, array $values): void {
    $this->container->get('ood_software.in_place_saver')->updateRevisions($repo, function (NodeInterface $revision) use ($values): void {
      foreach ($values as $field => $value) {
        $revision->set($field, $value);
      }
    });
  }

  /**
   * Creates a review of the repo.
   *
   * @param \Drupal\node\NodeInterface $repo
   *   The repo.
   */
  protected function makeReview(NodeInterface $repo): NodeInterface {
    $review = Node::create([
      'type' => 'appverse_review',
      'title' => 'Review: example/app',
      'uid' => $this->reviewer->id(),
      'status' => 1,
      'field_arv_repo' => $repo->id(),
    ]);
    $review->save();
    return $review;
  }

  /**
   * Requests the endpoint as the current user.
   */
  protected function get(int $nid, string $query = ''): Response {
    $request = Request::create('/appverse/repo/' . $nid . '/review-status' . $query);
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * Decodes a JSON response.
   *
   * @return array<string, mixed>
   *   The body.
   */
  protected function json(Response $response): array {
    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertSame(200, $response->getStatusCode());
    return json_decode((string) $response->getContent(), TRUE);
  }

  /**
   * Anonymous visitors and contributors are refused.
   */
  public function testAnonymousAndContributorGet403(): void {
    $repo = $this->repoInFlight();
    $this->setCurrentUser(User::getAnonymousUser());
    $this->assertSame(403, $this->get((int) $repo->id())->getStatusCode());
    $this->setCurrentUser($this->owner);
    $this->assertSame(403, $this->get((int) $repo->id())->getStatusCode());
  }

  /**
   * A reviewer gets every key, uncached.
   */
  public function testReviewerGets200WithEveryKey(): void {
    $repo = $this->repoInFlight();
    $this->setCurrentUser($this->reviewer);
    $response = $this->get((int) $repo->id());
    $body = $this->json($response);
    $keys = ['in_flight', 'status', 'latest_review', 'latest_review_url', 'step_label', 'review_url'];
    $this->assertEqualsCanonicalizing($keys, array_keys($body));
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
  }

  /**
   * A node that is not a repo is refused.
   */
  public function testNonRepoNodeIsRefused(): void {
    $app = Node::create(['type' => 'appverse_app', 'title' => 'An app', 'uid' => $this->owner->id()]);
    $app->save();
    $this->setCurrentUser($this->reviewer);
    $this->assertContains($this->get((int) $app->id())->getStatusCode(), [403, 404]);
  }

  /**
   * A fresh pending run is in flight.
   */
  public function testInFlightForFreshPending(): void {
    $repo = $this->repoInFlight();
    $this->setCurrentUser($this->reviewer);
    $body = $this->json($this->get((int) $repo->id()));
    $this->assertTrue($body['in_flight']);
    $this->assertSame('pending', $body['status']);
    $this->assertNull($body['latest_review']);
    $this->assertNull($body['review_url']);
    $this->assertNotSame('', $body['step_label']);
  }

  /**
   * A pending status older than the stale timeout is not in flight.
   */
  public function testNotInFlightWhenStale(): void {
    $repo = $this->repoInFlight();
    $this->setOnRepo($repo, ['field_review_dispatched_at' => \Drupal::time()->getCurrentTime() - 7201]);
    $this->setCurrentUser($this->reviewer);
    $body = $this->json($this->get((int) $repo->id()));
    $this->assertFalse($body['in_flight']);
    $this->assertSame('pending', $body['status']);
  }

  /**
   * A complete run is not in flight.
   */
  public function testNotInFlightWhenComplete(): void {
    $repo = $this->repoInFlight();
    $this->setOnRepo($repo, ['field_review_status' => 'complete']);
    $this->setCurrentUser($this->reviewer);
    $body = $this->json($this->get((int) $repo->id()));
    $this->assertFalse($body['in_flight']);
    $this->assertSame('complete', $body['status']);
  }

  /**
   * The newest review is reported, with its link.
   */
  public function testLatestReviewIsNewest(): void {
    $repo = $this->repoInFlight();
    $this->makeReview($repo);
    $newer = $this->makeReview($repo);
    $this->setCurrentUser($this->reviewer);
    $body = $this->json($this->get((int) $repo->id()));
    $this->assertSame((int) $newer->id(), $body['latest_review']);
    $this->assertSame('/appverse/review/' . $newer->id(), $body['latest_review_url']);
    $this->assertSame($body['latest_review_url'], $body['review_url']);
  }

  /**
   * GitHub is asked only when the caller says check=github.
   */
  public function testCheckGithubCallsPollRepoOnlyWhenAsked(): void {
    $repo = $this->repoInFlight();
    $this->setCurrentUser($this->reviewer);
    $this->get((int) $repo->id());
    $this->assertSame(0, $this->dispatcher->fetchCalls);
    $this->get((int) $repo->id(), '?check=github');
    $this->assertGreaterThan(0, $this->dispatcher->fetchCalls);
  }

  /**
   * The card offers Run AI report on a stale pending repo.
   */
  public function testHubCardShowsRunReportForStalePending(): void {
    $repo = $this->repoInFlight();
    $this->setOnRepo($repo, ['field_review_dispatched_at' => \Drupal::time()->getCurrentTime() - 7201]);
    $this->setCurrentUser($this->reviewer);
    $info = _ood_software_hub_review_info($this->latest($repo));
    $this->assertNotNull($info['run_review_action']);
  }

  /**
   * The card withholds Run AI report while a run is in flight.
   */
  public function testHubCardHidesRunReportWhileInFlight(): void {
    $repo = $this->repoInFlight();
    $this->setCurrentUser($this->reviewer);
    $info = _ood_software_hub_review_info($this->latest($repo));
    $this->assertNull($info['run_review_action']);
  }

}
