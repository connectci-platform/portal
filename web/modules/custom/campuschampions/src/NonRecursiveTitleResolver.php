<?php

namespace Drupal\campuschampions;

use Drupal\Core\Controller\TitleResolverInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Stops page title resolution from re-entering itself.
 *
 * Webform submission pages recurse infinitely when the token info cache is
 * cold: mgv's page title global variable asks the title resolver for the
 * title, WebformSubmission::label() runs that title through token replacement,
 * building token info makes webform's hook render its "more" examples in
 * isolation, that render runs template_preprocess_default_variables_alter,
 * and mgv asks for the page title again -- until the call stack is exhausted.
 *
 * A title is never legitimately resolved while another one is still being
 * resolved, so the nested call returns NULL and the cycle cannot start.
 *
 * @see \Drupal\mgv\Plugin\GlobalVariable\RawCurrentPageTitle::getValue()
 * @see \Drupal\webform\Hook\WebformTokensHooks::renderMore()
 * @see https://www.drupal.org/project/webform/issues/3309183
 */
class NonRecursiveTitleResolver implements TitleResolverInterface {

  /**
   * The decorated title resolver.
   *
   * @var \Drupal\Core\Controller\TitleResolverInterface
   */
  protected $inner;

  /**
   * Whether a title is currently being resolved.
   *
   * @var bool
   */
  protected $resolving = FALSE;

  /**
   * Constructs a NonRecursiveTitleResolver object.
   *
   * @param \Drupal\Core\Controller\TitleResolverInterface $inner
   *   The decorated title resolver.
   */
  public function __construct(TitleResolverInterface $inner) {
    $this->inner = $inner;
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle(Request $request, Route $route) {
    if ($this->resolving) {
      return NULL;
    }
    $this->resolving = TRUE;
    try {
      return $this->inner->getTitle($request, $route);
    }
    finally {
      $this->resolving = FALSE;
    }
  }

}
