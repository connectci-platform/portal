<?php

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\ood_software\Service\DecisionEmail;

/**
 * The contributor's one email per decision, for the whole repo.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Service\DecisionEmail
 */
class DecisionEmailTest extends UnitTestCase {

  const LINKS = ['review' => 'https://x/review', 'hub' => 'https://x/hub', 'catalog' => 'https://x/catalog'];

  /**
   * The blocks of one kind, in order.
   */
  protected static function of(array $email, string $kind): array {
    return array_values(array_filter($email['blocks'], fn ($b) => $b[0] === $kind));
  }

  protected static function links(array $email): array {
    return array_map(fn ($b) => $b[1], self::of($email, 'link'));
  }

  /**
   * @covers ::decision
   * @dataProvider subjects
   */
  public function testSubject(array $apps, bool $wasLive, string $subject): void {
    $this->assertSame($subject, DecisionEmail::decision('Hub', 'Repo', $apps, '', $wasLive, self::LINKS)['subject']);
  }

  public static function subjects(): array {
    return [
      'accept' => [['A' => 'accept'], FALSE, '[Hub] Accepted: Repo is in the AppVerse'],
      'accept, already live' => [['A' => 'accept'], TRUE, '[Hub] Review complete: Repo is accepted'],
      'suggestions' => [['A' => 'accept_with_suggestions'], FALSE, '[Hub] Accepted with suggestions: Repo'],
      'changes' => [['A' => 'request_changes'], FALSE, '[Hub] Changes requested on Repo'],
      'reject' => [['A' => 'reject'], FALSE, '[Hub] Repo was not accepted'],
      'one decision for every app' => [['A' => 'reject', 'B' => 'reject'], FALSE, '[Hub] Repo was not accepted'],
      'mixed' => [['A' => 'accept', 'B' => 'request_changes'], FALSE, '[Hub] Review decisions for Repo'],
    ];
  }

  /**
   * Changes requested: the response, then how to re-submit.
   *
   * @covers ::decision
   */
  public function testChangesRequested(): void {
    $email = DecisionEmail::decision('Hub', 'Repo', ['A' => 'request_changes'], "Add a LICENSE.\nPin the module.", FALSE, self::LINKS);
    $this->assertSame([['response', "Add a LICENSE.\nPin the module."]], self::of($email, 'response'));
    $this->assertSame([], self::of($email, 'apps'), 'a single app lists no apps');
    $this->assertSame(['Your AppVerse page', 'Read the full review'], self::links($email));
    $paragraphs = array_column(self::of($email, 'p'), 1);
    $this->assertStringContainsString('re-submit the repo', end($paragraphs));
  }

  /**
   * Accept with no response: no response block; the catalog link.
   *
   * @covers ::decision
   */
  public function testAccept(): void {
    $email = DecisionEmail::decision('Hub', 'Repo', ['A' => 'accept'], '  ', FALSE, self::LINKS);
    $this->assertSame([], self::of($email, 'response'));
    $this->assertSame(['View it in the catalog', 'Read the full review'], self::links($email));
    $this->assertStringContainsString('now published', $email['blocks'][0][1]);
  }

  /**
   * A monorepo lists each app's decision; a mix says which apps are live and
   * which to fix.
   *
   * @covers ::decision
   */
  public function testMixedMonorepo(): void {
    $email = DecisionEmail::decision('Hub', 'Repo', ['One' => 'accept', 'Two' => 'request_changes'], 'Two needs a manifest.', FALSE, self::LINKS);
    $this->assertSame([['apps', ['One' => 'Accepted', 'Two' => 'Changes requested']]], self::of($email, 'apps'));
    $paragraphs = array_column(self::of($email, 'p'), 1);
    $this->assertContains('The accepted apps are now published in the AppVerse catalog.', $paragraphs);
    $this->assertStringContainsString('Fix the apps marked Changes requested', end($paragraphs));
    $this->assertSame(['Your AppVerse page', 'View it in the catalog', 'Read the full review'], self::links($email));
  }

  /**
   * Suggestions and changes with nothing accepted: a reviewer publishes the
   * accepted apps later.
   *
   * @covers ::decision
   */
  public function testMixedWaitingForPublish(): void {
    $email = DecisionEmail::decision('Hub', 'Repo', ['One' => 'accept_with_suggestions', 'Two' => 'reject'], 'x', FALSE, self::LINKS);
    $this->assertContains('A reviewer will publish the accepted apps in the AppVerse catalog.', array_column(self::of($email, 'p'), 1));
    $this->assertSame(['Read the full review'], self::links($email));
  }

  /**
   * @covers ::nowLive
   */
  public function testNowLive(): void {
    $email = DecisionEmail::nowLive('Hub', 'Repo', ['One'], TRUE, self::LINKS);
    $this->assertSame('[Hub] Repo is now in the AppVerse', $email['subject']);
    $this->assertSame([['apps', ['One' => 'Published']]], self::of($email, 'apps'));
    $this->assertSame([], self::of(DecisionEmail::nowLive('Hub', 'Repo', ['Only'], FALSE, self::LINKS), 'apps'));
  }

}
