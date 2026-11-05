<?php

declare(strict_types=1);

namespace Drupal\ood_software\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\flag\FlagInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * JSON endpoint for the "We use this App" flag.
 *
 * JSON:API is read-only, so the Appverse front end flags and unflags apps
 * here instead. Only the current user's own flagging is ever touched.
 */
final class AppverseFlagController extends ControllerBase {

  /**
   * The flag machine name.
   */
  const FLAG_ID = 'appverse_apps';

  public function __construct(
    protected FlagServiceInterface $flagService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('flag'));
  }

  /**
   * Flags the app for the current user. Idempotent.
   */
  public function flag(NodeInterface $node): JsonResponse {
    $flag = $this->loadFlag($node);
    $account = $this->currentUser();
    if (!$this->flagService->getFlagging($flag, $node, $account)) {
      $this->flagService->flag($flag, $node, $account);
    }
    return new JsonResponse(['flagged' => TRUE, 'nid' => (int) $node->id()]);
  }

  /**
   * Unflags the app for the current user. Idempotent.
   */
  public function unflag(NodeInterface $node): JsonResponse {
    $flag = $this->loadFlag($node);
    $account = $this->currentUser();
    if ($this->flagService->getFlagging($flag, $node, $account)) {
      $this->flagService->unflag($flag, $node, $account);
    }
    return new JsonResponse(['flagged' => FALSE, 'nid' => (int) $node->id()]);
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
