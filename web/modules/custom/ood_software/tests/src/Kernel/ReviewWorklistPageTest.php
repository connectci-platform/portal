<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Form\ReviewPageForm;
use Drupal\ood_software\Service\FindingOverride;
use Drupal\ood_software\Service\ReviewPageData;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\Tests\ood_software\Kernel\Traits\AppverseModerationTestTrait;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The reviewer's page as worklists: one per app, blocking first.
 *
 * @group ood_software
 */
class ReviewWorklistPageTest extends KernelTestBase {

  use AppverseModerationTestTrait;
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
   * The repo's owner.
   */
  protected UserInterface $owner;

  /**
   * A reviewer.
   */
  protected UserInterface $reviewer;

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
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'filter', 'user', 'node', 'content_moderation', 'workflows']);
    $names = [
      'node.type.appverse_repo',
      'node.type.appverse_app',
      'node.type.appverse_review',
      'workflows.workflow.appverse_editorial',
      'workflows.workflow.appverse_review_workflow',
      'paragraphs.paragraphs_type.review_verdict',
      'paragraphs.paragraphs_type.review_finding',
      'paragraphs.paragraphs_type.review_note',
      'field.storage.node.field_appverse_repo',
      'field.field.node.appverse_app.field_appverse_repo',
    ];
    $dir = DRUPAL_ROOT . '/sites/default/config/default';
    $patterns = [
      'storage.node.field_arv_',
      'field.node.appverse_review.',
      'storage.node.field_review_',
      'field.node.appverse_repo.field_review_',
      'storage.node.field_repo_url',
      'field.node.appverse_repo.field_repo_url',
      'storage.paragraph.field_rv',
      'field.paragraph.review_',
    ];
    foreach ($patterns as $pattern) {
      foreach (glob($dir . '/field.' . $pattern . '*.yml') as $file) {
        $names[] = basename($file, '.yml');
      }
    }
    $this->importProdConfig(array_values(array_unique($names)));
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('system.site')->set('name', 'Appverse')->set('mail', 'site@example.org')->save();
    // User 1 bypasses access checks, so take that id first.
    $this->createUser([], 'admin');
    $this->owner = $this->createUser(['access content'], 'owner');
    $this->reviewer = $this->createUser(['access content', 'administer appverse content'], 'reviewer');
    $this->setCurrentUser($this->reviewer);
  }

  /**
   * A tool finding.
   *
   * @param string $rule
   *   The rule code.
   * @param string $severity
   *   Its severity.
   * @param string $result
   *   As stored: fail, warn or pass.
   * @param string $summary
   *   Its summary.
   * @param array<string, string> $extra
   *   Other field values, such as field_rvf_aspect.
   */
  protected function finding(string $rule, string $severity, string $result, string $summary, array $extra = []): ParagraphInterface {
    $p = Paragraph::create([
      'type' => 'review_finding',
      'field_rvf_rule' => $rule,
      'field_rvf_severity' => $severity,
      'field_rvf_result' => $result,
      'field_rvf_summary' => $summary,
      'field_rvf_evidence' => 'path/file.sh:3',
    ] + $extra);
    $p->save();
    return $p;
  }

  /**
   * A review with one verdict per app.
   *
   * @param array<string, array<int, \Drupal\paragraphs\ParagraphInterface>> $apps
   *   App id => its findings.
   * @param bool $sent
   *   Whether the decision is sent.
   */
  protected function makeReview(array $apps, bool $sent = FALSE): NodeInterface {
    $repo = $this->makeRepo('ready_for_review');
    $verdicts = [];
    foreach ($apps as $appId => $findings) {
      $app = Node::create([
        'type' => 'appverse_app',
        'title' => 'App ' . $appId,
        'uid' => $this->owner->id(),
        'field_appverse_repo' => $repo->id(),
        'moderation_state' => 'ready_for_review',
      ]);
      $app->save();
      $verdict = Paragraph::create([
        'type' => 'review_verdict',
        'field_rvv_app_ref' => $app->id(),
        'field_rvv_app_id' => $appId,
        'field_rvv_conclusion' => $sent ? 'request_changes' : NULL,
        'field_rvv_findings' => $findings,
      ]);
      $verdict->save();
      $verdicts[] = $verdict;
    }
    $review = Node::create([
      'type' => 'appverse_review',
      'title' => 'Review: example/app',
      'uid' => $this->reviewer->id(),
      'status' => 0,
      'field_arv_repo' => $repo->id(),
      'field_arv_verdicts' => $verdicts,
      'field_arv_reviewed_at' => 1700000000,
    ]);
    if ($sent) {
      $review->set('field_arv_decision_sent_at', 1700000100);
      $review->set('field_arv_decision_sent_by', $this->reviewer->id());
      $apps = [];
      foreach ($verdicts as $verdict) {
        $apps[(string) $verdict->id()] = 'request_changes';
      }
      $review->set('field_arv_sent_decisions', json_encode(['apps' => $apps, 'response' => 'Sent.']));
    }
    $review->save();
    return $review;
  }

  /**
   * The review's verdict pids, in order.
   *
   * @return array<int, string>
   *   The pids.
   */
  protected function verdictPids(NodeInterface $review): array {
    return array_map(static fn ($v): string => (string) $v->id(), Node::load($review->id())->get('field_arv_verdicts')->referencedEntities());
  }

  /**
   * Pushes a request for the review page.
   */
  protected function pushRequest(NodeInterface $review, string $method = 'GET'): void {
    $request = Request::create('/appverse/review/' . $review->id(), $method);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
  }

  /**
   * Renders the review page as a user.
   */
  protected function renderAs(UserInterface $user, NodeInterface $review): string {
    $this->setCurrentUser($user);
    $this->pushRequest($review);
    $renderer = $this->container->get('renderer');
    $context = new RenderContext();
    $form = $renderer->executeInRenderContext($context, fn () => $this->container->get('form_builder')->getForm(ReviewPageForm::class, Node::load($review->id())));
    if (!$context->isEmpty()) {
      $context->pop()->merge(BubbleableMetadata::createFromRenderArray($form))->applyTo($form);
    }
    $html = (string) $renderer->renderRoot($form);
    $this->container->get('request_stack')->pop();
    return $html;
  }

  /**
   * Submits the review page as the reviewer.
   *
   * @param \Drupal\node\NodeInterface $review
   *   The review.
   * @param array<string, mixed> $values
   *   The posted values, with the pressed button's name => label.
   */
  protected function submit(NodeInterface $review, array $values): FormStateInterface {
    $this->setCurrentUser($this->reviewer);
    $this->pushRequest($review, 'POST');
    $form_state = (new FormState())->setValues($values);
    $this->container->get('form_builder')->submitForm(ReviewPageForm::class, $form_state, Node::load($review->id()));
    $this->container->get('request_stack')->pop();
    return $form_state;
  }

  /**
   * Where an element's id sits in the page.
   */
  protected function at(string $html, string $id): int {
    $pos = strpos($html, 'id="' . $id . '"');
    $this->assertNotFalse($pos, "$id is on the page");
    return (int) $pos;
  }

  /**
   * Blocking first, then by severity; the floor is named on the row.
   */
  public function testFindingsRenderInWorklistOrder(): void {
    $doc = $this->finding('QUA-01', 'low', 'warn', 'Docs are thin.', ['field_rvf_aspect' => 'documentation']);
    $oodt = $this->finding('OODT-02', 'critical', 'fail', 'Token in the repo.', ['field_rvf_aspect' => 'security']);
    $str = $this->finding('STR-01', 'low', 'fail', 'No LICENSE.', ['field_rvf_aspect' => 'structure']);
    $review = $this->makeReview(['root' => [$doc, $oodt, $str]]);
    $pid = $this->verdictPids($review)[0];
    $html = $this->renderAs($this->reviewer, $review);

    // Both FAILs block: the critical one first, then the low one; the WARN
    // blocks nothing and comes after them.
    $this->assertLessThan($this->at($html, 'finding-' . $str->id()), $this->at($html, 'finding-' . $oodt->id()));
    $this->assertLessThan($this->at($html, 'finding-' . $doc->id()), $this->at($html, 'finding-' . $str->id()));
    $this->assertStringContainsString('At least Request changes', $html);
    $this->assertStringContainsString('At least Reject', $html);
    // The headings sit around them.
    $list = substr($html, $this->at($html, 'worklist-app-' . $pid));
    $this->assertLessThan(strpos($list, 'id="finding-' . $oodt->id() . '"'), strpos($list, '>Blocking<'));
    $this->assertLessThan(strpos($list, 'id="finding-' . $doc->id() . '"'), strpos($list, '>Findings<'));
    $this->assertGreaterThan(strpos($list, 'id="finding-' . $str->id() . '"'), strpos($list, '>Findings<'));
    // Each row says its section.
    $this->assertMatchesRegularExpression('/id="finding-' . $doc->id() . '"[^>]*data-section="documentation"/', $html);
    $this->assertStringContainsString('worklist-repo', $html);
    // A single app's list is headed by its name too.
    $this->assertStringContainsString('<h2 class="app-name">App root</h2>', $html);
  }

  /**
   * A list with nothing in it says so.
   */
  public function testEmptyListSaysNoFindings(): void {
    $review = $this->makeReview(['root' => []]);
    $html = $this->renderAs($this->reviewer, $review);
    $this->assertStringContainsString('No findings', $html);
    $this->assertStringNotContainsString('>Blocking<', $html);
  }

  /**
   * The ratings line keeps the badge, the edit icon and the override.
   */
  public function testRatingOverrideStaysOpen(): void {
    $review = $this->makeReview(['root' => []]);
    $verdict = Paragraph::load($this->verdictPids($review)[0]);
    $verdict->set('field_rvv_indicators_default', json_encode(['portability' => ['level' => 'solid']]));
    $verdict->set('field_rvv_port_level', 'needs_attention');
    $verdict->save();
    $html = $this->renderAs($this->reviewer, $review);
    $this->assertStringContainsString('class="arv-ratings"', $html);
    $this->assertSame(1, substr_count($html, 'arv-rating level-changed'));
    $this->assertStringContainsString('Changed from automated rating', $html);
    $this->assertSame(3, substr_count($html, 'class="edit-icon-btn"'), 'Portability, Documentation and Upkeep.');
    $this->assertStringContainsString('name="level[' . $verdict->id() . '][portability]"', $html);
    $this->assertStringContainsString('name="maint_level"', $html);
  }

  /**
   * A dismissed finding leaves the list for its fold, keeping its controls.
   */
  public function testDismissedFindingFolds(): void {
    $dismissed = $this->finding('QUA-03', 'medium', 'fail', 'Not really a defect.', ['field_rvf_aspect' => 'quality']);
    $tool = ['severity' => 'medium', 'summary' => 'Not really a defect.', 'evidence' => 'path/file.sh:3'];
    FindingOverride::apply($dismissed, FindingOverride::normalize(['dismissed' => 1] + $tool, $tool), (int) $this->reviewer->id(), 1700000000);
    $dismissed->save();
    $review = $this->makeReview(['root' => [$dismissed]]);
    $pid = $this->verdictPids($review)[0];
    $html = $this->renderAs($this->reviewer, $review);
    $fold = $this->at($html, 'fold-app-' . $pid . '-dismissed');
    $this->assertStringContainsString('Dismissed by the reviewer (1)', $html);
    $this->assertGreaterThan($fold, $this->at($html, 'finding-' . $dismissed->id()));
    $this->assertMatchesRegularExpression('/id="finding-' . $dismissed->id() . '"[^>]*data-section="code_quality"/', $html);
    $this->assertSame(1, substr_count($html, 'name="prose[' . $dismissed->id() . ']"'));
    // Out of the list itself, so the list has nothing in it.
    $list = substr($html, $this->at($html, 'worklist-app-' . $pid), $fold - $this->at($html, 'worklist-app-' . $pid));
    $this->assertStringContainsString('No findings', $list);
    // Add a finding here would move the add form into the fold.
    $this->assertStringNotContainsString('arv-add-here', $html);
  }

  /**
   * The duplicate check shows the other published apps for the software.
   */
  public function testSameSoftwareAppsShowInDuplicateCheck(): void {
    $review = $this->makeReview(['root' => []]);
    $verdict = Paragraph::load($this->verdictPids($review)[0]);
    $verdict->set('field_rvv_catalog', json_encode([
      'shape' => 'declared',
      'software' => ['status' => 'match', 'entry' => 'jupyter'],
      'same_software_apps' => [
        ['title' => 'Other Jupyter', 'github_url' => 'https://github.com/other/jupyter'],
        ['title' => 'This one', 'github_url' => 'https://github.com/example/app', 'this_repo' => TRUE],
      ],
    ]));
    $verdict->save();
    foreach ([FALSE, TRUE] as $sent) {
      if ($sent) {
        $node = Node::load($review->id());
        $node->set('field_arv_decision_sent_at', 1700000100);
        $sentApps = [(string) $verdict->id() => 'request_changes'];
        $node->set('field_arv_sent_decisions', json_encode(['apps' => $sentApps, 'response' => 'Sent.']));
        $node->save();
      }
      $html = $this->renderAs($this->reviewer, $review);
      $this->assertSame(1, preg_match('/<div class="duplicate-check-block" id="duplicate-' . $verdict->id() . '">(.*?)<\/div>\s*<h3/s', $html, $m), $sent ? 'sent' : 'editable');
      $this->assertStringContainsString('<a href="https://github.com/other/jupyter" target="_blank" rel="noopener">Other Jupyter</a>', $m[1]);
      $this->assertStringContainsString('1 published app from other repos implements jupyter', $m[1]);
      // Not a worklist item as well.
      $this->assertSame(1, substr_count($html, 'Other Jupyter'));
    }
  }

  /**
   * An every-app blocker shows once with its controls, and as a link elsewhere.
   */
  public function testReferenceRowHasNoControls(): void {
    $high = $this->finding('OODT-01', 'high', 'fail', 'Runs input through a shell.', ['field_rvf_aspect' => 'security']);
    $review = $this->makeReview(['alpha' => [$high], 'beta' => []]);
    $pid = (string) $high->id();
    $html = $this->renderAs($this->reviewer, $review);
    $this->assertSame(1, substr_count($html, 'id="finding-' . $pid . '"'));
    $this->assertSame(1, substr_count($html, 'name="prose[' . $pid . ']"'));
    $this->assertStringContainsString('href="#finding-' . $pid . '"', $html);
    $this->assertStringContainsString('Blocks every app', $html);
    $this->assertStringContainsString('in App alpha', $html);
    // Two apps, so each list is headed by its app.
    $this->assertStringContainsString('<h2 class="app-name">App alpha</h2>', $html);
    $this->assertStringContainsString('<h2 class="app-name">App beta</h2>', $html);
    // Each app list holds a blocker and nothing else.
    $this->assertSame(2, substr_count($html, 'No other findings'));
  }

  /**
   * Several every-app blockers from one app read as one row elsewhere.
   */
  public function testRepeatedBlockersCollapseToOneReference(): void {
    $first = $this->finding('OODT-01', 'critical', 'fail', 'Runs input through a shell.', ['field_rvf_aspect' => 'security']);
    $second = $this->finding('OODT-02', 'high', 'fail', 'Token in the repo.', ['field_rvf_aspect' => 'security']);
    $review = $this->makeReview(['alpha' => [$first, $second], 'beta' => []]);
    $html = $this->renderAs($this->reviewer, $review);
    foreach ([$first, $second] as $finding) {
      $this->assertSame(1, substr_count($html, 'id="finding-' . $finding->id() . '"'));
      $this->assertSame(1, substr_count($html, 'name="prose[' . $finding->id() . ']"'));
    }
    $this->assertSame(1, substr_count($html, 'finding--reference'));
    $this->assertStringContainsString('<a href="#finding-' . $first->id() . '">2 blocking findings in App alpha apply to every app</a>', $html);
    $this->assertStringNotContainsString('href="#finding-' . $second->id() . '"', $html);
  }

  /**
   * The repo gates' evidence, failures included, is its own fold.
   */
  public function testToolEvidenceIsNotPassedChecks(): void {
    $review = $this->makeReview(['root' => []]);
    $review->set('field_arv_repo_criteria', json_encode(['license' => 'fail', 'public' => 'pass']));
    $review->set('field_arv_gate_evidence', json_encode([
      ['result' => 'FAIL', 'rule' => 'STR-01', 'evidence' => 'No LICENSE file at the root'],
      ['result' => 'PASS', 'evidence' => 'Repository public'],
    ]));
    $review->save();
    $html = $this->renderAs($this->reviewer, $review);
    $passed = $this->fold($html, 'fold-repo-passed');
    $evidence = $this->fold($html, 'fold-repo-evidence');
    $this->assertStringContainsString('<summary>Tool evidence</summary>', $evidence);
    $this->assertStringContainsString('No LICENSE file at the root', $evidence);
    $this->assertStringContainsString('gate-result--fail', $evidence);
    $this->assertStringNotContainsString('No LICENSE file at the root', $passed);
    $this->assertStringNotContainsString('gate-result', $passed);
    $this->assertLessThan($this->at($html, 'fold-repo-evidence'), $this->at($html, 'fold-repo-passed'));
  }

  /**
   * The HTML of one top-level fold, by id.
   */
  protected function fold(string $html, string $id): string {
    $start = $this->at($html, $id);
    // The id sits inside the fold's opening tag, so the fold is open at
    // depth 1; its own </details> takes it back to 0.
    $depth = 1;
    $offset = $start;
    while (preg_match('/<details\b|<\/details>/', $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
      $depth += $m[0][0] === '</details>' ? -1 : 1;
      $offset = $m[0][1] + strlen($m[0][0]);
      if ($depth === 0) {
        break;
      }
    }
    return substr($html, $start, $offset - $start);
  }

  /**
   * Every anchor the catalog links to is on the page, once.
   */
  public function testSectionAnchorsStay(): void {
    $high = $this->finding('OODT-01', 'high', 'fail', 'Runs input through a shell.', ['field_rvf_aspect' => 'security']);
    $low = $this->finding('QUA-03', 'low', 'fail', 'No error handling.', ['field_rvf_aspect' => 'quality']);
    $review = $this->makeReview(['alpha' => [$high], 'beta' => [$low]]);
    $html = $this->renderAs($this->reviewer, $review);
    foreach (['alpha', 'beta'] as $appId) {
      $this->assertSame(1, substr_count($html, 'id="app-' . $appId . '"'));
      foreach (array_keys(ReviewPageData::APP_BLOCKS) as $key) {
        $this->assertSame(1, substr_count($html, 'id="block-' . $appId . '-' . $key . '"'), "block-$appId-$key");
      }
    }
    $this->assertSame(1, substr_count($html, 'id="block-maintenance"'));
    $this->assertSame(1, substr_count($html, 'id="maintenance"'));
    // A section's anchor sits just before its first item.
    $this->assertLessThan($this->at($html, 'finding-' . $low->id()), $this->at($html, 'block-beta-code_quality'));
  }

  /**
   * The add form files the finding under the section picked.
   */
  public function testAddFindingUsesChosenSection(): void {
    $review = $this->makeReview(['root' => []]);
    $pid = $this->verdictPids($review)[0];
    $key = 'app-' . $pid;
    $form_state = $this->submit($review, [
      'add_finding' => [$key => $this->addInput('security', 'OODT-01', 'high', 'Shell injection.')],
      'add_finding__' . $key => 'Add finding',
    ]);
    $this->assertSame([], $form_state->getErrors());
    $verdict = Paragraph::load($pid);
    $added = $verdict->get('field_rvv_findings')->referencedEntities();
    $this->assertCount(1, $added);
    $this->assertSame('OODT-01', $added[0]->get('field_rvf_rule')->value);
    $this->assertSame('security', ReviewPageData::blockFromFields($added[0]->get('field_rvf_aspect')->value, $added[0]->get('field_rvf_category')->value));
  }

  /**
   * A repo-level add goes to the repo's findings, under the section picked.
   */
  public function testRepoAddFindingUsesChosenSection(): void {
    $review = $this->makeReview(['root' => []]);
    $form_state = $this->submit($review, [
      'add_finding' => ['repo' => $this->addInput('maintenance', 'MNT-02', 'low', 'No releases.')],
      'add_finding__repo' => 'Add finding',
    ]);
    $this->assertSame([], $form_state->getErrors());
    $added = Node::load($review->id())->get('field_arv_repo_findings')->referencedEntities();
    $this->assertCount(1, $added);
    $this->assertSame('maintenance', ReviewPageData::blockFromFields($added[0]->get('field_rvf_aspect')->value, $added[0]->get('field_rvf_category')->value));
  }

  /**
   * A full post of a section the select never offered adds nothing.
   *
   * Drupal's illegal-choice check stops it before the list's own guard,
   * which ReviewPageAddFindingTest covers.
   */
  public function testFullPostOfSectionNotOfferedAddsNothing(): void {
    $review = $this->makeReview(['root' => []]);
    $pid = $this->verdictPids($review)[0];
    $key = 'app-' . $pid;
    $form_state = $this->submit($review, [
      'add_finding' => [$key => $this->addInput('maintenance', 'MNT-02', 'low', 'No releases.')],
      'add_finding__' . $key => 'Add finding',
    ]);
    $this->assertNotSame([], $form_state->getErrors());
    $this->assertSame([], Paragraph::load($pid)->get('field_rvv_findings')->referencedEntities());
  }

  /**
   * Without JavaScript an add lands back on the new finding.
   */
  public function testNonAjaxAddLandsOnNewFinding(): void {
    $review = $this->makeReview(['root' => []]);
    $pid = $this->verdictPids($review)[0];
    $key = 'app-' . $pid;
    $form_state = $this->submit($review, [
      'add_finding' => [$key => $this->addInput('code_quality', 'QUA-03', 'low', 'No error handling.')],
      'add_finding__' . $key => 'Add finding',
    ]);
    $added = Paragraph::load($pid)->get('field_rvv_findings')->referencedEntities();
    $this->assertCount(1, $added);
    $this->assertSame('finding-' . $added[0]->id(), $form_state->get('arv_focus'));
    $this->assertSame('finding-' . $added[0]->id(), $this->fragment($form_state));
  }

  /**
   * The add form starts on the section of the list's last item.
   */
  public function testAddFormDefaultsToLastItemsSection(): void {
    $high = $this->finding('OODT-01', 'high', 'fail', 'Runs input through a shell.', ['field_rvf_aspect' => 'security']);
    $low = $this->finding('QUA-03', 'low', 'fail', 'No error handling.', ['field_rvf_aspect' => 'quality']);
    $review = $this->makeReview(['root' => [$low, $high]]);
    $pid = $this->verdictPids($review)[0];
    $html = $this->renderAs($this->reviewer, $review);
    $select = $this->selectHtml($html, 'add_finding[app-' . $pid . '][section]');
    $this->assertMatchesRegularExpression('/<option value="code_quality" selected="selected">/', $select);
    $this->assertStringContainsString('<option value="structure">Structure</option>', $select);
    $this->assertStringNotContainsString('maintenance', $select);
    // The repo list's form offers its own sections, starting on Upkeep.
    $repo = $this->selectHtml($html, 'add_finding[repo][section]');
    $this->assertMatchesRegularExpression('/<option value="maintenance" selected="selected">Upkeep<\/option>/', $repo);
    // The rule is grouped by section.
    $this->assertStringContainsString('<optgroup label="Security">', $html);
    $this->assertStringContainsString('id="fold-app-' . $pid . '-add"', $html);
    // Each form's rule starts on a rule of its default section, never on
    // the first of all (STR-01, a gate that blocks the decision).
    $sections = ['app-' . $pid => 'code_quality', 'repo' => 'maintenance'];
    foreach ($sections as $key => $section) {
      $rule = $this->selectHtml($html, 'add_finding[' . $key . '][rule]');
      $this->assertSame(1, preg_match('/<option value="([^"]+)" selected="selected">/', $rule, $m), "$key rule has a default");
      $this->assertArrayHasKey($m[1], ReviewPageData::BLOCK_RULES[$section], "$key starts on a $section rule");
    }
  }

  /**
   * Save draft is never held up by an add form left empty.
   */
  public function testEmptyAddFormDoesNotBlockSaveDraft(): void {
    $high = $this->finding('OODT-01', 'high', 'fail', 'Runs input through a shell.', ['field_rvf_aspect' => 'security']);
    $review = $this->makeReview(['root' => [$high]]);
    $pid = $this->verdictPids($review)[0];
    $form_state = $this->submit($review, [
      'add_finding' => [
        'app-' . $pid => $this->addInput('security', 'OODT-01', 'medium', ''),
        'repo' => $this->addInput('maintenance', 'MNT-01', 'medium', ''),
      ],
      'prose' => [(string) $high->id() => 'Please quote it.'],
      'op' => 'Save draft',
    ]);
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame('Please quote it.', Paragraph::load($high->id())->get('field_rvf_reviewer_prose')->value);
    $this->assertSame('in_review', Node::load($review->id())->get('moderation_state')->value);
    $this->assertCount(1, Paragraph::load($pid)->get('field_rvv_findings')->referencedEntities(), 'The empty add form added nothing.');
  }

  /**
   * A finding's own Save still lands back on it.
   */
  public function testFindingSaveLandsOnFinding(): void {
    $high = $this->finding('OODT-01', 'high', 'fail', 'Runs input through a shell.', ['field_rvf_aspect' => 'security']);
    $review = $this->makeReview(['root' => [$high]]);
    $form_state = $this->submit($review, [
      'prose' => [(string) $high->id() => 'Noted.'],
      'save_finding__' . $high->id() => 'Save',
    ]);
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame('finding-' . $high->id(), $this->fragment($form_state));
  }

  /**
   * A sent review shows the lists without any of the controls.
   */
  public function testSentReviewHasNoControls(): void {
    $high = $this->finding('OODT-01', 'high', 'fail', 'Runs input through a shell.', ['field_rvf_aspect' => 'security']);
    $review = $this->makeReview(['root' => [$high]], TRUE);
    $pid = $this->verdictPids($review)[0];
    $html = $this->renderAs($this->reviewer, $review);
    $this->assertStringContainsString('id="worklist-app-' . $pid . '"', $html);
    $this->assertStringContainsString('id="worklist-repo"', $html);
    $this->assertStringContainsString('id="finding-' . $high->id() . '"', $html);
    $this->assertStringNotContainsString('add_finding', $html);
    $this->assertStringNotContainsString('fold-app-' . $pid . '-add', $html);
    $this->assertStringNotContainsString('save_finding__', $html);
    $this->assertStringNotContainsString('delete_finding__', $html);
    $this->assertStringNotContainsString('arv-add-here', $html);
    $this->assertStringNotContainsString('name="prose[', $html);
    // No duplicate check recorded: the slot is empty, so it draws nothing.
    $this->assertMatchesRegularExpression('/<div class="duplicate-check-block" id="duplicate-' . $pid . '"><\/div>/', $html);
  }

  /**
   * One add form's values.
   *
   * @return array<string, string>
   *   The values.
   */
  protected function addInput(string $section, string $rule, string $severity, string $summary): array {
    return ['section' => $section, 'rule' => $rule, 'severity' => $severity, 'summary' => $summary, 'evidence' => ''];
  }

  /**
   * The fragment of the page a submit redirects to.
   */
  protected function fragment(FormStateInterface $form_state): ?string {
    // A programmed submit never redirects, so ask for the one it set.
    $form_state->setProgrammed(FALSE);
    $redirect = $form_state->getRedirect();
    $this->assertInstanceOf(Url::class, $redirect);
    return $redirect->getOption('fragment');
  }

  /**
   * The HTML of one select, by name.
   */
  protected function selectHtml(string $html, string $name): string {
    $this->assertSame(1, preg_match('/<select[^>]*name="' . preg_quote($name, '/') . '".*?<\/select>/s', $html, $m), "select $name");
    return $m[0];
  }

}
