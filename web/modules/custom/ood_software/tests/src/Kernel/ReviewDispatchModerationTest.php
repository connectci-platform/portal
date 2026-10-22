<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\ood_software\Kernel\Traits\AppverseModerationTestTrait;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;

/**
 * Sending a repo for review keeps it in review.
 *
 * The run is started after the save. Started inside the save's
 * hook_node_update(), the dispatch re-saved the repo before content_moderation
 * wrote its state record, so a second state entity was created in draft and
 * the repo dropped out of the reviewer queue.
 *
 * @group ood_software
 */
class ReviewDispatchModerationTest extends KernelTestBase {

  use AppverseModerationTestTrait;
  use ProdConfigTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'options',
    'datetime', 'link', 'taxonomy', 'path', 'path_alias', 'file',
    'content_moderation', 'workflows', 'key', 'flag', 'ood_software',
  ];

  /**
   * The repo's owner, the contributor.
   */
  protected UserInterface $owner;

  /**
   * The review service, with the GitHub call recorded instead of sent.
   */
  protected StubDispatchReviewService $dispatcher;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'filter', 'user', 'node', 'content_moderation', 'workflows']);
    $this->importProdConfig([
      'node.type.appverse_repo',
      'node.type.appverse_app',
      'workflows.workflow.appverse_editorial',
      'field.storage.node.field_repo_url',
      'field.field.node.appverse_repo.field_repo_url',
      'field.storage.node.field_review_status',
      'field.field.node.appverse_repo.field_review_status',
      'field.storage.node.field_review_dispatched_at',
      'field.field.node.appverse_repo.field_review_dispatched_at',
      'field.storage.node.field_review_dispatched_by',
      'field.field.node.appverse_repo.field_review_dispatched_by',
      'field.storage.node.field_review_recommendation',
      'field.field.node.appverse_repo.field_review_recommendation',
      'field.storage.node.field_review_run_id',
      'field.field.node.appverse_repo.field_review_run_id',
    ]);
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('system.site')->set('name', 'Appverse')->set('mail', 'site@example.org')->save();
    // User 1 bypasses access checks, so take that id first.
    $this->createUser([], 'admin');
    $this->owner = $this->createUser([], 'owner', FALSE, ['mail' => 'owner@example.com']);
    $this->setCurrentUser($this->owner);

    // The same arguments as ood_software.review_dispatcher in services.yml.
    $this->dispatcher = new StubDispatchReviewService(
      $this->container->get('http_client'),
      $this->container->get('key.repository'),
      $this->container->get('logger.factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('datetime.time'),
      $this->container->get('file_system'),
      $this->container->get('ood_software.review_seeder'),
      $this->container->get('current_user'),
      $this->container->get('ood_software.repo_notifier'),
      $this->container->get('ood_software.in_place_saver'),
    );
    $this->container->set('ood_software.review_dispatcher', $this->dispatcher);
  }

  /**
   * The run starts after the save and leaves the repo awaiting review.
   */
  public function testSendForReviewKeepsReadyForReview(): void {
    $repo = $this->makeRepo('draft');
    $repo->set('moderation_state', 'ready_for_review')->save();
    $this->dispatcher->destruct();

    $this->assertSame(['example/app'], $this->dispatcher->sent);
    $this->assertLatestState('ready_for_review', $repo);
    $this->assertSame('pending', $this->latest($repo)->get('field_review_status')->value);
    $this->assertSame(1, $this->moderationStateEntityCount($repo));
  }

  /**
   * A repo moved on before the request ended is not reviewed.
   */
  public function testNoDispatchWhenTheTransitionDidNotStick(): void {
    $repo = $this->makeRepo('draft');
    $repo->set('moderation_state', 'ready_for_review')->save();
    $this->latest($repo)->set('moderation_state', 'draft')->save();
    $this->dispatcher->destruct();

    $this->assertSame([], $this->dispatcher->sent);
  }

  /**
   * Recording the dispatch on a published repo keeps its forward revision.
   */
  public function testDispatchOnPublishedRepoKeepsForwardRevision(): void {
    $repo = $this->publishedRepoWithForwardRevision('ready_for_review');
    $this->dispatcher->dispatchForNode($this->latest($repo));
    $this->assertLatestState('ready_for_review', $repo);
    $this->assertTrue($this->defaultRevision($repo)->isPublished());
  }

}
