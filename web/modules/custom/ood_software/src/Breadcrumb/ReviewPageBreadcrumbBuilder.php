<?php

declare(strict_types=1);

namespace Drupal\ood_software\Breadcrumb;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Form\ReviewPageForm;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Breadcrumb of the review page (D8-2931).
 *
 * Reviewers come from Manage Repos, contributors from My Appverse. The
 * reviewer test matches ReviewPageForm::viewModeFor(): the permission, and
 * not previewing the public view.
 */
class ReviewPageBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;

  public function __construct(
    protected AccountInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RequestStack $requestStack,
    protected ?BreadcrumbBuilderInterface $publicBuilder = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match, ?CacheableMetadata $cacheable_metadata = NULL): bool {
    // The answer depends on the route, and the breadcrumb block is cached
    // through it. Without this, the block's cache varies by route on every
    // page but this one, and Drupal warns about the conflicting redirect.
    $cacheable_metadata?->addCacheContexts(['route']);
    return $route_match->getRouteName() === 'ood_software.review_page';
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match): Breadcrumb {
    $review = $route_match->getParameter('node');
    $repo = $review instanceof NodeInterface && $review->hasField('field_arv_repo') ? $review->get('field_arv_repo')->entity : NULL;
    $repo = $repo instanceof NodeInterface ? $repo : NULL;

    // The same mode rule as ReviewPageForm::buildForm().
    $isReviewer = $this->currentUser->hasPermission(ReviewPageForm::REVIEWER_PERMISSION);
    $isContributor = $repo !== NULL
      && $this->currentUser->isAuthenticated()
      && (int) $repo->getOwnerId() === (int) $this->currentUser->id();
    $request = $this->requestStack->getCurrentRequest();
    $mode = ReviewPageForm::viewModeFor($isReviewer, $isContributor, $request?->query->get('view'));

    if ($mode === ReviewPageForm::MODE_PUBLIC) {
      // The public summary keeps the trail it had before this builder.
      $breadcrumb = $this->publicBuilder !== NULL && $this->publicBuilder->applies($route_match)
        ? $this->publicBuilder->build($route_match)
        : $this->homeAndTitle($review, $repo);
    }
    else {
      $breadcrumb = new Breadcrumb();
      $breadcrumb->addLink(Link::createFromRoute($this->t('Home'), '<front>'));
      if ($mode === ReviewPageForm::MODE_EDIT) {
        $breadcrumb->addLink(Link::createFromRoute($this->t('Manage Repos'), 'view.my_appverse.page_admin'));
      }
      else {
        $breadcrumb->addLink(new Link($this->t('My Appverse'), Url::fromUserInput('/user/' . $this->currentUser->id() . '/my-appverse')));
      }
      $title = $repo !== NULL ? (string) $repo->label() : (string) ($review instanceof NodeInterface ? $review->label() : '');
      $breadcrumb->addLink(Link::createFromRoute($title, '<none>'));
    }
    // The trail differs by permission, ownership and ?view=public.
    $breadcrumb->addCacheContexts(['user.permissions', 'user', 'url.query_args:view']);
    if ($review instanceof NodeInterface) {
      $breadcrumb->addCacheableDependency($review);
    }
    if ($repo !== NULL) {
      $breadcrumb->addCacheableDependency($repo);
    }
    return $breadcrumb;
  }

  /**
   * Home and the repo's title, for a site without easy_breadcrumb.
   */
  protected function homeAndTitle(mixed $review, ?NodeInterface $repo): Breadcrumb {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addLink(Link::createFromRoute($this->t('Home'), '<front>'));
    $title = $repo !== NULL ? (string) $repo->label() : (string) ($review instanceof NodeInterface ? $review->label() : '');
    $breadcrumb->addLink(Link::createFromRoute($title, '<none>'));
    return $breadcrumb;
  }

}
