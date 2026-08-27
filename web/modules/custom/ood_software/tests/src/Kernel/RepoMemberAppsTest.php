<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;

/**
 * Covers the RepoMemberApps shared service.
 *
 * The cascade previously lived as four hand-copied methods across the hub
 * controller and forms; one copy dropped the setValidationRequired(FALSE)
 * guard, which on Drupal 10.2+ makes the non-form moderation save throw
 * EntityStorageException (a 500 on "Request changes"). This test guards the
 * consolidated service: it queries member apps and cascades moderation
 * WITHOUT throwing, and only touches apps in the requested from-states.
 *
 * @group ood_software
 */
class RepoMemberAppsTest extends KernelTestBase {

  use ProdConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'options',
    'datetime', 'link', 'taxonomy', 'path', 'path_alias',
    'content_moderation', 'workflows', 'key', 'flag', 'ood_software',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig([
      'system', 'filter', 'user', 'node', 'taxonomy',
      'content_moderation', 'workflows',
    ]);
    $this->importProdConfig([
      'node.type.appverse_repo',
      'node.type.appverse_app',
      'workflows.workflow.appverse_editorial',
      'field.storage.node.field_repo_url',
      'field.field.node.appverse_repo.field_repo_url',
      'field.storage.node.field_appverse_repo',
      'field.field.node.appverse_app.field_appverse_repo',
    ]);
    $moduleSource = new FileStorage(
      DRUPAL_ROOT . '/modules/custom/ood_software/config/install'
    );
    foreach ([
      'field.storage.node.field_repo_shape',
      'field.field.node.appverse_repo.field_repo_shape',
    ] as $name) {
      $data = $moduleSource->read($name);
      $storage = \Drupal::entityTypeManager()->getStorage(
        \Drupal::service('config.manager')->getEntityTypeIdByName($name)
      );
      $idKey = $storage->getEntityType()->getKey('id');
      if (!($data[$idKey] ?? NULL) || !$storage->load($data[$idKey])) {
        $storage->createFromStorageRecord($data)->save();
      }
    }
  }

  private function makeRepo(): NodeInterface {
    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => 'Mono',
      'field_repo_url' => ['uri' => 'https://github.com/OSC/mono'],
      'field_repo_shape' => 'declared',
      'moderation_state' => 'published',
    ]);
    $repo->save();
    return $repo;
  }

  private function makeApp(string $title, NodeInterface $repo, string $state): NodeInterface {
    $app = Node::create([
      'type' => 'appverse_app',
      'title' => $title,
      'field_appverse_repo' => $repo->id(),
      'moderation_state' => $state,
    ]);
    $app->save();
    return $app;
  }

  /**
   * queryMemberAppIds finds member apps, optionally restricted to published.
   */
  public function testQueryMemberAppIds(): void {
    $repo = $this->makeRepo();
    $pub = $this->makeApp('Pub', $repo, 'published');
    $this->makeApp('Draft', $repo, 'draft');
    $service = $this->container->get('ood_software.repo_member_apps');

    self::assertCount(2, $service->queryMemberAppIds($repo), 'All member apps.');
    self::assertSame(
      [(int) $pub->id()],
      $service->queryMemberAppIds($repo, TRUE),
      'published_only returns just the published app.'
    );
  }

  /**
   * The unpublish cascade drops published members to draft WITHOUT throwing.
   *
   * This is the #2 regression guard: the save runs on a moderated node with
   * no prior validate(), which throws EntityStorageException on D10.2+ unless
   * setValidationRequired(FALSE) is set — which the service always does.
   */
  public function testCascadeUnpublishDoesNotThrow(): void {
    $repo = $this->makeRepo();
    $app = $this->makeApp('Member', $repo, 'published');
    $service = $this->container->get('ood_software.repo_member_apps');

    $count = $service->cascadeModeration(
      $repo, 'draft', [], 'Auto-unpublished via parent Repo.', TRUE
    );

    self::assertSame(1, $count, 'One published member app was cascaded.');
    $reloaded = Node::load($app->id());
    self::assertSame('draft', $reloaded->get('moderation_state')->value);
    self::assertFalse((bool) $reloaded->get('status')->value, 'App is now unpublished.');
  }

  /**
   * The publish cascade only touches apps in the given from-states.
   */
  public function testCascadePublishRespectsFromStates(): void {
    $repo = $this->makeRepo();
    $draft = $this->makeApp('Draft', $repo, 'draft');
    $published = $this->makeApp('AlreadyPub', $repo, 'published');
    $service = $this->container->get('ood_software.repo_member_apps');

    $count = $service->cascadeModeration(
      $repo, 'published',
      ['draft', 'ready_for_review', 'needs_adjustment'],
      'Auto-published via parent Repo first-publish.'
    );

    self::assertSame(1, $count, 'Only the draft app was published; the already-published one was skipped.');
    self::assertSame('published', Node::load($draft->id())->get('moderation_state')->value);
    self::assertSame('published', Node::load($published->id())->get('moderation_state')->value);
  }

}
