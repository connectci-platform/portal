<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * A contributor cannot move their repo out of review (appverse-planning#41).
 *
 * The repo edit form drops Draft from its widget, but API writes check field
 * access, not the form, so the moderation state itself is not editable by the
 * contributor while the repo awaits review. JSON:API is read-only (D8-2832);
 * this guard stays as defense in depth for any other write path.
 *
 * @group ood_software
 */
class RepoStateFieldAccessTest extends KernelTestBase {

  use Traits\ProdConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'options',
    'datetime', 'link', 'taxonomy', 'path', 'path_alias',
    'content_moderation', 'workflows', 'key', 'flag', 'file', 'ood_software',
  ];

  protected UserInterface $reviewer;

  protected UserInterface $owner;

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
    $this->installConfig(['system', 'filter', 'user', 'node', 'content_moderation', 'workflows']);
    $this->importProdConfig([
      'node.type.appverse_repo',
      'node.type.appverse_app',
      'workflows.workflow.appverse_editorial',
    ]);
    Role::create(['id' => 'appverse_pm', 'label' => 'Appverse PM'])
      ->grantPermission('administer appverse content')
      ->save();
    // User 1 bypasses access checks, so take that id first.
    User::create(['name' => 'admin'])->save();
    $this->reviewer = User::create(['name' => 'reviewer', 'status' => 1, 'roles' => ['appverse_pm']]);
    $this->reviewer->save();
    $this->owner = User::create(['name' => 'owner', 'status' => 1]);
    $this->owner->save();
  }

  protected function repo(string $state): NodeInterface {
    $repo = Node::create(['type' => 'appverse_repo', 'title' => "Repo $state", 'uid' => $this->owner->id(), 'moderation_state' => $state]);
    $repo->save();
    return $repo;
  }

  /**
   * Locked for the contributor while it awaits review; a reviewer can move
   * it, and the contributor can again once it is a draft.
   */
  public function testModerationStateIsLockedWhileAwaitingReview(): void {
    $awaiting = $this->repo('ready_for_review');
    $this->assertFalse($awaiting->get('moderation_state')->access('edit', $this->owner), 'contributor, awaiting review');
    $this->assertTrue($awaiting->get('moderation_state')->access('edit', $this->reviewer), 'reviewer, awaiting review');
    $this->assertTrue($this->repo('draft')->get('moderation_state')->access('edit', $this->owner), 'contributor, draft');
  }

}
