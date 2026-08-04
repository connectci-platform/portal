<?php

namespace Drupal\campuschampions\Plugin\views\filter;

use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\FilterPluginBase;

/**
 * Free-text "contains" search across an applicant's name and email elements.
 *
 * The applicant's first name, last name, and email live in separate rows of
 * {webform_submission_data}. A stock per-element string filter can only match
 * one of them, so an admin searching "jane smith" against the last-name column
 * finds nothing. This filter matches the term against any of the configured
 * elements at once, so a single exposed box finds the applicant by first name,
 * last name, or email.
 *
 * @ingroup views_filter_handlers
 */
#[ViewsFilter('campuschampions_applicant_search')]
class ApplicantSearch extends FilterPluginBase {

  /**
   * This filter is exposed as a free-text box, not an operator dropdown.
   *
   * @var bool
   */
  // phpcs:ignore
  public $no_operator = TRUE;

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['webform_id'] = ['default' => 'join_campus_champions'];
    // Element keys searched, comma-separated so the value needs no custom
    // config schema entry.
    $options['search_elements'] = ['default' => 'user_first_name,user_last_name,user_email'];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $form['webform_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Webform ID'),
      '#description' => $this->t('Machine name of the webform whose submissions are searched.'),
      '#default_value' => $this->options['webform_id'],
      '#required' => TRUE,
    ];
    $form['search_elements'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search element keys'),
      '#description' => $this->t('Comma-separated submission element keys to search, e.g. user_first_name,user_last_name,user_email.'),
      '#default_value' => $this->options['search_elements'],
      '#required' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function valueForm(&$form, FormStateInterface $form_state) {
    $form['value'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search'),
      '#size' => 30,
      '#default_value' => $this->value,
    ];
  }

  /**
   * Returns the configured element keys as a clean list.
   *
   * @return string[]
   *   Element machine names, trimmed with empties removed.
   */
  protected function getSearchElements() {
    $elements = array_map('trim', explode(',', (string) $this->options['search_elements']));
    return array_values(array_filter($elements, 'strlen'));
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // An empty exposed box must not filter anything out.
    $term = is_array($this->value) ? reset($this->value) : $this->value;
    $term = trim((string) $term);
    if ($term === '') {
      return;
    }
    $elements = $this->getSearchElements();
    if (!$elements) {
      return;
    }

    $this->ensureMyTable();

    // Placeholders are namespaced by handler id so the filter can appear more
    // than once in one view without colliding.
    $suffix = preg_replace('/[^a-z0-9_]/', '_', strtolower($this->options['id']));
    $wid = ':cc_search_wid_' . $suffix;
    $pat = ':cc_search_pat_' . $suffix;

    $like = '%' . Database::getConnection()->escapeLike($term) . '%';
    $args = [
      $wid => $this->options['webform_id'],
      $pat => $like,
    ];
    $name_placeholders = [];
    foreach ($elements as $i => $element) {
      $ph = ':cc_search_el_' . $suffix . '_' . $i;
      $name_placeholders[] = $ph;
      $args[$ph] = $element;
    }
    $name_in = implode(', ', $name_placeholders);

    // A submission matches when any searched element contains the term.
    $sql = <<<SQL
{$this->tableAlias}.sid IN (
  SELECT d.sid
  FROM {webform_submission_data} d
  INNER JOIN {webform_submission} s ON s.sid = d.sid
  WHERE s.webform_id = $wid
    AND d.name IN ($name_in)
    AND LOWER(d.value) LIKE LOWER($pat)
)
SQL;

    $this->query->addWhereExpression($this->options['group'], $sql, $args);
  }

}
