<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_general\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Tests OocsTagListFormatter's excluded_terms setting (D8-2753).
 *
 * Classroom-story nodes (bundle open_ondemand_classroom_stories) are tagged
 * against the appverse_implementation_tags vocabulary, which includes a
 * "classroom" term applied to every story. The landing-page slideshow view
 * (views.view.oocs_classroom_stories_slideshow) must not show that tag, while
 * the story list view and node page still must. OocsTagListFormatter extends
 * core's EntityReferenceLabelFormatter with an 'excluded_terms' setting: a
 * comma-separated list of taxonomy term names, matched case-insensitively
 * against the term label, whose matching elements are dropped from the
 * rendered output (with the remainder re-indexed contiguously).
 *
 * @group ood_general
 */
class OocsTagListFormatterTest extends KernelTestBase {

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
    'taxonomy',
    'key',
    'flag',
    'ood_software',
    'ood_general',
  ];

  /**
   * The taxonomy terms created for this test, keyed by name.
   *
   * @var \Drupal\taxonomy\Entity\Term[]
   */
  protected array $terms = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'user', 'node']);

    // Term access is checked when the formatter builds its elements, so the
    // acting user needs 'access content' or every element is filtered out.
    $this->setUpCurrentUser([], ['access content']);

    Vocabulary::create([
      'vid' => 'appverse_implementation_tags',
      'name' => 'Implementation Tags',
    ])->save();

    NodeType::create([
      'type' => 'test_classroom_story',
      'name' => 'Test Classroom Story',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_oocs_implementation_tags',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_oocs_implementation_tags',
      'entity_type' => 'node',
      'bundle' => 'test_classroom_story',
      'settings' => [
        'handler' => 'default:taxonomy_term',
        'handler_settings' => [
          'target_bundles' => ['appverse_implementation_tags' => 'appverse_implementation_tags'],
        ],
      ],
    ])->save();
  }

  /**
   * Creates a taxonomy term in the appverse_implementation_tags vocabulary.
   */
  protected function makeTerm(string $name): Term {
    $term = Term::create([
      'vid' => 'appverse_implementation_tags',
      'name' => $name,
    ]);
    $term->save();
    return $term;
  }

  /**
   * Creates a story node referencing the given terms.
   *
   * @param \Drupal\taxonomy\Entity\Term[] $terms
   *   The terms to reference.
   */
  protected function makeStory(array $terms): Node {
    $node = Node::create([
      'type' => 'test_classroom_story',
      'title' => 'Test Story',
      'field_oocs_implementation_tags' => array_map(
        static fn (Term $term) => $term->id(),
        $terms
      ),
    ]);
    $node->save();
    return $node;
  }

  /**
   * Renders the story's tags field with the oocs_tag_list formatter.
   */
  protected function renderTags(Node $node, string $excludedTerms): string {
    $build = $node->get('field_oocs_implementation_tags')->view([
      'type' => 'oocs_tag_list',
      'label' => 'hidden',
      'settings' => [
        'link' => FALSE,
        'excluded_terms' => $excludedTerms,
      ],
    ]);

    $renderer = \Drupal::service('renderer');
    return (string) $renderer->renderRoot($build);
  }

  /**
   * An excluded term is dropped from the output while others are kept.
   */
  public function testExcludedTermIsDroppedWhileOthersAreKept(): void {
    $node = $this->makeStory([
      $this->makeTerm('classroom'),
      $this->makeTerm('containerized'),
      $this->makeTerm('gpu-enabled'),
    ]);

    $output = $this->renderTags($node, 'classroom');

    $this->assertStringContainsString('containerized', $output);
    $this->assertStringContainsString('gpu-enabled', $output);
    $this->assertStringNotContainsString('classroom', $output);
  }

  /**
   * Excluded term matching is case-insensitive against the term label.
   */
  public function testExclusionMatchingIsCaseInsensitive(): void {
    $node = $this->makeStory([
      $this->makeTerm('Classroom'),
      $this->makeTerm('containerized'),
    ]);

    $output = $this->renderTags($node, 'classroom');

    $this->assertStringContainsString('containerized', $output);
    $this->assertStringNotContainsString('Classroom', $output);
  }

  /**
   * An empty excluded_terms setting renders every term unchanged.
   *
   * This is the story-list view / node page behavior: no exclusion applies.
   */
  public function testEmptyExcludedTermsRendersEveryTerm(): void {
    $node = $this->makeStory([
      $this->makeTerm('classroom'),
      $this->makeTerm('containerized'),
      $this->makeTerm('gpu-enabled'),
    ]);

    $output = $this->renderTags($node, '');

    $this->assertStringContainsString('classroom', $output);
    $this->assertStringContainsString('containerized', $output);
    $this->assertStringContainsString('gpu-enabled', $output);
  }

  /**
   * Excluding every referenced term renders empty output.
   *
   * This is what lets the slideshow's tag strip be empty rather than showing
   * a stray "classroom" tag when it is the only term on a story.
   */
  public function testAllTermsExcludedRendersEmptyOutput(): void {
    $node = $this->makeStory([
      $this->makeTerm('classroom'),
    ]);

    $output = $this->renderTags($node, 'classroom');

    $this->assertSame('', trim($output));
  }

}
