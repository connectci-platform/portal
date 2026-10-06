<?php

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\ood_software\Service\ReviewDecision;

/**
 * Sending a review's decision: when it may be sent, and what it says.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Service\ReviewDecision
 */
class ReviewDecisionTest extends UnitTestCase {

  /**
   * Every app needs a decision; the response is required unless the overall
   * (strictest) decision is Accept.
   *
   * @covers ::problems
   * @dataProvider problemCases
   *
   * @param array<string, string|null> $apps
   *   App name => its decision.
   */
  public function testProblems(array $apps, string $response, int $expected): void {
    $this->assertCount($expected, ReviewDecision::problems($apps, $response));
  }

  /**
   * Decisions and a response, with how many problems they raise.
   *
   * @return array<string, array<int, mixed>>
   */
  public static function problemCases(): array {
    return [
      'accept, no response' => [['App' => 'accept'], '', 0],
      'suggestions need a response' => [['App' => 'accept_with_suggestions'], ' ', 1],
      'suggestions with a response' => [['App' => 'accept_with_suggestions'], 'Please add a LICENSE.', 0],
      'request changes need a response' => [['App' => 'request_changes'], '', 1],
      'reject needs a response' => [['App' => 'reject'], '', 1],
      'an undecided app' => [['One' => 'accept', 'Two' => NULL], '', 1],
      // The strictest app decides whether a response is required.
      'monorepo, one app sent back' => [['One' => 'accept', 'Two' => 'request_changes'], '', 1],
      'no apps' => [[], 'text', 1],
    ];
  }

  /**
   * The "When you confirm" rows say what happens to the repo, the review,
   * the email, the contributor and the next step, and depend on whether the
   * repo is live.
   *
   * @covers ::effects
   */
  public function testEffects(): void {
    foreach (['accept', 'accept_with_suggestions', 'request_changes', 'reject'] as $decision) {
      $rows = ReviewDecision::effects($decision, FALSE);
      $this->assertSame(['Repo', 'Review', 'Email', 'Contributor', 'Next step'], array_column($rows, 0), $decision);
    }
    $this->assertStringContainsString('Ready to publish', ReviewDecision::effects('accept_with_suggestions', FALSE)[0][1]);
    $this->assertStringContainsString('Stays live', ReviewDecision::effects('accept_with_suggestions', TRUE)[0][1]);
    $this->assertStringContainsString('Unpublished', ReviewDecision::effects('request_changes', TRUE)[0][1]);
    $this->assertStringNotContainsString('Unpublished', ReviewDecision::effects('request_changes', FALSE)[0][1]);
    $this->assertStringContainsString('Publish button', ReviewDecision::effects('accept_with_suggestions', FALSE)[4][1]);
    // Accept on a live repo changes nothing in the catalog; the email says so.
    $this->assertStringContainsString('Published', ReviewDecision::effects('accept', FALSE)[0][1]);
    $this->assertStringContainsString('Stays live', ReviewDecision::effects('accept', TRUE)[0][1]);
    $this->assertStringContainsString('stays live', ReviewDecision::effects('accept', TRUE)[2][1]);
    $this->assertStringContainsString('published', ReviewDecision::effects('accept', FALSE)[2][1]);
    // Accept with suggestions on a new repo: a second email when published.
    $this->assertStringContainsString('Another goes out', ReviewDecision::effects('accept_with_suggestions', FALSE)[2][1]);
    $this->assertStringNotContainsString('Another goes out', ReviewDecision::effects('accept_with_suggestions', TRUE)[2][1]);
    $this->assertSame([], ReviewDecision::effects('bogus', FALSE));
  }

  /**
   * Each app goes its own way, and the repo is published when any app is.
   *
   * @covers ::plan
   * @dataProvider planCases
   *
   * @param array<string, string> $apps
   *   App key => its decision.
   * @param array<string, string|null> $appMoves
   *   App key => its expected move.
   */
  public function testPlan(array $apps, ?string $repo, array $appMoves, ?string $review): void {
    $this->assertSame(['repo' => $repo, 'apps' => $appMoves, 'review' => $review], ReviewDecision::plan($apps));
  }

  /**
   * App decisions, with the repo, app and review moves they plan.
   *
   * @return array<string, array<int, mixed>>
   */
  public static function planCases(): array {
    return [
      'single accept' => [['a' => 'accept'], 'publish', ['a' => 'publish'], 'publish'],
      'single suggestions waits for Publish' => [['a' => 'accept_with_suggestions'], NULL, ['a' => NULL], NULL],
      // An app moves itself even when it goes where the repo goes (#50).
      'single request changes' => [['a' => 'request_changes'], 'needs_adjustment', ['a' => 'needs_adjustment'], NULL],
      'single reject' => [['a' => 'reject'], 'declined', ['a' => 'declined'], NULL],
      'one accepted, one sent back' => [['a' => 'accept', 'b' => 'request_changes'], 'publish', ['a' => 'publish', 'b' => 'needs_adjustment'], 'publish'],
      'one accepted, one declined' => [['a' => 'accept', 'b' => 'reject'], 'publish', ['a' => 'publish', 'b' => 'declined'], 'publish'],
      'suggestions and sent back: the repo waits' => [['a' => 'accept_with_suggestions', 'b' => 'request_changes'], NULL, ['a' => NULL, 'b' => 'needs_adjustment'], NULL],
      'accept and suggestions' => [['a' => 'accept', 'b' => 'accept_with_suggestions'], 'publish', ['a' => 'publish', 'b' => NULL], 'publish'],
      // Declined only when every app is; a fixable app sends the repo back.
      'sent back and declined' => [['a' => 'request_changes', 'b' => 'reject'], 'needs_adjustment', ['a' => 'needs_adjustment', 'b' => 'declined'], NULL],
      'all declined' => [['a' => 'reject', 'b' => 'reject'], 'declined', ['a' => 'declined', 'b' => 'declined'], NULL],
    ];
  }

  /**
   * One decision for every app reads as effects(); a mix lists each app.
   *
   * @covers ::effectsFor
   */
  public function testEffectsFor(): void {
    $this->assertSame(ReviewDecision::effects('request_changes', TRUE), ReviewDecision::effectsFor(['A' => 'request_changes', 'B' => 'request_changes'], TRUE));

    $rows = array_column(ReviewDecision::effectsFor(['A' => 'accept', 'B' => 'request_changes'], FALSE), 1, 0);
    $this->assertSame(['Repo', 'Apps', 'Review', 'Email', 'Contributor', 'Next step'], array_keys($rows));
    $this->assertStringContainsString('accepted apps only', $rows['Repo']);
    $this->assertSame('A: published; B: unpublished, back to the contributor as Needs changes.', $rows['Apps']);
    $this->assertStringStartsWith('Published', $rows['Review']);
    $this->assertStringContainsString('Re-review', $rows['Next step']);

    $rows = array_column(ReviewDecision::effectsFor(['A' => 'accept_with_suggestions', 'B' => 'reject'], TRUE), 1, 0);
    $this->assertStringStartsWith('Stays live', $rows['Repo']);
    $this->assertStringStartsWith('Not public yet', $rows['Review']);
    $this->assertStringStartsWith('One email', $rows['Email']);
    $this->assertStringNotContainsString('Another goes out', $rows['Email'], 'a live repo: nothing new to publish');
    $this->assertStringContainsString('Publish button', $rows['Next step']);
  }

}
