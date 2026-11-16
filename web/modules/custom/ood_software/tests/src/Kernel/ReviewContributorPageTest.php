<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Routing\RouteMatch;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Form\ReviewPageForm;
use Drupal\ood_software\Service\FindingOverride;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Tests\ood_software\Kernel\Traits\AppverseModerationTestTrait;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The contributor's page of a review: what they see and what they do not.
 *
 * @group ood_software
 */
class ReviewContributorPageTest extends KernelTestBase {

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
   * The repo's owner, the contributor.
   */
  protected UserInterface $owner;

  /**
   * A reviewer.
   */
  protected UserInterface $reviewer;

  /**
   * The cache contexts of the form renderAs() last built.
   *
   * @var string[]
   */
  protected array $lastCacheContexts = [];

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
   * Builds a repo of the owner's with one app and a review of it.
   *
   * The review carries a high finding the tool raised, a finding the reviewer
   * dismissed, an unsent response and an internal note.
   *
   * @return array{review: \Drupal\node\NodeInterface, repo: \Drupal\node\NodeInterface}
   *   The review and its repo.
   */
  protected function makeReview(bool $sent, string $decision = 'request_changes'): array {
    $repo = $this->makeRepo('ready_for_review');
    $app = Node::create([
      'type' => 'appverse_app',
      'title' => 'Example App',
      'uid' => $this->owner->id(),
      'field_appverse_repo' => $repo->id(),
      'moderation_state' => 'ready_for_review',
    ]);
    $app->save();
    $high = Paragraph::create([
      'type' => 'review_finding',
      'field_rvf_rule' => 'OODT-01',
      'field_rvf_severity' => 'high',
      'field_rvf_result' => 'fail',
      'field_rvf_summary' => 'Runs user input through a shell.',
      'field_rvf_evidence' => 'template/script.sh.erb:14',
      'field_rvf_reviewer_prose' => 'Note to the owner text.',
    ]);
    $high->save();
    $dismissed = Paragraph::create([
      'type' => 'review_finding',
      'field_rvf_rule' => 'OODT-02',
      'field_rvf_severity' => 'medium',
      'field_rvf_result' => 'fail',
      'field_rvf_summary' => 'Dismissed finding summary.',
      'field_rvf_evidence' => 'form.yml.erb:3',
    ]);
    $tool = ['severity' => 'medium', 'summary' => 'Dismissed finding summary.', 'evidence' => 'form.yml.erb:3'];
    FindingOverride::apply($dismissed, FindingOverride::normalize(['dismissed' => 1] + $tool, $tool), (int) $this->reviewer->id(), 1700000000);
    $dismissed->save();
    $verdict = Paragraph::create([
      'type' => 'review_verdict',
      'field_rvv_app_ref' => $app->id(),
      'field_rvv_app_id' => 'example-app',
      'field_rvv_conclusion' => $sent ? $decision : NULL,
      'field_rvv_findings' => [$high, $dismissed],
    ]);
    $verdict->save();
    $note = Paragraph::create([
      'type' => 'review_note',
      'field_rvn_author' => $this->reviewer->id(),
      'field_rvn_at' => 1700000000,
      'field_rvn_text' => ['value' => 'Internal note text.', 'format' => 'plain_text'],
    ]);
    $note->save();
    $review = Node::create([
      'type' => 'appverse_review',
      'title' => 'Review: example/app',
      'uid' => $this->reviewer->id(),
      'status' => 0,
      'field_arv_repo' => $repo->id(),
      'field_arv_verdicts' => [$verdict],
      'field_arv_internal_notes' => [$note],
      'field_arv_reviewed_at' => 1700000000,
      'field_arv_contributor_response' => $sent ? '' : 'Unsent response text.',
    ]);
    if ($sent) {
      $review->set('field_arv_decision_sent_at', 1700000100);
      $review->set('field_arv_decision_sent_by', $this->reviewer->id());
      $review->set('field_arv_sent_decisions', json_encode([
        'apps' => [(string) $verdict->id() => $decision],
        'response' => 'Sent response text.',
        'email' => ['subject' => 'Your review', 'html' => '<p>Email body only the reviewer sees.</p>'],
      ]));
    }
    $review->save();
    return ['review' => $review, 'repo' => $repo];
  }

