<?php

declare(strict_types=1);

namespace Drupal\ood_general\Plugin\Field\FieldFormatter;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceLabelFormatter;
use Drupal\Core\Form\FormStateInterface;

/**
 * Renders an entity-reference field as labels, omitting excluded terms.
 *
 * Used for the appverse_implementation_tags field on classroom-story nodes:
 * the vocabulary carries a "classroom" term alongside the real implementation
 * tags, added so the story-list view and node page can display it, but the
 * landing-page slideshow view must not show it. Rather than fork the whole
 * story-list view or add a preprocess hook, this formatter behaves exactly
 * like core's EntityReferenceLabelFormatter and is only swapped in on the
 * slideshow's field formatter, with "classroom" configured as an excluded
 * term (D8-2753).
 *
 * @FieldFormatter(
 *   id = "oocs_tag_list",
 *   label = @Translation("OOCS tag list (with exclusions)"),
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 */
class OocsTagListFormatter extends EntityReferenceLabelFormatter {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default settings.
   */
  public static function defaultSettings() {
    return [
      'excluded_terms' => '',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The settings form elements.
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);

    $elements['excluded_terms'] = [
      '#title' => $this->t('Excluded term names'),
      '#type' => 'textfield',
      '#default_value' => $this->getSetting('excluded_terms'),
      '#description' => $this->t('Comma-separated list of term names to omit from the rendered list. Matching is case-insensitive against the term label.'),
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();

    $excluded_terms = $this->getSetting('excluded_terms');
    if (!empty($excluded_terms)) {
      $summary[] = $this->t('Excluding: @terms', ['@terms' => $excluded_terms]);
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = parent::viewElements($items, $langcode);

    $excluded_terms = $this->getExcludedTerms();
    if (!$excluded_terms) {
      return $elements;
    }

    // Dropping an element also drops the cache metadata the parent attached
    // for that term, so renaming an excluded term would not invalidate this
    // render even though the rename changes whether the term is excluded.
    // Keep that metadata by folding it into the first surviving element. No
    // metadata-only element is appended when nothing survives: the field is
    // rendered with Views' "ul" multi-value setting, which would wrap it in a
    // stray empty list item.
    $dropped = new CacheableMetadata();
    foreach ($this->getEntitiesToView($items, $langcode) as $delta => $entity) {
      $label = mb_strtolower(trim((string) $entity->label()));
      if (in_array($label, $excluded_terms, TRUE)) {
        $dropped->addCacheableDependency($entity);
        unset($elements[$delta]);
      }
    }

    $elements = array_values($elements);
    if ($elements) {
      $first = CacheableMetadata::createFromRenderArray($elements[0])->merge($dropped);
      $first->applyTo($elements[0]);
    }

    return $elements;
  }

  /**
   * Parses the excluded_terms setting into a normalized list.
   *
   * @return string[]
   *   Lowercased, trimmed, non-empty term names to exclude.
   */
  protected function getExcludedTerms(): array {
    $excluded_terms = (string) $this->getSetting('excluded_terms');
    if (trim($excluded_terms) === '') {
      return [];
    }

    $terms = array_map(
      static fn (string $term): string => mb_strtolower(trim($term)),
      explode(',', $excluded_terms)
    );

    return array_values(array_filter($terms, static fn (string $term): bool => $term !== ''));
  }

}
