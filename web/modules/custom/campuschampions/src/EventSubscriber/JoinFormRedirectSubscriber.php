<?php

namespace Drupal\campuschampions\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects non-admins to their existing Join Campus Champions submission.
 */
class JoinFormRedirectSubscriber implements EventSubscriberInterface {

  /**
   * The webform that is guarded by this subscriber.
   */
  const WEBFORM_ID = 'join_campus_champions';

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs a JoinFormRedirectSubscriber object.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   */
  public function __construct(AccountInterface $current_user, EntityTypeManagerInterface $entity_type_manager, RouteMatchInterface $route_match) {
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // Run after the router has set the route match, before the controller.
    $events[KernelEvents::REQUEST][] = ['onRequest', 30];
    return $events;
  }

  /**
   * Redirects to the user's previous submission, when one exists.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequest(RequestEvent $event) {
    if (!$event->isMainRequest()) {
      return;
    }

    // Only act on the canonical Join Campus Champions form page.
    if ($this->routeMatch->getRouteName() !== 'entity.webform.canonical') {
      return;
    }
    $webform = $this->routeMatch->getParameter('webform');
    if (!$webform || $webform->id() !== self::WEBFORM_ID) {
      return;
    }

    // Administrators keep full access to the blank form.
    if ($this->currentUser->isAnonymous() || in_array('administrator', $this->currentUser->getRoles(), TRUE)) {
      return;
    }

    $sids = $this->entityTypeManager->getStorage('webform_submission')->getQuery()
      ->accessCheck(FALSE)
      ->condition('webform_id', self::WEBFORM_ID)
      ->condition('uid', $this->currentUser->id())
      ->sort('sid', 'DESC')
      ->range(0, 1)
      ->execute();
    if (empty($sids)) {
      // No previous submission: let them fill out a new form.
      return;
    }

    $url = Url::fromRoute('entity.webform.user.submission.edit', [
      'webform' => self::WEBFORM_ID,
      'webform_submission' => reset($sids),
    ]);
    $event->setResponse(new RedirectResponse($url->toString()));
  }

}
