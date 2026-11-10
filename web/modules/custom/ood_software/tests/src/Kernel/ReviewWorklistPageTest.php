<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormAjaxException;
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
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
   * Catalog checks that matched a Software entry no other app implements.
   */
  const DECLARED = [
    'shape' => 'declared',
    'software' => ['status' => 'match', 'value' => 'jupyter', 'entry' => 'jupyter'],
    'same_software_apps' => [],
  ];

  /**
   * Catalog checks that found a declared name with no entry, and a close one.
   */
  const MISSPELLED = [
    'shape' => 'declared',
    'software' => ['status' => 'no_match', 'value' => 'Jupiterlab', 'closest' => 'JupyterLab'],
  ];

  /**
   * Catalog checks of an inferred repo, which declares no software.
   */
  const INFERRED = [
    'shape' => 'inferred',
    'software' => ['status' => 'inferred'],
    'same_software_apps' => [],
  ];

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
   * The test's browser session.
   */
  protected ?Session $session = NULL;

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
      'node.type.appverse_software',
      'field.storage.node.field_appverse_software_implemen',
      'field.field.node.appverse_app.field_appverse_software_implemen',
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
  protected function pushRequest(NodeInterface $review, string $method = 'GET', string $query = ''): void {
    $request = Request::create('/appverse/review/' . $review->id() . ($query !== '' ? '?' . $query : ''), $method);
    $request->setSession($this->session());
    $this->container->get('request_stack')->push($request);
  }

  /**
   * Renders the review page as a user.
   */
  protected function renderAs(UserInterface $user, NodeInterface $review, string $query = ''): string {
    $this->setCurrentUser($user);
    $this->pushRequest($review, 'GET', $query);
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
   * The duplicate check lists the other published apps for the software once.
   *
   * It is a worklist item while nothing is recorded, with the check's line
   * and the links, editable or sent. The catalog's "Same software" row is
   * not shown as well.
   */
  public function testSameSoftwareAppsShowInDuplicateCheck(): void {
    $review = $this->makeReview(['root' => []]);
    $verdict = Paragraph::load($this->verdictPids($review)[0]);
    $verdict->set('field_rvv_catalog', json_encode([
      'shape' => 'declared',
      'software' => ['status' => 'match', 'value' => 'jupyter', 'entry' => 'jupyter'],
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
      $check = $this->duplicateHtml($html, (string) $verdict->id());
      $this->assertStringContainsString('finding--duplicate', $check, $sent ? 'sent' : 'editable');
      $this->assertStringContainsString('appverse.yml declares jupyter. 1 other published app implements it:', $check);
      $this->assertStringContainsString('<a href="https://github.com/other/jupyter" target="_blank" rel="noopener">Other Jupyter</a>', $check);
      $this->assertSame(1, substr_count($html, 'Other Jupyter'), 'Listed once.');
      $this->assertSame($sent, !str_contains($check, 'name="duplicate[' . $verdict->id() . '][note]"'), 'The control only where it can be edited.');
    }
  }

  /**
   * A clear check's "Same software" PASS row is in Passed checks only.
   */
  public function testSameSoftwarePassShowsOnce(): void {
    $review = $this->makeReview(['root' => []]);
    $verdict = Paragraph::load($this->verdictPids($review)[0]);
    $verdict->set('field_rvv_catalog', json_encode([
      'shape' => 'declared',
      'software' => ['status' => 'match', 'value' => 'jupyter', 'entry' => 'jupyter'],
      'same_software_apps' => [],
    ]));
    $verdict->save();
    $html = $this->renderAs($this->reviewer, $review);
    $this->assertSame(1, substr_count($html, 'No other published app implements jupyter'));
    $this->assertStringContainsString('appverse.yml declares jupyter. No other published app implements it.', $this->duplicateHtml($html, (string) $verdict->id()));
  }

  /**
   * An inferred app compares against the software staff assigned on it.
   */
  public function testInferredAppComparesAgainstAssignedSoftware(): void {
    $software = $this->software('RStudio');
    $review = $this->inferredReview($software);
    $pid = $this->verdictPids($review)[0];
    $this->otherApp('OSC RStudio', $software, TRUE);
    $this->otherApp('Unlisted RStudio', $software, FALSE);
    $html = $this->renderAs($this->reviewer, $review);
    $check = $this->duplicateHtml($html, $pid);
    $this->assertStringContainsString('Assigned software: RStudio. 1 other published app implements it:', $check);
    $this->assertStringContainsString('>OSC RStudio</a>', $check);
    $this->assertStringNotContainsString('Unlisted RStudio', $html);
    $this->assertStringNotContainsString('confirm_duplicate__', $html);

    $error = 'duplicate][' . $pid . '][note';
    foreach (['none', 'distinct'] as $outcome) {
      [, $formState] = $this->post($review, $this->keys($review) + [
        'duplicate' => [$pid => ['outcome' => $outcome, 'note' => '']],
      ], 'op', 'Save draft', FALSE);
      $this->assertArrayHasKey($error, $formState->getErrors(), $outcome);
      $this->assertNull(Paragraph::load($pid)->get('field_rvv_duplicate')->value, $outcome);
    }
    [, $formState] = $this->post($review, $this->keys($review) + [
      'duplicate' => [$pid => ['outcome' => 'distinct', 'note' => 'Runs RStudio in a container.']],
    ], 'op', 'Save draft', FALSE);
    $this->assertSame([], $formState->getErrors());
    $this->assertSame('distinct', Paragraph::load($pid)->get('field_rvv_duplicate')->value);
    $this->assertSame('Runs RStudio in a container.', Paragraph::load($pid)->get('field_rvv_duplicate_note')->value);
  }

  /**
   * A refused save's duplicate-check error shows inside the check.
   */
  public function testDuplicateErrorShowsInTheCheck(): void {
    $software = $this->software('RStudio');
    $review = $this->inferredReview($software);
    $pid = $this->verdictPids($review)[0];
    $this->otherApp('OSC RStudio', $software, TRUE);
    [$form, $formState] = $this->post($review, $this->keys($review) + [
      'duplicate' => [$pid => ['outcome' => 'none', 'note' => '']],
    ], 'op', 'Save draft', TRUE);
    $this->assertArrayHasKey('duplicate][' . $pid . '][note', $formState->getErrors());
    $check = $this->duplicateHtml($this->renderForm($form), $pid);
    $this->assertSame(1, preg_match('/<p class="arv-field-error" id="([^"]+--error)">Give the rationale for the duplicate check/', $check, $m));
    $this->assertMatchesRegularExpression('/<textarea[^>]*aria-describedby="[^"]*' . preg_quote($m[1], '/') . '/', $check);
  }

  /**
   * Confirm records "No other app" with the check's line as the note.
   */
  public function testConfirmRecordsNoOtherApp(): void {
    foreach ([TRUE, FALSE] as $ajax) {
      $review = $this->inferredReview($this->software('S'));
      $pid = $this->verdictPids($review)[0];
      $html = $this->renderAs($this->reviewer, $review);
      $this->assertStringContainsString('Assigned software: S. No other published app implements it.', $this->duplicateHtml($html, $pid));
      [$form, $formState, $response] = $this->post($review, $this->keys($review) + [
        'duplicate' => [$pid => ['note' => '']],
      ], 'confirm_duplicate__' . $pid, 'Confirm', $ajax);
      $this->assertSame([], $formState->getErrors());
      $verdict = Paragraph::load($pid);
      $this->assertSame('none', $verdict->get('field_rvv_duplicate')->value);
      $this->assertSame('Confirmed: Assigned software: S. No other published app implements it.', $verdict->get('field_rvv_duplicate_note')->value);
      if ($ajax) {
        $this->assertSame('Duplicate check recorded.', $formState->get('arv_announce'));
        $this->assertSame('duplicate-' . $pid, $formState->get('arv_focus'));
        // The rebuilt page shows the outcome and no Confirm.
        $this->assertSame('none', $form['duplicate'][$pid]['outcome']['#value']);
        $check = $this->duplicateHtml($this->renderForm($form), $pid);
        $this->assertStringContainsString('No other app for this software', $check);
        $this->assertStringNotContainsString('confirm_duplicate__', $check);
      }
      else {
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringEndsWith('/appverse/review/' . $review->id() . '#duplicate-' . $pid, $response->getTargetUrl());
      }
    }
  }

  /**
   * Confirm's note is plain text: a title with & and ' is stored as typed.
   */
  public function testConfirmedNoteIsPlainText(): void {
    $review = $this->inferredReview($this->software("R&D O'Brien"));
    $pid = $this->verdictPids($review)[0];
    [, $formState] = $this->post($review, $this->keys($review) + [
      'duplicate' => [$pid => ['note' => '']],
    ], 'confirm_duplicate__' . $pid, 'Confirm', TRUE);
    $this->assertSame([], $formState->getErrors());
    $this->assertSame("Confirmed: Assigned software: R&D O'Brien. No other published app implements it.", Paragraph::load($pid)->get('field_rvv_duplicate_note')->value);
    $check = $this->duplicateHtml($this->renderAs($this->reviewer, $review), $pid);
    // Escaped once, as any text is, never twice.
    $this->assertStringContainsString('Confirmed: Assigned software: R&amp;D O&#039;Brien. No other published app implements it.', $check);
    $this->assertStringNotContainsString('&amp;amp;', $check);
    $this->assertStringNotContainsString('&amp;#039;', $check);
  }

  /**
   * Confirm's note says what the review shows now, not the cached form.
   */
  public function testConfirmUsesTheFreshLine(): void {
    $software = $this->software('Old name');
    $review = $this->inferredReview($software);
    $pid = $this->verdictPids($review)[0];
    $keys = $this->keys($review);
    // Renamed after the page was loaded; the review itself is unchanged.
    $software->setTitle('New name')->save();
    // The next request starts with no entities in memory; the cached form
    // still says "Old name".
    $this->container->get('entity.memory_cache')->deleteAll();
    [, $formState] = $this->post($review, $keys + [
      'duplicate' => [$pid => ['note' => '']],
    ], 'confirm_duplicate__' . $pid, 'Confirm', TRUE);
    $this->assertSame([], $formState->getErrors());
    $this->assertSame('Confirmed: Assigned software: New name. No other published app implements it.', Paragraph::load($pid)->get('field_rvv_duplicate_note')->value);
  }

  /**
   * A recorded outcome keeps what the check found, with the apps' links.
   */
  public function testSettledCheckKeepsItsBasis(): void {
    $software = $this->software('S');
    $other = $this->otherApp('Other S', $software, TRUE);
    foreach ([FALSE, TRUE] as $sent) {
      $review = $this->inferredReview($software, $sent);
      $pid = $this->verdictPids($review)[0];
      Paragraph::load($pid)
        ->set('field_rvv_duplicate', 'distinct')
        ->set('field_rvv_duplicate_note', 'Runs S in a container.')
        ->save();
      $check = $this->duplicateHtml($this->renderAs($this->reviewer, $review), $pid);
      $label = $sent ? 'sent' : 'editable';
      $this->assertStringContainsString('Same software, a meaningfully different approach', $check, $label);
      $this->assertStringContainsString('Assigned software: S. 1 other published app implements it:', $check, $label);
      $this->assertStringContainsString('<a href="' . $other->toUrl()->toString() . '" target="_blank" rel="noopener">Other S</a>', $check, $label);
    }
  }

  /**
   * An outcome the reviewer picked before Confirm is the one kept.
   */
  public function testConfirmKeepsPickedOutcome(): void {
    $review = $this->inferredReview($this->software('S'));
    $pid = $this->verdictPids($review)[0];
    [, $formState] = $this->post($review, $this->keys($review) + [
      'duplicate' => [$pid => ['outcome' => 'duplicate', 'note' => 'Same as OSC S.']],
    ], 'confirm_duplicate__' . $pid, 'Confirm', TRUE);
    $this->assertSame([], $formState->getErrors());
    $this->assertSame('duplicate', Paragraph::load($pid)->get('field_rvv_duplicate')->value);
    $this->assertSame('Same as OSC S.', Paragraph::load($pid)->get('field_rvv_duplicate_note')->value);
  }

  /**
   * Only a clear check offers Confirm.
   */
  public function testConfirmOnlyInClearMode(): void {
    $software = $this->software('S');
    $this->otherApp('Other S', $software, TRUE);
    $compare = $this->inferredReview($software);
    $misspelled = $this->makeReview(['root' => []]);
    $this->setChecks($misspelled, 0, self::MISSPELLED);
    $unknown = $this->makeReview(['root' => []]);
    $this->setChecks($unknown, 0, ['shape' => 'inferred', 'software' => ['status' => 'inferred']]);
    foreach (['compare' => $compare, 'misspelled' => $misspelled, 'unknown' => $unknown] as $mode => $review) {
      $html = $this->renderAs($this->reviewer, $review);
      $this->assertStringNotContainsString('confirm_duplicate__', $html, $mode);
      $this->assertStringContainsString('finding--duplicate', $this->duplicateHtml($html, $this->verdictPids($review)[0]), $mode);
    }
    $html = $this->renderAs($this->reviewer, $misspelled);
    $this->assertStringContainsString('appverse.yml declares Jupiterlab, which has no Software entry. It may mean JupyterLab.', $html);
    $html = $this->renderAs($this->reviewer, $unknown);
    $this->assertMatchesRegularExpression('#The software couldn&\#039;t be identified\. Check the <a href="[^"]*/appverse"[^>]*>catalog</a> for an existing app\.#', $html);
    $clear = $this->inferredReview($this->software('T'));
    $this->assertStringContainsString('confirm_duplicate__' . $this->verdictPids($clear)[0], $this->renderAs($this->reviewer, $clear));
  }

  /**
   * While a sent decision is updated, Confirm saves through saveUpdate().
   */
  public function testConfirmInUpdateModeUsesSaveUpdate(): void {
    $review = $this->inferredReview($this->software('S'), TRUE);
    $pid = $this->verdictPids($review)[0];
    $this->assertStringContainsString('confirm_duplicate__' . $pid, $this->renderAs($this->reviewer, $review, 'update=1'));
    $before = $this->revisionCount($review);
    [, $formState, $response] = $this->post($review, $this->keys($review, 'update=1') + [
      'duplicate' => [$pid => ['note' => '']],
      'response' => 'Sent.',
    ], 'confirm_duplicate__' . $pid, 'Confirm', FALSE, 'update=1');
    $this->assertSame([], $formState->getErrors());
    $this->assertSame('none', Paragraph::load($pid)->get('field_rvv_duplicate')->value);
    $this->assertSame($before + 1, $this->revisionCount($review));
    $this->assertStringContainsString('decision update drafted by', (string) Node::load($review->id())->getRevisionLogMessage());
    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertStringEndsWith('/appverse/review/' . $review->id() . '?update=1#duplicate-' . $pid, $response->getTargetUrl());
    $this->assertStringNotContainsString('/decision', $response->getTargetUrl());
    // The sent record stands until the update is confirmed.
    $this->assertNotNull(Node::load($review->id())->get('field_arv_decision_sent_at')->value);

    // Over AJAX the page is rebuilt, still updating, on the new revision.
    $review = $this->inferredReview($this->software('U'), TRUE);
    $pid = $this->verdictPids($review)[0];
    [$form, $formState] = $this->post($review, $this->keys($review, 'update=1') + [
      'duplicate' => [$pid => ['note' => '']],
      'response' => 'Sent.',
    ], 'confirm_duplicate__' . $pid, 'Confirm', TRUE, 'update=1');
    $this->assertSame([], $formState->getErrors());
    $this->assertTrue($formState->isRebuilding());
    $this->assertSame('none', Paragraph::load($pid)->get('field_rvv_duplicate')->value);
    $this->assertSame((string) Node::load($review->id())->getRevisionId(), (string) $form['arv_revision']['#value']);
    $this->assertTrue($form['#updating']);
    $this->assertSame('duplicate-' . $pid, $formState->get('arv_focus'));
  }

  /**
   * The note Confirm wrote is still there after the next save.
   */
  public function testConfirmedNoteSurvivesTheNextSave(): void {
    $high = $this->finding('OODT-01', 'high', 'fail', 'Runs input through a shell.', ['field_rvf_aspect' => 'security']);
    $review = $this->inferredReview($this->software('S'), FALSE, [$high]);
    $pid = $this->verdictPids($review)[0];
    $hid = (string) $high->id();
    $input = $this->keys($review) + ['duplicate' => [$pid => ['note' => '']]];
    [$form] = $this->post($review, $input, 'confirm_duplicate__' . $pid, 'Confirm', TRUE);
    $note = 'Confirmed: Assigned software: S. No other published app implements it.';
    $this->assertSame($note, $form['duplicate'][$pid]['note']['#value']);
    // What the replaced page posts next.
    $input = $this->nextInput($form, $input);
    $input['duplicate'][$pid] = [
      'outcome' => $form['duplicate'][$pid]['outcome']['#value'],
      'note' => $form['duplicate'][$pid]['note']['#value'],
    ];
    unset($input['confirm_duplicate__' . $pid]);
    [, $formState] = $this->post($review, ['prose' => [$hid => 'Noted.']] + $input, 'save_finding__' . $hid, 'Save', TRUE);
    $this->assertSame([], $formState->getErrors());
    $this->assertSame('none', Paragraph::load($pid)->get('field_rvv_duplicate')->value);
    $this->assertSame($note, Paragraph::load($pid)->get('field_rvv_duplicate_note')->value);
  }

  /**
   * On a review with catalog data, an assigned clear app needs no note.
   */
  public function testAssignedClearAppOnCatalogReviewNeedsNoNote(): void {
    $review = $this->makeReview(['root' => [], 'other' => []]);
    [$pid, $other] = $this->verdictPids($review);
    $this->setChecks($review, 1, self::DECLARED);
    $this->assign($review, 0, $this->software('S'));
    $page = $this->page($review);
    $this->assertSame('clear', $page['apps'][0]['duplicate']['basis']['mode']);
    $this->assertTrue($page['apps'][0]['catalog']['backed']);
    [, $formState] = $this->post($review, $this->keys($review) + [
      'duplicate' => [$pid => ['outcome' => 'none', 'note' => ''], $other => ['note' => '']],
    ], 'op', 'Save draft', FALSE);
    $this->assertSame([], $formState->getErrors());
    $this->assertSame('none', Paragraph::load($pid)->get('field_rvv_duplicate')->value);
  }

  /**
   * An app without checks on a review that has them still needs a note.
   */
  public function testLegacyAppOnCatalogReviewNeedsNote(): void {
    $review = $this->makeReview(['root' => [], 'other' => []]);
    [$pid] = $this->verdictPids($review);
    $this->setChecks($review, 1, self::DECLARED);
    $page = $this->page($review);
    $this->assertSame('legacy', $page['apps'][0]['duplicate']['basis']['mode']);
    $this->assertFalse($page['apps'][0]['catalog']['backed']);
    // A review with no catalog data at all: backed, as before.
    $old = $this->makeReview(['root' => []]);
    $this->assertTrue($this->page($old)['apps'][0]['catalog']['backed']);
    [, $formState] = $this->post($review, $this->keys($review) + [
      'duplicate' => [$pid => ['outcome' => 'none', 'note' => '']],
    ], 'op', 'Save draft', FALSE);
    $this->assertArrayHasKey('duplicate][' . $pid . '][note', $formState->getErrors());
  }

  /**
   * A verdict seeded without app_ref finds its app by repo and app id.
   */
  public function testMissingAppRefFallsBackToRepoAndAppId(): void {
    $review = $this->inferredReview($this->software('S'));
    $verdict = Paragraph::load($this->verdictPids($review)[0]);
    $verdict->set('field_rvv_app_ref', NULL)->save();
    $basis = $this->page($review)['apps'][0]['duplicate']['basis'];
    $this->assertSame(['clear', 'assigned', 'S'], [$basis['mode'], $basis['source'], $basis['entry']]);
  }

  /**
   * Apps under an unpublished repo are not in the catalog, so not compared.
   */
  public function testSameSoftwareSkipsUnpublishedRepos(): void {
    $software = $this->software('S');
    $review = $this->inferredReview($software);
    $repo = (int) Node::load($review->id())->get('field_arv_repo')->target_id;
    $hidden = $this->makeRepo('draft');
    $shown = $this->makeRepo('published');
    $this->otherApp('B under a draft repo', $software, TRUE, $hidden);
    $a = $this->otherApp('a with no repo', $software, TRUE);
    $c = $this->otherApp('C under a published repo', $software, TRUE, $shown);
    $this->otherApp('Unpublished', $software, FALSE, $shown);
    $this->otherApp('Same repo', $software, TRUE, Node::load($repo));
    $this->otherApp('Other software', $this->software('T'), TRUE, $shown);
    $form = $this->container->get('class_resolver')->getInstanceFromDefinition(ReviewPageForm::class);
    $this->assertSame([
      ['title' => 'a with no repo', 'url' => $a->toUrl()->toString()],
      ['title' => 'C under a published repo', 'url' => $c->toUrl()->toString()],
    ], $form->sameSoftwareApps((int) $software->id(), $repo));
  }

  /**
   * A sent review that is not being updated offers no Confirm.
   */
  public function testNoConfirmOnReadOnlyReview(): void {
    $review = $this->inferredReview($this->software('S'), TRUE);
    $pid = $this->verdictPids($review)[0];
    $html = $this->renderAs($this->reviewer, $review);
    $this->assertStringNotContainsString('confirm_duplicate__', $html);
    $this->assertStringNotContainsString('name="duplicate[', $html);
    $this->assertStringContainsString('Assigned software: S. No other published app implements it.', $this->duplicateHtml($html, $pid));
  }

  /**
   * Nothing is filled in for the reviewer, in any mode.
   */
  public function testNoteIsNeverPrefilled(): void {
    $software = $this->software('S');
    $review = $this->makeReview(['clear' => [], 'compare' => [], 'misspelled' => [], 'unknown' => [], 'legacy' => []]);
    $this->assign($review, 0, $this->software('Lone'));
    $this->assign($review, 1, $software);
    $this->otherApp('Other S', $software, TRUE);
    $this->setChecks($review, 2, self::MISSPELLED);
    $this->setChecks($review, 3, ['shape' => 'inferred', 'software' => ['status' => 'inferred']]);
    $modes = array_map(static fn (array $a): string => $a['duplicate']['basis']['mode'], $this->page($review)['apps']);
    $this->assertSame(['clear', 'compare', 'misspelled', 'unknown', 'legacy'], $modes);
    $html = $this->renderAs($this->reviewer, $review);
    foreach ($this->verdictPids($review) as $i => $pid) {
      $this->assertSame(1, preg_match('/<textarea[^>]*name="duplicate\[' . $pid . '\]\[note\]"[^>]*>(.*?)<\/textarea>/s', $html, $m), $modes[$i]);
      $this->assertSame('', trim($m[1]), $modes[$i]);
      $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="duplicate\[' . $pid . '\]\[outcome\]"[^>]*checked/', $html, $modes[$i]);
    }
  }

  /**
   * Save draft records no outcome, so Send stays blocked for an Accept.
   */
  public function testSaveDraftRecordsNothing(): void {
    $review = $this->inferredReview($this->software('S'));
    $pid = $this->verdictPids($review)[0];
    [, $formState] = $this->post($review, $this->keys($review) + [
      'duplicate' => [$pid => ['note' => '']],
      'conclusion' => [$pid => 'accept'],
    ], 'op', 'Save draft', FALSE);
    $this->assertSame([], $formState->getErrors());
    $this->assertNull(Paragraph::load($pid)->get('field_rvv_duplicate')->value);
    $this->assertSame('accept', Paragraph::load($pid)->get('field_rvv_conclusion')->value);
    $html = $this->renderAs($this->reviewer, $review);
    $this->assertSame(1, preg_match('/<input[^>]*name="send_decision"[^>]*>/', $html, $m));
    $this->assertStringContainsString('disabled', $m[0]);
    $this->assertStringContainsString('Record the duplicate check for each app you accept first.', $html);
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
   * An AJAX Save rebuilds the page in place rather than redirecting.
   */
  public function testAjaxSaveRebuildsInsteadOfRedirecting(): void {
    $high = $this->finding('OODT-01', 'high', 'fail', 'Runs input through a shell.', ['field_rvf_aspect' => 'security']);
    $review = $this->makeReview(['root' => [$high]]);
    $pid = (string) $high->id();
    $keys = $this->keys($review);
    \Drupal::messenger()->deleteAll();
    [, $formState] = $this->post($review, $keys + [
      'prose' => [$pid => 'Noted.'],
    ], 'save_finding__' . $pid, 'Save', TRUE);
    $this->assertSame([], $formState->getErrors());
    $this->assertTrue($formState->isRebuilding());
    // Rebuilding: no redirect at all.
    $this->assertFalse($formState->getRedirect());
    $this->assertSame('Saved.', $formState->get('arv_announce'));
    $this->assertSame('finding-' . $pid, $formState->get('arv_focus'));
    $this->assertSame('Noted.', Paragraph::load($pid)->get('field_rvf_reviewer_prose')->value);
    $this->assertSame([], \Drupal::messenger()->messagesByType('status'), 'An AJAX save leaves no message for the next page.');
  }

  /**
   * An add saves the rest of the posted page too, in one revision.
   */
  public function testAjaxAddSavesTheRestOfThePage(): void {
    $review = $this->makeReview(['root' => []]);
    $pid = $this->verdictPids($review)[0];
    $key = 'app-' . $pid;
    $before = $this->revisionCount($review);
    [, $formState] = $this->post($review, $this->keys($review) + [
      'add_finding' => [$key => $this->addInput('code_quality', 'QUA-03', 'low', 'No error handling.')],
      'response' => 'Thanks for the app.',
      'conclusion' => [$pid => 'request_changes'],
    ], 'add_finding__' . $key, 'Add finding', TRUE);
    $this->assertSame([], $formState->getErrors());
    $added = Paragraph::load($pid)->get('field_rvv_findings')->referencedEntities();
    $this->assertCount(1, $added);
    $this->assertSame('QUA-03', $added[0]->get('field_rvf_rule')->value);
    $this->assertSame('request_changes', Paragraph::load($pid)->get('field_rvv_conclusion')->value);
    $node = Node::load($review->id());
    $this->assertSame('Thanks for the app.', $node->get('field_arv_contributor_response')->value);
    $this->assertSame($before + 1, $this->revisionCount($review));
    $this->assertStringContainsString('added finding QUA-03', (string) $node->getRevisionLogMessage());
    $this->assertSame('Finding added.', $formState->get('arv_announce'));
    $this->assertSame('finding-' . $added[0]->id(), $formState->get('arv_focus'));
  }

  /**
   * What is typed elsewhere on the page is still there after a save.
   */
  public function testAjaxSaveKeepsOtherInput(): void {
    $high = $this->finding('OODT-01', 'high', 'fail', 'Runs input through a shell.', ['field_rvf_aspect' => 'security']);
    $review = $this->makeReview(['root' => [$high], 'other' => []]);
    [, $other] = $this->verdictPids($review);
    $pid = (string) $high->id();
    [$form] = $this->post($review, $this->keys($review) + [
      'prose' => [$pid => 'Noted.'],
      'add_finding' => ['app-' . $other => ['summary' => 'half typed']],
    ], 'save_finding__' . $pid, 'Save', TRUE);
    $this->assertSame('half typed', $form['add_finding']['app-' . $other]['summary']['#value']);
    $this->assertSame('Noted.', $form['prose'][$pid]['#value']);
  }

  /**
   * An add empties its own add form and no other.
   */
  public function testAjaxAddClearsOnlyItsOwnAddForm(): void {
    $review = $this->makeReview(['root' => [], 'other' => []]);
    [$pid, $other] = $this->verdictPids($review);
    [$form, $formState] = $this->post($review, $this->keys($review) + [
      'add_finding' => [
        'app-' . $pid => $this->addInput('code_quality', 'QUA-03', 'low', 'No error handling.'),
        'app-' . $other => ['summary' => 'half typed'],
      ],
    ], 'add_finding__app-' . $pid, 'Add finding', TRUE);
    $this->assertSame([], $formState->getErrors());
    $this->assertCount(1, Paragraph::load($pid)->get('field_rvv_findings')->referencedEntities());
    $this->assertSame('', $form['add_finding']['app-' . $pid]['summary']['#value']);
    $this->assertSame('half typed', $form['add_finding']['app-' . $other]['summary']['#value']);
  }

  /**
   * Add saves the whole page, so an error anywhere on it blocks the add.
   */
  public function testAddWithUnnotedOverrideElsewhereSavesNothing(): void {
    $high = $this->finding('OODT-01', 'high', 'fail', 'Runs input through a shell.', ['field_rvf_aspect' => 'security']);
    $review = $this->makeReview(['root' => [$high]]);
    $pid = $this->verdictPids($review)[0];
    $key = 'app-' . $pid;
    $before = $this->revisionCount($review);
    [, $formState] = $this->post($review, $this->keys($review) + [
      'override' => [(string) $high->id() => ['severity' => 'low']],
      'add_finding' => [$key => $this->addInput('code_quality', 'QUA-03', 'low', 'No error handling.')],
    ], 'add_finding__' . $key, 'Add finding', TRUE);
    $this->assertNotSame([], $formState->getErrors());
    $this->assertCount(1, Paragraph::load($pid)->get('field_rvv_findings')->referencedEntities(), 'No finding was added.');
    $this->assertEmpty(Paragraph::load($high->id())->get('field_rvf_override_severity')->value, 'No override was stored.');
    $this->assertSame($before, $this->revisionCount($review));
  }

  /**
   * A new severity with no note saves nothing and keeps the finding in focus.
   */
  public function testAjaxOverrideWithoutNoteSavesNothing(): void {
    $high = $this->finding('OODT-01', 'high', 'fail', 'Runs input through a shell.', ['field_rvf_aspect' => 'security']);
    $review = $this->makeReview(['root' => [$high]]);
    $pid = (string) $high->id();
    $before = $this->revisionCount($review);
    [, $formState] = $this->post($review, $this->keys($review) + [
      'override' => [$pid => ['severity' => 'low']],
    ], 'save_finding__' . $pid, 'Save', TRUE);
    $this->assertArrayHasKey('prose][' . $pid, $formState->getErrors());
    $this->assertEmpty(Paragraph::load($pid)->get('field_rvf_override_severity')->value);
    $this->assertSame($before, $this->revisionCount($review));
    $this->assertSame('finding-' . $pid, $formState->get('arv_focus'));
  }

  /**
   * A window opened before the review changed elsewhere saves nothing.
   *
   * Sending a decision of Request changes hands the repo back to its
   * contributor, so the sent page offers "Start the next round" as its first
   * button: the stale press must not fall through to it.
   */
  public function testStaleWindowSavesNothing(): void {
    $high = $this->finding('OODT-01', 'high', 'fail', 'Runs input through a shell.', ['field_rvf_aspect' => 'security']);
    $review = $this->makeReview(['root' => [$high]]);
    $pid = (string) $high->id();
    $keys = $this->keys($review);
    // Sent in another window.
    $node = Node::load($review->id());
    $node->set('field_arv_decision_sent_at', 1700000100);
    $node->set('field_arv_decision_sent_by', $this->reviewer->id());
    $node->set('field_arv_sent_decisions', json_encode([
      'apps' => [$this->verdictPids($review)[0] => 'request_changes'],
      'response' => 'Sent.',
    ]));
    $node->setNewRevision(TRUE);
    $node->save();
    $repo = Node::load($node->get('field_arv_repo')->target_id);
    $repo->set('moderation_state', 'needs_adjustment')->save();
    $sent = (int) Node::load($review->id())->getRevisionId();
    foreach ([TRUE, FALSE] as $ajax) {
      \Drupal::messenger()->deleteAll();
      [, $formState] = $this->post($review, $keys + ['prose' => [$pid => 'Late note.']], 'save_finding__' . $pid, 'Save', $ajax);
      $this->assertContains('This review changed in another window. Reload the page to see the latest version.', array_map('strval', \Drupal::messenger()->messagesByType('error')), $ajax ? 'AJAX' : 'no JS');
      $this->assertSame($sent, (int) Node::load($review->id())->getRevisionId());
      $this->assertEmpty(Paragraph::load($pid)->get('field_rvf_reviewer_prose')->value);
      $this->assertSame('needs_adjustment', Node::load($repo->id())->get('moderation_state')->value, 'No next round was started.');
      if ($ajax) {
        $this->assertSame('arv-review-form', $formState->get('arv_focus'));
      }
    }
  }

  /**
   * The page's own saves move its revision along, so they never look stale.
   */
  public function testOwnSavesDoNotTripTheStaleCheck(): void {
    $high = $this->finding('OODT-01', 'high', 'fail', 'Runs input through a shell.', ['field_rvf_aspect' => 'security']);
    $review = $this->makeReview(['root' => [$high]]);
    $pid = (string) $high->id();
    $input = $this->keys($review);
    $before = $this->revisionCount($review);
    foreach (['One.', 'Two.', 'Three.'] as $i => $note) {
      [$form, $formState] = $this->post($review, ['prose' => [$pid => $note]] + $input, 'save_finding__' . $pid, 'Save', TRUE);
      $this->assertSame([], $formState->getErrors(), "Save $i");
      $this->assertSame($note, Paragraph::load($pid)->get('field_rvf_reviewer_prose')->value);
      $input = $this->nextInput($form, $input);
    }
    $this->assertSame($before + 3, $this->revisionCount($review));
  }

  /**
   * A save from the cached form works on the review as it is now.
   */
  public function testRebuildUsesFreshReview(): void {
    $high = $this->finding('OODT-01', 'high', 'fail', 'Runs input through a shell.', ['field_rvf_aspect' => 'security']);
    $review = $this->makeReview(['root' => [$high]]);
    $pid = (string) $high->id();
    $input = $this->keys($review);
    [$form] = $this->post($review, ['prose' => [$pid => 'One.']] + $input, 'save_finding__' . $pid, 'Save', TRUE);
    $input = $this->nextInput($form, $input);
    // Changed by something else, in the same revision.
    $node = Node::load($review->id());
    $node->set('field_arv_contributor_response', ['value' => 'Written elsewhere.', 'format' => 'plain_text']);
    $node->setTitle('Renamed elsewhere');
    // Saved as a sync, so not as a new revision: the page is not stale.
    $node->setSyncing(TRUE);
    $node->setNewRevision(FALSE);
    $node->save();
    unset($input['response']);
    [, $formState] = $this->post($review, ['prose' => [$pid => 'Two.']] + $input, 'save_finding__' . $pid, 'Save', TRUE);
    $this->assertSame([], $formState->getErrors());
    $object = $formState->getFormObject();
    $property = new \ReflectionProperty($object, 'node');
    $this->assertSame('Renamed elsewhere', $property->getValue($object)->label());
    // The save went onto that review, rather than writing back the copy the
    // cached form was built from.
    $this->assertSame('Renamed elsewhere', Node::load($review->id())->label());
  }

  /**
   * A note saved over AJAX is saved once, and its box empties.
   */
  public function testAjaxSaveDoesNotRepeatTheNote(): void {
    $high = $this->finding('OODT-01', 'high', 'fail', 'Runs input through a shell.', ['field_rvf_aspect' => 'security']);
    $review = $this->makeReview(['root' => [$high]]);
    $pid = (string) $high->id();
    $input = $this->keys($review) + ['new_note' => 'Called them.'];
    [$form] = $this->post($review, $input, 'save_finding__' . $pid, 'Save', TRUE);
    $this->assertSame('', $form['new_note']['#value']);
    $input = $this->nextInput($form, $input);
    $this->post($review, $input, 'save_finding__' . $pid, 'Save', TRUE);
    $this->assertCount(1, Node::load($review->id())->get('field_arv_internal_notes')->referencedEntities());
  }

  /**
   * The first save assigns the saver, and the rebuilt select says so.
   */
  public function testAjaxSaveShowsTheAutoAssignee(): void {
    $high = $this->finding('OODT-01', 'high', 'fail', 'Runs input through a shell.', ['field_rvf_aspect' => 'security']);
    $this->importProdConfig([
      'field.storage.node.field_repo_assigned_reviewer',
      'field.field.node.appverse_repo.field_repo_assigned_reviewer',
    ]);
    // Only a PM can be assigned.
    Role::create(['id' => 'appverse_pm', 'label' => 'Appverse PM'])->save();
    $this->reviewer->addRole('appverse_pm');
    $this->reviewer->save();
    $review = $this->makeReview(['root' => [$high]]);
    $pid = (string) $high->id();
    [$form] = $this->post($review, $this->keys($review) + ['assignee' => ''], 'save_finding__' . $pid, 'Save', TRUE);
    $this->assertSame((string) $this->reviewer->id(), (string) $form['assignee']['#value']);
  }

  /**
   * A reviewer's finding left blank can still be deleted.
   */
  public function testDeleteSkipsTheDeletedFindingsErrors(): void {
    $mine = $this->finding('QUA-03', 'low', 'fail', 'No error handling.', [
      'field_rvf_aspect' => 'quality',
      'field_rvf_source' => 'reviewer',
    ]);
    $review = $this->makeReview(['root' => [$mine]]);
    $pid = $this->verdictPids($review)[0];
    $mid = (string) $mine->id();
    [$form, $formState] = $this->post($review, $this->keys($review) + [
      'edit_finding' => [$mid => ['rule' => 'QUA-03', 'severity' => 'low', 'summary' => '', 'evidence' => '']],
    ], 'delete_finding__' . $mid, 'Delete finding', TRUE);
    $this->assertSame([], $formState->getErrors());
    $this->assertSame([], Paragraph::load($pid)->get('field_rvv_findings')->referencedEntities());
    $this->assertSame('Finding deleted.', $formState->get('arv_announce'));
    $this->assertSame('fold-app-' . $pid . '-add', $formState->get('arv_focus'));
    $this->assertArrayNotHasKey($mid, $form['edit_finding'] ?? []);
    $this->assertStringContainsString('deleted finding QUA-03', (string) Node::load($review->id())->getRevisionLogMessage());
  }

  /**
   * Delete saves the whole page, so an error elsewhere blocks it.
   */
  public function testDeleteBlockedByAnUnnotedOverrideElsewhere(): void {
    $high = $this->finding('OODT-01', 'high', 'fail', 'Runs input through a shell.', ['field_rvf_aspect' => 'security']);
    $mine = $this->finding('QUA-03', 'low', 'fail', 'No error handling.', [
      'field_rvf_aspect' => 'quality',
      'field_rvf_source' => 'reviewer',
    ]);
    $review = $this->makeReview(['root' => [$high, $mine]]);
    $pid = $this->verdictPids($review)[0];
    [, $formState] = $this->post($review, $this->keys($review) + [
      'override' => [(string) $high->id() => ['severity' => 'low']],
    ], 'delete_finding__' . $mine->id(), 'Delete finding', TRUE);
    $this->assertArrayHasKey('prose][' . $high->id(), $formState->getErrors());
    $this->assertCount(2, Paragraph::load($pid)->get('field_rvv_findings')->referencedEntities());
  }

  /**
   * Only a reviewer's own finding is deleted.
   *
   * The button is only built for one, so this posts from a cached form
   * whose finding has since become the tool's.
   */
  public function testDeleteOfToolFindingSavesNothing(): void {
    $mine = $this->finding('QUA-03', 'low', 'fail', 'No error handling.', [
      'field_rvf_aspect' => 'quality',
      'field_rvf_source' => 'reviewer',
    ]);
    $review = $this->makeReview(['root' => [$mine]]);
    $pid = $this->verdictPids($review)[0];
    $mid = (string) $mine->id();
    $input = $this->keys($review);
    [$form] = $this->post($review, $input, 'save_finding__' . $mid, 'Save', TRUE);
    $input = $this->nextInput($form, $input);
    $p = Paragraph::load($mid);
    $p->set('field_rvf_source', 'tool')->save();
    $before = $this->revisionCount($review);
    \Drupal::messenger()->deleteAll();
    [, $formState] = $this->post($review, $input, 'delete_finding__' . $mid, 'Delete finding', TRUE);
    $this->assertContains('Only a finding a reviewer added can be deleted.', array_map('strval', $formState->getErrors()));
    $this->assertCount(1, Paragraph::load($pid)->get('field_rvv_findings')->referencedEntities());
    $this->assertSame($before, $this->revisionCount($review));
    $this->assertNotContains('Review saved.', array_map('strval', \Drupal::messenger()->messagesByType('status')));
  }

  /**
   * Without JavaScript a finding's Save is a full post back to the finding.
   */
  public function testNonAjaxSaveStillRedirectsToFinding(): void {
    $high = $this->finding('OODT-01', 'high', 'fail', 'Runs input through a shell.', ['field_rvf_aspect' => 'security']);
    $review = $this->makeReview(['root' => [$high]]);
    $pid = (string) $high->id();
    $input = $this->keys($review) + ['prose' => [$pid => 'Noted.']];
    [, , $response] = $this->post($review, $input, 'save_finding__' . $pid, 'Save', FALSE);
    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertStringEndsWith('/appverse/review/' . $review->id() . '#finding-' . $pid, $response->getTargetUrl());
    $this->assertSame('Noted.', Paragraph::load($pid)->get('field_rvf_reviewer_prose')->value);
  }

  /**
   * Without JavaScript a finding's Delete lands on its list's add form.
   */
  public function testNonAjaxDeleteRedirectsToTheListsAddForm(): void {
    $mine = $this->finding('QUA-03', 'low', 'fail', 'No error handling.', [
      'field_rvf_aspect' => 'quality',
      'field_rvf_source' => 'reviewer',
    ]);
    $review = $this->makeReview(['root' => [$mine]]);
    $pid = $this->verdictPids($review)[0];
    [, $form_state, $response] = $this->post($review, $this->keys($review), 'delete_finding__' . $mine->id(), 'Delete finding', FALSE);
    $this->assertSame([], Paragraph::load($pid)->get('field_rvv_findings')->referencedEntities());
    $target = $response instanceof RedirectResponse ? $response->getTargetUrl() : (string) $this->fragmentUrl($form_state);
    $this->assertStringEndsWith('#fold-app-' . $pid . '-add', $target);
  }

  /**
   * An add's state does not carry over to the next save from the cached form.
   */
  public function testCachedFormStartsEachSaveAfresh(): void {
    $high = $this->finding('OODT-01', 'high', 'fail', 'Runs input through a shell.', ['field_rvf_aspect' => 'security']);
    $review = $this->makeReview(['root' => [$high]]);
    $pid = $this->verdictPids($review)[0];
    $key = 'app-' . $pid;
    $hid = (string) $high->id();
    $input = $this->keys($review);
    [$form, $formState] = $this->post($review, $input + [
      'add_finding' => [$key => $this->addInput('code_quality', 'QUA-03', 'low', 'No error handling.')],
    ], 'add_finding__' . $key, 'Add finding', TRUE);
    $this->assertSame('Finding added.', $formState->get('arv_announce'));
    $input = $this->nextInput($form, $input);
    [, $formState] = $this->post($review, ['prose' => [$hid => 'Noted.']] + $input, 'save_finding__' . $hid, 'Save', TRUE);
    $this->assertSame([], $formState->getErrors());
    $this->assertSame('Saved.', $formState->get('arv_announce'));
    $this->assertSame('finding-' . $hid, $formState->get('arv_focus'));
    $log = (string) Node::load($review->id())->getRevisionLogMessage();
    $this->assertStringStartsWith('Review page: saved by ', $log);
    $this->assertStringNotContainsString('added finding', $log);
  }

  /**
   * Save on one finding, then another: focus follows the second.
   */
  public function testSecondSaveFocusesItsOwnFinding(): void {
    $high = $this->finding('OODT-01', 'high', 'fail', 'Runs input through a shell.', ['field_rvf_aspect' => 'security']);
    $low = $this->finding('QUA-03', 'low', 'warn', 'No error handling.', ['field_rvf_aspect' => 'quality']);
    $review = $this->makeReview(['root' => [$high, $low]]);
    $a = (string) $high->id();
    $b = (string) $low->id();
    $input = $this->keys($review);
    [$form] = $this->post($review, ['prose' => [$a => 'One.']] + $input, 'save_finding__' . $a, 'Save', TRUE);
    $input = $this->nextInput($form, $input);
    [, $formState] = $this->post($review, ['prose' => [$b => 'Two.']] + $input, 'save_finding__' . $b, 'Save', TRUE);
    $this->assertSame('finding-' . $b, $formState->get('arv_focus'));
  }

  /**
   * A save is confirmed on the page, in the finding's row, until the next.
   */
  public function testAjaxSaveShowsConfirmation(): void {
    $high = $this->finding('OODT-01', 'high', 'fail', 'Runs input through a shell.', ['field_rvf_aspect' => 'security']);
    $review = $this->makeReview(['root' => [$high]]);
    $pid = (string) $high->id();
    $this->assertStringNotContainsString('arv-saved', $this->renderAs($this->reviewer, $review));
    [$form] = $this->post($review, $this->keys($review) + ['prose' => [$pid => 'Noted.']], 'save_finding__' . $pid, 'Save', TRUE);
    $row = $this->row($this->renderForm($form), 'finding-' . $pid);
    $this->assertStringContainsString('<p class="arv-saved" aria-hidden="true">Saved.</p>', $row);
  }

  /**
   * A delete is confirmed at its list's add form.
   */
  public function testAjaxDeleteShowsConfirmationInList(): void {
    $mine = $this->finding('QUA-03', 'low', 'fail', 'No error handling.', [
      'field_rvf_aspect' => 'quality',
      'field_rvf_source' => 'reviewer',
    ]);
    $review = $this->makeReview(['root' => [$mine]]);
    $pid = $this->verdictPids($review)[0];
    [$form] = $this->post($review, $this->keys($review), 'delete_finding__' . $mine->id(), 'Delete finding', TRUE);
    $html = $this->renderForm($form);
    $this->assertMatchesRegularExpression('/<p class="arv-saved" aria-hidden="true">Finding deleted\.<\/p>\s*<details class="add-finding" id="fold-app-' . $pid . '-add"/', $html);
  }

  /**
   * A post that does not say which revision it came from saves nothing.
   */
  public function testPostWithoutRevisionIsRefused(): void {
    $high = $this->finding('OODT-01', 'high', 'fail', 'Runs input through a shell.', ['field_rvf_aspect' => 'security']);
    $review = $this->makeReview(['root' => [$high]]);
    $pid = (string) $high->id();
    $input = $this->keys($review);
    unset($input['arv_revision']);
    $before = $this->revisionCount($review);
    \Drupal::messenger()->deleteAll();
    $this->post($review, $input + ['prose' => [$pid => 'Noted.']], 'save_finding__' . $pid, 'Save', TRUE);
    $this->assertContains('This review changed in another window. Reload the page to see the latest version.', array_map('strval', \Drupal::messenger()->messagesByType('error')));
    $this->assertSame($before, $this->revisionCount($review));
    $this->assertEmpty(Paragraph::load($pid)->get('field_rvf_reviewer_prose')->value);
  }

  /**
   * Renders a form a post returned.
   *
   * @param array<string, mixed> $form
   *   The form.
   */
  protected function renderForm(array $form): string {
    $this->pushRequest(Node::load($form['#page']['nid']));
    $renderer = $this->container->get('renderer');
    $html = (string) $renderer->executeInRenderContext(new RenderContext(), fn () => $renderer->render($form));
    $this->container->get('request_stack')->pop();
    return $html;
  }

  /**
   * One finding row's HTML, up to the next row.
   */
  protected function row(string $html, string $id): string {
    $start = $this->at($html, $id);
    $next = strpos($html, '<div class="finding"', $start + 1);
    return substr($html, $start, $next === FALSE ? NULL : $next - $start);
  }

  /**
   * What a browser posts back from a freshly loaded page.
   *
   * @return array<string, string>
   *   The form's id, build id, token and revision.
   */
  protected function keys(NodeInterface $review, string $query = ''): array {
    $this->setCurrentUser($this->reviewer);
    $this->pushRequest($review, 'GET', $query);
    $form = $this->container->get('form_builder')->getForm(ReviewPageForm::class, Node::load($review->id()));
    $this->container->get('request_stack')->pop();
    return [
      'form_id' => 'appverse_review_page_form',
      'form_build_id' => (string) $form['#build_id'],
      // The page renders the token from a placeholder; this is its value.
      'form_token' => $this->container->get('csrf_token')->get((string) $form['#token']),
      'arv_revision' => (string) $form['arv_revision']['#value'],
    ];
  }

  /**
   * What the browser posts next from a page an AJAX response replaced.
   *
   * @param array<string, mixed> $form
   *   The rebuilt form.
   * @param array<string, mixed> $input
   *   What was posted.
   *
   * @return array<string, mixed>
   *   The input, with the values the rebuild changed.
   */
  protected function nextInput(array $form, array $input): array {
    $input['form_build_id'] = (string) $form['#build_id'];
    $input['arv_revision'] = (string) $form['arv_revision']['#value'];
    $input['new_note'] = (string) ($form['new_note']['#value'] ?? '');
    return $input;
  }

  /**
   * Posts the page as a browser does.
   *
   * With JavaScript that is Drupal's AJAX submit, which names the button
   * pressed; without, a plain POST carrying the button's name and label.
   *
   * @param \Drupal\node\NodeInterface $review
   *   The review.
   * @param array<string, mixed> $input
   *   The posted input.
   * @param string $button
   *   The pressed button's #name.
   * @param string $label
   *   Its label.
   * @param bool $ajax
   *   Whether JavaScript posts it.
   * @param string $query
   *   The page's own query string, such as update=1.
   *
   * @return array{0: array<string, mixed>|null, 1: \Drupal\Core\Form\FormStateInterface, 2: \Symfony\Component\HttpFoundation\Response|null}
   *   The processed (or rebuilt) form, its state, and the response a full
   *   post ends in.
   */
  protected function post(NodeInterface $review, array $input, string $button, string $label, bool $ajax, string $query = ''): array {
    $this->setCurrentUser($this->reviewer);
    $input[$button] = $label;
    $uri = '/appverse/review/' . $review->id() . ($query !== '' ? '?' . $query : '');
    if ($ajax) {
      $uri .= ($query !== '' ? '&' : '?') . 'ajax_form=1&_wrapper_format=drupal_ajax';
      $input += ['_triggering_element_name' => $button, '_triggering_element_value' => $label, '_drupal_ajax' => '1'];
    }
    $request = Request::create($uri, 'POST', $input);
    $request->setSession($this->session());
    $this->container->get('request_stack')->push($request);
    $form_state = new FormState();
    // A new request: an earlier post's errors do not carry over (FormState
    // keeps whether any form had one in a static).
    $form_state->clearErrors();
    $form_state->addBuildInfo('args', [Node::load($review->id())]);
    $form = NULL;
    $response = NULL;
    try {
      $form = $this->container->get('form_builder')->buildForm(ReviewPageForm::class, $form_state);
    }
    catch (FormAjaxException $e) {
      $form = $e->getForm();
      $form_state = $e->getFormState();
    }
    catch (EnforcedResponseException $e) {
      $response = $e->getResponse();
    }
    $this->container->get('request_stack')->pop();
    return [$form, $form_state, $response];
  }

  /**
   * One browser session across the test's requests, so a form's token holds.
   */
  protected function session(): Session {
    if ($this->session === NULL) {
      $this->session = new Session(new MockArraySessionStorage('MOCKSESSID', $this->container->get('session_manager.metadata_bag')));
      // Started up front: starting later would reload the token seed empty.
      $this->session->start();
    }
    return $this->session;
  }

  /**
   * How many revisions the review has.
   */
  protected function revisionCount(NodeInterface $review): int {
    return (int) $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
      ->allRevisions()
      ->accessCheck(FALSE)
      ->condition('nid', $review->id())
      ->count()
      ->execute();
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
   * The redirect a submit set, as a string.
   */
  protected function fragmentUrl(FormStateInterface $form_state): string {
    return '#' . $this->fragment($form_state);
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

  /**
   * A published Software entry.
   */
  protected function software(string $title): NodeInterface {
    $software = Node::create([
      'type' => 'appverse_software',
      'title' => $title,
      'uid' => $this->owner->id(),
      'status' => 1,
    ]);
    $software->save();
    return $software;
  }

  /**
   * Another app with the software, under another repo or none.
   */
  protected function otherApp(string $title, NodeInterface $software, bool $published, ?NodeInterface $repo = NULL): NodeInterface {
    $app = Node::create([
      'type' => 'appverse_app',
      'title' => $title,
      'uid' => $this->owner->id(),
      'field_appverse_repo' => $repo?->id(),
      'field_appverse_software_implemen' => $software->id(),
      'moderation_state' => $published ? 'published' : 'draft',
    ]);
    $app->save();
    return $app;
  }

  /**
   * Sets a verdict's app's assigned software.
   *
   * @param \Drupal\node\NodeInterface $review
   *   The review.
   * @param int $index
   *   The verdict's position.
   * @param \Drupal\node\NodeInterface $software
   *   The Software entry.
   */
  protected function assign(NodeInterface $review, int $index, NodeInterface $software): void {
    $app = Paragraph::load($this->verdictPids($review)[$index])->get('field_rvv_app_ref')->entity;
    $this->assertInstanceOf(NodeInterface::class, $app);
    $app->set('field_appverse_software_implemen', $software->id())->save();
  }

  /**
   * Sets a verdict's catalog checks.
   *
   * @param \Drupal\node\NodeInterface $review
   *   The review.
   * @param int $index
   *   The verdict's position.
   * @param array<string, mixed> $checks
   *   The checks, as the artifact sends them.
   */
  protected function setChecks(NodeInterface $review, int $index, array $checks): void {
    Paragraph::load($this->verdictPids($review)[$index])->set('field_rvv_catalog', json_encode($checks))->save();
  }

  /**
   * A one-app review of an inferred repo whose app has assigned software.
   *
   * @param \Drupal\node\NodeInterface $software
   *   The Software entry.
   * @param bool $sent
   *   Whether the decision is sent.
   * @param array<int, \Drupal\paragraphs\ParagraphInterface> $findings
   *   The app's findings.
   */
  protected function inferredReview(NodeInterface $software, bool $sent = FALSE, array $findings = []): NodeInterface {
    $review = $this->makeReview(['root' => $findings], $sent);
    $this->setChecks($review, 0, self::INFERRED);
    $this->assign($review, 0, $software);
    return $review;
  }

  /**
   * The page data the form builds for the review.
   *
   * @return array<string, mixed>
   *   The page.
   */
  protected function page(NodeInterface $review): array {
    $this->setCurrentUser($this->reviewer);
    $this->pushRequest($review);
    $form = $this->container->get('form_builder')->getForm(ReviewPageForm::class, Node::load($review->id()));
    $this->container->get('request_stack')->pop();
    return $form['#page'];
  }

  /**
   * The HTML of an app's duplicate check, wherever it is drawn.
   */
  protected function duplicateHtml(string $html, string $pid): string {
    // From the start of the element that carries the id.
    $start = (int) strrpos(substr($html, 0, $this->at($html, 'duplicate-' . $pid)), '<');
    $ends = array_filter([
      preg_match('/<div class="finding[ "]/', $html, $m, PREG_OFFSET_CAPTURE, $start + 1) ? (int) $m[0][1] : FALSE,
      strpos($html, '<h3', $start + 1),
      strpos($html, '<details', $start + 1),
    ]);
    return substr($html, $start, $ends === [] ? NULL : min($ends) - $start);
  }

}
