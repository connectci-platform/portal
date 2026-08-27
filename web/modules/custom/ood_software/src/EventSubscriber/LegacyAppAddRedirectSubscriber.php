<?php

declare(strict_types=1);

namespace Drupal\ood_software\EventSubscriber;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * 301-redirects /node/add/appverse_app to /appverse/add-repo.
 *
 * Fires on KernelEvents::REQUEST early enough to short-circuit before
 * core's routing builds the add form. (Symfony event priorities are
 * descending — higher number runs first. Drupal's path-processor passes
 * run at much higher priorities than this subscriber, which is fine; we
 * just need to short-circuit before form construction, not before path
 * processing.)
 *
 * Users without 'submit appverse repo' permission still get the redirect;
 * /appverse/add-repo's _permission check then 403s them. That is the
 * correct behavior: pretending the legacy URL doesn't exist for them
 * would hide the rename from anyone who bookmarks the old path.
 */
final class LegacyAppAddRedirectSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly MessengerInterface $messenger,
    private readonly AccountInterface $currentUser,
  ) {}

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $path = $event->getRequest()->getPathInfo();
    if ($path !== '/node/add/appverse_app') {
      return;
    }
    // Only message authenticated users; anonymous visitors get a clean
    // 301 with no flash (they would lose the message on the next request
    // anyway since /appverse/add-repo would 403 them).
    if ($this->currentUser->isAuthenticated()) {
      $this->messenger->addStatus($this->t('App registration now happens through the dedicated <em>Add an Appverse repo</em> form. We routed you to the new place.'));
    }
    $url = Url::fromRoute('ood_software.add_repo')->toString();
    $event->setResponse(new RedirectResponse($url, 301));
  }

  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => [['onRequest', 33]],
    ];
  }

}
