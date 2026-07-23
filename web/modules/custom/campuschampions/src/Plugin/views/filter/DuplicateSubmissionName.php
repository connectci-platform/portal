<?php

namespace Drupal\campuschampions\Plugin\views\filter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\BooleanOperator;

/**
 * Filters webform submissions down to those sharing an applicant name.
 *
 * A submission is considered a duplicate when the combination of its first
 * and last name elements — lowercased and trimmed — appears on at least one
 * other submission of the same webform, regardless of submission status.
 *
 * @ingroup views_filter_handlers
 */
#[ViewsFilter('campuschampions_duplicate_submission_name')]
class DuplicateSubmissionName extends BooleanOperator {

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['webform_id'] = ['default' => 'join_campus_champions'];
    $options['first_name_element'] = ['default' => 'user_first_name'];
    $options['last_name_element'] = ['default' => 'user_last_name'];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function getValueOptions() {
    $this->valueOptions = [
      1 => $this->t('Duplicates only'),
      0 => $this->t('Non-duplicates only'),
    ];
    return $this->valueOptions;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $form['webform_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Webform ID'),
      '#description' => $this->t('Machine name of the webform whose submissions are compared.'),
      '#default_value' => $this->options['webform_id'],
      '#required' => TRUE,
    ];
    $form['first_name_element'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First name element key'),
      '#default_value' => $this->options['first_name_element'],
      '#required' => TRUE,
    ];
    $form['last_name_element'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last name element key'),
      '#default_value' => $this->options['last_name_element'],
      '#required' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // '- Any -' never reaches query(); core skips the handler entirely. Only
    // an explicit yes/no choice gets here.
    if ($this->value === NULL || $this->value === '' || $this->value === []) {
      return;
    }

    $this->ensureMyTable();
    $operator = empty($this->value) ? 'NOT IN' : 'IN';

    // Placeholders are namespaced by handler id so the filter can appear more
    // than once in a single view without colliding.
    $suffix = preg_replace('/[^a-z0-9_]/', '_', strtolower($this->options['id']));
    $wid = ':cc_dup_wid_' . $suffix;
    $first = ':cc_dup_first_' . $suffix;
    $last = ':cc_dup_last_' . $suffix;

    $name_expression = "CONCAT(LOWER(TRIM(f.value)), '|', LOWER(TRIM(l.value)))";
    $inner_expression = "CONCAT(LOWER(TRIM(f2.value)), '|', LOWER(TRIM(l2.value)))";

    $sql = <<<SQL
{$this->tableAlias}.sid $operator (
  SELECT s.sid
  FROM {webform_submission} s
  INNER JOIN {webform_submission_data} f ON f.sid = s.sid AND f.name = $first
  INNER JOIN {webform_submission_data} l ON l.sid = s.sid AND l.name = $last
  WHERE s.webform_id = $wid
    AND $name_expression IN (
      SELECT dupes.cc_dup_name FROM (
        SELECT $inner_expression AS cc_dup_name
        FROM {webform_submission} s2
        INNER JOIN {webform_submission_data} f2 ON f2.sid = s2.sid AND f2.name = $first
        INNER JOIN {webform_submission_data} l2 ON l2.sid = s2.sid AND l2.name = $last
        WHERE s2.webform_id = $wid
        GROUP BY $inner_expression
        HAVING COUNT(DISTINCT s2.sid) > 1
      ) dupes
    )
)
SQL;

    $this->query->addWhereExpression($this->options['group'], $sql, [
      $wid => $this->options['webform_id'],
      $first => $this->options['first_name_element'],
      $last => $this->options['last_name_element'],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function adminSummary() {
    if ($this->isAGroup()) {
      return $this->t('grouped');
    }
    if (!empty($this->options['exposed'])) {
      return $this->t('exposed');
    }
    return empty($this->value) ? $this->t('Non-duplicates only') : $this->t('Duplicates only');
  }

}
