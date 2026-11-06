<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\ood_software\Breadcrumb\ReviewPageBreadcrumbBuilder;
use Drupal\Core\Routing\RouteMatch;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Form\ReviewPageForm;
use Drupal\Tests\ood_software\Kernel\Traits\AppverseModerationTestTrait;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Route;

/**
 * The review page's breadcrumb and its repo/app links (D8-2931).
 *
 * @group ood_software
 */
class ReviewPageBreadcrumbTest extends KernelTestBase {

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
    'entity_reference_revisions', 'paragraphs', 'easy_breadcrumb', 'ood_software',
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
    $this->createUser([], 'admin');
    $this->owner = $this->createUser(['access content'], 'owner');
    $this->reviewer = $this->createUser(['access content', 'administer appverse content'], 'reviewer');
  }

  /**
   * An undecided review of a repo (a fresh one by default).
   */
  protected function makeReview(?NodeInterface $repo = NULL): NodeInterface {
    $repo ??= $this->makeRepo('ready_for_review');
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
   * Builds the crumb for the review as the given user.
   *
   * @param \Drupal\node\NodeInterface $review
   *   The review.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   Who views it.
   * @param array<string, string> $query
   *   Query arguments of the request.
   */
  protected function crumb(NodeInterface $review, AccountInterface $user, array $query = []): Breadcrumb {
    $this->setCurrentUser($user);
    $request = Request::create('/appverse/review/' . $review->id(), 'GET', $query);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    $builder = $this->container->get('ood_software.review_page_breadcrumb');
    $match = new RouteMatch('ood_software.review_page', new Route('/appverse/review/{node}'), ['node' => Node::load($review->id())], ['node' => $review->id()]);
    $this->assertTrue($builder->applies($match));
    $breadcrumb = $builder->build($match);
    $this->container->get('request_stack')->pop();
    return $breadcrumb;
  }

  /**
   * Whether the builder applies is varied by route, on every route.
   *
   * easy_breadcrumb varies the breadcrumb block by route on other pages. If
   * this builder claimed the review page without saying so, the block's cache
   * would vary by route everywhere but here, and Drupal warns about the
   * conflicting cache redirect on the review page.
   */
  public function testAppliesVariesByRoute(): void {
    $builder = $this->container->get('ood_software.review_page_breadcrumb');
    foreach (['ood_software.review_page' => TRUE, 'entity.node.canonical' => FALSE] as $routeName => $expected) {
      $metadata = new CacheableMetadata();
      $match = new RouteMatch($routeName, new Route('/x'));
      $this->assertSame($expected, $builder->applies($match, $metadata));
      $this->assertContains('route', $metadata->getCacheContexts(), $routeName);
    }
  }

  /**
   * A reviewer comes from Manage Repos.
   */
  public function testReviewerGetsManageReposCrumb(): void {
    $links = $this->crumb($this->makeReview(), $this->reviewer)->getLinks();
    $this->assertSame('<front>', $links[0]->getUrl()->getRouteName());
    $this->assertSame('view.my_appverse.page_admin', $links[1]->getUrl()->getRouteName());
    $this->assertSame('Manage Repos', (string) $links[1]->getText());
  }

  /**
   * The owner comes from My Appverse.
   */
  public function testContributorGetsMyAppverseCrumb(): void {
    $links = $this->crumb($this->makeReview(), $this->owner)->getLinks();
    $this->assertSame('/user/' . $this->owner->id() . '/my-appverse', $links[1]->getUrl()->toString());
    $this->assertSame('My Appverse', (string) $links[1]->getText());
  }

  /**
   * The public summary keeps easy_breadcrumb's trail, for any visitor.
   */
  public function testPublicModeKeepsEasyBreadcrumbTrail(): void {
    $review = $this->makeReview();
    $anonymous = User::getAnonymousUser();
    foreach ([[$anonymous, []], [$this->reviewer, ['view' => 'public']]] as [$user, $query]) {
      $crumb = $this->crumb($review, $user, $query);
      $expected = $this->easyTrail($review, $user, $query);
      $this->assertSame($expected, array_map(static fn ($l) => (string) $l->getText(), $crumb->getLinks()));
      $this->assertContains('user', $crumb->getCacheContexts());
      $this->assertContains('user.permissions', $crumb->getCacheContexts());
      $this->assertContains('url.query_args:view', $crumb->getCacheContexts());
    }
  }

  /**
   * Public mode returns what the delegate builds, not this builder's trail.
   */
  public function testPublicModeReturnsDelegateTrail(): void {
    $stub = new class() implements BreadcrumbBuilderInterface {

      /**
       * {@inheritdoc}
       */
      public function applies(RouteMatchInterface $route_match) {
        return TRUE;
      }

      /**
       * {@inheritdoc}
       */
      public function build(RouteMatchInterface $route_match) {
        return (new Breadcrumb())->addLink(Link::createFromRoute('Delegated', '<front>'));
      }

    };
    $builder = new ReviewPageBreadcrumbBuilder($this->container->get('current_user'), $this->container->get('entity_type.manager'), $this->container->get('request_stack'), $stub);
    $review = $this->makeReview();
    $this->setCurrentUser(User::getAnonymousUser());
    $match = new RouteMatch('ood_software.review_page', new Route('/appverse/review/{node}'), ['node' => $review], ['node' => $review->id()]);
    $crumb = $builder->build($match);
    $this->assertSame(['Delegated'], array_map(static fn ($l) => (string) $l->getText(), $crumb->getLinks()));
    $this->assertContains('url.query_args:view', $crumb->getCacheContexts());
  }

  /**
   * Link texts easy_breadcrumb builds for the review page.
   *
   * @param \Drupal\node\NodeInterface $review
   *   The review.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   Who views it.
   * @param array<string, string> $query
   *   Query arguments of the request.
   *
   * @return string[]
   *   The link texts.
   */
  protected function easyTrail(NodeInterface $review, AccountInterface $user, array $query): array {
    $this->setCurrentUser($user);
    $request = Request::create('/appverse/review/' . $review->id(), 'GET', $query);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    $match = new RouteMatch('ood_software.review_page', new Route('/appverse/review/{node}'), ['node' => Node::load($review->id())], ['node' => $review->id()]);
    $easy = $this->container->get('easy_breadcrumb.breadcrumb');
    $links = $easy->build($match)->getLinks();
    $this->container->get('request_stack')->pop();
    return array_map(static fn ($l) => (string) $l->getText(), $links);
  }

  /**
   * The last crumb is the repo title.
   */
  public function testLastCrumbIsRepoTitle(): void {
    $links = $this->crumb($this->makeReview(), $this->reviewer)->getLinks();
    $this->assertCount(3, $links);
    $this->assertSame('example/app', (string) $links[2]->getText());
  }

  /**
   * The header lists the repo and each member app.
   */
  public function testBuildPageListsRepoAndMemberApps(): void {
    $repo = $this->makeRepo('ready_for_review');
    $apps = [];
    foreach (['One', 'Two'] as $title) {
      $app = Node::create([
        'type' => 'appverse_app',
        'title' => $title,
        'field_appverse_repo' => $repo->id(),
        'moderation_state' => 'draft',
      ]);
      $app->save();
      $apps[$title] = $app;
    }
    $review = $this->makeReview($repo);
    $this->setCurrentUser($this->reviewer);
    $request = Request::create('/appverse/review/' . $review->id());
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    $form = $this->container->get('form_builder')->getForm(ReviewPageForm::class, Node::load($review->id()));
    $page = $form['#page'];
    $this->assertSame($repo->toUrl()->toString(), $page['repo_link']['url']);
    $this->assertSame('Repo page', (string) $page['repo_link']['label']);
    $this->assertCount(2, $page['app_links']);
    $urls = array_column($page['app_links'], 'url');
    sort($urls);
    $expected = [$apps['One']->toUrl()->toString(), $apps['Two']->toUrl()->toString()];
    sort($expected);
    $this->assertSame($expected, $urls);
  }

  /**
   * The comparison is against the nearest earlier review, not a newer one.
   */
  public function testComparisonUsesTheNearestEarlierReview(): void {
    $repo = $this->makeRepo('ready_for_review');
    $models = ['{"model":"qwen/qwen3"}', '{"model":"claude-sonnet-5-5"}', '{"model":"claude-sonnet-5-5"}'];
    $reviews = [];
    foreach ($models as $i => $meta) {
      $review = $this->makeReview($repo);
      $review->set('field_arv_run_meta', $meta);
      $review->setCreatedTime(1700000000 + $i * 1000);
      $review->save();
      $reviews[] = $review;
    }

    $page = $this->buildReviewPage($reviews[1]);
    $this->assertTrue($page['round_delta']['has_previous']);
    $this->assertSame(1, $page['compared_position']);
    // The first review used another model; the newer third one did not.
    $this->assertTrue($page['model_changed']);

    $first = $this->buildReviewPage($reviews[0]);
    $this->assertFalse($first['round_delta']['has_previous']);
    $this->assertNull($first['compared_position']);
    $this->assertFalse($first['model_changed']);

    $last = $this->buildReviewPage($reviews[2]);
    $this->assertSame(2, $last['compared_position']);
    $this->assertFalse($last['model_changed']);
  }

  /**
   * The page data the reviewer's template reads, for a review.
   *
   * @return array<mixed>
   *   The form's #page.
   */
  protected function buildReviewPage(NodeInterface $review): array {
    // Draft reviews are visible to those who bypass node access; the history
    // and the comparison only count reviews the viewer may see.
    $this->setCurrentUser($this->createUser(['access content', 'bypass node access']));
    $request = Request::create('/appverse/review/' . $review->id());
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    $form = $this->container->get('form_builder')->getForm(ReviewPageForm::class, Node::load($review->id()));
    return $form['#page'];
  }

}
