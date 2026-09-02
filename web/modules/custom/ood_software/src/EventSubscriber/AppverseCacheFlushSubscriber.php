<?php

namespace Drupal\ood_software\EventSubscriber;

use Drupal\ood_software\Service\AppverseCacheService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Flushes the appverse static cache once per request, after the response.
 *
 * Operations that mutate appverse content (node saves via hooks, controller
 * actions, sync) call AppverseCacheService::markDirty(). This subscriber runs
 * the single (expensive) regeneration on kernel.terminate so it happens after
 * the response is sent to the user and at most once per request, regardless of
 * how many saves occurred (e.g. a first-publish cascade of a Repo + N apps).
 */
class AppverseCacheFlushSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected AppverseCacheService $cache,
  ) {}

  /**
   * Regenerate the cache if anything marked it dirty this request.
   */
  public function onTerminate(): void {
    $this->cache->flushIfDirty();
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::TERMINATE => ['onTerminate'],
    ];
  }

}
