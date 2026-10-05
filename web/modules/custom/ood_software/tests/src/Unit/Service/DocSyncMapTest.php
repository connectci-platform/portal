<?php

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\ood_software\Service\DocSyncService;
use Drupal\Tests\UnitTestCase;

/**
 * Guards the DocSync source map: which GitHub files feed which doc nodes.
 *
 * The map is a constant, so the test is a contract check: the Review Rubric
 * source is present, the retired Security Rubric source is gone, and every
 * key is a raw.githubusercontent.com markdown URL that basename() can name.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Service\DocSyncService
 */
class DocSyncMapTest extends UnitTestCase {

  const RUBRIC_URL = 'https://raw.githubusercontent.com/Sweet-and-Fizzy/appverse-review/main/references/review-rubric.md';
  const RETIRED_URL = 'https://raw.githubusercontent.com/Sweet-and-Fizzy/appverse-review/main/references/security-rubric.md';
  const PROCESS_URL = 'https://raw.githubusercontent.com/Sweet-and-Fizzy/appverse-review/main/references/review-checklist.md';

  /**
   * The rubric source feeds node 12246 and the retired source is gone.
   */
  public function testRubricReplacesSecurityRubric(): void {
    $map = DocSyncService::DOC_MAP;
    $this->assertArrayHasKey(self::RUBRIC_URL, $map);
    $this->assertSame(12246, $map[self::RUBRIC_URL]);
    $this->assertArrayNotHasKey(self::RETIRED_URL, $map);
  }

  /**
   * The reviewer process keeps its node.
   */
  public function testProcessDocKeepsNode(): void {
    $this->assertSame(11932, DocSyncService::DOC_MAP[self::PROCESS_URL]);
  }

  /**
   * Every key is a raw GitHub markdown URL and every nid is a positive int.
   */
  public function testMapShape(): void {
    foreach (DocSyncService::DOC_MAP as $url => $nid) {
      $this->assertMatchesRegularExpression('#^https://raw\.githubusercontent\.com/.+/main/.+\.md$#', $url);
      $this->assertIsInt($nid);
      $this->assertGreaterThan(0, $nid);
    }
  }

}
