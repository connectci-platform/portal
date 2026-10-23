<?php

use PHPUnit\Framework\TestCase;

/**
 * Renders release note items and picks the version bump.
 */
class RendererTest extends TestCase {

  private static function item(string $section, string $program, string $text, ?string $key, array $prs): array {
    return ['section' => $section, 'program' => $program, 'text' => $text, 'key' => $key, 'prs' => $prs];
  }

  public function testRendersSectionsInOrder(): void {
    $body = Renderer::render([
      self::item('Fixes', 'OOD', 'A fix', 'D8-1', [1]),
      self::item('Features', 'ACCESS', 'A feature', 'D8-2', [2]),
    ], FALSE);
    $this->assertLessThan(strpos($body, '## Fixes'), strpos($body, '## Features'));
    $this->assertStringNotContainsString('## Improvements', $body);
    $this->assertStringNotContainsString('## Maintenance', $body);
  }

  public function testLineFormat(): void {
    $body = Renderer::render([self::item('Fixes', 'OOD', 'Classroom Story: unable to add images when editing a story', 'D8-2878', [2278])], FALSE);
    $this->assertStringContainsString("- [OOD] Classroom Story: unable to add images when editing a story (D8-2878, #2278)\n", $body);
  }

  public function testSecurityLineHasNoKey(): void {
    $body = Renderer::render([self::item('Fixes', 'ACCESS', 'Security hardening', NULL, [2300])], FALSE);
    $this->assertStringContainsString("- [ACCESS] Security hardening (#2300)\n", $body);
  }

  public function testMultiplePrs(): void {
    $body = Renderer::render([self::item('Fixes', 'OOD', 'A fix', 'D8-2878', [2278, 2291])], FALSE);
    $this->assertStringContainsString('(D8-2878, #2278, #2291)', $body);
  }

  public function testItemsSortedByProgramThenText(): void {
    $body = Renderer::render([
      self::item('Fixes', 'OOD', 'B', NULL, [3]),
      self::item('Fixes', 'ACCESS', 'Z', NULL, [2]),
      self::item('Fixes', 'ACCESS', 'A', NULL, [1]),
    ], FALSE);
    $this->assertStringContainsString("- [ACCESS] A (#1)\n- [ACCESS] Z (#2)\n- [OOD] B (#3)\n", $body);
  }

  public function testDegradedBanner(): void {
    $body = Renderer::render([self::item('Improvements', 'Connect.CI', 'Update (details in D8-2878)', NULL, [2278])], TRUE);
    $this->assertStringStartsWith("> Jira could not be reached when these notes were written; some items are listed generically.\n\n", $body);
  }

  public function testEmptyRelease(): void {
    $this->assertSame("No changes in this release.\n", Renderer::render([], FALSE));
    $this->assertSame('patch', Renderer::bump([]));
  }

  public function testBump(): void {
    $this->assertSame('minor', Renderer::bump([self::item('Features', 'ACCESS', 'X', 'D8-1', [1])]));
    $this->assertSame('patch', Renderer::bump([
      self::item('Improvements', 'ACCESS', 'X', 'D8-1', [1]),
      self::item('Fixes', 'ACCESS', 'Y', 'D8-2', [2]),
      self::item('Maintenance', 'Connect.CI', 'Z', NULL, [3]),
    ]));
  }

}