  /**
   * Renders the review page as a user.
   */
  protected function renderAs(UserInterface $user, NodeInterface $review, string $query = ''): string {
    $this->setCurrentUser($user);
    $request = Request::create('/appverse/review/' . $review->id() . $query);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    // A page is built inside a render context, which is where the CSRF
    // token's placeholder in the Re-submit URL bubbles to; without one it
    // would stay a placeholder.
    $renderer = $this->container->get('renderer');
    $context = new RenderContext();
    $form = $renderer->executeInRenderContext($context, fn () => $this->container->get('form_builder')->getForm(ReviewPageForm::class, Node::load($review->id())));
    if (!$context->isEmpty()) {
      $context->pop()->merge(BubbleableMetadata::createFromRenderArray($form))->applyTo($form);
    }
    $this->lastCacheContexts = $form['#cache']['contexts'] ?? [];
    $html = (string) $renderer->renderRoot($form);
    $this->container->get('request_stack')->pop();
    return $html;
  }

  /**
   * Before the decision the contributor sees the tool's findings only.
   */
  public function testPrecheckRendersToolFindingsOnly(): void {
    ['review' => $review] = $this->makeReview(FALSE);
    $html = $this->renderAs($this->owner, $review);
    $this->assertStringContainsString('Automated check', $html);
    $this->assertStringContainsString('A reviewer will confirm, change or remove these before sending a decision.', $html);
    $this->assertStringContainsString('Runs user input through a shell.', $html);
    $this->assertStringContainsString('Dismissed finding summary.', $html, 'A dismissal is the reviewer\'s until sent, so the tool\'s finding still shows.');
    $this->assertStringContainsString('Changes you push to GitHub now will be reviewed in the next round.', $html);
    $this->assertStringContainsString('example/app', $html);
    // The public summary's layout: categories as headings, findings open.
    $this->assertStringContainsString('<h3 class="summary-row-title">', $html);
    $this->assertStringContainsString('<details class="summary-findings" open>', $html);
    $this->assertStringContainsString('arv-contributor__progress', $html);
    $this->assertStringContainsString('arv-owner-band', $html);
    $this->assertStringContainsString('2 findings</summary>', $html, 'An open list is named, not offered.');
    $this->assertStringNotContainsString('<summary>Show ', $html);
    $this->assertStringNotContainsString('Reviewed by', $html);
    $this->assertStringNotContainsString('Unsent response text.', $html);
    $this->assertStringNotContainsString('Internal note text.', $html);
    $this->assertStringNotContainsString('Note to the owner text.', $html);
    $this->assertStringNotContainsString('data-review-rerun', $html);
    $this->assertStringNotContainsString('Response from the reviewer', $html);
  }

