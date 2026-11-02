<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Routing\RouteMatch;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Form\ReviewPageForm;
use Drupal\Tests\ood_software\Kernel\Traits\AppverseModerationTestTrait;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
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
   * @param \Drupal\user\UserInterface $user
   *   Who views it.
   * @param array<string, string> $query
   *   Query arguments of the request.
   */
  protected function crumb(NodeInterface $review, UserInterface $user, array $query = []): Breadcrumb {
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
   * A reviewer previewing the public view gets the visitor crumb.
   */
  public function testReviewerPreviewingPublicViewGetsMyAppverseCrumb(): void {
    $crumb = $this->crumb($this->makeReview(), $this->reviewer, ['view' => 'public']);
    $this->assertSame('My Appverse', (string) $crumb->getLinks()[1]->getText());
    $this->assertContains('url.query_args:view', $crumb->getCacheContexts());
    $this->assertContains('user.permissions', $crumb->getCacheContexts());
    $this->assertContains('user', $crumb->getCacheContexts());
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
    $this->assertSame('View repo', (string) $page['repo_link']['label']);
    $this->assertCount(2, $page['app_links']);
    $urls = array_column($page['app_links'], 'url');
    sort($urls);
    $expected = [$apps['One']->toUrl()->toString(), $apps['Two']->toUrl()->toString()];
    sort($expected);
    $this->assertSame($expected, $urls);
  }

}
