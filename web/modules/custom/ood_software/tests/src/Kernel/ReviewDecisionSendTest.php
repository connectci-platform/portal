<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountInterface;
use Drupal\ood_software\Form\AppverseHubRequestChangesForm;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Controller\AppverseHubController;
use Drupal\ood_software\Service\ReviewDecisionApplier;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;

/**
 * Sending a decision against the real workflow (appverse-planning#50).
 *
 * Each app ends in its own state, a decision goes out once, never on a
 * superseded review, and the hub's repo routes refuse a reviewed repo.
 *
 * @group ood_software
 */
class ReviewDecisionSendTest extends KernelTestBase {

  use AssertMailTrait;
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
    $this->installConfig(['system', 'filter', 'user', 'node', 'content_moderation', 'workflows']);
    $this->importProdConfig([
      'node.type.appverse_repo',
      'node.type.appverse_app',
      'node.type.appverse_review',
      'paragraphs.paragraphs_type.review_verdict',
      'workflows.workflow.appverse_editorial',
      'workflows.workflow.appverse_review_workflow',
      'field.storage.node.field_appverse_repo',
      'field.field.node.appverse_app.field_appverse_repo',
      'field.storage.node.field_arv_repo',
      'field.field.node.appverse_review.field_arv_repo',
      'field.storage.node.field_arv_decision_sent_at',
      'field.field.node.appverse_review.field_arv_decision_sent_at',
      'field.storage.node.field_arv_sent_decisions',
      'field.field.node.appverse_review.field_arv_sent_decisions',
      'field.storage.node.field_arv_decision_sent_by',
      'field.field.node.appverse_review.field_arv_decision_sent_by',
      'field.storage.node.field_arv_contributor_response',
      'field.field.node.appverse_review.field_arv_contributor_response',
      'field.storage.node.field_arv_withdrawn_at',
      'field.field.node.appverse_review.field_arv_withdrawn_at',
      'field.storage.node.field_arv_verdicts',
      'field.field.node.appverse_review.field_arv_verdicts',
      'field.storage.paragraph.field_rvv_app_ref',
      'field.field.paragraph.review_verdict.field_rvv_app_ref',
      'field.storage.paragraph.field_rvv_app_id',
      'field.field.paragraph.review_verdict.field_rvv_app_id',
      'field.storage.paragraph.field_rvv_conclusion',
      'field.field.paragraph.review_verdict.field_rvv_conclusion',
    ]);
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('system.site')->set('name', 'Appverse')->set('mail', 'site@example.org')->save();

