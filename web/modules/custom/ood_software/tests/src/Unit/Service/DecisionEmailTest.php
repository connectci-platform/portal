<?php

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\Core\DependencyInjection\ContainerBuilder;
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

  const PEOPLE = ['contributor' => 'Ada', 'reviewer' => 'Grace'];

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
   * The email with every text rendered to a string.
   *
   * @param array<string, string> $decisions
   *   Verdict id => decision.
   * @param array<string, string> $names
   *   Verdict id => app name; defaults to the ids.
   * @param array{contributor: string, reviewer: string} $people
   *   Display names.
   *
   * @return array{subject: string, blocks: array<int, array<int, mixed>>}
   */
  protected static function email(array $decisions, string $response = '', bool $wasLive = FALSE, array $names = [], array $people = self::PEOPLE): array {
    $names = $names ?: array_combine(array_keys($decisions), array_keys($decisions));
    return self::render(DecisionEmail::decision('Hub', 'Repo', $decisions, $names, $response, $wasLive, $people, self::LINKS));
  }

  /**
   * Renders TranslatableMarkup in an email to strings.
   *
   * @param array<string, mixed> $email
   *   As DecisionEmail builds it.
   *
   * @return array{subject: string, blocks: array<int, array<int, mixed>>}
   */
  protected static function render(array $email): array {
    $blocks = array_map(static function (array $b): array {
      if ($b[0] === 'apps') {
        return ['apps', array_map(static fn ($a) => [(string) $a[0], (string) $a[1]], $b[1])];
      }
      return array_map(static fn ($v) => is_object($v) ? (string) $v : $v, $b);
    }, $email['blocks']);
    return ['subject' => (string) $email['subject'], 'blocks' => $blocks];
  }

  /**
   * The blocks of one kind, in order.
   *
   * @param array{subject: string, blocks: array<int, array<int, mixed>>} $email
   *   A rendered email.
   *
   * @return array<int, array<int, mixed>>
   */
  protected static function of(array $email, string $kind): array {
    return array_values(array_filter($email['blocks'], fn ($b) => $b[0] === $kind));
  }

  /**
   * The paragraphs' text.
   *
   * @param array{subject: string, blocks: array<int, array<int, mixed>>} $email
   *   A rendered email.
   *
   * @return array<int, string>
   */
  protected static function paragraphs(array $email): array {
    return array_column(self::of($email, 'p'), 1);
  }

  /**
   * The link labels.
   *
   * @param array{subject: string, blocks: array<int, array<int, mixed>>} $email
   *   A rendered email.
   *
   * @return array<int, string>
   */
  protected static function links(array $email): array {
    return array_column(self::of($email, 'link'), 1);
  }

  /**
   * @covers ::decision
   * @dataProvider subjects
   *
   * @param array<string, string> $apps
   *   Verdict id => decision.
   */
  public function testSubject(array $apps, bool $wasLive, string $subject): void {
    $this->assertSame($subject, self::email($apps, '', $wasLive)['subject']);
  }

  /**
   * Decisions, whether the repo was live, and the subject they give.
   *
   * @return array<string, array<int, mixed>>
   */
  public static function subjects(): array {
    return [
      'accept' => [['A' => 'accept'], FALSE, '[Hub] Accepted: Repo is in the Appverse'],
      'accept, already live' => [['A' => 'accept'], TRUE, '[Hub] Review complete: Repo is accepted'],
      'suggestions' => [['A' => 'accept_with_suggestions'], FALSE, '[Hub] Accepted with suggestions: Repo'],
      'changes' => [['A' => 'request_changes'], FALSE, '[Hub] Changes requested on Repo'],
      'reject' => [['A' => 'reject'], FALSE, '[Hub] Repo was not accepted'],
      'one decision for every app' => [['A' => 'reject', 'B' => 'reject'], FALSE, '[Hub] Repo was not accepted'],
      'mixed' => [['A' => 'accept', 'B' => 'request_changes'], FALSE, '[Hub] Review decisions for Repo'],
    ];
  }

  /**
   * Changes requested: greeting, the reviewer by name, the response, the
   * next step in plain words and where a reply goes.
   *
   * @covers ::decision
   */
  public function testChangesRequested(): void {
    $email = self::email(['A' => 'request_changes'], "Add a LICENSE.\nPin the module.");
    $p = self::paragraphs($email);
    $this->assertSame('Hi Ada,', $p[0]);
    $this->assertSame('Grace reviewed your repo "Repo" for the Appverse catalog and is asking for changes before it can be listed.', $p[1]);
    $this->assertContains("Grace's response:", $p);
    $this->assertSame([['response', "Add a LICENSE.\nPin the module."]], self::of($email, 'response'));
    $this->assertContains('When you have made the changes on GitHub, click Re-submit on your Appverse page and a new review will start.', $p);
    $this->assertSame('Questions about the review? Reply to this email and it goes to Grace.', end($p));
    $this->assertSame([], self::of($email, 'apps'), 'a single app lists no apps');
    $this->assertSame(['Your Appverse page', 'The full review'], self::links($email));
  }

  /**
   * Accept with no response: no response block, and the catalog link.
   *
   * @covers ::decision
   */
  public function testAccept(): void {
    $email = self::email(['A' => 'accept'], '  ');
    $this->assertSame([], self::of($email, 'response'));
    $this->assertSame(['The repo in the catalog', 'The full review'], self::links($email));
    $this->assertStringContainsString('It is now listed in the catalog.', self::paragraphs($email)[1]);
  }

  /**
   * A declined repo's review stays private, and the email says so.
   *
   * @covers ::decision
   */
  public function testRejectSaysTheReviewIsPrivate(): void {
    $this->assertContains('The review is visible only to you and the Appverse reviewers.', self::paragraphs(self::email(['A' => 'reject'], 'Out of scope.')));
    $this->assertNotContains('The review is visible only to you and the Appverse reviewers.', self::paragraphs(self::email(['A' => 'accept'])));
  }

  /**
   * A monorepo lists each app's decision; a mix says which apps are live, and
   * that the whole repo is re-submitted, not app by app.
   *
   * @covers ::decision
   */
  public function testMixedMonorepo(): void {
    $email = self::email(['v1' => 'accept', 'v2' => 'request_changes'], 'RStudio needs a manifest.', FALSE, ['v1' => 'Jupyter', 'v2' => 'RStudio']);
    $this->assertSame([['apps', [['Jupyter', 'Accepted'], ['RStudio', 'Changes requested']]]], self::of($email, 'apps'));
    $p = self::paragraphs($email);
    $this->assertContains('The accepted apps are now listed in the catalog.', $p);
    $this->assertContains('When you have made the changes on GitHub, click Re-submit on your Appverse page. The whole repo is reviewed again, and the apps already accepted stay listed.', $p);
    $this->assertSame(['Your Appverse page', 'The repo in the catalog', 'The full review'], self::links($email));
  }

  /**
   * Two apps with the same name are both listed, each with its own decision.
   *
   * @covers ::decision
   */
  public function testAppsSharingANameKeepTheirOwnDecisions(): void {
    $email = self::email(['v1' => 'accept', 'v2' => 'request_changes'], 'x', FALSE, ['v1' => 'App', 'v2' => 'App']);
    $this->assertSame([['apps', [['App', 'Accepted'], ['App', 'Changes requested']]]], self::of($email, 'apps'));
  }

  /**
   * Suggestions and a decline with nothing accepted: the reviewer publishes
   * the accepted apps later.
   *
   * @covers ::decision
   */
  public function testMixedWaitingForPublish(): void {
    $email = self::email(['v1' => 'accept_with_suggestions', 'v2' => 'reject'], 'x');
    $this->assertContains('Grace will publish the accepted apps in the catalog.', self::paragraphs($email));
    $this->assertSame(['The full review'], self::links($email));
  }

  /**
   * Without names, the email still reads.
   *
   * @covers ::decision
   */
  public function testWithoutNames(): void {
    $p = self::paragraphs(self::email(['A' => 'accept'], '', FALSE, [], ['contributor' => '', 'reviewer' => '']));
    $this->assertSame('Hello,', $p[0]);
    $this->assertStringStartsWith('The Appverse team reviewed your repo', $p[1]);
  }

  /**
   * @covers ::nowLive
   */
  public function testNowLive(): void {
    $email = self::render(DecisionEmail::nowLive('Hub', 'Repo', ['One'], TRUE, self::PEOPLE, self::LINKS));
    $this->assertSame('[Hub] Repo is now in the Appverse', $email['subject']);
    $this->assertSame([['apps', [['One', 'Published']]]], self::of($email, 'apps'));
    $this->assertSame('Grace published your repo "Repo". It is now listed in the Appverse catalog.', self::paragraphs($email)[1]);
    $single = self::render(DecisionEmail::nowLive('Hub', 'Repo', ['Only'], FALSE, self::PEOPLE, self::LINKS));
    $this->assertSame([], self::of($single, 'apps'));
  }

}
