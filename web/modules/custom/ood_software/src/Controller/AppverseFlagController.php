<?php

declare(strict_types=1);

namespace Drupal\ood_software\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\flag\FlagInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * JSON endpoint for the "We use this App" flag.
 *
 * JSON:API is read-only and the flagging resource is disabled, so the
 * Appverse front end lists, flags and unflags apps here instead. Only the
 * current user's own flaggings are ever read or touched.
 */
final class AppverseFlagController extends ControllerBase {

  /**
   * The flag machine name.
   */
  const FLAG_ID = 'appverse_apps';

  public function __construct(
    protected FlagServiceInterface $flagService,
    protected LockBackendInterface $lock,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('flag'), $container->get('lock'));
  }

  /**
   * Lists the apps the current user has flagged, limited to viewable apps.
   */
  public function flags(): JsonResponse {
    $flag = $this->flagService->getFlagById(self::FLAG_ID);
    if (!$flag instanceof FlagInterface) {
      throw new NotFoundHttpException();
    }
    $nids = [];
    foreach ($this->flagService->getFlagUserFlaggings($flag, $this->currentUser()) as $flagging) {
      $nids[] = (int) $flagging->getFlaggableId();
    }
    $flagged = [];
    // Check view access per node: entity queries do not drop unpublished
    // nodes when no node access module is enabled.
    foreach ($this->entityTypeManager()->getStorage('node')->loadMultiple($nids) as $node) {
      if ($node instanceof NodeInterface && $node->bundle() === 'appverse_app' && $node->access('view')) {
        $flagged[(int) $node->id()] = ['uuid' => $node->uuid(), 'nid' => (int) $node->id()];
      }
    }
    ksort($flagged);
    return new JsonResponse(['flagged' => array_values($flagged)]);
  }

  /**
   * Flags the app for the current user. Idempotent.
   *
   * The check and the write run under a per-user, per-app lock, because the
   * flagging table has no unique key and two concurrent requests could
   * otherwise save two rows. The response has "flagged" (always TRUE) and
   * "changed" (TRUE only if this request created the flagging).
   */
  public function flag(NodeInterface $node): JsonResponse {
    $flag = $this->loadFlag($node);
    $account = $this->currentUser();
    $changed = $this->withLock($flag, $node, function () use ($flag, $node, $account): bool {
      if ($this->isFlagged($flag, $node, $account)) {
        return FALSE;
      }
      try {
        $this->flagService->flag($flag, $node, $account);
      }
      catch (\LogicException $e) {
        // Flag throws this both for "already flagged" and for a flag that does
        // not apply to the entity, so only swallow it if a flagging exists.
        if ($this->isFlagged($flag, $node, $account)) {
          return FALSE;
        }
        throw $e;
      }
      return TRUE;
    });
    return new JsonResponse(['flagged' => TRUE, 'changed' => $changed, 'nid' => (int) $node->id()]);
  }

  /**
   * Unflags the app for the current user. Idempotent.
   *
   * Runs under the same lock as flag(). The response has "flagged" (always
   * FALSE) and "changed" (TRUE only if this request removed the flagging).
   */
  public function unflag(NodeInterface $node): JsonResponse {
    $flag = $this->loadFlag($node);
    $account = $this->currentUser();
    $changed = $this->withLock($flag, $node, function () use ($flag, $node, $account): bool {
      if (!$this->isFlagged($flag, $node, $account)) {
        return FALSE;
      }
      try {
        $this->flagService->unflag($flag, $node, $account);
      }
      catch (\LogicException $e) {
        // See flag(): only swallow it if the flagging is really gone.
        if (!$this->isFlagged($flag, $node, $account)) {
          return FALSE;
        }
        throw $e;
      }
      return TRUE;
    });
    return new JsonResponse(['flagged' => FALSE, 'changed' => $changed, 'nid' => (int) $node->id()]);
  }

  /**
   * Runs a callable while holding the flag lock for the user and app.
   *
   * @param \Drupal\flag\FlagInterface $flag
   *   The flag.
   * @param \Drupal\node\NodeInterface $node
   *   The app.
   * @param callable $callback
   *   The callable to run. It returns whether it changed anything.
   *
   * @return bool
   *   The callable's result.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException
   *   If the lock could not be acquired, even after waiting.
   */
  protected function withLock(FlagInterface $flag, NodeInterface $node, callable $callback): bool {
    $name = 'ood_software.appverse_flag:' . $flag->id() . ':' . $node->id() . ':' . $this->currentUser()->id();
    if (!$this->lock->acquire($name)) {
      $this->lock->wait($name, 5);
      if (!$this->lock->acquire($name)) {
        throw new ServiceUnavailableHttpException(5, 'The request could not be processed right now. Try again.');
      }
    }
    try {
      return (bool) $callback();
    }
    finally {
      $this->lock->release($name);
    }
  }

  /**
   * Whether the account has flagged the node.
   *
   * Reads shared state, so the result can change between calls.
   *
   * @phpstan-impure
   */
  protected function isFlagged(FlagInterface $flag, NodeInterface $node, AccountInterface $account): bool {
    return $this->flagService->getFlagging($flag, $node, $account) !== NULL;
  }

  /**
   * Loads the flag, or throws a 404 if the node is not an Appverse app.
   */
  protected function loadFlag(NodeInterface $node): FlagInterface {
    $flag = $node->bundle() === 'appverse_app' ? $this->flagService->getFlagById(self::FLAG_ID) : NULL;
    if (!$flag instanceof FlagInterface) {
      throw new NotFoundHttpException();
    }
    return $flag;
  }

}