  /**
   * Before the decision none of the reviewer's work in progress shows.
   */
  public function testPrecheckHidesReviewerDrafts(): void {
    ['review' => $review] = $this->makeReview(FALSE);
    $verdict = $review->get('field_arv_verdicts')->entity;
    $this->assertInstanceOf(Paragraph::class, $verdict);
    $verdict->set('field_rvv_conclusion', 'accept');
    $verdict->set('field_rvv_port_level', 'some_notes');
    $verdict->set('field_rvv_port_level_note', 'Rating note text.');
    $verdict->set('field_rvv_indicators_default', json_encode(['portability' => ['level' => 'solid']]));
    $findings = $verdict->get('field_rvv_findings')->referencedEntities();
    $high = $findings[0];
    $tool = [
      'severity' => 'high',
      'summary' => 'Runs user input through a shell.',
      'evidence' => 'template/script.sh.erb:14',
    ];
    $change = ['severity' => 'low', 'summary' => 'Reworded by the reviewer.'] + $tool;
    FindingOverride::apply($high, FindingOverride::normalize($change, $tool), (int) $this->reviewer->id(), 1700000000);
    $high->save();
    $added = Paragraph::create([
      'type' => 'review_finding',
      'field_rvf_source' => 'reviewer',
      'field_rvf_author' => $this->reviewer->id(),
      'field_rvf_rule' => 'QUA-01',
      'field_rvf_severity' => 'medium',
      'field_rvf_result' => 'fail',
      'field_rvf_aspect' => 'quality',
      'field_rvf_summary' => 'Finding the reviewer added.',
    ]);
    $added->save();
    $verdict->set('field_rvv_findings', [$high, $findings[1], $added]);
    $verdict->save();
    $repoAdded = Paragraph::create([
      'type' => 'review_finding',
      'field_rvf_source' => 'reviewer',
      'field_rvf_author' => $this->reviewer->id(),
      'field_rvf_rule' => 'STR-01',
      'field_rvf_severity' => 'medium',
      'field_rvf_result' => 'fail',
      'field_rvf_aspect' => 'structure',
      'field_rvf_summary' => 'Repo finding the reviewer added.',
    ]);
    $repoAdded->save();
    $review->set('field_arv_repo_findings', [$repoAdded]);
    $review->set('field_arv_assessment', 'Draft assessment text.');
    $review->set('field_arv_maint_level', 'needs_attention');
    $review->set('field_arv_indicators_default', json_encode(['maintenance' => ['level' => 'solid']]));
    $review->set('field_arv_verdicts', [$verdict]);
    $review->save();

    $html = $this->renderAs($this->owner, $review);
    $this->assertStringContainsString('Runs user input through a shell.', $html, "The tool's wording.");
    $this->assertStringContainsString('High <span class="n">(1)</span>', $html, "The tool's severity.");
    $this->assertStringContainsString('Not decided', $html);
    $this->assertStringNotContainsString('Reworded by the reviewer.', $html);
    $this->assertStringNotContainsString('Reviewer changed the severity', $html);
    $this->assertStringNotContainsString('Finding the reviewer added.', $html);
    $this->assertStringNotContainsString('Draft assessment text.', $html);
    $this->assertStringNotContainsString('decision-pill--accept', $html);
    $this->assertStringNotContainsString('Repo finding the reviewer added.', $html);
    // The tool rated Portability and Upkeep solid; the reviewer's some_notes
    // (medium) and needs_attention (low) are drafts.
    $this->assertStringContainsString('level-high', $html, "The tool's ratings.");
    $this->assertStringNotContainsString('level-medium', $html, "The reviewer's Portability rating.");
    $this->assertStringNotContainsString('level-low', $html, "The reviewer's Upkeep rating.");
    $this->assertStringNotContainsString('Reviewer changed the rating', $html);
    $this->assertStringNotContainsString('Rating note text.', $html);

    // The same layout built from the live fields (the reviewer's preview of
    // the public page) shows every draft, so the checks above mean something.
    $draftHtml = $this->renderAs($this->reviewer, $review, '?view=public');
    $this->assertStringContainsString('Reworded by the reviewer.', $draftHtml);
    $this->assertStringContainsString('Finding the reviewer added.', $draftHtml);
    $this->assertStringContainsString('Repo finding the reviewer added.', $draftHtml);
    $this->assertStringContainsString('Draft assessment text.', $draftHtml);
    $this->assertStringContainsString('decision-pill--accept', $draftHtml);
    $this->assertStringContainsString('level-medium', $draftHtml);
    $this->assertStringContainsString('level-low', $draftHtml);
    $this->assertStringContainsString('Reviewer changed the rating', $draftHtml);
    $this->assertStringNotContainsString('Rating note text.', $draftHtml, 'The reason is a note to the owner, not visitors.');
  }

  /**
   * Once sent, a re-rated category says so, and the owner gets the reason.
   */
  public function testSentReratedCategoryShowsReasonToOwnerOnly(): void {
    ['review' => $review] = $this->makeReview(TRUE);
    $verdict = $review->get('field_arv_verdicts')->entity;
    $this->assertInstanceOf(Paragraph::class, $verdict);
    $verdict->set('field_rvv_port_level', 'solid');
    $verdict->set('field_rvv_port_level_note', 'The README explains the site settings.');
    $verdict->set('field_rvv_indicators_default', json_encode(['portability' => ['level' => 'needs_attention']]));
    $verdict->save();
    $review->set('field_arv_verdicts', [$verdict]);
    $review->setPublished()->save();

    $html = $this->renderAs($this->owner, $review);
    $this->assertStringContainsString('Reviewer changed the rating from', $html);
    $this->assertStringContainsString('Note from the reviewer: The README explains the site settings.', $html);

    $visitorHtml = $this->renderAs($this->createUser(['access content'], 'visitor'), $review);
    $this->assertStringContainsString('Reviewer changed the rating from', $visitorHtml);
    $this->assertStringNotContainsString('The README explains the site settings.', $visitorHtml);
  }

