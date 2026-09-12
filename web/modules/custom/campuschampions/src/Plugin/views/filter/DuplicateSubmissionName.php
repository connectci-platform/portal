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
 * The filter has two faces. When configured in the Views UI (non-exposed) it
 * behaves like the parent BooleanOperator: a 0/1/'All' value with the options
 * from getValueOptions(). When exposed on the page it renders as a single
 * checkbox whose value is only ever 1 (checked, show duplicates) or 'All'
 * (unchecked, no filter) — the "Non-duplicates only" (0) state is unreachable.
 * valueForm() branches on $form_state->get('exposed') to build each face, and
 * adminSummary() only runs for the non-exposed one; keep those two branch
 * conditions in sync if you touch either.
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
   *
   * These options back the non-exposed (Views UI config) radios only. The
   * exposed checkbox uses its own hardcoded label in valueForm() and never
   * yields the 0 ("Non-duplicates only") state, so relabelling the exposed
   * filter means editing valueForm(), not this list.
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
  protected function valueForm(&$form, FormStateInterface $form_state) {
    // The only useful state is "show duplicates", so expose a single checkbox
    // (checked = duplicates only, unchecked = no filter) rather than core's
    // three-option select.
    //
    // Do NOT call parent::valueForm() on the exposed path: BooleanOperator
    // back-fills $form_state user input from $this->value, which forces the
    // checkbox to render checked. Build the checkbox from the actual request
    // instead so its state matches acceptExposedInput().
    if ($form_state->get('exposed')) {
      $identifier = $this->options['expose']['identifier'];
      $form['value'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Duplicate names only'),
        '#default_value' => (bool) \Drupal::request()->query->get($identifier),
        '#return_value' => 1,
      ];
      return;
    }
    parent::valueForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function acceptExposedInput($input) {
    if (empty($this->options['exposed'])) {
      return parent::acceptExposedInput($input);
    }
    // An unchecked checkbox submits nothing, but Views back-fills the exposed
    // input from the stored default, so $input can carry a stale "on" value.
    // Read the real submitted request parameter instead: it is present and
    // truthy only when the box was actually checked. Absent (whether unchecked
    // on submit or a fresh page load) always means "no filter".
    $identifier = $this->options['expose']['identifier'];
    $this->value = \Drupal::request()->query->get($identifier) ? 1 : 'All';
    return $this->value === 1;
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // Unchecked box (or '- Any -') means no filtering — show everything.
    if (empty($this->value) || $this->value === 'All') {
      return;
    }

    $this->ensureMyTable();
    // A checked box shows only duplicates.
    $operator = 'IN';

    // Placeholders are namespaced by handler id so the filter can appear more
    // than once in a single view without colliding.
    $suffix = preg_replace('/[^a-z0-9_]/', '_', strtolower($this->options['id']));
    $wid = ':cc_dup_wid_' . $suffix;
    $first = ':cc_dup_first_' . $suffix;
    $last = ':cc_dup_last_' . $suffix;

    // Lowercase and trim so trailing/leading whitespace and case don't split a
    // match, e.g. "Bathiya" and "Bathiya ".
    $name_expression = "CONCAT(LOWER(TRIM(f.value)), '|', LOWER(TRIM(l.value)))";
    $inner_expression = "CONCAT(LOWER(TRIM(f2.value)), '|', LOWER(TRIM(l2.value)))";

    // Status-agnostic: a duplicate is any name appearing on more than one
    // submission. Narrow to a specific status (e.g. approved) with the view's
    // separate "Filter by Status" exposed filter rather than baking it in here.
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
