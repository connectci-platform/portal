<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\ood_software\Service\AppverseReviewService;

/**
 * The review service with the GitHub call replaced by a record of the repo.
 *
 * Everything else is the real service, so dispatchForNode() still records the
 * dispatch on the node the way production does.
 */
class StubDispatchReviewService extends AppverseReviewService {

  /**
   * Repos a run was started for.
   *
   * @var string[]
   */
  public array $sent = [];

  /**
   * Whether GitHub accepts the dispatch.
   */
  public bool $accepts = TRUE;

  /**
   * Called on each dispatch, before it returns.
   */
  public ?\Closure $onDispatch = NULL;

  /**
   * {@inheritdoc}
   */
  public function dispatch(string $targetRepo, ?string $model = NULL, string $correlationId = '', ?string $aspectsOverride = NULL, ?string $ref = NULL): bool {
    $this->sent[] = $targetRepo;
    if ($this->onDispatch) {
      ($this->onDispatch)();
    }
    return $this->accepts;
  }

}
