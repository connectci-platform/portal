<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ood_software\Service\FindingOverride;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * A reviewer's change is stored beside the tool's values, never over them,
 * and undoing it clears it (A1).
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Service\FindingOverride
 */
class FindingOverrideTest extends KernelTestBase {

  use ProdConfigTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'options',
    'datetime', 'link', 'taxonomy', 'path', 'path_alias', 'file',
    'content_moderation', 'workflows', 'key', 'flag',
    'entity_reference_revisions', 'paragraphs', 'ood_software',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('paragraph');
    $names = ['paragraphs.paragraphs_type.review_finding'];
    foreach (['rule', 'severity', 'result', 'summary', 'evidence', 'dismissed', 'override_severity', 'override_summary', 'override_evidence', 'override_by', 'override_at'] as $f) {
      $names[] = "field.storage.paragraph.field_rvf_$f";
      $names[] = "field.field.paragraph.review_finding.field_rvf_$f";
    }
    $this->importProdConfig($names);
  }

  /**
   * @covers ::apply
   * @covers ::stored
   */
  public function testApplyKeepsTheToolsValuesAndUndoClears(): void {
    $reviewer = $this->createUser([], 'reviewer');
    $finding = Paragraph::create([
      'type' => 'review_finding',
      'field_rvf_rule' => 'OODT-05',
      'field_rvf_severity' => 'high',
      'field_rvf_result' => 'fail',
      'field_rvf_summary' => 'Binds to all interfaces.',
      'field_rvf_evidence' => 'script.sh.erb:9',
    ]);
    $finding->save();
    $tool = ['severity' => 'high', 'summary' => 'Binds to all interfaces.', 'evidence' => 'script.sh.erb:9'];

    $change = FindingOverride::normalize(['dismissed' => 1, 'summary' => 'Binds inside its own container only.'] + $tool, $tool);
    $this->assertSame('dismissed OODT-05, edited the wording of OODT-05', FindingOverride::apply($finding, $change, (int) $reviewer->id(), 1700000000));
    $finding->save();
    $finding = $this->reload($finding);
    $this->assertSame('high', $finding->get('field_rvf_severity')->value, "The tool's severity is kept.");
    $this->assertSame('fail', $finding->get('field_rvf_result')->value, "The tool's result is kept.");
    $this->assertSame('Binds to all interfaces.', $finding->get('field_rvf_summary')->value, "The tool's wording is kept.");
    $this->assertSame('Binds inside its own container only.', $finding->get('field_rvf_override_summary')->value);
    $this->assertSame($change, FindingOverride::stored($finding));
    $this->assertSame((int) $reviewer->id(), (int) $finding->get('field_rvf_override_by')->target_id);
    $this->assertSame(1700000000, (int) $finding->get('field_rvf_override_at')->value);

    $this->assertNull(FindingOverride::apply($finding, $change, (int) $reviewer->id(), 1700000001), 'The same change again is no change.');

    $none = FindingOverride::normalize($tool, $tool);
    $this->assertSame('undid the change to OODT-05', FindingOverride::apply($finding, $none, (int) $reviewer->id(), 1700000002));
    $finding->save();
    $finding = $this->reload($finding);
    $this->assertSame($none, FindingOverride::stored($finding));
    $this->assertTrue($finding->get('field_rvf_override_by')->isEmpty());
    $this->assertTrue($finding->get('field_rvf_override_at')->isEmpty());
    $this->assertTrue($finding->get('field_rvf_override_summary')->isEmpty());
  }

  /**
   * Loads a finding fresh.
   */
  protected function reload(ParagraphInterface $finding): ParagraphInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('paragraph');
    $storage->resetCache();
    $loaded = $storage->load($finding->id());
    $this->assertInstanceOf(ParagraphInterface::class, $loaded);
    return $loaded;
  }

}
