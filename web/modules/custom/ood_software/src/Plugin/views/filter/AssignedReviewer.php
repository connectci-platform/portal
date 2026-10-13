<?php

namespace Drupal\ood_software\Plugin\views\filter;

use Drupal\Core\Cache\Cache;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\InOperator;

/**
 * The hub's "Assigned" filter: repos assigned to the viewer, or to no one.
 *
 * A repo's reviewer lives on the repo (field_repo_assigned_reviewer;
 * appverse-planning#33). Core's filter on that field can only match a fixed
 * user id, not "whoever is looking", nor an empty value, so this one joins
 * the field table itself (LEFT, so unassigned repos stay in the result) and
 * compares with the current user or IS NULL. "- Any -" adds no condition.
 *
 * @ingroup views_filter_handlers
 */
#[ViewsFilter('ood_software_assigned_reviewer')]
class AssignedReviewer extends InOperator {

  const FIELD_TABLE = 'node__field_repo_assigned_reviewer';

  /**
   * {@inheritdoc}
   */
  public function getValueOptions() {
    $this->valueOptions = [
      'me' => $this->t('Assigned to me'),
      'none' => $this->t('Unassigned'),
    ];
    return $this->valueOptions;
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    $value = is_array($this->value) ? reset($this->value) : $this->value;
    if (!in_array($value, ['me', 'none'], TRUE)) {
      return;
    }
    $this->ensureMyTable();
    $join = \Drupal::service('plugin.manager.views.join')->createInstance('standard', [
      'table' => self::FIELD_TABLE,
      'field' => 'entity_id',
      'left_table' => $this->tableAlias,
      'left_field' => 'nid',
      'type' => 'LEFT',
      'extra' => [['field' => 'deleted', 'value' => 0]],
    ]);
    $alias = $this->query->addTable(self::FIELD_TABLE, $this->relationship, $join);
    $column = "$alias.field_repo_assigned_reviewer_target_id";
    if ($value === 'me') {
      $this->query->addWhere($this->options['group'], $column, (int) \Drupal::currentUser()->id(), '=');
    }
    else {
      $this->query->addWhere($this->options['group'], $column, NULL, 'IS NULL');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    // "Assigned to me" differs per viewer.
    return Cache::mergeContexts(parent::getCacheContexts(), ['user']);
  }

}
