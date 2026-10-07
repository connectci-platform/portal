<?php

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\ood_software\Service\ReviewFloors;

/**
 * The mildest decision each app may get, as appverse-review's
 * check-decisions.py computes it.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Service\ReviewFloors
 */
class ReviewFloorsTest extends UnitTestCase {

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
   * A finding record as floors() reads it.
   *
   * @return array<string, string>
   */
  protected static function finding(string $rule, string $severity, string $result = 'fail', string $aspect = '', string $evidence = 'x.sh:1'): array {
    return ['rule' => $rule, 'aspect' => $aspect, 'severity' => $severity, 'result' => $result, 'evidence' => $evidence];
  }

  /**
   * @covers ::floors
   * @dataProvider floorCases
   *
   * @param array<int, array<string, string>> $repo
   * @param array<string, array<int, array<string, string>>> $apps
   * @param array<string, string> $criteria
   * @param array<string, string> $expected
   */
  public function testFloors(array $repo, array $apps, array $criteria, array $expected): void {
    $this->assertSame($expected, array_map(fn ($f) => $f['decision'], ReviewFloors::floors($repo, $apps, $criteria)));
  }

  /**
   * @return array<string, array<int, array<mixed>>>
   */
  public static function floorCases(): array {
    $two = static fn (array $one = [], array $other = []) => ['one' => $one, 'other' => $other];
    return [
      'nothing blocking' => [[], $two(), [], []],
      // Security anywhere decides every app: installing one clones them all.
      'a High security finding in one app' => [[], $two([self::finding('SEC-03', 'high', 'fail', 'security')]), [], ['one' => 'request_changes', 'other' => 'request_changes']],
      'an OODT rule counts as security' => [[], $two([], [self::finding('OODT-2', 'critical')]), [], ['one' => 'reject', 'other' => 'reject']],
      'a Critical security finding in shared code' => [[self::finding('SEC-01', 'critical', 'FAIL', 'security')], $two(), [], ['one' => 'reject', 'other' => 'reject']],
      // Structure and upkeep stay with their own app, or every app at repo level.
      'a High structure finding in one app' => [[], $two([self::finding('STR-04', 'high')]), [], ['one' => 'request_changes']],
      'a High structure finding at repo level' => [[self::finding('STR-01', 'high')], $two(), [], ['one' => 'request_changes', 'other' => 'request_changes']],
      'the upkeep gate' => [[self::finding('MNT-01', 'high')], $two(), [], ['one' => 'request_changes', 'other' => 'request_changes']],
      'the stricter of every-app and own' => [[self::finding('SEC-03', 'high', 'fail', 'security')], $two([self::finding('STR-04', 'critical')]), [], ['one' => 'reject', 'other' => 'request_changes']],
      // Only FAILs; a stored-before-results finding counts as one.
      'a WARN sets no floor' => [[], $two([self::finding('SEC-03', 'high', 'warn', 'security')]), [], []],
      'no stored result counts as FAIL' => [[], $two([self::finding('STR-04', 'high', '')]), [], ['one' => 'request_changes']],
      'Medium sets no floor' => [[], $two([self::finding('SEC-03', 'medium', 'fail', 'security')]), [], []],
      // Documentation, portability and code quality never set one.
      'a High quality finding' => [[], $two([self::finding('DOC-02', 'high', 'fail', 'quality')]), [], []],
      'a failed repo gate' => [[], $two(), ['not_archived' => 'fail', 'license' => 'fail'], ['one' => 'request_changes', 'other' => 'request_changes']],
      'a passed repo gate' => [[], $two(), ['public' => 'pass'], []],
      // Every Structure row is a gate: a FAIL is Request changes at any
      // severity, and Critical still Reject.
      'a Medium gate FAIL in one app' => [[], $two([self::finding('STR-01', 'medium')]), [], ['one' => 'request_changes']],
      'a Low gate FAIL at repo level' => [[self::finding('STR-06', 'low', 'FAIL')], $two(), [], ['one' => 'request_changes', 'other' => 'request_changes']],
      'an Info gate FAIL' => [[], $two([], [self::finding('STR-02', 'info')]), [], ['other' => 'request_changes']],
      'a Critical gate FAIL' => [[], $two([self::finding('STR-07', 'critical')]), [], ['one' => 'reject']],
      'a Medium gate WARN sets no floor' => [[], $two([self::finding('STR-01', 'medium', 'warn')]), [], []],
      // A row seeded before results were stored is mostly a PASS at Info; only
      // an explicit FAIL sets the gate floor below High.
      'an Info gate row with no stored result' => [[], $two([self::finding('STR-02', 'info', '')]), [], []],
      // Security and upkeep keep their High floor.
      'a Low security FAIL sets no floor' => [[], $two([self::finding('OODT-05', 'low')]), [], []],
      'a Medium upkeep FAIL sets no floor' => [[self::finding('MNT-01', 'medium')], $two(), [], []],
    ];
  }

  /**
   * The reason names the finding, and says when it applies to every app.
   *
   * @covers ::floors
   */
  public function testReason(): void {
    $floors = ReviewFloors::floors([], ['one' => [self::finding('SEC-03', 'high', 'fail', 'security', 'lib/x.sh:4')], 'other' => []], []);
    $this->assertSame('SEC-03 High FAIL at lib/x.sh:4 (applies to every app)', $floors['other']['reason']);
    $single = ReviewFloors::floors([], ['root' => [self::finding('SEC-03', 'high', 'fail', 'security', 'lib/x.sh:4')]], []);
    $this->assertSame('SEC-03 High FAIL at lib/x.sh:4', $single['root']['reason']);
  }

  /**
   * @covers ::choices
   */
  public function testChoices(): void {
    $this->assertSame(['accept', 'accept_with_suggestions', 'request_changes', 'reject'], ReviewFloors::choices(NULL));
    $this->assertSame(['request_changes', 'reject'], ReviewFloors::choices('request_changes'));
    $this->assertSame(['reject'], ReviewFloors::choices('reject'));
  }

  /**
   * @covers ::problems
   */
  public function testProblems(): void {
    $floors = ['a' => ['decision' => 'request_changes', 'reason' => 'SEC-03 High FAIL at x']];
    $this->assertSame([], ReviewFloors::problems(['a' => 'request_changes', 'b' => 'accept'], $floors));
    $this->assertSame([], ReviewFloors::problems(['a' => 'reject'], $floors));
    $this->assertSame([], ReviewFloors::problems(['a' => NULL], $floors));
    $this->assertSame(
      ['App A cannot be Accepted with suggestions: SEC-03 High FAIL at x needs at least Changes requested.'],
      ReviewFloors::problems(['a' => 'accept_with_suggestions'], $floors, ['a' => 'App A']),
    );
  }

}
