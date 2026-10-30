<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\better_exposed_filters\Plugin\views\exposed_form\BetterExposedFilters;
use Drupal\views\Plugin\views\filter\InOperator;
use Drupal\views\ResultRow;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;

/**
 * Covers the my_appverse:page_admin filters reworked in D8-2927.
 *
 * Results are compared without de-duplicating: a repo listed once per member
 * app is exactly the defect this ticket fixes, and array_unique() would hide
 * it.
 *
 * @group ood_software
 */
class ManageReposFiltersTest extends KernelTestBase {

  use ProdConfigTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'options',
    'datetime', 'link', 'taxonomy', 'path', 'path_alias',
    'content_moderation', 'workflows', 'key', 'flag', 'views', 'file', 'ood_software',
    // page_admin's exposed form is Better Exposed Filters with Select2, and
    // Views has no fallback for a missing exposed-form plugin.
    'better_exposed_filters', 'select2',
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
      'node.type.appverse_software',
      'workflows.workflow.appverse_editorial',
      'field.storage.node.field_appverse_repo',
      'field.field.node.appverse_app.field_appverse_repo',
      'field.storage.node.field_appverse_software_implemen',
      'field.field.node.appverse_app.field_appverse_software_implemen',
      'field.storage.node.field_repo_url',
      'field.field.node.appverse_repo.field_repo_url',
      'field.storage.node.field_repo_assigned_reviewer',
      'field.field.node.appverse_repo.field_repo_assigned_reviewer',
      'field.storage.node.field_review_status',
      'field.field.node.appverse_repo.field_review_status',
      'field.storage.node.field_review_recommendation',
      'field.field.node.appverse_repo.field_review_recommendation',
    ]);
    $this->importViewConfigRaw('views.view.my_appverse');

    // Burn uid 1, whose superuser access would bypass what the display's own
    // access checks do.
    $this->createUser();
  }

  /**
   * Writes a view config object straight to storage; see
   * ManageReposFilterDefaultsTest::importViewConfigRaw() for why.
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
   * An appverse_repo in the review queue unless told otherwise.
   *
   * @param array<string, mixed> $values
   */
  private function makeRepo(string $title, string $moderationState = 'ready_for_review', array $values = []): NodeInterface {
    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => $title,
      'moderation_state' => $moderationState,
    ] + $values);
    $repo->save();
    return $repo;
  }

  /**
   * A member app of the repo, implementing the given software.
   *
   * @param int[] $softwareNids
   */
  private function makeApp(NodeInterface $repo, array $softwareNids = []): NodeInterface {
    $app = Node::create([
      'type' => 'appverse_app',
      'title' => $repo->label() . ' app ' . random_int(1, 99999),
      'field_appverse_repo' => $repo->id(),
      'field_appverse_software_implemen' => $softwareNids,
    ]);
    $app->save();
    return $app;
  }

  private function makeSoftware(string $title): NodeInterface {
    $software = Node::create(['type' => 'appverse_software', 'title' => $title]);
    $software->save();
    return $software;
  }

  private function getPageAdminView(): ViewExecutable {
    $view = Views::getView('my_appverse');
    $view->setDisplay('page_admin');
    return $view;
  }

  /**
   * One of the display's list filters, after initHandlers().
   */
  private function inOperator(ViewExecutable $view, string $id): InOperator {
    $filter = $view->filter[$id];
    $this->assertInstanceOf(InOperator::class, $filter);
    return $filter;
  }

  /**
   * The repo nids the display returned, in order, duplicates kept.
   *
   * @return int[]
   */
  private function resultNids(ViewExecutable $view): array {
    return array_map(static fn (ResultRow $row) => (int) $row->_entity->id(), $view->result);
  }

  /**
   * Runs page_admin with the given exposed input and returns resultNids().
   *
   * @param array<string, mixed> $input
   *
   * @return int[]
   */
  private function runPageAdmin(array $input = []): array {
    $view = $this->getPageAdminView();
    $view->setExposedInput($input);
    $view->execute();
    return $this->resultNids($view);
  }

  public function testRepoWithSeveralAppsAppearsOnce(): void {
    $monorepo = $this->makeRepo('Monorepo');
    $this->makeApp($monorepo);
    $this->makeApp($monorepo);
    $this->makeApp($monorepo);
    $single = $this->makeRepo('Single');
    $this->makeApp($single);

    $view = $this->getPageAdminView();
    $view->get_total_rows = TRUE;
    $view->execute();
    $nids = $this->resultNids($view);

    $this->assertCount(1, array_keys($nids, (int) $monorepo->id(), TRUE), 'A repo with three apps must be listed once.');
    $this->assertSame(2, (int) $view->total_rows, 'The pager must count repos, not app rows.');
  }

  public function testSoftwareUsedNarrowsToRepos(): void {
    $a = $this->makeSoftware('Alpha');
    $b = $this->makeSoftware('Beta');
    $unused = $this->makeSoftware('Unused');
    $repo1 = $this->makeRepo('One');
    $this->makeApp($repo1, [(int) $a->id()]);
    $repo2 = $this->makeRepo('Two');
    $this->makeApp($repo2, [(int) $b->id()]);
    $repo3 = $this->makeRepo('Three');
    $this->makeApp($repo3);

    $this->assertSame([(int) $repo1->id()], $this->runPageAdmin(['software' => (string) $a->id()]));
    $this->assertSame([], $this->runPageAdmin(['software' => (string) $unused->id()]));
  }

  public function testSoftwareUsedRepoWithTwoMatchingAppsAppearsOnce(): void {
    $a = $this->makeSoftware('Alpha');
    $repo = $this->makeRepo('Both apps use Alpha');
    $this->makeApp($repo, [(int) $a->id()]);
    $this->makeApp($repo, [(int) $a->id()]);

    $this->assertSame([(int) $repo->id()], $this->runPageAdmin(['software' => (string) $a->id()]));
  }

  public function testSoftwareOptionsAreSoftwareInUse(): void {
    $a = $this->makeSoftware('Alpha');
    $b = $this->makeSoftware('Beta');
    $this->makeSoftware('Unused');
    $repo = $this->makeRepo('Repo');
    $this->makeApp($repo, [(int) $b->id()]);
    $this->makeApp($repo, [(int) $a->id()]);

    $view = $this->getPageAdminView();
    $view->initHandlers();
    $this->assertArrayHasKey('ood_repo_software_used', $view->filter);
    $this->assertSame(
      [(int) $a->id() => 'Alpha', (int) $b->id() => 'Beta'],
      $this->inOperator($view, 'ood_repo_software_used')->getValueOptions(),
    );
  }

  /**
   * The tillicum repo: its title does not contain its GitHub name.
   */
  private function makeTillicum(): NodeInterface {
    return $this->makeRepo('Llama.cpp WebUI', 'ready_for_review', [
      'field_repo_url' => ['uri' => 'https://github.com/UWrc/tillicum-llama-chat'],
    ]);
  }

  public function testSearchMatchesTitle(): void {
    $repo = $this->makeTillicum();
    $this->makeRepo('Something else');
    $this->assertSame([(int) $repo->id()], $this->runPageAdmin(['title' => 'Llama']));
  }

  public function testSearchMatchesRepoUrl(): void {
    $repo = $this->makeTillicum();
    $this->makeRepo('Something else', 'ready_for_review', [
      'field_repo_url' => ['uri' => 'https://github.com/OSC/bc_osc_vmd'],
    ]);
    $this->assertSame([(int) $repo->id()], $this->runPageAdmin(['title' => 'tillicum']));
    $this->assertSame([(int) $repo->id()], $this->runPageAdmin(['title' => 'UWrc']));
  }

  public function testSearchIsCaseInsensitive(): void {
    $repo = $this->makeTillicum();
    $this->assertSame([(int) $repo->id()], $this->runPageAdmin(['title' => 'TILLICUM']));
  }

  public function testSearchWildcardsAreLiteral(): void {
    $this->makeTillicum();
    $this->assertSame([], $this->runPageAdmin(['title' => '%']));
    $this->assertSame([], $this->runPageAdmin(['title' => '_']));
  }

  public function testEmptySearchAddsNothing(): void {
    $repo = $this->makeTillicum();
    $other = $this->makeRepo('Something else');
    $nids = $this->runPageAdmin(['title' => '']);
    sort($nids);
    $this->assertSame([(int) $repo->id(), (int) $other->id()], $nids);
  }

  /**
   * An active user with the reviewer role ReviewAssignment::reviewers() reads.
   */
  private function makeReviewer(string $name): UserInterface {
    if (!Role::load('appverse_pm')) {
      Role::create(['id' => 'appverse_pm', 'label' => 'Appverse PM'])->save();
    }
    $user = $this->createUser([], $name);
    $user->addRole('appverse_pm');
    $user->save();
    return $user;
  }

  private function assignTo(NodeInterface $repo, ?UserInterface $user): void {
    $repo->set('field_repo_assigned_reviewer', $user?->id());
    $repo->save();
  }

  public function testAssignedOptionsListReviewers(): void {
    $zed = $this->makeReviewer('zed');
    $amy = $this->makeReviewer('amy');

    $view = $this->getPageAdminView();
    $view->initHandlers();
    $options = $this->inOperator($view, 'ood_assigned_reviewer')->getValueOptions();

    $this->assertSame(['me', 'none', (int) $amy->id(), (int) $zed->id()], array_keys($options));
    $this->assertSame(['amy', 'zed'], array_map('strval', array_slice(array_values($options), 2)));
  }

  public function testAssignedNamedReviewerReturnsTheirRepos(): void {
    $one = $this->makeReviewer('one');
    $two = $this->makeReviewer('two');
    $repoOne = $this->makeRepo('Repo one');
    $this->assignTo($repoOne, $one);
    $repoTwo = $this->makeRepo('Repo two');
    $this->assignTo($repoTwo, $two);
    $this->makeRepo('Unassigned repo');

    $this->assertSame([(int) $repoOne->id()], $this->runPageAdmin(['assigned' => (string) $one->id()]));
  }

  public function testAssignedMeAndNoneUnchanged(): void {
    $one = $this->makeReviewer('one');
    $repoOne = $this->makeRepo('Repo one');
    $this->assignTo($repoOne, $one);
    $unassigned = $this->makeRepo('Unassigned repo');
    $this->setCurrentUser($one);

    $this->assertSame([(int) $repoOne->id()], $this->runPageAdmin(['assigned' => 'me']));
    $this->assertSame([(int) $unassigned->id()], $this->runPageAdmin(['assigned' => 'none']));
  }

  public function testAssignedToNonReviewerListedUnderAnyNotUnassigned(): void {
    $former = $this->createUser([], 'former_reviewer');
    $repo = $this->makeRepo('Assigned to a former reviewer');
    $this->assignTo($repo, $former);

    $this->assertContains((int) $repo->id(), $this->runPageAdmin([]));
    $this->assertNotContains((int) $repo->id(), $this->runPageAdmin(['assigned' => 'none']));
  }

  public function testAssignedOptionsCarryUserListTag(): void {
    $view = $this->getPageAdminView();
    $view->initHandlers();
    $this->assertContains('user_list', $view->filter['ood_assigned_reviewer']->getCacheTags());
  }

  /**
   * A repo whose AI report has the given run status and recommendation.
   */
  private function makeReported(string $title, ?string $status, ?string $recommendation = NULL): NodeInterface {
    return $this->makeRepo($title, 'ready_for_review', array_filter([
      'field_review_status' => $status,
      'field_review_recommendation' => $recommendation,
    ]));
  }

  public function testAiReportOptions(): void {
    $view = $this->getPageAdminView();
    $view->initHandlers();
    $this->assertArrayHasKey('ood_ai_report', $view->filter);
    $options = array_map('strval', $this->inOperator($view, 'ood_ai_report')->getValueOptions());
    $this->assertSame([
      'not_started' => 'Not started',
      'queued' => 'Queued',
      'running' => 'Running',
      'failed' => 'Failed',
      'accept' => 'Suggests Accept',
      'accept_with_suggestions' => 'Suggests Accept with suggestions',
      'request_changes' => 'Suggests Request changes',
      'reject' => 'Suggests Reject',
    ], $options);
  }

  public function testAiReportEachOptionReturnsItsRepos(): void {
    $repos = [
      'not_started' => $this->makeReported('Never run', NULL),
      'queued' => $this->makeReported('Queued', 'pending'),
      'running' => $this->makeReported('Running', 'in_progress'),
      'failed' => $this->makeReported('Failed', 'error'),
      'accept' => $this->makeReported('Accept', 'complete', 'accepted'),
      'accept_with_suggestions' => $this->makeReported('Accept with suggestions', 'complete', 'accepted_with_suggestions'),
      'request_changes' => $this->makeReported('Request changes', 'complete', 'changes_requested'),
      'reject' => $this->makeReported('Reject', 'complete', 'rejected'),
    ];
    foreach ($repos as $option => $repo) {
      $this->assertSame([(int) $repo->id()], $this->runPageAdmin(['ai_report' => [$option]]), "Option $option");
    }
  }

  public function testAiReportRerunIsQueued(): void {
    // A rerun sets the status to pending and clears the recommendation.
    $repo = $this->makeReported('Rerun', 'pending');
    $this->assertSame([(int) $repo->id()], $this->runPageAdmin(['ai_report' => ['queued']]));
    $this->assertSame([], $this->runPageAdmin(['ai_report' => ['not_started']]));
  }

  public function testAiReportOptionsAreOred(): void {
    $changes = $this->makeReported('Request changes', 'complete', 'changes_requested');
    $failed = $this->makeReported('Failed', 'error');
    $this->makeReported('Running', 'in_progress');
    $nids = $this->runPageAdmin(['ai_report' => ['request_changes', 'failed']]);
    sort($nids);
    $this->assertSame([(int) $changes->id(), (int) $failed->id()], $nids);
  }

  public function testAiReportCompleteWithoutRecommendationMatchesNoSuggestion(): void {
    $this->makeReported('Unrecognized recommendation', 'complete');
    foreach (['accept', 'accept_with_suggestions', 'request_changes', 'reject', 'not_started'] as $option) {
      $this->assertSame([], $this->runPageAdmin(['ai_report' => [$option]]), "Option $option");
    }
  }

  /**
   * Builds the display's exposed form and returns its render array.
   *
   * @return array<string, mixed>
   */
  private function exposedForm(string $display = 'page_admin'): array {
    $view = Views::getView('my_appverse');
    $view->setDisplay($display);
    $view->build();
    return $view->exposed_widgets;
  }

  public function testPageAdminExposedFormIsBefWithSelect2(): void {
    $admin = $this->getPageAdminView();
    $exposedForm = $admin->display_handler->getPlugin('exposed_form');
    $this->assertSame('bef', $exposedForm->getPluginId());
    $this->assertInstanceOf(BetterExposedFilters::class, $exposedForm);
    $bef = $exposedForm->options['bef']['filter'];
    foreach (['moderation_state', 'ood_ai_report', 'ood_assigned_reviewer', 'field_repo_organization_target_id', 'ood_repo_software_used', 'field_repo_validation_st_value'] as $filter) {
      $this->assertSame('bef_select2', $bef[$filter]['plugin_id'] ?? NULL, "$filter uses Select2");
    }

    $user = Views::getView('my_appverse');
    $user->setDisplay('page_user');
    $this->assertSame('basic', $user->display_handler->getPlugin('exposed_form')->getPluginId(), 'page_user keeps its own exposed form.');
  }

  public function testModerationOptionsStillTrimmed(): void {
    $form = $this->exposedForm()['moderation_group'];
    // The select2 element turns itself back into #type select once processed,
    // for validation; its #select2 settings are what make it render as one.
    $this->assertArrayHasKey('#select2', $form['moderation_state']);
    $keys = [];
    array_walk_recursive($form['moderation_state']['#options'], static function ($label, $key) use (&$keys): void {
      $keys[] = (string) $key;
    });
    $this->assertNotEmpty($keys);
    foreach ($keys as $key) {
      $this->assertStringStartsWith('appverse_editorial-', $key);
    }
  }

  /**
   * The link is in the rendered form, and keeps the other filters' values.
   *
   * Asserted on rendered HTML: an element description set on the form array
   * is not printed by every theme's exposed-form template.
   */
  public function testShowAllStatesLinkRendersAndKeepsFilters(): void {
    $view = Views::getView('my_appverse');
    $view->setDisplay('page_admin');
    $view->setExposedInput(['title' => 'tillicum', 'assigned' => 'none']);
    $view->build();
    $html = (string) \Drupal::service('renderer')->renderInIsolation($view->exposed_widgets);

    $this->assertMatchesRegularExpression('#<a [^>]*href="([^"]*)"[^>]*>Show all states</a>#', $html);
    preg_match('#<a [^>]*href="([^"]*)"[^>]*>Show all states</a>#', $html, $m);
    parse_str((string) parse_url(html_entity_decode($m[1]), PHP_URL_QUERY), $query);
    $this->assertSame('All', $query['moderation_state'] ?? NULL);
    $this->assertSame('tillicum', $query['title'] ?? NULL);
    $this->assertSame('none', $query['assigned'] ?? NULL);
  }

  /**
   * Repos that tie on the sort columns still page in one stable order.
   *
   * Many imported repos share a created time; without a final tiebreaker the
   * database may order ties differently for each page's query, repeating
   * some repos across pages and never showing others.
   */
  public function testPagingIsStableWhenSortColumnsTie(): void {
    $view = $this->getPageAdminView();
    $sorts = array_keys($view->display_handler->getOption('sorts'));
    $this->assertSame('nid', end($sorts), 'The last sort must be the node id.');

    $created = 1752600000;
    $expected = [];
    for ($i = 0; $i < 25; $i++) {
      $expected[] = (int) $this->makeRepo("Tied $i", 'published', ['created' => $created])->id();
    }
    $seen = [];
    foreach ([0, 1] as $page) {
      $view = $this->getPageAdminView();
      $view->setExposedInput(['moderation_state' => 'All']);
      $view->setCurrentPage($page);
      $view->execute();
      $seen = array_merge($seen, $this->resultNids($view));
    }
    sort($seen);
    $this->assertSame($expected, $seen, 'Two pages must list every repo exactly once.');
  }

  /**
   * The exported view carries the cache metadata its handlers declare.
   *
   * Views uses the stored copy when there is one, and a config import does
   * not recompute it, so a stale copy would leave the reviewer and software
   * option lists cached past a change.
   */
  public function testExportedCacheMetadataIsCurrent(): void {
    $source = new FileStorage(DRUPAL_ROOT . '/sites/default/config/default');
    $stored = $source->read('views.view.my_appverse')['display']['page_admin']['cache_metadata'];
    $this->assertContains('user_list', $stored['tags']);
    $this->assertContains('node_list', $stored['tags']);
    $this->assertContains('user', $stored['contexts']);
  }

}
