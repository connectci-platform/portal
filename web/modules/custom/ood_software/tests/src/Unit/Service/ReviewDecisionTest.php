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
   */
  public function testProblems(array $apps, string $response, int $expected): void {
    $this->assertCount($expected, ReviewDecision::problems($apps, $response));
  }

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
    // Accept on a live repo changes nothing in the catalog, so no email.
    $this->assertStringContainsString('Published', ReviewDecision::effects('accept', FALSE)[0][1]);
    $this->assertStringContainsString('Stays live', ReviewDecision::effects('accept', TRUE)[0][1]);
    $this->assertStringStartsWith('None', ReviewDecision::effects('accept', TRUE)[2][1]);
    $this->assertStringStartsNotWith('None', ReviewDecision::effects('accept', FALSE)[2][1]);
    $this->assertSame([], ReviewDecision::effects('bogus', FALSE));
  }

}
