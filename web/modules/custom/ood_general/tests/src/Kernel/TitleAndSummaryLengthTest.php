<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_general\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;

/**
 * Regression guard for the headline (title) and project-summary length caps.
 *
 * Both caps are enforced by a Length constraint added in
 * hook_entity_bundle_field_info_alter(): neither field has a storage
 * max_length (title is a plain base field, field_oocs_project_summary is
 * string_long), so the constraint is the only enforcement.
 *
 * @group ood_general
 */
class TitleAndSummaryLengthTest extends KernelTestBase {

  use ProdConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'key',
    'flag',
    'ood_software',
    'ood_general',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['system', 'filter', 'user', 'node']);

    $this->importProdConfig([
      'node.type.open_ondemand_classroom_stories',
      'core.base_field_override.node.open_ondemand_classroom_stories.title',
      'field.storage.node.field_oocs_project_summary',
      'field.field.node.open_ondemand_classroom_stories.field_oocs_project_summary',
    ]);
  }

  /**
   * Builds a story node with the given title and (optional) summary.
   */
  protected function makeNode(string $title, string $summary = 'x'): Node {
    return Node::create([
      'type' => 'open_ondemand_classroom_stories',
      'title' => $title,
      'field_oocs_project_summary' => $summary,
    ]);
  }

  /**
   * Counts validation violations at a given property path.
   */
  protected function violationsAt(Node $node, string $path): int {
    $count = 0;
    foreach ($node->validate() as $violation) {
      if ((string) $violation->getPropertyPath() === $path) {
        $count++;
      }
    }
    return $count;
  }

  /**
   * A 76-character title yields one violation at title.0.value.
   */
  public function testTitleOver75CharsProducesOneViolation(): void {
    $node = $this->makeNode(str_repeat('a', 76));
    $this->assertSame(1, $this->violationsAt($node, 'title.0.value'));
  }

  /**
   * A 75-character title validates clean.
   */
  public function testTitleAt75CharsValidatesClean(): void {
    $node = $this->makeNode(str_repeat('a', 75));
    $this->assertSame(0, $this->violationsAt($node, 'title.0.value'));
  }

  /**
   * A 201-character summary yields one violation.
   */
  public function testSummaryOver200CharsProducesOneViolation(): void {
    $node = $this->makeNode('Title', str_repeat('b', 201));
    $this->assertSame(1, $this->violationsAt($node, 'field_oocs_project_summary.0.value'));
  }

  /**
   * A 200-character summary validates clean.
   */
  public function testSummaryAt200CharsValidatesClean(): void {
    $node = $this->makeNode('Title', str_repeat('b', 200));
    $this->assertSame(0, $this->violationsAt($node, 'field_oocs_project_summary.0.value'));
  }

}
