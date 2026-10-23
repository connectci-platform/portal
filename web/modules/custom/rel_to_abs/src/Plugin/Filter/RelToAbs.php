<?php

namespace Drupal\rel_to_abs\Plugin\Filter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
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

    $result->setProcessedText(Html::transformRootRelativeUrlsToAbsolute($text, $request->getSchemeAndHttpHost()));
    return $result;
  }

}
