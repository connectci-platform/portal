<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_general\Kernel;

use Drupal\domain\Entity\Domain;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;

/**
 * Tests that Open OnDemand-only content types are pinned to the OOD domain.
 *
 * The domain fields are hidden on these node forms, so Domain Access'
 * add_current_domain setting used to assign the editor's current domain (for
 * example ACCESS), which hid the node from the Open OnDemand views.
 * ood_general_node_presave() now forces field_domain_access and
 * field_domain_source to the Open OnDemand domain on every save.
 *
 * @group ood_general
 */
class OodDomainAssignmentTest extends KernelTestBase {

  use ProdConfigTrait;

  private const DOMAIN_OOD = 'openondemand_cyberinfrastructure_org';

  private const DOMAIN_ACCESS = 'amp_cyberinfrastructure_org';

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
    'domain',
    'domain_access',
    'domain_source',
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
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'user', 'node', 'domain']);

    $config = [
      'field.storage.node.field_domain_access',
      'field.storage.node.field_domain_source',
      'field.storage.node.field_domain_all_affiliates',
    ];
    foreach (array_merge($this->pinnedBundles(), ['page']) as $bundle) {
      $config[] = "node.type.$bundle";
      $config[] = "field.field.node.$bundle.field_domain_access";
      $config[] = "field.field.node.$bundle.field_domain_source";
      $config[] = "field.field.node.$bundle.field_domain_all_affiliates";
    }
    // Storage must come first, then each bundle's node type before its fields.
    $this->importProdConfig($config);

    Domain::create([
      'id' => self::DOMAIN_OOD,
      'hostname' => 'openondemand.test',
      'name' => 'Open OnDemand',
      'scheme' => 'https',
      'status' => 1,
    ])->save();
    Domain::create([
      'id' => self::DOMAIN_ACCESS,
      'hostname' => 'amp.test',
      'name' => 'ACCESS',
      'scheme' => 'https',
      'status' => 1,
    ])->save();

    // The editor is browsing the ACCESS domain.
    $this->setActiveDomain(self::DOMAIN_ACCESS);
  }

  /**
   * Bundles that belong only to the Open OnDemand domain.
   *
   * @return string[]
   *   Bundle machine names.
   */
  protected function pinnedBundles(): array {
    return [
      'open_ondemand_classroom_stories',
      'appverse_app',
      'appverse_software',
    ];
  }

  /**
   * Sets the active domain, as the negotiator would for a request.
   */
  protected function setActiveDomain(string $id): void {
    $domain = Domain::load($id);
    \Drupal::service('domain.negotiator')->setActiveDomain($domain);
  }

  /**
   * Returns the target ids of an entity reference field.
   *
   * @return string[]
   *   Target ids.
   */
  protected function targetIds(NodeInterface $node, string $field): array {
    return array_column($node->get($field)->getValue(), 'target_id');
  }

  /**
   * Pre-set ACCESS domain is replaced by exactly the OOD domain.
   */
  public function testAccessDomainIsReplaced(): void {
    foreach ($this->pinnedBundles() as $bundle) {
      $node = Node::create([
        'type' => $bundle,
        'title' => "Test $bundle",
        'field_domain_access' => [self::DOMAIN_ACCESS],
      ]);
      $node->save();
      $node = Node::load($node->id());
      $this->assertSame([self::DOMAIN_OOD], $this->targetIds($node, 'field_domain_access'), $bundle);
      $this->assertSame([self::DOMAIN_OOD], $this->targetIds($node, 'field_domain_source'), $bundle);
    }
  }

  /**
   * Multiple pre-set domains are replaced, not merged.
   */
  public function testExtraDomainsAreRemoved(): void {
    $node = Node::create([
      'type' => 'appverse_app',
      'title' => 'Multi',
      'field_domain_access' => [self::DOMAIN_ACCESS, self::DOMAIN_OOD],
    ]);
    $node->save();
    $this->assertSame([self::DOMAIN_OOD], $this->targetIds(Node::load($node->id()), 'field_domain_access'));
  }

  /**
   * An empty field_domain_access gets the OOD domain.
   */
  public function testEmptyDomainGetsOod(): void {
    foreach ($this->pinnedBundles() as $bundle) {
      $node = Node::create(['type' => $bundle, 'title' => "Empty $bundle"]);
      $node->save();
      $node = Node::load($node->id());
      $this->assertSame([self::DOMAIN_OOD], $this->targetIds($node, 'field_domain_access'), $bundle);
      $this->assertSame([self::DOMAIN_OOD], $this->targetIds($node, 'field_domain_source'), $bundle);
    }
  }

  /**
   * An existing node moved to ACCESS is reset to OOD on re-save.
   */
  public function testExistingNodeIsReset(): void {
    $node = Node::create(['type' => 'appverse_software', 'title' => 'Existing']);
    $node->save();
    $node->set('field_domain_access', [self::DOMAIN_ACCESS]);
    $node->set('field_domain_source', [self::DOMAIN_ACCESS]);
    $node->save();
    $node = Node::load($node->id());
    $this->assertSame([self::DOMAIN_OOD], $this->targetIds($node, 'field_domain_access'));
    $this->assertSame([self::DOMAIN_OOD], $this->targetIds($node, 'field_domain_source'));
  }

  /**
   * Unrelated bundles are left alone.
   */
  public function testUnrelatedBundleIsUntouched(): void {
    $node = Node::create([
      'type' => 'page',
      'title' => 'Page',
      'field_domain_access' => [self::DOMAIN_ACCESS],
    ]);
    $node->save();
    $node = Node::load($node->id());
    $this->assertSame([self::DOMAIN_ACCESS], $this->targetIds($node, 'field_domain_access'));
    $this->assertSame([], $this->targetIds($node, 'field_domain_source'));
  }

}
