<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Form\ReviewPageForm;
use Drupal\ood_software\Form\ReviewRerunConfirmForm;
use Drupal\Tests\ood_software\Kernel\Traits\AppverseModerationTestTrait;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Rerun report from the review page (D8-2931).
 *
 * @group ood_software
 */
class ReviewRerunTest extends KernelTestBase {

  use AppverseModerationTestTrait;
  use ProdConfigTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'options',
    'datetime', 'link', 'taxonomy', 'path', 'path_alias', 'file',
    'content_moderation', 'workflows', 'key', 'flag',
    'entity_reference_revisions', 'paragraphs', 'ood_software',
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
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'filter', 'user', 'node', 'content_moderation', 'workflows']);
    $names = [
      'node.type.appverse_repo',
      'node.type.appverse_app',
      'node.type.appverse_review',
      'workflows.workflow.appverse_editorial',
      'workflows.workflow.appverse_review_workflow',
      'paragraphs.paragraphs_type.review_verdict',
      'paragraphs.paragraphs_type.review_finding',
      'paragraphs.paragraphs_type.review_note',
      'field.storage.node.field_appverse_repo',
      'field.field.node.appverse_app.field_appverse_repo',
    ];
    $dir = DRUPAL_ROOT . '/sites/default/config/default';
    $patterns = [
      'storage.node.field_arv_',
      'field.node.appverse_review.',
      'storage.node.field_review_',
      'field.node.appverse_repo.field_review_',
      'storage.node.field_repo_url',
      'field.node.appverse_repo.field_repo_url',
      'storage.paragraph.field_rv',
      'field.paragraph.review_',
    ];
    foreach ($patterns as $pattern) {
      foreach (glob($dir . '/field.' . $pattern . '*.yml') as $file) {
        $names[] = basename($file, '.yml');
      }
    }
    $this->importProdConfig(array_values(array_unique($names)));
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('system.site')->set('name', 'Appverse')->set('mail', 'site@example.org')->save();
    // User 1 bypasses access checks, so take that id first.
    $this->createUser([], 'admin');
    $this->owner = $this->createUser(['access content'], 'owner');
    $this->reviewer = $this->createUser(['access content', 'administer appverse content'], 'reviewer');
    $this->setCurrentUser($this->reviewer);

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
   * A repo with no run in flight.
   */
  protected function repoIdle(): NodeInterface {
    $repo = $this->makeRepo('draft');
    $repo->set('moderation_state', 'ready_for_review')->save();
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
   * Creates an undecided review of the repo.
   */
  protected function makeReview(NodeInterface $repo): NodeInterface {
    $review = Node::create([
      'type' => 'appverse_review',
      'title' => 'Review: example/app',
      'uid' => $this->reviewer->id(),
      'status' => 0,
      'field_arv_repo' => $repo->id(),
    ]);
    $review->save();
    return $review;
  }

  /**
   * Builds the review page form for the current user.
   *
   * @return array<string, mixed>
   *   The form render array.
   */
  protected function buildPage(NodeInterface $review): array {
    $request = Request::create('/appverse/review/' . $review->id());
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    $form = $this->container->get('form_builder')->getForm(ReviewPageForm::class, Node::load($review->id()));
    $this->container->get('request_stack')->pop();
    return $form;
  }

  /**
   * The page form object, for rerunState().
   */
  protected function pageForm(): ReviewPageForm {
    return ReviewPageForm::create($this->container);
  }

  /**
   * The Rerun button shows on the newest undecided review when idle.
   */
  public function testShownOnNewestUndecidedReviewWhenIdle(): void {
    $repo = $this->repoIdle();
    $review = $this->makeReview($repo);
    $state = $this->pageForm()->rerunState($review, $repo);
    $this->assertTrue($state['eligible']);
    $this->assertTrue($state['show']);
    $this->assertFalse($state['in_flight']);
    $this->assertSame(Cache::PERMANENT, $state['max_age']);
    $form = $this->buildPage($review);
    $this->assertNotEmpty($form['#page']['rerun']);
    $this->assertTrue($form['#page']['rerun']['show']);
    $this->assertSame('/appverse/review/' . $review->id() . '/rerun', $form['#page']['rerun']['url']);
    $this->assertArrayNotHasKey('oodReviewStatus', $form['#attached']['drupalSettings'] ?? []);
  }

  /**
   * The Rerun button is hidden while a run is in flight.
   */
  public function testHiddenWhileRunInFlight(): void {
    $repo = $this->repoInFlight();
    $review = $this->makeReview($repo);
    $state = $this->pageForm()->rerunState($review, $repo);
    $this->assertTrue($state['eligible']);
    $this->assertFalse($state['show']);
    $this->assertTrue($state['in_flight']);
    $this->assertSame('pending', $state['status']);
    $form = $this->buildPage($review);
    // Rendered but hidden, so the poller can reveal it.
    $this->assertFalse($form['#page']['rerun']['show']);
    $this->assertTrue($form['#page']['run_status']['in_flight']);
    $this->assertSame('AI report queued', $form['#page']['run_status']['label']);
    $settings = $form['#attached']['drupalSettings']['oodReviewStatus'];
    $this->assertTrue($settings['inFlight']);
    $this->assertSame((int) $review->id(), $settings['review']);
    $this->assertSame((int) $review->id(), $settings['latestAtLoad']);
    $this->assertSame('/appverse/repo/' . $repo->id() . '/review-status?check=github', $settings['url']);
  }

  /**
   * A superseded review's poller knows the newest review at page load.
   *
   * Without it, a failed run would offer "New report ready" pointing at a
   * report that already existed when the page loaded.
   */
  public function testSupersededReviewCarriesLatestAtLoad(): void {
    $repo = $this->repoInFlight();
    $older = $this->makeReview($repo);
    $newer = $this->makeReview($repo);
    $state = $this->pageForm()->rerunState($older, $repo);
    $this->assertFalse($state['eligible']);
    $this->assertSame((int) $newer->id(), $state['latest_review']);
    $settings = $this->buildPage($older)['#attached']['drupalSettings']['oodReviewStatus'];
    $this->assertSame((int) $older->id(), $settings['review']);
    $this->assertSame((int) $newer->id(), $settings['latestAtLoad']);
  }

  /**
   * The running status label shows while a run is in flight.
   */
  public function testRunningStatusLabel(): void {
    $repo = $this->repoInFlight();
    $review = $this->makeReview($repo);
    $this->setOnRepo($repo, ['field_review_status' => 'in_progress']);
    $form = $this->buildPage($review);
    $this->assertSame('AI report running', $form['#page']['run_status']['label']);
  }

  /**
   * The Rerun button shows when the pending run is stale.
   */
  public function testShownWhenPendingIsStale(): void {
    $repo = $this->repoInFlight();
    $review = $this->makeReview($repo);
    $this->setOnRepo($repo, ['field_review_dispatched_at' => \Drupal::time()->getCurrentTime() - 7201]);
    $state = $this->pageForm()->rerunState($review, $repo);
    $this->assertFalse($state['in_flight']);
    $this->assertTrue($state['show']);
    $this->assertSame(Cache::PERMANENT, $state['max_age']);
  }

  /**
   * The Rerun button is hidden on a superseded review.
   */
  public function testHiddenOnSupersededReview(): void {
    $repo = $this->repoIdle();
    $old = $this->makeReview($repo);
    $this->makeReview($repo);
    $state = $this->pageForm()->rerunState($old, $repo);
    $this->assertFalse($state['eligible']);
    $this->assertFalse($state['show']);
    $this->assertEmpty($this->buildPage($old)['#page']['rerun']);
  }

  /**
   * The Rerun button is hidden on a decided review.
   */
  public function testHiddenOnDecidedReview(): void {
    $repo = $this->repoIdle();
    $review = $this->makeReview($repo);
    $review->set('field_arv_decision_sent_at', \Drupal::time()->getCurrentTime())->save();
    $this->assertFalse($this->pageForm()->rerunState($review, $repo)['eligible']);

    $published = $this->makeReview($repo);
    $published->set('moderation_state', 'published')->save();
    $this->assertTrue(Node::load($published->id())->isPublished());
    $this->assertTrue($published->get('field_arv_decision_sent_at')->isEmpty());
    $this->assertFalse($this->pageForm()->rerunState(Node::load($published->id()), $repo)['eligible']);
  }

  /**
   * The markup carries the hooks the settings poller needs.
   */
  public function testRenderedMarkupCarriesTheHooksTheSettingsPollerNeeds(): void {
    $repo = $this->repoIdle();
    $review = $this->makeReview($repo);
    $form = $this->buildPage($review);
    $html = (string) \Drupal::service('renderer')->renderInIsolation($form);
    $this->assertMatchesRegularExpression('/<a [^>]*href="[^"]*\/rerun"[^>]*data-review-rerun(?![^>]*hidden)[^>]*>/', $html);
    $this->assertStringContainsString('data-review-run-status', $html);
    $this->assertStringNotContainsString('AI report queued', $html);

    $inFlight = $this->repoInFlight();
    $review = $this->makeReview($inFlight);
    $form = $this->buildPage($review);
    $html = (string) \Drupal::service('renderer')->renderInIsolation($form);
    $this->assertMatchesRegularExpression('/data-review-rerun[^>]*hidden>/', $html);
    $this->assertStringContainsString('AI report queued', $html);
  }

  /**
   * The Rerun button is hidden in the contributor view.
   */
  public function testHiddenForContributorView(): void {
    $repo = $this->repoInFlight();
    $review = $this->makeReview($repo);
    $review->set('field_arv_decision_sent_at', \Drupal::time()->getCurrentTime())->save();
    $this->setCurrentUser($this->owner);
    $form = $this->buildPage($review);
    $this->assertArrayNotHasKey('rerun', $form['#page']);
    $this->assertArrayNotHasKey('run_status', $form['#page']);
  }

  /**
   * The contributor view attaches no polling.
   */
  public function testContributorViewAttachesNoPolling(): void {
    $repo = $this->repoInFlight();
    $review = $this->makeReview($repo);
    $review->set('field_arv_decision_sent_at', \Drupal::time()->getCurrentTime())->save();
    $this->setCurrentUser($this->owner);
    $form = $this->buildPage($review);
    $this->assertArrayNotHasKey('oodReviewStatus', $form['#attached']['drupalSettings'] ?? []);
    $this->assertArrayNotHasKey('max-age', $form['#cache']);
  }

  /**
   * The cache max-age is bounded by the stale timeout.
   */
  public function testMaxAgeBoundedByStaleTimeout(): void {
    $repo = $this->repoInFlight();
    $review = $this->makeReview($repo);
    $this->setOnRepo($repo, ['field_review_dispatched_at' => \Drupal::time()->getCurrentTime() - 600]);
    $state = $this->pageForm()->rerunState($review, $repo);
    $this->assertEqualsWithDelta(7200 - 600, $state['max_age'], 2);
    $form = $this->buildPage($review);
    $this->assertEqualsWithDelta(7200 - 600, $form['#cache']['max-age'], 2);
  }

  /**
   * Posts the run-review route as the current user.
   */
  protected function postRunReview(NodeInterface $repo, string $destination): Response {
    $path = 'appverse/repo/' . $repo->id() . '/run-review';
    $token = \Drupal::csrfToken()->get($path);
    $request = Request::create('/' . $path, 'POST', [], [], [], [], '');
    $request->query->replace(['token' => $token, 'destination' => $destination]);
    $request->server->set('QUERY_STRING', http_build_query(['token' => $token, 'destination' => $destination]));
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * A local destination returns to the review after a rerun.
   */
  public function testRunReviewWithLocalDestinationReturnsToReview(): void {
    $repo = $this->repoIdle();
    $review = $this->makeReview($repo);
    $response = $this->postRunReview($repo, '/appverse/review/' . $review->id());
    $this->assertSame(302, $response->getStatusCode());
    $this->assertStringEndsWith('/appverse/review/' . $review->id(), (string) $response->headers->get('Location'));
    $this->assertNotEmpty($this->dispatcher->sent);
  }

  /**
   * An external destination is ignored after a rerun.
   */
  public function testRunReviewIgnoresExternalDestination(): void {
    $repo = $this->repoIdle();
    $response = $this->postRunReview($repo, 'https://evil.example/');
    // The controller's response is a local redirect, so core refuses the
    // external target and the reviewer lands on the fallback (a 400 when core
    // sees a plain redirect). Either way nothing points at the external host.
    $this->assertContains($response->getStatusCode(), [302, 400]);
    $this->assertStringNotContainsString('evil.example', (string) $response->headers->get('Location'));
  }

  /**
   * The confirm form is uncacheable and limited to reviews.
   */
  public function testConfirmFormIsUncacheableAndReviewOnly(): void {
    $repo = $this->repoIdle();
    $review = $this->makeReview($repo);
    $form = $this->container->get('form_builder')->getForm(ReviewRerunConfirmForm::class, Node::load($review->id()));
    $this->assertSame(0, $form['#cache']['max-age']);
    $this->assertStringContainsString('/appverse/repo/' . $repo->id() . '/run-review?token=', $form['#action']);
    $this->assertStringEndsWith('destination=' . urlencode('/appverse/review/' . $review->id()), $form['#action']);
    $this->assertStringContainsString('Rerun the AI report for example/app?', (string) $form['#title']);

    $this->expectException(NotFoundHttpException::class);
    $this->container->get('form_builder')->getForm(ReviewRerunConfirmForm::class, Node::load($repo->id()));
  }

}