  /**
   * A failed check no finding explains reaches the owner as a finding.
   */
  public function testUnexplainedGateIsListedForOwner(): void {
    ['review' => $review] = $this->makeReview(TRUE);
    $verdict = $review->get('field_arv_verdicts')->entity;
    $this->assertInstanceOf(Paragraph::class, $verdict);
    $verdict->set('field_rvv_criteria', json_encode(['template_syntax' => 'fail', 'metadata' => 'pass']));
    $verdict->save();
    $review->set('field_arv_verdicts', [$verdict]);
    $review->save();
    $html = $this->renderAs($this->owner, $review);
    $this->assertStringContainsString('The &quot;Template syntax&quot; check failed.', $html);
    $this->assertStringNotContainsString('Metadata', $html, 'A passed check adds nothing.');
  }

  /**
   * While a sent decision is being updated, the owner still sees what was sent.
   */
  public function testOwnerSeesSentDecisionDuringUpdate(): void {
    ['review' => $review] = $this->makeReview(TRUE, 'request_changes');
    $verdict = $review->get('field_arv_verdicts')->entity;
    $this->assertInstanceOf(Paragraph::class, $verdict);
    // What ReviewPageForm::saveUpdate() writes before the update is sent.
    $verdict->set('field_rvv_conclusion', 'accept');
    $verdict->save();
    $review->set('field_arv_verdicts', [$verdict]);
    $review->set('field_arv_contributor_response', 'Drafted update text.');
    $review->save();
    $html = $this->renderAs($this->owner, $review);
    $this->assertStringContainsString('decision-pill--request_changes', $html);
    $this->assertStringContainsString('Sent response text.', $html);
    $this->assertStringNotContainsString('decision-pill--accept', $html);
    $this->assertStringNotContainsString('Drafted update text.', $html);
  }

  /**
   * After the decision the contributor sees the response and the report.
   */
  public function testDecidedRendersResponseAndDropsDismissed(): void {
    ['review' => $review] = $this->makeReview(TRUE);
    $html = $this->renderAs($this->owner, $review);
    $this->assertStringContainsString('Changes requested', $html);
    $this->assertStringContainsString('Response from the reviewer', $html);
    $this->assertStringContainsString('Sent response text.', $html);
    $this->assertStringContainsString('<h3 class="summary-row-title">', $html);
    $this->assertStringContainsString('Runs user input through a shell.', $html);
    $this->assertStringContainsString('Note from the reviewer: Note to the owner text.', $html);
    $this->assertStringContainsString('arv-contributor__progress', $html);
    $this->assertStringContainsString('<p>Read the review, fix the repo on GitHub, then click Re-submit. Questions? Reply to the review email.</p>', $html);
    $this->assertStringNotContainsString('Changes requested. Read', $html);
    $this->assertStringContainsString('Reviewed by reviewer, ', $html);
    $this->assertStringContainsString('example/app', $html);
    $this->assertStringNotContainsString('Dismissed finding summary.', $html);
    $this->assertStringNotContainsString('Internal note text.', $html);
    $this->assertStringNotContainsString('Automated check', $html);
  }

  /**
   * Visitors get the summary without the owner's band or notes.
   */
  public function testVisitorGetsNoBandOrNotes(): void {
    ['review' => $review] = $this->makeReview(TRUE);
    $review->setPublished()->save();
    $visitor = $this->createUser(['access content'], 'visitor');
    $html = $this->renderAs($visitor, $review);
    $this->assertStringContainsString('Runs user input through a shell.', $html);
    $this->assertStringContainsString('<h3 class="summary-row-title">', $html);
    $this->assertStringContainsString('<details class="summary-findings">', $html, 'Findings start closed for visitors.');
    $this->assertStringNotContainsString('arv-owner-band', $html);
    $this->assertStringNotContainsString('Sent response text.', $html);
    $this->assertStringNotContainsString('Note to the owner text.', $html);
  }

