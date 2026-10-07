<?php

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\ood_software\Service\ReviewProgress as P;

/**
 * The progress line for the eleven situations of the state model.
 *
 * Each case is a row of appverse-planning review-system/REVIEW-STATES.md:
 * the stored facts, the five reviewer step states, the four contributor step
 * states, and the labels that matter.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Service\ReviewProgress
 */
class ReviewProgressTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The labels go through t(), which returns TranslatableMarkup, and
    // casting that to a string needs the container's translation service
    // (appverse-planning#49).
    $container = new \Drupal\Core\DependencyInjection\ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * @covers ::steps
   * @dataProvider situations
   */
  public function testSituation(array $facts, array $reviewer, array $contributor, array $labels): void {
    $steps = P::steps($facts);

    $this->assertSame($reviewer, array_column($steps['reviewer'], 'state'), 'reviewer step states');
    $this->assertSame($contributor, array_column($steps['contributor'], 'state'), 'contributor step states');
    $byStep = array_column($steps['reviewer'], 'label', 'step');
    foreach ($labels as $step => $label) {
      $this->assertSame($label, $byStep[$step], "label of $step");
    }
  }

  public static function situations(): array {
    [$D, $C, $W, $F, $N] = [P::DONE, P::CURRENT, P::WAITING, P::FAILED, P::NOT_REACHED];
    $base = ['repo_state' => 'ready_for_review', 'run_status' => 'complete', 'review_state' => NULL, 'decision_sent' => FALSE, 'decision' => NULL, 'suggestion' => 'request_changes', 'round' => 1];
    return [
      '1 added, not submitted' => [
        ['repo_state' => 'draft', 'run_status' => NULL] + $base,
        [$W, $N, $N, $N, $N], [$W, $N, $N, $N], ['Submitted' => 'Not submitted'],
      ],
      '2 submitted, AI report queued' => [
        ['run_status' => 'pending'] + $base,
        [$D, $C, $N, $N, $N], [$D, $C, $N, $N], ['AI report' => 'Queued'],
      ],
      '3 AI report running' => [
        ['run_status' => 'in_progress'] + $base,
        [$D, $C, $N, $N, $N], [$D, $C, $N, $N], ['AI report' => 'Running'],
      ],
      // The contributor still sees "In review" (current), never a failure.
      '4 AI report failed' => [
        ['run_status' => 'error'] + $base,
        [$D, $F, $N, $N, $N], [$D, $C, $N, $N], ['AI report' => 'Failed · rerun'],
      ],
      '5 AI report ready, nobody started' => [
        ['review_state' => 'draft'] + $base,
        [$D, $D, $C, $N, $N], [$D, $C, $N, $N], ['AI report' => 'Ready · suggests Request changes', 'Review' => 'Not started'],
      ],
      '6 reviewer working' => [
        ['review_state' => 'in_review'] + $base,
        [$D, $D, $C, $N, $N], [$D, $C, $N, $N], ['Review' => 'In progress'],
      ],
      '7 changes requested' => [
        ['repo_state' => 'needs_adjustment', 'review_state' => 'in_review', 'decision_sent' => TRUE, 'decision' => 'request_changes'] + $base,
        [$D, $D, $D, $W, $N], [$D, $D, $W, $N], ['Decision' => 'Changes requested · round 1'],
      ],
      '8 resubmitted, new AI report' => [
        ['run_status' => 'pending', 'round' => 2] + $base,
        [$D, $C, $N, $N, $N], [$D, $C, $N, $N], ['Submitted' => 'Resubmitted · round 2', 'AI report' => 'Queued'],
      ],
      '9 accepted, not yet published' => [
        ['review_state' => 'in_review', 'decision_sent' => TRUE, 'decision' => 'accept_with_suggestions'] + $base,
        [$D, $D, $D, $D, $C], [$D, $D, $D, $C], ['Decision' => 'Accepted with suggestions', 'Live' => 'Ready to publish'],
      ],
      '10 published' => [
        ['repo_state' => 'published', 'review_state' => 'published', 'decision_sent' => TRUE, 'decision' => 'accept'] + $base,
        [$D, $D, $D, $D, $D], [$D, $D, $D, $D], ['Decision' => 'Accepted', 'Live' => 'Live'],
      ],
      '11 declined' => [
        ['repo_state' => 'declined', 'review_state' => 'in_review', 'decision_sent' => TRUE, 'decision' => 'reject'] + $base,
        [$D, $D, $D, $F, $N], [$D, $D, $F, $N], ['Decision' => 'Declined'],
      ],
      // A live repo re-submitted with its sent-back apps stays published
      // while the new review runs (appverse-planning#48), so the contributor
      // is told both things (appverse-planning#49).
      '12 live, update in review' => [
        ['repo_state' => 'published', 'review_state' => 'in_review'] + $base,
        [$D, $D, $C, $N, $D], [$D, $C, $N, $D], ['Review' => 'In progress'],
      ],
    ];
  }

  /**
   * Repos from before the review system: no run and no review.
   *
   * @covers ::steps
   */
  public function testNeverReviewed(): void {
    [$D, $C, $N] = [P::DONE, P::CURRENT, P::NOT_REACHED];
    // Published before reviews existed: nothing is queued or in review.
    $steps = P::steps(['repo_state' => 'published', 'run_status' => NULL, 'review_state' => NULL]);
    $this->assertSame([$D, $N, $N, $N, $D], array_column($steps['reviewer'], 'state'));
    $this->assertSame([$D, $N, $N, $D], array_column($steps['contributor'], 'state'));
    $this->assertSame('Live in the AppVerse catalog.', P::contributorSentence($steps['contributor']));
    // In the queue with no run: a reviewer has to start one.
    $steps = P::steps(['repo_state' => 'ready_for_review', 'run_status' => NULL, 'review_state' => NULL]);
    $this->assertSame([$D, $C, $N, $N, $N], array_column($steps['reviewer'], 'state'));
    $this->assertSame('Not started', $steps['reviewer'][1]['label']);
    $this->assertSame([$D, $C, $N, $N], array_column($steps['contributor'], 'state'));
  }

  /**
   * The contributor's card sentence: "In review…" from submission to a
   * decision, whatever the AI is doing (situations 2–6 and 8).
   *
   * @covers ::contributorSentence
   */
  public function testContributorSentence(): void {
    $expected = [
      '1 added, not submitted' => 'Not submitted yet.',
      '2 submitted, AI report queued' => 'In review. A reviewer will respond by email.',
      '3 AI report running' => 'In review. A reviewer will respond by email.',
      '4 AI report failed' => 'In review. A reviewer will respond by email.',
      '5 AI report ready, nobody started' => 'In review. A reviewer will respond by email.',
      '6 reviewer working' => 'In review. A reviewer will respond by email.',
      '7 changes requested' => 'Changes requested. Read the review, fix the repo on GitHub, then click Re-submit. Questions? Reply to the review email.',
      '8 resubmitted, new AI report' => 'In review. A reviewer will respond by email.',
      '9 accepted, not yet published' => 'Accepted. A reviewer will publish it.',
      '10 published' => 'Live in the AppVerse catalog.',
      '11 declined' => 'Declined. The review says why.',
      '12 live, update in review' => 'Live, and your update is in review. A reviewer will respond by email.',
    ];
    foreach (self::situations() as $name => [$facts]) {
      $this->assertSame($expected[$name], P::contributorSentence(P::steps($facts)['contributor']), $name);
    }
  }

  /**
   * The card chip: where the repo is, in a few words.
   *
   * @covers ::chip
   */
  public function testChip(): void {
    $expected = [
      // name => [reviewer label, contributor label, reviewer modifier]
      '1 added, not submitted' => ['Not submitted', 'Not submitted', 'secondary'],
      '2 submitted, AI report queued' => ['Queued', 'In review', 'warning'],
      '3 AI report running' => ['Running', 'In review', 'warning'],
      // A failed run is the reviewer's to rerun and is never shown to the
      // contributor, who still reads "In review".
      '4 AI report failed' => ['Failed · rerun', 'In review', 'danger'],
      '5 AI report ready, nobody started' => ['In review', 'In review', 'warning'],
      '6 reviewer working' => ['In review', 'In review', 'warning'],
      '7 changes requested' => ['Changes requested · round 1', 'Changes requested · round 1', 'warning'],
      // A resubmission starts a fresh AI report, so the reviewer chip reads
      // the same as case 2: the round shows on the progress line's Submitted
      // step, not on the chip, which says where the repo is now.
      '8 resubmitted, new AI report' => ['Queued', 'In review', 'warning'],
      '9 accepted, not yet published' => ['Ready to publish', 'Ready to publish', 'warning'],
      '10 published' => ['Live', 'Live', 'success'],
      '11 declined' => ['Declined', 'Declined', 'danger'],
      '12 live, update in review' => ['In review', 'Published, update in review', 'warning'],
    ];
    foreach (self::situations() as $name => [$facts]) {
      $steps = P::steps($facts);
      [$reviewer, $contributor, $modifier] = $expected[$name];
      $this->assertSame($reviewer, P::chip($steps['reviewer'])['label'], "$name (reviewer)");
      $this->assertSame($contributor, P::chip($steps['contributor'])['label'], "$name (contributor)");
      $this->assertSame($modifier, P::chip($steps['reviewer'])['modifier'], "$name (modifier)");
    }
  }

  /**
   * A decision on the review that has not been sent does not show: the
   * steps follow the sent decision only.
   *
   * @covers ::steps
   */
  public function testAnUnsentDecisionIsNotShown(): void {
    $steps = P::steps(['repo_state' => 'ready_for_review', 'run_status' => 'complete', 'review_state' => 'in_review', 'decision_sent' => FALSE, 'decision' => 'accept']);
    $this->assertSame([P::NOT_REACHED, P::NOT_REACHED], [$steps['reviewer'][3]['state'], $steps['reviewer'][4]['state']]);
  }

  /**
   * The overall decision is the strictest per-app one (appverse-review#70).
   *
   * @covers ::strictestDecision
   */
  public function testStrictestDecision(): void {
    $this->assertSame('request_changes', P::strictestDecision(['accept', 'request_changes', 'accept_with_suggestions']));
    $this->assertSame('reject', P::strictestDecision(['reject', 'accept']));
    $this->assertSame('accept_with_suggestions', P::strictestDecision(['accept', 'accept_with_suggestions']));
    $this->assertNull(P::strictestDecision([]));
    $this->assertNull(P::strictestDecision([NULL, 'bogus']));
  }

  /**
   * A monorepo whose apps went different ways names each one.
   *
   * The step's own label is the strictest decision, which on its own said
   * "Changes requested" with no word on which apps went live.
   *
   * @covers ::steps
   */
  public function testMixedMonorepoNamesEachApp(): void {
    $steps = P::steps([
      'repo_state' => 'published',
      'run_status' => 'complete',
      'review_state' => 'in_review',
      'decision_sent' => TRUE,
      'decision' => 'request_changes',
      'app_decisions' => ['jupyter' => 'accept', 'rstudio' => 'request_changes'],
      'round' => 1,
    ]);
    $decision = $steps['reviewer'][3];

    $this->assertSame('Changes requested · round 1', $decision['label'], 'The step still shows the strictest.');
    $this->assertSame(
      ['jupyter' => 'Accepted', 'rstudio' => 'Changes requested'],
      $decision['apps'],
      'Each app is named with its own decision.'
    );
  }

  /**
   * When every app went the same way there is nothing to break out.
   *
   * @covers ::steps
   */
  public function testMonorepoWithOneDecisionDoesNotRepeatIt(): void {
    $steps = P::steps([
      'repo_state' => 'published',
      'run_status' => 'complete',
      'review_state' => 'in_review',
      'decision_sent' => TRUE,
      'decision' => 'accept',
      'app_decisions' => ['jupyter' => 'accept', 'rstudio' => 'accept'],
      'round' => 1,
    ]);

    $this->assertArrayNotHasKey('apps', $steps['reviewer'][3], 'All the same says it once.');
  }

}
