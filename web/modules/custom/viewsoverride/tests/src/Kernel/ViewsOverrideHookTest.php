<?php

namespace Drupal\Tests\viewsoverride\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the viewsoverride hooks are registered and rewrite term conditions.
 *
 * @group viewsoverride
 */
#[RunTestsInSeparateProcesses]
class ViewsOverrideHookTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['views', 'viewsoverride'];

  /**
   * Hooks must be registered (Drupal 11 matches the function name by case).
   *
   * @dataProvider hookProvider
   */
  public function testHooksAreRegistered(string $hook): void {
    $implementers = [];
    \Drupal::moduleHandler()->invokeAllWith($hook, function (callable $callable, string $module) use (&$implementers) {
      $implementers[] = $module;
    });
    $this->assertContains('viewsoverride', $implementers);
    $this->assertTrue(\Drupal::moduleHandler()->hasImplementations($hook, 'viewsoverride'));
  }

  /**
   * Data provider for hooks implemented by the module.
   *
   * @return array<string, array<string>>
   *   Hook names.
   */
  public static function hookProvider(): array {
    return [
      'views_query_alter' => ['views_query_alter'],
      'cron' => ['cron'],
    ];
  }

  /**
   * Term filters are rewritten into LIKE conditions.
   *
   * @dataProvider termFieldProvider
   */
  public function testAlterQueryConditionRewritesTermFields(string $field): void {
    $condition = ['field' => $field, 'value' => '682', 'operator' => '='];
    _alter_query_condition($condition);
    $this->assertSame('%%(682)%%', $condition['value']);
    $this->assertSame('LIKE', $condition['operator']);
  }

  /**
   * Data provider for the rewritten fields.
   *
   * @return array<string, array<string>>
   *   Field names.
   */
  public static function termFieldProvider(): array {
    return [
      'resource terms' => ['webform_submission_field_resource_terms.value'],
      'project terms' => ['webform_submission_field_project_terms.value'],
    ];
  }

  /**
   * Unrelated conditions are left alone.
   */
  public function testAlterQueryConditionIgnoresOtherFields(): void {
    $condition = ['field' => 'webform_submission.webform_id', 'value' => '682', 'operator' => '='];
    $expected = $condition;
    _alter_query_condition($condition);
    $this->assertSame($expected, $condition);
  }

  /**
   * Conditions nested in a condition group are rewritten.
   */
  public function testRecursiveAlterRewritesNestedGroup(): void {
    $group = \Drupal::database()->condition('AND')
      ->condition('webform_submission_field_project_terms.value', '682');
    $conditions = [['field' => $group, 'value' => NULL, 'operator' => NULL]];
    _recursively_alter_query_conditions($conditions);
    $nested = $group->conditions();
    $this->assertSame('%%(682)%%', $nested[0]['value']);
    $this->assertSame('LIKE', $nested[0]['operator']);
  }

}