    // User 1 bypasses access checks, so take that id first.
    $this->createUser([], 'admin');
    $this->owner = $this->createUser([], 'owner', FALSE, ['mail' => 'owner@example.com']);
    $reviewer = $this->createUser(['administer appverse content'], 'reviewer', FALSE, ['mail' => 'reviewer@example.com']);
    $this->assertInstanceOf(AccountInterface::class, $reviewer);
    $this->setCurrentUser($reviewer);
  }

  /**
   * Each decision on a new single-app repo, the app moving with its repo.
   *
   * @dataProvider newRepoCases
   */
  public function testDecisionOnANewRepo(string $decision, string $repoState, string $appState, string $reviewState): void {
    $repo = $this->makeRepo('ready_for_review');
    $app = $this->makeApp($repo, 'ready_for_review');
    $review = $this->makeReview($repo, [$decision => $app]);

    $this->assertNull($this->applier()->send($review, 'Thanks for the submission.'));

    $this->assertState($repoState, $repo);
    $this->assertState($appState, $app);
    $this->assertState($reviewState, $review);
    $this->assertFalse($this->reload($review)->get('field_arv_decision_sent_at')->isEmpty());
    // What was sent is stored, for Publish and the page (appverse-planning#51).
    $sent = ReviewDecisionApplier::sent($this->reload($review));
    $this->assertSame([$decision], array_values($sent['apps']));
    $this->assertSame('Thanks for the submission.', $sent['response']);
    $this->assertCount(1, $this->mails(), 'One decision email.');
  }

  /**
   * Decision => repo, app and review states after sending.
   *
   * @return array<string, array<int, string>>
   */
  public static function newRepoCases(): array {
    return [
      'accept' => ['accept', 'published', 'published', 'published'],
      // The app waits for the review page's Publish.
      'accept with suggestions' => ['accept_with_suggestions', 'ready_for_review', 'ready_for_review', 'in_review'],
      // The app goes back with its repo, not left awaiting review.
      'request changes' => ['request_changes', 'needs_adjustment', 'needs_adjustment', 'in_review'],
      'reject' => ['reject', 'declined', 'declined', 'in_review'],
    ];
  }

  /**
   * The decision email greets the contributor and replies to the reviewer.
   */
  public function testDecisionEmailRepliesToTheReviewer(): void {
    $repo = $this->makeRepo('ready_for_review');
    $review = $this->makeReview($repo, ['request_changes' => $this->makeApp($repo, 'ready_for_review')]);

    $this->assertNull($this->applier()->send($review, "Please add a <LICENSE>.\n\n**Required:** fix `form.yml`."));

    $mail = $this->mails()[0];
    $this->assertSame('owner@example.com', $mail['to']);
    $this->assertSame('reviewer@example.com', $mail['reply-to']);
    $this->assertSame('[Appverse] Changes requested on example/repo', $mail['subject']);
    $this->assertStringContainsString('Hi owner,', $mail['body']);
    $this->assertStringContainsString('reviewer reviewed your repo "example/repo"', $mail['body']);
    $this->assertStringContainsString('Reply to this email and it goes to the reviewer.', $mail['body']);
    // The response is escaped: the mail is turned into plain text, which
    // strips a tag but keeps escaped text as written.
    $this->assertStringContainsString('Please add a <LICENSE>.', $mail['body']);
    // The response is Markdown: rendered, not shown as typed.
    $this->assertStringNotContainsString('**Required:**', $mail['body']);
    $this->assertStringNotContainsString('`form.yml`', $mail['body']);
  }

  /**
   * The confirm page previews the email that send() then mails.
   */
  public function testPreviewIsWhatIsSent(): void {
    $repo = $this->makeRepo('ready_for_review');
    $review = $this->makeReview($repo, ['request_changes' => $this->makeApp($repo, 'ready_for_review')]);
    $review->set('field_arv_contributor_response', "**Required:** add a LICENSE.")->save();

    $preview = $this->applier()->previewDecision($review);
    $this->assertNotNull($preview);
    $this->assertSame('owner@example.com', $preview['to']);
    $this->assertSame('reviewer@example.com', $preview['reply_to']);
    $this->assertSame('owner', $preview['contributor']);
    $html = implode('', array_map('strval', $preview['body']));
    $this->assertStringContainsString('<strong>Required:</strong> add a LICENSE.', $html);

    $this->assertNull($this->applier()->send($review, "**Required:** add a LICENSE."));
    $mail = $this->mails()[0];
    $this->assertSame($preview['subject'], $mail['subject']);
    $this->assertSame($preview['to'], $mail['to']);
    $this->assertSame($preview['reply_to'], $mail['reply-to']);
  }

  /**
   * Request changes on a live repo leaves it and its app live (D8-2882).
   *
   * A decision only affects what is under review: needs_adjustment is not a
   * default revision, so both stay published and the sent-back update waits
   * on a forward revision.
   */
  public function testRequestChangesOnALiveRepo(): void {
    $repo = $this->makeRepo('published');
    $app = $this->makeApp($repo, 'published');
    $review = $this->makeReview($repo, ['request_changes' => $app]);

    $this->assertNull($this->applier()->send($review, 'Please fix the form.'));

    $this->assertTrue($this->reload($repo)->isPublished());
    $this->assertTrue($this->reload($app)->isPublished());
    $this->assertState('needs_adjustment', $repo);
    $this->assertState('needs_adjustment', $app);
    $this->assertSame('published', $this->reload($repo)->get('moderation_state')->value, 'The live revision is still the default.');
  }

  /**
   * In a monorepo each app goes its own way, and the repo goes live with one.
   */
  public function testMixedMonorepo(): void {
    $repo = $this->makeRepo('ready_for_review');
    $accepted = $this->makeApp($repo, 'ready_for_review', 'Jupyter');
    $sentBack = $this->makeApp($repo, 'ready_for_review', 'RStudio');
    $review = $this->makeReview($repo, ['accept' => $accepted, 'request_changes' => $sentBack]);

    $this->assertNull($this->applier()->send($review, 'RStudio needs a LICENSE.'));

    $this->assertState('published', $repo);
    $this->assertState('published', $accepted);
    $this->assertState('needs_adjustment', $sentBack);
  }

  /**
   * A second send, as a double submit makes, sends nothing more.
   */
  public function testASecondSendIsRefused(): void {
    $repo = $this->makeRepo('ready_for_review');
    $review = $this->makeReview($repo, ['request_changes' => $this->makeApp($repo, 'ready_for_review')]);

    $this->assertNull($this->applier()->send($review, 'Please fix the form.'));
    // The form holds the review as it was loaded, before the first send.
    $this->assertNotNull($this->applier()->send($review, 'Please fix the form.'));
    $this->assertCount(1, $this->mails(), 'Still one decision email.');
  }

  /**
   * No decision goes out on a review a newer one has superseded.
   */
  public function testNoDecisionOnASupersededReview(): void {
    $repo = $this->makeRepo('ready_for_review');
    $app = $this->makeApp($repo, 'ready_for_review');
    $old = $this->makeReview($repo, ['accept' => $app], \Drupal::time()->getRequestTime() - 3600);
    $this->makeReview($repo, ['request_changes' => $app]);

    $this->assertNotNull($this->applier()->decisionBlocker($old));
    $this->assertNotNull($this->applier()->send($old, ''));
    $this->assertState('ready_for_review', $repo);
    $this->assertTrue($this->reload($old)->get('field_arv_decision_sent_at')->isEmpty());
    $this->assertCount(0, $this->mails());
  }

  /**
   * The hub's repo Publish and Request changes refuse a reviewed repo.
   */
  public function testHubRepoRoutesRefuseAReviewedRepo(): void {
    $controller = AppverseHubController::create(\Drupal::getContainer());
    $reviewer = \Drupal::currentUser();
    $legacy = $this->makeRepo('ready_for_review');
    $reviewed = $this->makeRepo('ready_for_review');
    $this->makeReview($reviewed, []);

    $this->assertTrue($controller->adminWithoutReviewAccess($reviewer, $legacy)->isAllowed());
    $this->assertTrue($controller->adminWithoutReviewAccess($reviewer, $reviewed)->isForbidden());
    $this->assertFalse($controller->adminWithoutReviewAccess($this->owner, $legacy)->isAllowed());
  }

  /**
   * Sends a published node for review, leaving a pending revision (D8-2882).
   */
  protected function sendPending(NodeInterface $node, ?string $title = NULL): void {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $latest = $storage->loadRevision($storage->getLatestRevisionId($node->id()));
    $this->assertInstanceOf(NodeInterface::class, $latest);
    // The author's edits travel with the submission.
    if ($title !== NULL) {
      $latest->set('title', $title);
    }
    $latest->set('moderation_state', 'ready_for_review');
    $latest->setNewRevision(TRUE);
    // The submission itself is not under test: keep its mail and dispatch out.
    // @phpstan-ignore-next-line
    $latest->_ood_software_suppress_notifications = TRUE;
    $latest->save();
    $this->assertTrue($this->hasPending($node), 'Precondition: a review is pending.');
  }

  /**
   * The newest revision of a node.
   */
  protected function latestRevision(NodeInterface $node): NodeInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $latest = $storage->loadRevision($storage->getLatestRevisionId($node->id()));
    $this->assertInstanceOf(NodeInterface::class, $latest);
    return $latest;
  }

  /**
   * Whether a node is live with a review waiting on top.
   */
  protected function hasPending(NodeInterface $node): bool {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([$node->id()]);
    $fresh = $storage->load($node->id());
    $this->assertInstanceOf(NodeInterface::class, $fresh);
    return \Drupal::service('content_moderation.moderation_information')->hasPendingRevision($fresh)
      && _ood_software_has_pending_review($fresh);
  }

  /**
   * Accepting publishes the pending revision of a live repo and app.
   *
   * The repo and app stay "published" by default revision while the review
   * waits on top; the decision has to make that revision the live one or the
   * repo shows "Published, review pending" and stays queued forever
   * (D8-2882).
   */
  public function testAcceptPublishesPendingReviewOnLiveRepoAndApp(): void {
    \Drupal::moduleHandler()->loadInclude('ood_software', 'module');
    $repo = $this->makeRepo('published');
    $app = $this->makeApp($repo, 'published');
    $this->sendPending($repo);
    $this->sendPending($app);
    $review = $this->makeReview($repo, ['accept' => $app]);

    $this->assertNull($this->applier()->send($review, 'Thanks.'));

    foreach ([$repo, $app] as $node) {
      $fresh = $this->reload($node);
      $this->assertSame('published', $fresh->get('moderation_state')->value, $node->bundle());
      $this->assertTrue($fresh->isPublished());
      $this->assertFalse($this->hasPending($node), $node->bundle() . ' must not stay pending.');
      $this->assertState('published', $node);
    }
    $this->assertCount(1, $this->mails(), 'Still one decision email.');
  }

  /**
   * A decision on a live repo's pending review leaves what is live alone.
   *
   * The repo stays published on the revision that was live; the update goes
   * back to the author on a forward revision that keeps their edits and
   * carries the reviewer's response as its log. A new app in the same repo
   * has no live revision, so it becomes needs_adjustment / declined outright.
   *
   * @dataProvider sentBackCases
   */
  public function testSentBackResolvesPendingReviewOnLiveRepo(string $decision, string $state): void {
    \Drupal::moduleHandler()->loadInclude('ood_software', 'module');
    $repo = $this->makeRepo('published');
    $liveApp = $this->makeApp($repo, 'published', 'Live App');
    $newApp = $this->makeApp($repo, 'ready_for_review', 'New App');
    $defaultBefore = $this->reload($repo)->getRevisionId();
    $this->sendPending($repo, 'Edited by the author');
    $this->sendPending($liveApp);
    $liveDefaultBefore = $this->reload($liveApp)->getRevisionId();
    $review = $this->makeReview($repo, [$decision => [$liveApp, $newApp]]);

    $this->assertNull($this->applier()->send($review, 'No, because.'));

    $liveRepo = $this->reload($repo);
    $this->assertTrue($liveRepo->isPublished(), 'The repo stays in the catalog.');
    $this->assertSame($defaultBefore, $liveRepo->getRevisionId(), 'Its default revision is unchanged.');
    $latest = $this->latestRevision($repo);
    $this->assertSame($state, $latest->get('moderation_state')->value);
    $this->assertSame('No, because.', $latest->getRevisionLogMessage(), 'The reviewer response is the notes the author reads.');
    $this->assertSame('Edited by the author', $latest->label(), 'The pending edits go back to the author.');
    $this->assertSame('example/repo', $liveRepo->label(), 'The live content is untouched.');
    $this->assertFalse($this->hasPending($repo), 'No longer waiting on a reviewer.');
    $this->assertSame($state, _ood_software_pending_state($liveRepo));

    $this->assertTrue($this->reload($liveApp)->isPublished(), 'The live app stays live.');
    $this->assertSame($liveDefaultBefore, $this->reload($liveApp)->getRevisionId());
    $this->assertState($state, $liveApp);

    // Never live: the state is its default revision.
    $new = $this->reload($newApp);
    $this->assertSame($state, $new->get('moderation_state')->value);
    $this->assertFalse($new->isPublished());
  }

  /**
   * Decision => the repo's resulting state.
   *
   * @return array<string, array<int, string>>
   */
  public static function sentBackCases(): array {
    return [
      'request changes' => ['request_changes', 'needs_adjustment'],
      'reject' => ['reject', 'declined'],
    ];
  }

  /**
   * The hub's request-changes form resolves a live repo's pending review.
   *
   * The repo stays live and the comment is the notes the author reads
   * (D8-2882).
   */
  public function testRequestChangesFormResolvesPendingReview(): void {
    \Drupal::moduleHandler()->loadInclude('ood_software', 'module');
    $repo = $this->makeRepo('published');
    $app = $this->makeApp($repo, 'published');
    $this->sendPending($repo, 'Edited by the author');

    $form = AppverseHubRequestChangesForm::create(\Drupal::getContainer());
    $state = new FormState();
    $state->setValue('comment', 'Please fix.');
    $built = [];
    $form->buildForm($built, $state, $this->reload($repo));
    $form->submitForm($built, $state);

    $this->assertTrue($this->reload($repo)->isPublished(), 'The repo stays live.');
    $this->assertTrue($this->reload($app)->isPublished(), 'So does its app.');
    $this->assertState('needs_adjustment', $repo);
    $latest = $this->latestRevision($repo);
    $this->assertSame('Please fix.', $latest->getRevisionLogMessage());
    $this->assertSame('Edited by the author', $latest->label());
    $this->assertFalse($this->hasPending($repo), 'The pending review is resolved, not stuck.');
  }

  /**
   * A repo that was never live is sent back as its default revision.
   *
   * The workflow change only leaves a live repo's revision alone (D8-2882).
   *
   * @dataProvider sentBackCases
   */
  public function testNeverPublishedRepoSentBackIsTheDefaultRevision(string $decision, string $state): void {
    $repo = $this->makeRepo('ready_for_review');
    $app = $this->makeApp($repo, 'ready_for_review');
    $review = $this->makeReview($repo, [$decision => $app]);

    $this->assertNull($this->applier()->send($review, 'No.'));

    $fresh = $this->reload($repo);
    $this->assertSame($state, $fresh->get('moderation_state')->value, 'Default revision.');
    $this->assertFalse($fresh->isPublished());
    $this->assertSame($state, $this->reload($app)->get('moderation_state')->value);
  }

  /**
   * Updating a sent-back decision to Accept publishes the forward revision.
   *
   * A live repo and app go live with the author's edits (D8-2882).
   */
  public function testUpdateToAcceptPublishesTheForwardRevision(): void {
    \Drupal::moduleHandler()->loadInclude('ood_software', 'module');
    $repo = $this->makeRepo('published');
    $app = $this->makeApp($repo, 'published');
    $this->sendPending($repo, 'Edited by the author');
    $this->sendPending($app, 'App edited by the author');
    $review = $this->makeReview($repo, ['request_changes' => $app]);
    $this->assertNull($this->applier()->send($review, 'Please fix.'));
    $this->assertSame('needs_adjustment', _ood_software_pending_state($this->reload($repo)));

    // The reviewer changes their mind.
    $review = $this->reload($review);
    $verdict = $review->get('field_arv_verdicts')->referencedEntities()[0];
    $verdict->set('field_rvv_conclusion', 'accept')->save();
    $review = $this->reload($review);
    $this->assertNull($this->applier()->update($review, 'On second thought, fine.'));

    foreach ([$repo, $app] as $node) {
      $fresh = $this->reload($node);
      $this->assertTrue($fresh->isPublished());
      $this->assertStringContainsString('edited by the author', strtolower($fresh->label()), 'The default revision is now the one with the edit.');
      $this->assertNull(_ood_software_pending_state($fresh), 'No pending state is left.');
      $this->assertState('published', $node);
    }
  }

  /**
   * Updating an accepted decision to a sent back one keeps the repo live.
   *
   * It builds on the author's pending revision.
   */
  public function testUpdateToRequestChangesOnLiveRepo(): void {
    \Drupal::moduleHandler()->loadInclude('ood_software', 'module');
    $repo = $this->makeRepo('published');
    $app = $this->makeApp($repo, 'published');
    $this->sendPending($repo, 'Edited by the author');
    $this->sendPending($app);
    $review = $this->makeReview($repo, ['accept' => $app]);
    $this->assertNull($this->applier()->send($review, ''));
    $this->sendPending($repo, 'Edited again');

    $review = $this->reload($review);
    $review->get('field_arv_verdicts')->referencedEntities()[0]->set('field_rvv_conclusion', 'request_changes')->save();
    $this->assertNull($this->applier()->update($this->reload($review), 'Actually, no.'));

    $this->assertTrue($this->reload($repo)->isPublished());
    $this->assertState('needs_adjustment', $repo);
    $this->assertSame('Edited again', $this->latestRevision($repo)->label());
  }

  /**
   * Draft is neither offered nor accepted on a live node's held-back update.
   *
   * Draft is a default-revision state, so choosing it would pull the live
   * version out of the catalog while fixing the update.
   *
   * @dataProvider heldBackBundles
   */
  public function testEditFormBlocksDraftOnHeldBackUpdate(string $bundle): void {
    \Drupal::moduleHandler()->loadInclude('ood_software', 'module');
    $repo = $this->makeRepo('published');
    $node = $bundle === 'appverse_repo' ? $repo : $this->makeApp($repo, 'published');
    $this->sendPending($node);
    $latest = $this->latestRevision($node);
    $latest->set('moderation_state', 'needs_adjustment')->setNewRevision(TRUE);
    // @phpstan-ignore-next-line
    $latest->_ood_software_suppress_notifications = TRUE;
    $latest->save();

    $formObject = \Drupal::entityTypeManager()->getFormObject('node', 'edit');
    $formObject->setEntity($this->latestRevision($node));
    $formState = new FormState();
    $formState->setFormObject($formObject);
    $options = ['draft' => 'Draft', 'ready_for_review' => 'Ready for Review', 'needs_adjustment' => 'Needs Adjustment'];
    $form = ['moderation_state' => ['widget' => [0 => ['state' => ['#options' => $options]]]]];

    $id = 'node_' . $bundle . '_edit_form';
    ood_software_form_alter($form, $formState, $id);

    $this->assertArrayNotHasKey('draft', $form['moderation_state']['widget'][0]['state']['#options']);
    $this->assertArrayHasKey('ready_for_review', $form['moderation_state']['widget'][0]['state']['#options']);
    $this->assertContains('_ood_software_live_update_validate', $form['#validate']);

    // A crafted submission of draft is refused.
    $formState->setValue(['moderation_state', 0, 'state'], 'draft');
    _ood_software_live_update_validate($form, $formState);
    $this->assertNotEmpty($formState->getErrors());
    $this->assertStringContainsString('To take it out of the catalog use Unpublish', (string) current($formState->getErrors()));

    $ok = new FormState();
    $ok->setFormObject($formObject);
    $ok->setValue(['moderation_state', 0, 'state'], 'ready_for_review');
    _ood_software_live_update_validate($form, $ok);
    $this->assertSame([], $ok->getErrors());
  }

  /**
   * Bundles whose edit form blocks draft on a held back update.
   *
   * @return array<string, array<int, string>>
   *   Bundle per case.
   */
  public static function heldBackBundles(): array {
    return ['repo' => ['appverse_repo'], 'app' => ['appverse_app']];
  }

  /**
   * Draft stays available when nothing is held back.
   */
  public function testEditFormOffersDraftOtherwise(): void {
    \Drupal::moduleHandler()->loadInclude('ood_software', 'module');
    $live = $this->makeRepo('published');
    $sentBackNew = $this->makeRepo('needs_adjustment');
    foreach ([$live, $sentBackNew] as $repo) {
      $formObject = \Drupal::entityTypeManager()->getFormObject('node', 'edit');
      $formObject->setEntity($this->latestRevision($repo));
      $formState = new FormState();
      $formState->setFormObject($formObject);
      $form = ['moderation_state' => ['widget' => [0 => ['state' => ['#options' => ['draft' => 'Draft']]]]]];
      ood_software_form_alter($form, $formState, 'node_appverse_repo_edit_form');
      $this->assertArrayHasKey('draft', $form['moderation_state']['widget'][0]['state']['#options'], $repo->get('moderation_state')->value);
    }
  }

  /**
   * The pending state is memoized per node and cleared by a save (D8-2882).
   */
  public function testPendingStateMemoizationIsResetOnSave(): void {
    \Drupal::moduleHandler()->loadInclude('ood_software', 'module');
    $repo = $this->makeRepo('published');
    $other = $this->makeRepo('published');
    $this->assertNull(_ood_software_pending_state($this->reload($repo)));
    $this->assertNull(_ood_software_pending_state($this->reload($other)));

    $this->sendPending($repo);
    $this->assertSame('ready_for_review', _ood_software_pending_state($this->reload($repo)));
    $this->assertTrue(_ood_software_has_pending_review($this->reload($repo)));

    $latest = $this->latestRevision($repo);
    $latest->set('moderation_state', 'needs_adjustment')->setNewRevision(TRUE);
    // @phpstan-ignore-next-line
    $latest->_ood_software_suppress_notifications = TRUE;
    $latest->save();
    $this->assertSame('needs_adjustment', _ood_software_pending_state($this->reload($repo)), 'Fresh after a save in the same request.');
    $this->assertFalse(_ood_software_has_pending_review($this->reload($repo)));

    $latest = $this->latestRevision($repo);
    $latest->set('moderation_state', 'published')->setNewRevision(TRUE);
    $latest->save();
    $this->assertNull(_ood_software_pending_state($this->reload($repo)));
    $this->assertNull(_ood_software_pending_state($this->reload($other)));
    // A new node has no pending state.
    $this->assertNull(_ood_software_pending_state(Node::create(['type' => 'appverse_repo', 'title' => 'x'])));
  }

  /**
   * The decision applier.
   */
  protected function applier(): ReviewDecisionApplier {
    $applier = \Drupal::service('ood_software.review_decision_applier');
    assert($applier instanceof ReviewDecisionApplier);
    return $applier;
  }

  /**
   * Asserts a node's moderation state, read fresh.
   */
  protected function assertState(string $expected, NodeInterface $node): void {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $latest = $storage->loadRevision($storage->getLatestRevisionId($node->id()));
    $this->assertInstanceOf(NodeInterface::class, $latest);
    $this->assertSame($expected, $latest->get('moderation_state')->value, $node->bundle() . ' ' . $node->label());
  }

  /**
   * A node loaded fresh.
   */
  protected function reload(NodeInterface $node): NodeInterface {
    $fresh = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id());
    $this->assertInstanceOf(NodeInterface::class, $fresh);
    return $fresh;
  }

  /**
   * The decision emails collected so far.
   *
   * @return array<int, array<string, mixed>>
   */
  protected function mails(): array {
    return array_values(array_filter(
      $this->getMails(),
      fn (array $mail): bool => ($mail['key'] ?? '') === 'review_decision',
    ));
  }

  /**
   * Creates a repo owned by the contributor.
   */
  protected function makeRepo(string $state): NodeInterface {
    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => 'example/repo',
      'uid' => $this->owner->id(),
      'moderation_state' => $state,
    ]);
    $repo->save();
    return $repo;
  }

  /**
   * Creates a member app of a repo.
   */
  protected function makeApp(NodeInterface $repo, string $state, string $title = 'Example App'): NodeInterface {
    $app = Node::create([
      'type' => 'appverse_app',
      'title' => $title,
      'uid' => $this->owner->id(),
      'field_appverse_repo' => $repo->id(),
      'moderation_state' => $state,
    ]);
    $app->save();
    return $app;
  }

  /**
   * Creates an undecided review with a verdict per app.
   *
   * @param \Drupal\node\NodeInterface $repo
   *   The reviewed repo.
   * @param array<string, \Drupal\node\NodeInterface|array<int, \Drupal\node\NodeInterface>> $verdicts
   *   Decision => the app (or apps) it decides.
   * @param int|null $created
   *   When the review was created.
   */
  protected function makeReview(NodeInterface $repo, array $verdicts, ?int $created = NULL): NodeInterface {
    $paragraphs = [];
    foreach ($verdicts as $decision => $apps) {
      foreach (is_array($apps) ? $apps : [$apps] as $app) {
        $paragraph = Paragraph::create([
          'type' => 'review_verdict',
          'field_rvv_app_ref' => $app->id(),
          'field_rvv_app_id' => $app->label(),
          'field_rvv_conclusion' => $decision,
        ]);
        $paragraph->save();
        $paragraphs[] = $paragraph;
      }
    }
    $review = Node::create([
      'type' => 'appverse_review',
      'title' => 'Review: ' . $repo->label(),
      'field_arv_repo' => $repo->id(),
      'field_arv_verdicts' => $paragraphs,
      'moderation_state' => 'draft',
      'created' => $created ?? \Drupal::time()->getRequestTime(),
    ]);
    $review->save();
    return $review;
  }

}
