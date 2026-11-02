<?php

declare(strict_types=1);

namespace Drupal\ood_software\Breadcrumb;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
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
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    return $route_match->getRouteName() === 'ood_software.review_page';
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match): Breadcrumb {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheContexts(['user.permissions', 'user', 'url.query_args:view']);
    $breadcrumb->addLink(Link::createFromRoute($this->t('Home'), '<front>'));

    $request = $this->requestStack->getCurrentRequest();
    $reviewer = $this->currentUser->hasPermission(ReviewPageForm::REVIEWER_PERMISSION)
      && $request?->query->get('view') !== ReviewPageForm::MODE_PUBLIC;
    if ($reviewer) {
      $breadcrumb->addLink(Link::createFromRoute($this->t('Manage Repos'), 'view.my_appverse.page_admin'));
    }
    elseif ($this->currentUser->isAuthenticated()) {
      $breadcrumb->addLink(new Link($this->t('My Appverse'), Url::fromUserInput('/user/' . $this->currentUser->id() . '/my-appverse')));
    }

    $review = $route_match->getParameter('node');
    $repo = $review instanceof NodeInterface && $review->hasField('field_arv_repo') ? $review->get('field_arv_repo')->entity : NULL;
    if ($review instanceof NodeInterface) {
      $breadcrumb->addCacheableDependency($review);
    }
    if ($repo instanceof NodeInterface) {
      $breadcrumb->addCacheableDependency($repo);
    }
    $title = $repo instanceof NodeInterface ? (string) $repo->label() : (string) ($review instanceof NodeInterface ? $review->label() : '');
    $breadcrumb->addLink(Link::createFromRoute($title, '<none>'));
    return $breadcrumb;
  }

}
