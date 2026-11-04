<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\node\NodeInterface;
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
   * What GitHub reports as completed runs; NULL is a request error.
   *
   * @var array<int, array<string, mixed>>|null
   */
  public ?array $completedRuns = [];

  /**
   * What GitHub reports as in-progress runs.
   *
   * @var array<int, array<string, mixed>>|null
   */
  public ?array $activeRuns = [];

  /**
   * How many times the workflow runs were requested.
   */
  public int $fetchCalls = 0;

  /**
   * The GitHub token, or NULL for none.
   */
  public ?string $token = 'test-token';

  /**
   * Nids whose completed run was processed.
   *
   * @var int[]
   */
  public array $processed = [];

  /**
   * {@inheritdoc}
   */
  protected function getToken(): ?string {
    return $this->token;
  }

  /**
   * {@inheritdoc}
   */
  protected function fetchWorkflowRuns(string $token, string $status = 'completed'): ?array {
    $this->fetchCalls++;
    return $status === 'completed' ? $this->completedRuns : $this->activeRuns;
  }

  /**
   * {@inheritdoc}
   */
  protected function processCompletedRun(NodeInterface $node, array $run, string $token): void {
    $this->processed[] = (int) $node->id();
    $this->updateNodeReviewStatus($node, 'complete', (int) $run['id']);
  }

  /**
   * Reaches the protected pollNode().
   *
   * @param mixed ...$args
   *   The arguments of pollNode().
   */
  public function callPollNode(...$args): ?string {
    return $this->pollNode(...$args);
  }

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