  /**
   * The decision carries reviewer-only values the contributor must not get.
   */
  public function testDecidedPageLeaksNoReviewerOnlyDecisionFields(): void {
    // Accepted with suggestions on a repo still in review is the case where
    // the publish URL is set.
    ['review' => $review] = $this->makeReview(TRUE, 'accept_with_suggestions');
    $reviewerHtml = $this->renderAs($this->reviewer, $review);
    $publish = '/appverse/review/' . $review->id() . '/publish';
    $this->assertStringContainsString($publish, $reviewerHtml, 'The reviewer gets the publish URL, so the check below means something.');
    $html = $this->renderAs($this->owner, $review);
    $this->assertStringContainsString('Accepted with suggestions', $html);
    $this->assertStringNotContainsString($publish, $html, 'No publish URL.');
    $this->assertStringNotContainsString('Waiting on a reviewer to publish', $html, 'No waiting-on text.');
    $this->assertStringNotContainsString('Email body only the reviewer sees', $html);
    $this->assertStringNotContainsString('Update decision', $html);
    $this->assertStringNotContainsString('Your review', $html, 'No email subject.');
  }

  /**
   * The status sentence is dropped from the band, not from the hub card.
   */
  public function testWithoutStatusSentence(): void {
    $this->assertSame('The review says why.', ReviewPageForm::withoutStatusSentence('Declined. The review says why.'));
    $this->assertSame('A reviewer will publish it.', ReviewPageForm::withoutStatusSentence('Accepted. A reviewer will publish it.'));
    $this->assertSame('Live in the Appverse catalog.', ReviewPageForm::withoutStatusSentence('Live in the Appverse catalog.'));
  }

  /**
   * A reviewer keeps the editable review page.
   */
  public function testReviewerStillGetsTheReviewForm(): void {
    ['review' => $review] = $this->makeReview(FALSE);
    $html = $this->renderAs($this->reviewer, $review);
    $this->assertStringNotContainsString('arv-contributor', $html, 'Not the contributor view.');
    $this->assertStringContainsString('data-review-rerun', $html);
  }

  /**
   * A superseded review says so above everything else.
   */
  public function testSupersededReviewSaysSo(): void {
    ['review' => $older, 'repo' => $repo] = $this->makeReview(FALSE);
    $newer = Node::create([
      'type' => 'appverse_review',
      'title' => 'Review: example/app',
      'uid' => $this->reviewer->id(),
      'status' => 0,
      'field_arv_repo' => $repo->id(),
      'field_arv_reviewed_at' => 1700000500,
    ]);
    $newer->save();
    $html = $this->renderAs($this->owner, $older);
    $this->assertStringContainsString('A newer review of this repo exists', $html);
    $this->assertStringContainsString('/appverse/review/' . $newer->id(), $html);
  }

  /**
   * Puts the repo in a moderation state.
   */
  protected function setRepoState(NodeInterface $repo, string $state): void {
    $repo = Node::load($repo->id());
    $repo->set('moderation_state', $state);
    $repo->save();
  }

  /**
   * The form's action, or NULL when the page has no Re-submit.
   */
  protected function resubmitAction(string $html): ?string {
    if (!str_contains($html, 'Re-submit for review')) {
      return NULL;
    }
    $this->assertSame(1, preg_match('/<form method="post" action="([^"]+)"/', $html, $m));
    return html_entity_decode($m[1]);
  }

  /**
   * Changes requested on the newest review offers a tokened Re-submit.
   */
  public function testResubmitShownForNewestChangesRequested(): void {
    ['review' => $review, 'repo' => $repo] = $this->makeReview(TRUE);
    $this->setRepoState($repo, 'needs_adjustment');
    $action = $this->resubmitAction($this->renderAs($this->owner, $review));
    $this->assertNotNull($action);
    $this->assertStringContainsString('/appverse/repo/' . $repo->id() . '/send-for-review?', $action);
    $this->assertStringContainsString('token=', $action);
    parse_str((string) parse_url($action, PHP_URL_QUERY), $query);
    // Core's own CSRF access check, as the route runs it, accepts the token.
    $route = $this->container->get('router.route_provider')->getRouteByName('ood_software.repo_send_for_review');
    $request = Request::create($action, 'POST');
    $match = new RouteMatch('ood_software.repo_send_for_review', $route, ['node' => $repo], ['node' => (string) $repo->id()]);
    $this->assertTrue($this->container->get('access_check.csrf')->access($route, $request, $match)->isAllowed());
    // The token is per session, so the page must vary by it.
    $this->assertStringContainsString('session', implode(' ', $this->lastCacheContexts));
  }

  /**
   * An accepted review has nothing to re-submit.
   */
  public function testResubmitHiddenWhenAccepted(): void {
    ['review' => $review, 'repo' => $repo] = $this->makeReview(TRUE, 'accept');
    $this->setRepoState($repo, 'needs_adjustment');
    $this->assertStringNotContainsString('Re-submit for review', $this->renderAs($this->owner, $review));
  }

