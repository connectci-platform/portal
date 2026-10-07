<?php

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\Core\DependencyInjection\ContainerBuilder;
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

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
   * The send button names the decision, and a monorepo's mix in counts.
   *
   * @covers ::sendLabel
   */
  public function testSendLabel(): void {
    $this->assertSame('Request changes…', (string) ReviewDecision::sendLabel(['a' => 'request_changes']));
    $this->assertSame('Accept and publish…', (string) ReviewDecision::sendLabel(['a' => 'accept', 'b' => 'accept']));
    $this->assertSame('Accept with suggestions…', (string) ReviewDecision::sendLabel(['a' => 'accept_with_suggestions']));
    $this->assertSame('Decline…', (string) ReviewDecision::sendLabel(['a' => 'reject']));
    $this->assertSame('Send decision…', (string) ReviewDecision::sendLabel(['a' => NULL]));
    $this->assertSame('Send decisions (1 accepted, 2 changes requested)…', (string) ReviewDecision::sendLabel(['a' => 'request_changes', 'b' => 'accept', 'c' => 'request_changes']));
  }

  /**
   * The button waits for every app's decision and, unless all are Accept, a
   * response.
   *
   * @covers ::sendBlocker
   */
  public function testSendBlocker(): void {
    $this->assertNull(ReviewDecision::sendBlocker(['a' => 'accept'], ''));
    $this->assertNull(ReviewDecision::sendBlocker(['a' => 'request_changes'], 'Fix the form.'));
    $this->assertSame('Choose a decision for every app first.', (string) ReviewDecision::sendBlocker(['a' => 'accept', 'b' => NULL], 'x'));
    $this->assertSame('Write the response to the contributor first.', (string) ReviewDecision::sendBlocker(['a' => 'accept', 'b' => 'reject'], ' '));
    $this->assertNotNull(ReviewDecision::sendBlocker([], 'x'));
  }

  /**
   * The confirm page's heading says the decision whole.
   *
   * @covers ::headline
   */
  public function testHeadline(): void {
    $this->assertSame('Request changes on example', (string) ReviewDecision::headline(['a' => 'request_changes'], 'example'));
    $this->assertSame('Accept example with suggestions', (string) ReviewDecision::headline(['a' => 'accept_with_suggestions'], 'example'));
    $this->assertSame('Send the decisions on example', (string) ReviewDecision::headline(['a' => 'accept', 'b' => 'reject'], 'example'));
  }

  /**
   * What sending causes, the public side stated plainly.
   *
   * @covers ::consequences
   */
  public function testConsequences(): void {
    $say = fn (array $d, bool $live): string => implode(' ', array_map('strval', ReviewDecision::consequences($d, $live, 'Ada')));
    $this->assertStringContainsString('published in the Appverse catalog', $say(['a' => 'accept'], FALSE));
    $this->assertStringContainsString('Nothing is published yet', $say(['a' => 'accept_with_suggestions'], FALSE));
    $this->assertStringContainsString('leaves the catalog', $say(['a' => 'request_changes'], TRUE));
    $this->assertStringNotContainsString('leaves the catalog', $say(['a' => 'request_changes'], FALSE));
    $this->assertStringContainsString('Ada can read the review and re-submit', $say(['a' => 'request_changes'], FALSE));
    $this->assertStringContainsString('The review is never published', $say(['a' => 'reject'], FALSE));
    $mixed = $say(['a' => 'accept', 'b' => 'reject'], FALSE);
    $this->assertStringContainsString('with the accepted apps, and so is the review', $mixed);
    $this->assertStringContainsString('The declined apps stay out of the catalog.', $mixed);
  }

}
