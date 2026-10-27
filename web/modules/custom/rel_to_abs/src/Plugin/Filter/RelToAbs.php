<?php

namespace Drupal\rel_to_abs\Plugin\Filter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\filter\Attribute\Filter;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\filter\Plugin\FilterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Converts root-relative URLs to absolute URLs.
 *
 * Absolute URLs are needed wherever processed text leaves the site, such as
 * email bodies and API output. Protocol-relative ("//host/...") and fragment
 * ("#anchor") URLs are left alone.
 *
 * Links that match a route are built with the URL generator, so they get the
 * path alias and outbound processing, such as Domain Source moving a node link
 * to the node's source domain. Everything else (files, srcset, unrouted
 * paths) gets the current scheme and host.
 */
#[Filter(
  id: "rel_to_abs",
  title: new TranslatableMarkup("Convert relative paths to absolute URLs"),
  type: FilterInterface::TYPE_TRANSFORM_REVERSIBLE,
)]
class RelToAbs extends FilterBase implements ContainerFactoryPluginInterface {

  /**
   * The request stack.
   */
  protected RequestStack $requestStack;

  /**
   * {@inheritdoc}
   *
   * @phpstan-param array<string, mixed> $configuration
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    // Using new static() is safe here: filter plugins are only instantiated via
    // the plugin manager, never subclassed with an incompatible constructor.
    // @phpstan-ignore-next-line new.static
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->requestStack = $container->get('request_stack');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    $result = new FilterProcessResult($text);
    // The host differs per domain, so the output must vary by site.
    $result->addCacheContexts(['url.site']);

    $request = $this->requestStack->getCurrentRequest();
    // Skip the DOM round-trip when there is nothing to rewrite.
    if (!$request || !str_contains($text, '/')) {
      return $result;
    }

    $dom = Html::load($text);
    foreach ($dom->getElementsByTagName('a') as $link) {
      $href = $link->getAttribute('href');
      if (!str_starts_with($href, '/') || str_starts_with($href, '//')) {
        continue;
      }
      $absolute = $this->routedAbsoluteUrl($href, $result);
      if ($absolute !== NULL) {
        $link->setAttribute('href', $absolute);
      }
    }

    // Links rewritten above are already absolute, so this leaves them alone.
    $result->setProcessedText(Html::transformRootRelativeUrlsToAbsolute(Html::serialize($dom), $request->getSchemeAndHttpHost()));
    return $result;
  }

  /**
   * Builds an absolute URL for a root-relative path that matches a route.
   *
   * @param string $path
   *   A root-relative path, optionally with a query string and fragment.
   * @param \Drupal\filter\FilterProcessResult $result
   *   The filter result, which receives the cacheability of the generated URL
   *   (for example the cache tag of a node whose source domain picks the host).
   *
   * @return string|null
   *   The absolute URL, or NULL when the path matches no route.
   */
  protected function routedAbsoluteUrl(string $path, FilterProcessResult $result): ?string {
    try {
      $url = Url::fromUserInput($path);
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
    if (!$url->isRouted()) {
      return NULL;
    }
    $generated = $url->setAbsolute()->toString(TRUE);
    $result->addCacheableDependency($generated);
    return $generated->getGeneratedUrl();
  }

}
