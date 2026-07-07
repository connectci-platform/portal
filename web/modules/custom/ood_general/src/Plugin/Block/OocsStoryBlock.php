<?php

namespace Drupal\ood_general\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the OOCS Classroom Story Details block.
 *
 * Renders, on open_ondemand_classroom_stories nodes, the story author's
 * profile card followed by the story's Implementation Tags, Topic Tags and
 * Class Context sections. Returns nothing when not on a story node page.
 *
 * @Block(
 *   id = "oocs_story_block",
 *   admin_label = @Translation("OOCS Classroom Story Details"),
 *   category = @Translation("Custom"),
 * )
 */
class OocsStoryBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Root-relative fallback profile photo.
   */
  const FALLBACK_PHOTO = '/themes/contrib/asp-theme/images/user-picture.svg';

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * Constructs a new OocsStoryBlock object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, RouteMatchInterface $route_match, FileUrlGeneratorInterface $file_url_generator) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->routeMatch = $route_match;
    $this->fileUrlGenerator = $file_url_generator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
      $container->get('file_url_generator')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $node = $this->routeMatch->getParameter('node');

    // Guard: only render on story node pages. Protects the Layout Builder /
    // layout page, which would otherwise render the block out of context.
    if (!$node instanceof NodeInterface || $node->bundle() !== 'open_ondemand_classroom_stories') {
      return [];
    }

    // Author = the person referenced by field_oocs_name, with Role and
    // Institution taken from the node's own fields. Suppressed when no person
    // is referenced.
    $person = NULL;
    if ($node->hasField('field_oocs_name') && !$node->get('field_oocs_name')->isEmpty()) {
      $person = $node->get('field_oocs_name')->entity;
    }
    $author = ($person instanceof UserInterface) ? $this->buildAuthorData($node, $person) : NULL;

    // Tags. Accumulate each rendered term's cache tags so renames/deletes
    // invalidate the block.
    $tag_cache_tags = [];
    $implementation_tags = $this->buildTagData($node, 'field_oocs_implementation_tags', $tag_cache_tags);
    $science_domain_tags = $this->buildTagData($node, 'field_oocs_science_domain_tags', $tag_cache_tags);
    $topic_tags = $this->buildTagData($node, 'field_oocs_topic_tags', $tag_cache_tags);

    // Class context (text_long). Render through Drupal's text-format system so
    // its HTML displays while the field's format (basic_html / full_html)
    // filters run — never print the raw value.
    $class_context = NULL;
    if ($node->hasField('field_oocs_class_context') && !$node->get('field_oocs_class_context')->isEmpty()) {
      $item = $node->get('field_oocs_class_context')->first();
      $class_context = [
        '#type' => 'processed_text',
        '#text' => $item->value,
        '#format' => $item->format,
        '#langcode' => $node->language()->getId(),
      ];
    }

    $cache_tags = Cache::mergeTags(
      $node->getCacheTags(),
      $person ? $person->getCacheTags() : [],
      $tag_cache_tags
    );

    return [
      '#theme' => 'oocs_story_block',
      '#author' => $author,
      '#implementation_tags' => $implementation_tags,
      '#science_domain_tags' => $science_domain_tags,
      '#topic_tags' => $topic_tags,
      '#class_context' => $class_context,
      '#cache' => [
        'tags' => $cache_tags,
        'contexts' => ['route'],
      ],
    ];
  }

  /**
   * Builds the author profile card data.
   *
   * The person's photo and name come from the referenced user; Role and
   * Institution come from the story node's own fields.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The story node.
   * @param \Drupal\user\UserInterface $user
   *   The person referenced by field_oocs_name.
   *
   * @return array
   *   Author data: photo_url, first_name, last_name, role, institution.
   */
  private function buildAuthorData(NodeInterface $node, UserInterface $user) {
    $photo_url = NULL;
    if ($user->hasField('user_picture') && !$user->get('user_picture')->isEmpty()) {
      $file = $user->get('user_picture')->entity;
      if ($file) {
        // Root-relative so the real photo matches the fallback SVG and no
        // host is leaked in cached / multi-domain output.
        $photo_url = $this->fileUrlGenerator->generateString($file->getFileUri());
      }
    }

    if (!$photo_url) {
      $photo_url = self::FALLBACK_PHOTO;
    }

    $first_name = '';
    if ($user->hasField('field_user_first_name') && !$user->get('field_user_first_name')->isEmpty()) {
      $first_name = $user->get('field_user_first_name')->value;
    }

    $last_name = '';
    if ($user->hasField('field_user_last_name') && !$user->get('field_user_last_name')->isEmpty()) {
      $last_name = $user->get('field_user_last_name')->value;
    }

    $role = '';
    if ($node->hasField('field_oocs_role') && !$node->get('field_oocs_role')->isEmpty()) {
      $role = $node->get('field_oocs_role')->value;
    }

    $institution = '';
    if ($node->hasField('field_oocs_institution') && !$node->get('field_oocs_institution')->isEmpty()) {
      $institution = $node->get('field_oocs_institution')->value;
    }

    return [
      'photo_url' => $photo_url,
      'first_name' => $first_name,
      'last_name' => $last_name,
      'role' => $role,
      'institution' => $institution,
    ];
  }

  /**
   * Builds linked tag data from a taxonomy entity-reference field.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The story node.
   * @param string $field_name
   *   The entity-reference field machine name.
   * @param array $tag_cache_tags
   *   Running list of term cache tags, merged into by reference.
   *
   * @return array
   *   List of ['label' => ..., 'url' => ...] structs.
   */
  private function buildTagData(NodeInterface $node, $field_name, array &$tag_cache_tags) {
    $tags = [];

    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return $tags;
    }

    foreach ($node->get($field_name) as $item) {
      $term = $item->entity;
      if (!$term) {
        continue;
      }
      $tags[] = [
        'label' => $term->label(),
        'url' => $term->toUrl()->toString(),
      ];
      $tag_cache_tags = Cache::mergeTags($tag_cache_tags, $term->getCacheTags());
    }

    return $tags;
  }

}
