<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Which dispatches start a new round, and what a hub card is tagged with.
 *
 * Both are appverse-planning#49 and both are about a card showing something
 * that is no longer true.
 *
 * A run dispatched after a decision was sent used to mean the contributor had
 * re-submitted, whoever started it. An admin's Run AI report dispatches too,
 * so re-running the report on a repo the reviewer had sent back showed the
 * contributor "Resubmitted · round 2" and told them the repo was in review,
 * with the Re-submit button still in front of them.
 *
 * Re-submitting moves the repo off the state the decision left it in; a rerun
 * moves nothing. That is what separates them here.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Service\RepoProgress
 */
class RepoProgressAndHubCacheTest extends KernelTestBase {

  use ProdConfigTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'options',
    'datetime', 'link', 'taxonomy', 'path', 'path_alias', 'file',
    'content_moderation', 'workflows', 'key', 'flag',
    'entity_reference_revisions', 'paragraphs', 'ood_software',
  ];

  /**
   * When the decision was sent.
   */
  protected const SENT_AT = 1760000000;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'user', 'node', 'field', 'content_moderation', 'workflows']);
    $this->importProdConfig([
      'node.type.appverse_repo',
      'node.type.appverse_app',
      'node.type.appverse_review',
      'workflows.workflow.appverse_editorial',
      'paragraphs.paragraphs_type.review_verdict',
      'field.storage.paragraph.field_rvv_conclusion',
      'field.field.paragraph.review_verdict.field_rvv_conclusion',
      'field.storage.node.field_arv_repo',
      'field.field.node.appverse_review.field_arv_repo',
      'field.storage.node.field_arv_sha',
      'field.field.node.appverse_review.field_arv_sha',
      'field.storage.node.field_arv_decision_sent_at',
      'field.field.node.appverse_review.field_arv_decision_sent_at',
      'field.storage.node.field_arv_verdicts',
      'field.field.node.appverse_review.field_arv_verdicts',
      'field.storage.node.field_arv_recommendation',
      'field.field.node.appverse_review.field_arv_recommendation',
      'field.storage.node.field_arv_withdrawn_at',
      'field.field.node.appverse_review.field_arv_withdrawn_at',
      'field.storage.node.field_review_status',
      'field.field.node.appverse_repo.field_review_status',
      'field.storage.node.field_review_dispatched_at',
      'field.field.node.appverse_repo.field_review_dispatched_at',
      'field.storage.node.field_repo_assigned_reviewer',
      'field.field.node.appverse_repo.field_repo_assigned_reviewer',
      // The cache metadata walks the repo's member apps.
      'field.storage.node.field_appverse_repo',
      'field.field.node.appverse_app.field_appverse_repo',
    ]);
    $this->createUser();
  }

  /**
   * Re-running the report on a sent-back repo is still round 1.
   *
   * The repo stays in needs_adjustment, because Run AI report does not move
   * it. Nothing about the contributor's position has changed, so the line
   * must not say they re-submitted.
   *
   * @covers ::facts
   */
  public function testAdminRerunOnASentBackRepoIsNotANewRound(): void {
    $repo = $this->repoWithSentDecision('needs_adjustment');
    $this->dispatchAfterTheDecision($repo);

    $facts = $this->facts($repo);

    $this->assertSame(1, $facts['round'], 'A rerun does not advance the round.');
    $this->assertTrue($facts['decision_sent'], 'The decision that was sent still stands.');
    $this->assertSame('request_changes', $facts['decision']);
  }

  /**
   * The same rerun on a declined repo is also still round 1.
   *
   * @covers ::facts
   */
  public function testAdminRerunOnADeclinedRepoIsNotANewRound(): void {
    $repo = $this->repoWithSentDecision('declined');
    $this->dispatchAfterTheDecision($repo);

    $facts = $this->facts($repo);

    $this->assertSame(1, $facts['round']);
    $this->assertTrue($facts['decision_sent']);
  }

  /**
   * Re-submitting does start a new round.
   *
   * This is the case the old rule was written for and it still holds: the
   * contributor's send-for-review moves the repo to ready_for_review, so the
   * decision that was sent belongs to the round before.
   *
   * @covers ::facts
   */
  public function testResubmittingStartsANewRound(): void {
    $repo = $this->repoWithSentDecision('needs_adjustment');
    // Send for review: the repo moves, then the run is dispatched.
    $repo->set('moderation_state', 'ready_for_review')->save();
    $this->dispatchAfterTheDecision($repo);

    $facts = $this->facts($repo);

    $this->assertSame(2, $facts['round'], 'The contributor re-submitted, so this is round 2.');
    $this->assertFalse($facts['decision_sent'], 'The previous round\'s decision is not this round\'s.');
  }

  /**
   * With no new run at all, the sent decision stands.
   *
   * @covers ::facts
   */
  public function testWithoutANewRunTheDecisionStands(): void {
    $repo = $this->repoWithSentDecision('needs_adjustment');

    $facts = $this->facts($repo);

    $this->assertSame(1, $facts['round']);
    $this->assertTrue($facts['decision_sent']);
    $this->assertSame('request_changes', $facts['decision']);
  }

  /**
   * The card's cache tags include the assigned reviewer's.
   *
   * The card prints that reviewer's name, so renaming the account has to
   * invalidate the card; without the tag the old name stayed on it
   * (appverse-planning#49).
   */
  public function testTheCardIsTaggedWithItsAssignedReviewer(): void {
    $reviewer = $this->createUser([], 'a-reviewer');
    $repo = $this->repoWithSentDecision('needs_adjustment');
    $repo->set('field_repo_assigned_reviewer', $reviewer->id())->save();

    $meta = _ood_software_hub_cache_meta($repo);

    $this->assertContains(
      'user:' . $reviewer->id(),
      $meta['tags'],
      'Renaming the reviewer must invalidate the cards that name them.'
    );
  }

  /**
   * An unassigned repo is not tagged with a reviewer that is not there.
   */
  public function testAnUnassignedRepoHasNoReviewerTag(): void {
    $repo = $this->repoWithSentDecision('needs_adjustment');

    $meta = _ood_software_hub_cache_meta($repo);

    $this->assertSame(
      [],
      array_filter($meta['tags'], static fn (string $t): bool => str_starts_with($t, 'user:')
        && $t !== 'user:' . $repo->getOwnerId()),
      'Only the owner, who is tagged separately.'
    );
  }

  /**
   * The facts for one repo.
   *
   * @return array<string, mixed>
   */
  protected function facts(NodeInterface $repo): array {
    return \Drupal::service('ood_software.repo_progress')->facts($repo);
  }

  /**
   * A repo in $state whose review requested changes and was sent.
   */
  protected function repoWithSentDecision(string $state): NodeInterface {
    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => 'Example repo',
      'moderation_state' => $state,
    ]);
    $repo->save();

    $verdict = \Drupal::entityTypeManager()->getStorage('paragraph')->create([
      'type' => 'review_verdict',
      'field_rvv_conclusion' => 'request_changes',
    ]);
    $verdict->save();

    $review = Node::create([
      'type' => 'appverse_review',
      'title' => 'Review of Example repo',
      'moderation_state' => 'published',
      'field_arv_repo' => $repo->id(),
      'field_arv_sha' => 'abc1234',
      'field_arv_decision_sent_at' => self::SENT_AT,
      'field_arv_verdicts' => [$verdict],
    ]);
    $review->save();

    return $repo;
  }

  /**
   * Records a run dispatched after the decision was sent.
   */
  protected function dispatchAfterTheDecision(NodeInterface $repo): void {
    $repo->set('field_review_dispatched_at', self::SENT_AT + 3600);
    $repo->set('field_review_status', 'pending');
    $repo->save();
  }

}
