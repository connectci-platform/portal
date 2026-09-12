<?php

namespace Drupal\connectci_llms\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\connectci_llms\LlmsText;
use Drupal\domain\DomainNegotiatorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves /llms.txt for the active domain.
 */
final class LlmsTxtController extends ControllerBase {

  public function __construct(protected DomainNegotiatorInterface $domainNegotiator) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('domain.negotiator'));
  }

  /**
   * Build the llms.txt response for the negotiated domain.
   */
  public function content(): CacheableResponse {
    $domain = $this->domainNegotiator->getActiveDomain();
    // Contrib's docblock says non-null, but negotiation can come up empty
    // early in bootstrap; keep the guard.
    // @phpstan-ignore-next-line
    if ($domain === NULL) {
      throw new NotFoundHttpException();
    }

    // system.site is domain-overridden by domain_config, so this returns
    // the active domain's slogan.
    $site_config = $this->config('system.site');
    $body = LlmsText::build(
      $domain->label(),
      $domain->getHostname(),
      trim((string) $site_config->get('slogan')),
      // The ACCESS resources/content APIs are support-domain services.
      $domain->id() === 'amp_cyberinfrastructure_org',
    );
    $response = new CacheableResponse($body, 200, [
      'Content-Type' => 'text/plain; charset=UTF-8',
    ]);
    // url.site is load-bearing: without it one domain's llms.txt is cached
    // for another domain's hostname.
    $meta = new CacheableMetadata();
    $meta->addCacheContexts(['url.site']);
    $meta->addCacheableDependency($domain);
    $meta->addCacheableDependency($site_config);
    $response->addCacheableDependency($meta);
    return $response;
  }

}
