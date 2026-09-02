<?php

namespace Drupal\ood_software\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for redirecting the deprecated /appverse/my-apps shortcut.
 */
class MyAppsRedirectController extends ControllerBase {

  /**
   * Redirects authenticated users to their my-appverse page.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A 301 redirect response to the user's my-appverse page.
   */
  public function redirectToMyApps() {
    $current_user = $this->currentUser();
    $uid = $current_user->id();

    return new RedirectResponse("/user/{$uid}/my-appverse", 301);
  }

}
