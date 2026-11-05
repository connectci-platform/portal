<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\KernelTests\KernelTestBase;
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
  protected function makeReview(bool $sent): array {
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
      'field_rvv_conclusion' => $sent ? 'request_changes' : NULL,
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
        'apps' => [(string) $verdict->id() => 'request_changes'],
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
  protected function renderAs(UserInterface $user, NodeInterface $review): string {
    $this->setCurrentUser($user);
    $request = Request::create('/appverse/review/' . $review->id());
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    $form = $this->container->get('form_builder')->getForm(ReviewPageForm::class, Node::load($review->id()));
    $html = (string) $this->container->get('renderer')->renderRoot($form);
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
    $this->assertStringContainsString('A reviewer will confirm, change or remove these.', $html);
    $this->assertStringContainsString('Runs user input through a shell.', $html);
    $this->assertStringContainsString('Dismissed finding summary.', $html, 'A dismissal is the reviewer\'s until sent, so the tool\'s finding still shows.');
    $this->assertStringContainsString('Changes you push to GitHub now will be reviewed in the next round.', $html);
    $this->assertStringNotContainsString('Unsent response text.', $html);
    $this->assertStringNotContainsString('Internal note text.', $html);
    $this->assertStringNotContainsString('data-review-rerun', $html);
    $this->assertStringNotContainsString('Response from the reviewer', $html);
  }

  /**
   * After the decision the contributor sees the response and what to fix.
   */
  public function testDecidedRendersResponseAndDropsDismissed(): void {
    ['review' => $review] = $this->makeReview(TRUE);
    $html = $this->renderAs($this->owner, $review);
    $this->assertStringContainsString('Changes requested', $html);
    $this->assertStringContainsString('Response from the reviewer', $html);
    $this->assertStringContainsString('Sent response text.', $html);
    $this->assertStringContainsString('What to fix', $html);
    $this->assertStringContainsString('Runs user input through a shell.', $html);
    $this->assertStringContainsString('<summary>Progress</summary>', $html);
    $this->assertStringNotContainsString('Dismissed finding summary.', $html);
    $this->assertStringNotContainsString('Internal note text.', $html);
    $this->assertStringNotContainsString('Automated check', $html);
  }

  /**
   * The decision carries reviewer-only values the contributor must not get.
   */
  public function testDecidedPageLeaksNoReviewerOnlyDecisionFields(): void {
    ['review' => $review] = $this->makeReview(TRUE);
    $html = $this->renderAs($this->owner, $review);
    $this->assertStringNotContainsString('/publish', $html, 'No publish URL.');
    $this->assertStringNotContainsString('Email body only the reviewer sees', $html);
    $this->assertStringNotContainsString('to re-submit. They were asked', $html, 'No waiting-on text.');
    $this->assertStringNotContainsString('Waiting on owner', $html);
    $this->assertStringNotContainsString('Update decision', $html);
    $this->assertStringNotContainsString('Your review', $html, 'No email subject.');
  }

  /**
   * A reviewer keeps the editable review page.
   */
  public function testReviewerStillGetsTheReviewForm(): void {
    ['review' => $review] = $this->makeReview(FALSE);
    $html = $this->renderAs($this->reviewer, $review);
    $this->assertStringNotContainsString('Automated check', $html);
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
    $this->assertStringContainsString('A newer review replaced this one.', $html);
    $this->assertStringContainsString('/appverse/review/' . $newer->id(), $html);
  }

}
