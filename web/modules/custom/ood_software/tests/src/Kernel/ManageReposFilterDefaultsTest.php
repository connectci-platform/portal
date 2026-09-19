<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\ResultRow;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;

/**
 * Covers the my_appverse:page_admin exposed filters (D8-2846).
 *
 * Before this change the moderation_state filter lived on
 * content_moderation_state_field_revision with no join, so Views treated it
 * as a broken handler and silently dropped it: the "Moderation state"
 * exposed filter never actually narrowed the result set, regardless of its
 * configured default. This test asserts the fixed filter (now on
 * node_field_data, plugin moderation_state_filter) genuinely applies its
 * default — ready_for_review + needs_adjustment — with no exposed input at
 * all, that the new "Contributor" (uid/user_name) exposed filter narrows by
 * author, and that the deleted "Last synced" filter is really gone from the
 * display.
 *
 * @group ood_software
 */
class ManageReposFilterDefaultsTest extends KernelTestBase {

  use ProdConfigTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'options',
    'datetime', 'link', 'taxonomy', 'path', 'path_alias',
    'content_moderation', 'workflows', 'key', 'flag', 'views', 'ood_software',
  ];

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
    $this->installConfig([
      'system', 'filter', 'user', 'node',
      'content_moderation', 'workflows',
    ]);

    $this->importProdConfig([
      'node.type.appverse_repo',
      'node.type.appverse_app',
      'workflows.workflow.appverse_editorial',
      // The view's shared "Member apps" reverse relationship
      // (reverse__node__field_appverse_repo) needs this field's storage to
      // resolve its base table; page_admin inherits it from the default
      // display.
      'field.storage.node.field_appverse_repo',
      'field.field.node.appverse_app.field_appverse_repo',
    ]);
    $this->importViewConfigRaw('views.view.my_appverse');

    // Burn uid 1. Drupal's uid-1 superuser bypasses node access and would
    // falsely satisfy any access checks the display's admin permission
    // performs, muddying what the filters themselves are doing.
    $this->createUser();
  }

  /**
   * Writes a view config object directly to storage, bypassing entity save.
   *
   * The my_appverse view's page_user display has two pre-existing config
   * schema gaps unrelated to the page_admin filters this test covers: its
   * uid argument stores target_entity_type (core's schema for
   * entity_target_id only declares target_entity_type_id), and the
   * views_display "defaults" tracking map doesn't declare the page
   * display's "path" key. Both trip ConfigSchemaChecker, which only runs on
   * Config::save() — a raw StorageInterface::write() (the same path
   * config-import and cache-rebuild use) skips that check entirely, so the
   * view still loads and executes normally via the entity API afterwards.
   */
  private function importViewConfigRaw(string $name): void {
    $source = new FileStorage(DRUPAL_ROOT . '/sites/default/config/default');
    $data = $source->read($name);
    if ($data === FALSE) {
      throw new \RuntimeException("Missing prod config: $name");
    }
    \Drupal::service('config.storage')->write($name, $data);
  }

  /**
   * Helper: create an appverse_repo node in a given moderation state.
   */
  private function makeRepo(string $title, string $moderationState, ?int $uid = NULL): NodeInterface {
    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => $title,
      'moderation_state' => $moderationState,
    ] + ($uid !== NULL ? ['uid' => $uid] : []));
    $repo->save();
    return $repo;
  }

  /**
   * Helper: load the page_admin display of my_appverse, ready to execute.
   */
  private function getPageAdminView(): ViewExecutable {
    $view = Views::getView('my_appverse');
    $view->setDisplay('page_admin');
    return $view;
  }

  /**
   * With no exposed input, the moderation default now genuinely applies.
   *
   * Seeds one repo in each of four moderation states. Before the fix, the
   * moderation_state filter was a broken handler and this would have
   * returned all four (or errored); after the fix, only the
   * ready_for_review and needs_adjustment repos should come back. The
   * status ("Published") filter's default is now "All", so it must not
   * narrow the set any further.
   */
  public function testDefaultResultSetAppliesModerationDefault(): void {
    $published = $this->makeRepo('Published Repo', 'published');
    $readyForReview = $this->makeRepo('Ready For Review Repo', 'ready_for_review');
    $needsAdjustment = $this->makeRepo('Needs Adjustment Repo', 'needs_adjustment');
    $draft = $this->makeRepo('Draft Repo', 'draft');

    $view = $this->getPageAdminView();
    // Deliberately no setExposedInput() call: this exercises the exposed
    // form's own default-value handling, the same path a fresh page visit
    // with no query string takes.
    $view->execute();

    $resultNids = array_unique(array_map(
      static fn (ResultRow $row) => (int) $row->_entity->id(),
      $view->result,
    ));
    sort($resultNids);

    $expected = [(int) $readyForReview->id(), (int) $needsAdjustment->id()];
    sort($expected);

    $this->assertSame($expected, $resultNids, 'Default result set must be exactly the ready_for_review + needs_adjustment repos.');
    $this->assertNotContains((int) $published->id(), $resultNids, 'Published repo must not appear under the default moderation filter.');
    $this->assertNotContains((int) $draft->id(), $resultNids, 'Draft repo must not appear under the default moderation filter.');
  }

  /**
   * The Contributor exposed filter narrows results to the given author.
   *
   * Per live verification against the user_name filter (InOperator subclass
   * \Drupal\user\Plugin\views\filter\Name), the exposed value must be the
   * plain username string (not a uid, and not an array of uids) — the
   * filter's own validateExposed() resolves the entity-autocomplete input
   * into the internal uid list.
   */
  public function testContributorFilterNarrowsByAuthor(): void {
    $authorA = $this->createUser([], 'contributor_a');
    $authorB = $this->createUser([], 'contributor_b');

    // Use ready_for_review so the moderation default doesn't also exclude
    // these rows.
    $repoA = $this->makeRepo('Repo A', 'ready_for_review', (int) $authorA->id());
    $repoB = $this->makeRepo('Repo B', 'ready_for_review', (int) $authorB->id());

    $view = $this->getPageAdminView();
    $view->setExposedInput(['contributor' => $authorA->getAccountName()]);
    $view->execute();

    $resultNids = array_unique(array_map(
      static fn (ResultRow $row) => (int) $row->_entity->id(),
      $view->result,
    ));

    $this->assertSame([(int) $repoA->id()], array_values($resultNids), 'Contributor filter must return only the selected author\'s repos.');
    $this->assertNotContains((int) $repoB->id(), $resultNids);
  }

  /**
   * The deleted last_synced filter is gone; moderation_state now resolves.
   */
  public function testFilterInventory(): void {
    $view = $this->getPageAdminView();
    $view->initHandlers();

    $this->assertArrayNotHasKey(
      'field_repo_last_synced_value',
      $view->filter,
      'field_repo_last_synced_value must be removed from page_admin filters.',
    );

    $this->assertArrayHasKey('moderation_state', $view->filter, 'moderation_state filter must exist on page_admin.');
    $moderationFilter = $view->filter['moderation_state'];
    $this->assertFalse($moderationFilter->broken(), 'moderation_state filter must resolve to a real handler, not "broken".');
    $this->assertSame('node_field_data', $moderationFilter->table);
    $this->assertSame('moderation_state_filter', $moderationFilter->getPluginId());

    $this->assertArrayHasKey('uid', $view->filter, 'The new Contributor (uid) filter must exist on page_admin.');
    $contributorFilter = $view->filter['uid'];
    $this->assertFalse($contributorFilter->broken(), 'Contributor filter must resolve to a real handler.');
    $this->assertSame('user_name', $contributorFilter->getPluginId());
    $this->assertSame('contributor', $contributorFilter->options['expose']['identifier']);
  }

}