  /**
   * Before the decision is sent there is nothing to re-submit either.
   */
  public function testResubmitHiddenBeforeDecision(): void {
    ['review' => $review, 'repo' => $repo] = $this->makeReview(FALSE);
    $this->setRepoState($repo, 'needs_adjustment');
    $this->assertStringNotContainsString('Re-submit for review', $this->renderAs($this->owner, $review));
  }

  /**
   * A superseded review says so and offers no Re-submit.
   */
  public function testResubmitHiddenOnSupersededReview(): void {
    ['review' => $older, 'repo' => $repo] = $this->makeReview(TRUE);
    $this->setRepoState($repo, 'needs_adjustment');
    Node::create([
      'type' => 'appverse_review',
      'title' => 'Review: example/app',
      'uid' => $this->reviewer->id(),
      'status' => 0,
      'field_arv_repo' => $repo->id(),
      'field_arv_reviewed_at' => 1700000500,
    ])->save();
    $html = $this->renderAs($this->owner, $older);
    $this->assertStringContainsString('A newer review of this repo exists', $html);
    $this->assertStringNotContainsString('Re-submit for review', $html);
  }

  /**
   * A repo in some other state (here, back in review) has no Re-submit.
   */
  public function testResubmitHiddenWhenRepoIsNotSentBack(): void {
    ['review' => $review] = $this->makeReview(TRUE);
    $this->assertStringNotContainsString('Re-submit for review', $this->renderAs($this->owner, $review));
  }

  /**
   * A live repo with one app sent back offers Re-submit; with none it does not.
   */
  public function testResubmitFollowsSentBackAppInMixedRepo(): void {
    ['review' => $review, 'repo' => $repo] = $this->makeReview(TRUE);
    $this->setRepoState($repo, 'published');
    $this->assertStringNotContainsString('Re-submit for review', $this->renderAs($this->owner, $review));
    $apps = Node::loadMultiple(\Drupal::entityQuery('node')->accessCheck(FALSE)->condition('type', 'appverse_app')->execute());
    $sentBack = reset($apps);
    $sentBack->set('moderation_state', 'needs_adjustment');
    $sentBack->save();
    $action = $this->resubmitAction($this->renderAs($this->owner, $review));
    $this->assertNotNull($action);
    $this->assertStringContainsString('token=', $action);
  }

  /**
   * A never-published repo in review with one app sent back offers Re-submit.
   *
   * One app is accepted with suggestions and the other has changes requested,
   * so the repo stays ready_for_review with an app in needs_adjustment, the
   * case sendForReview() and the hub card both handle.
   */
  public function testResubmitShownForMixedDecisionOnUnpublishedRepo(): void {
    ['review' => $review, 'repo' => $repo] = $this->makeReview(TRUE);
    $ids = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('type', 'appverse_app')->execute();
    $first = Node::load(reset($ids));
    $second = Node::create([
      'type' => 'appverse_app',
      'title' => 'Second App',
      'uid' => $this->owner->id(),
      'field_appverse_repo' => $repo->id(),
      'moderation_state' => 'ready_for_review',
    ]);
    $second->save();
    $verdict = Paragraph::create([
      'type' => 'review_verdict',
      'field_rvv_app_ref' => $second->id(),
      'field_rvv_app_id' => 'second-app',
      'field_rvv_conclusion' => 'accept_with_suggestions',
    ]);
    $verdict->save();
    $review = Node::load($review->id());
    $first_verdict = $review->get('field_arv_verdicts')->referencedEntities()[0];
    $review->get('field_arv_verdicts')->appendItem($verdict);
    $review->set('field_arv_sent_decisions', json_encode([
      'apps' => [
        (string) $first_verdict->id() => 'request_changes',
        (string) $verdict->id() => 'accept_with_suggestions',
      ],
      'response' => 'Sent response text.',
      'email' => ['subject' => 'Your review', 'html' => '<p>x</p>'],
    ]));
    $review->save();
    $first->set('moderation_state', 'needs_adjustment');
    $first->save();
    $this->assertSame('ready_for_review', Node::load($repo->id())->get('moderation_state')->value);
    $action = $this->resubmitAction($this->renderAs($this->owner, $review));
    $this->assertNotNull($action);
    $this->assertStringContainsString('token=', $action);
  }

}
