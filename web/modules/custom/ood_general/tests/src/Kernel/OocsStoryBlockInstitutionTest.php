<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_general\Kernel;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\ood_general\Plugin\Block\OocsStoryBlock;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;

/**
 * Regression guard for the story author's institution fallback (D8-2753).
 *
 * OocsStoryBlock::buildAuthorData() must fall back to the user's
 * field_institution when there is no field_access_organization reference, and
 * must keep field_institution when the organization reference points at node
 * 3695 ("Other"). Mirrors the same convention already used by
 * \Drupal\cssn\Plugin\Block\PersonaBlock::build().
 *
 * @group ood_general
 */
class OocsStoryBlockInstitutionTest extends KernelTestBase {

  use ProdConfigTrait;
  use UserCreationTrait;

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
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'user', 'node']);

    $this->importProdConfig([
      'node.type.open_ondemand_classroom_stories',
      'node.type.access_organization',
      'field.storage.node.field_oocs_name',
      'field.field.node.open_ondemand_classroom_stories.field_oocs_name',
      'field.storage.user.field_access_organization',
      'field.field.user.user.field_access_organization',
      'field.storage.user.field_institution',
      'field.field.user.user.field_institution',
    ]);

    // Burn uid 1: it bypasses access checks, which would mask a broken
    // fallback if buildAuthorData() ever started depending on access.
    $this->createUser();
  }

  /**
   * Builds a story node authored by the given user.
   */
  protected function makeStory(User $author): Node {
    $node = Node::create([
      'type' => 'open_ondemand_classroom_stories',
      'title' => 'Test Story',
      'field_oocs_name' => $author->id(),
    ]);
    $node->save();
    return $node;
  }

  /**
   * Builds the block and returns its render array for the given story.
   */
  protected function buildBlock(Node $story): array {
    $routeMatch = $this->createMock(RouteMatchInterface::class);
    $routeMatch->method('getParameter')->with('node')->willReturn($story);

    $block = new OocsStoryBlock(
      [],
      'oocs_story_block',
      ['provider' => 'ood_general'],
      \Drupal::entityTypeManager(),
      $routeMatch,
      \Drupal::service('file_url_generator')
    );

    return $block->build();
  }

  /**
   * An author with an org reference shows the org title.
   */
  public function testOrgReferenceShowsOrgTitle(): void {
    $org = Node::create([
      'type' => 'access_organization',
      'title' => 'Test University',
    ]);
    $org->save();

    $author = User::create([
      'name' => 'author1',
      'field_access_organization' => $org->id(),
      'field_institution' => 'Fallback Institution',
    ]);
    $author->save();

    $build = $this->buildBlock($this->makeStory($author));
    $this->assertSame('Test University', $build['#author']['institution']);
  }

  /**
   * An author with no org reference shows field_institution.
   */
  public function testNoOrgReferenceShowsFieldInstitution(): void {
    $author = User::create([
      'name' => 'author2',
      'field_institution' => 'Fallback Institution',
    ]);
    $author->save();

    $build = $this->buildBlock($this->makeStory($author));
    $this->assertSame('Fallback Institution', $build['#author']['institution']);
  }

  /**
   * An author whose org reference is node 3695 shows field_institution.
   */
  public function testOrgOtherNodeShowsFieldInstitution(): void {
    $org = Node::create([
      'type' => 'access_organization',
      'nid' => OocsStoryBlock::ORG_OTHER_NID,
      'title' => 'Other',
    ]);
    $org->save();
    $this->assertSame(OocsStoryBlock::ORG_OTHER_NID, (int) $org->id());

    $author = User::create([
      'name' => 'author3',
      'field_access_organization' => $org->id(),
      'field_institution' => 'Fallback Institution',
    ]);
    $author->save();

    $build = $this->buildBlock($this->makeStory($author));
    $this->assertSame('Fallback Institution', $build['#author']['institution']);
  }

}
