<?php

namespace Drupal\ood_software\Plugin\views\filter;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Session\AccountInterface;
use Drupal\ood_software\Service\ReviewAssignment;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\InOperator;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The hub's "Assigned" filter: repos assigned to the viewer, to no one, or to
 * a named reviewer.
 *
 * A repo's reviewer lives on the repo (field_repo_assigned_reviewer;
 * appverse-planning#33). Core's filter on that field can only match a fixed
 * user id, not "whoever is looking", nor an empty value, so this one joins
 * the field table itself (LEFT, so unassigned repos stay in the result) and
 * compares with the current user, IS NULL, or a reviewer's uid. The named
 * reviewers are the ones the assign action offers (D8-2927). "- Any -" adds
 * no condition.
 *
 * @ingroup views_filter_handlers
 */
#[ViewsFilter('ood_software_assigned_reviewer')]
class AssignedReviewer extends InOperator {

  const FIELD_TABLE = 'node__field_repo_assigned_reviewer';

  protected ReviewAssignment $reviewAssignment;

  protected AccountInterface $currentUser;

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $configuration
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->reviewAssignment = $container->get('ood_software.review_assignment');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * @return array<int|string, string|\Stringable>
   */
  public function getValueOptions() {
    $this->valueOptions = [
      'me' => $this->t('Assigned to me'),
      'none' => $this->t('Unassigned'),
    ] + $this->reviewAssignment->reviewers();
    return $this->valueOptions;
  }

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    $value = is_array($this->value) ? reset($this->value) : $this->value;
    $uid = is_numeric($value) ? (int) $value : 0;
    if (!in_array($value, ['me', 'none'], TRUE) && $uid <= 0) {
      return;
    }
    $this->ensureMyTable();
    /** @var \Drupal\views\Plugin\views\query\Sql $query */
    $query = $this->query;
    $join = Views::pluginManager('join')->createInstance('standard', [
      'table' => self::FIELD_TABLE,
      'field' => 'entity_id',
      'left_table' => $this->tableAlias,
      'left_field' => 'nid',
      'type' => 'LEFT',
      'extra' => [['field' => 'deleted', 'value' => 0]],
    ]);
    $alias = $query->addTable(self::FIELD_TABLE, $this->relationship, $join);
    $column = "$alias.field_repo_assigned_reviewer_target_id";
    if ($value === 'me') {
      $query->addWhere($this->options['group'], $column, (int) $this->currentUser->id(), '=');
    }
    elseif ($uid > 0) {
      $query->addWhere($this->options['group'], $column, $uid, '=');
    }
    else {
      $query->addWhere($this->options['group'], $column, NULL, 'IS NULL');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    // "Assigned to me" differs per viewer.
    return Cache::mergeContexts(parent::getCacheContexts(), ['user']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    // The reviewer options change when someone gains or loses the role.
    return Cache::mergeTags(parent::getCacheTags(), ['user_list']);
  }

}
