<?php

namespace Drupal\ood_software\Plugin\views\filter;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\InOperator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The hub's "Software used" filter: repos with an app implementing it.
 *
 * Software lives on a repo's member apps (field_appverse_software_implemen on
 * appverse_app), not on the repo. Joining the apps in, as the view's "Member
 * apps" relationship did, returns a repo once per app, which the SQL DISTINCT
 * cannot collapse and which throws off the pager. Narrowing by subquery
 * instead keeps the result one row per repo (D8-2927).
 *
 * @ingroup views_filter_handlers
 */
#[ViewsFilter('ood_software_repo_software_used')]
class RepoSoftwareUsed extends InOperator {

  protected Connection $database;

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $configuration
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->database = $container->get('database');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * @return array<int|string, string>
   */
  public function getValueOptions() {
    $query = $this->database->select('node__field_appverse_software_implemen', 's');
    $query->join('node_field_data', 'n', 'n.nid = s.field_appverse_software_implemen_target_id');
    $query->fields('n', ['nid', 'title'])
      ->condition('s.bundle', 'appverse_app')
      ->condition('s.deleted', 0)
      ->distinct();
    $options = $query->execute()->fetchAllKeyed();
    natcasesort($options);
    $this->valueOptions = $options;
    return $this->valueOptions;
  }

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    $values = array_filter(array_map('intval', (array) $this->value));
    if ($values === []) {
      return;
    }
    $this->ensureMyTable();
    /** @var \Drupal\views\Plugin\views\query\Sql $query */
    $query = $this->query;
    $repos = $this->database->select('node__field_appverse_repo', 'r');
    $repos->join('node__field_appverse_software_implemen', 's', 's.entity_id = r.entity_id AND s.deleted = 0');
    $repos->fields('r', ['field_appverse_repo_target_id'])
      ->condition('r.deleted', 0)
      ->condition('s.field_appverse_software_implemen_target_id', $values, 'IN');
    $query->addWhere($this->options['group'], "$this->tableAlias.nid", $repos, 'IN');
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    // The options are whatever software the apps use right now.
    return Cache::mergeTags(parent::getCacheTags(), ['node_list']);
  }

}
