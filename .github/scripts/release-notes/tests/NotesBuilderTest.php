<?php

use PHPUnit\Framework\TestCase;

/**
 * Turns released PRs and their tickets into release note items.
 */
class NotesBuilderTest extends TestCase {

  private function builder(): NotesBuilder {
    return new NotesBuilder(ProgramMapper::fromFile(__DIR__ . '/../../../release-programs.json'));
  }

  private static function tickets(): array {
    return json_decode(file_get_contents(__DIR__ . '/fixtures/tickets.json'), TRUE);
  }

  private static function pr(int $n, string $head, string $title = '', array $labels = []): array {
    return [
      'number' => $n,
      'merged_at' => '2026-10-23T18:00:00Z',
      'head' => ['ref' => $head],
      'base' => ['ref' => 'md-dev'],
      'title' => $title ?: '#' . substr($head, 3),
      'body' => '',
      'labels' => array_map(fn ($l) => ['name' => $l], $labels),
    ];
  }

  public function testBugGoesToFixesWithProgram(): void {
    $items = $this->builder()->build([self::pr(2278, 'md-2878')], self::tickets());
    $this->assertSame([[
      'section' => 'Fixes',
      'program' => 'OOD',
      'text' => 'Classroom Story: unable to add images when editing a story',
      'key' => 'D8-2878',
      'prs' => [2278],
    ]], $items);
  }

  public function testStoryIsFeature(): void {
    $items = $this->builder()->build([self::pr(2288, 'md-2843')], self::tickets());
    $this->assertSame('Features', $items[0]['section']);
    $this->assertSame('ACCESS', $items[0]['program']);
  }

  public function testTaskIsImprovement(): void {
    $items = $this->builder()->build([self::pr(2249, 'md-2832')], self::tickets());
    $this->assertSame('Improvements', $items[0]['section']);
  }

  public function testFeatureLabelPromotesTask(): void {
    $items = $this->builder()->build([self::pr(2249, 'md-2832', '', ['feature'])], self::tickets());
    $this->assertSame('Features', $items[0]['section']);
  }

  public function testSecurityHidesTitle(): void {
    $items = $this->builder()->build([self::pr(2211, 'md-2805')], self::tickets());
    $this->assertSame('Security hardening', $items[0]['text']);
    $this->assertNull($items[0]['key']);
    $this->assertSame('Fixes', $items[0]['section']);
    $this->assertStringNotContainsString('contact form', json_encode($items));
  }

  public function testSecurityPrLabelHidesTitle(): void {
    $items = $this->builder()->build([self::pr(2278, 'md-2878', '', ['security'])], self::tickets());
    $this->assertSame('Security hardening', $items[0]['text']);
    $this->assertNull($items[0]['key']);
  }

  public function testJiraDownUsesGenericLine(): void {
    $items = $this->builder()->build([self::pr(2278, 'md-2878')], NULL);
    $this->assertSame([[
      'section' => 'Improvements',
      'program' => 'Connect.CI',
      'text' => 'Update (details in D8-2878)',
      'key' => NULL,
      'prs' => [2278],
    ]], $items);
  }

  public function testUnknownKeyUsesGenericLine(): void {
    $items = $this->builder()->build([self::pr(2299, 'md-9999')], self::tickets());
    $this->assertSame('Update (details in D8-9999)', $items[0]['text']);
    $this->assertSame('Improvements', $items[0]['section']);
  }

  public function testSameTicketTwoPrsIsOneItem(): void {
    $items = $this->builder()->build([self::pr(2278, 'md-2878'), self::pr(2291, 'md-2878')], self::tickets());
    $this->assertCount(1, $items);
    $this->assertSame([2278, 2291], $items[0]['prs']);
  }

  public function testNoTicketIsMaintenanceWithPrTitle(): void {
    $items = $this->builder()->build([self::pr(2283, 'drupal/flag', '[Composer] drupal/flag #nobuild')], self::tickets());
    $this->assertSame([[
      'section' => 'Maintenance',
      'program' => 'Connect.CI',
      'text' => '[Composer] drupal/flag #nobuild',
      'key' => NULL,
      'prs' => [2283],
    ]], $items);
  }

  public function testSkipLabelDrops(): void {
    $items = $this->builder()->build([self::pr(2278, 'md-2878', '', ['skip-release-notes'])], self::tickets());
    $this->assertSame([], $items);
  }

  public function testSubtaskRollsIntoParent(): void {
    $items = $this->builder()->build([self::pr(2288, 'md-2843'), self::pr(2295, 'md-2930')], self::tickets());
    $this->assertCount(1, $items);
    $this->assertSame('D8-2843', $items[0]['key']);
    $this->assertSame([2288, 2295], $items[0]['prs']);
  }

}
