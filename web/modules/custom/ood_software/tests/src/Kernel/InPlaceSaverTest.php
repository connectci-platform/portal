<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Service\InPlaceSaver;
use Drupal\Tests\ood_software\Kernel\Traits\AppverseModerationTestTrait;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;

/**
 * Non-editorial saves of a repo do not add revisions or move its state.
 *
 * @group ood_software
 */
class InPlaceSaverTest extends KernelTestBase {

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
   * The service under test.
   */
  protected InPlaceSaver $saver;

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
      'field.storage.node.field_repo_stars',
      'field.field.node.appverse_repo.field_repo_stars',
      'field.storage.node.field_review_status',
      'field.field.node.appverse_repo.field_review_status',
    ]);
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('system.site')->set('name', 'Appverse')->set('mail', 'site@example.org')->save();
    // User 1 bypasses access checks, so take that id first.
    $this->createUser([], 'admin');
    $this->owner = $this->createUser([], 'owner', FALSE, ['mail' => 'owner@example.com']);
    $this->setCurrentUser($this->owner);
    $this->saver = $this->container->get('ood_software.in_place_saver');
  }

  /**
   * A save that keeps the moderation state rewrites the revision it loaded.
   */
  public function testUnchangedStateUpdatesInPlace(): void {
    $repo = $this->makeRepo('ready_for_review');
    $before = $this->revisionCount($repo);
    $repo->set('field_repo_stars', 7);
    $this->saver->save($repo);
    $this->assertSame($before, $this->revisionCount($repo));
    $this->assertLatestState('ready_for_review', $repo);
    $this->assertSame(1, $this->moderationStateEntityCount($repo));
    $this->assertFalse($repo->isSyncing());
  }

  /**
   * A save that moves the state is editorial, so it is a normal save.
   */
  public function testStateChangeStillCreatesNewRevision(): void {
    $repo = $this->makeRepo('published');
    $before = $this->revisionCount($repo);
    $repo->set('moderation_state', 'archived');
    $this->saver->save($repo);
    $this->assertSame($before + 1, $this->revisionCount($repo));
    $this->assertLatestState('archived', $repo);
  }

  /**
   * A field write reaches the published revision and the forward one.
   */
  public function testUpdateRevisionsWritesDefaultAndForward(): void {
    $repo = $this->publishedRepoWithForwardRevision('ready_for_review');
    $before = $this->revisionCount($repo);
    $this->saver->updateRevisions($repo, fn (NodeInterface $r) => $r->set('field_review_status', 'pending'));
    $this->assertSame($before, $this->revisionCount($repo));
    $this->assertLatestState('ready_for_review', $repo);
    $this->assertSame('pending', $this->latest($repo)->get('field_review_status')->value);
    $this->assertSame('pending', $this->defaultRevision($repo)->get('field_review_status')->value);
  }

}
