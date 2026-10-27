<?php

namespace Drupal\ood_software\Plugin\views\filter;

use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\StringFilter;
use Drupal\views\Views;

/**
 * The hub's "Search": a repo's title or its GitHub URL contains the text.
 *
 * A contributor's title often differs from the repo's GitHub name (41 of 100
 * repos on 2026-10-27), and reviewers search by the name they know. Views'
 * Combine filter cannot do this: it takes only click-sortable fields, and a
 * link field never is, because its options column is a blob (D8-2927).
 *
 * @ingroup views_filter_handlers
 */
#[ViewsFilter('ood_software_repo_search')]
class RepoSearch extends StringFilter {

  const URL_TABLE = 'node__field_repo_url';

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    $text = trim((string) $this->value);
    if ($text === '') {
      return;
    }
    $this->ensureMyTable();
    /** @var \Drupal\views\Plugin\views\query\Sql $query */
    $query = $this->query;
    $join = Views::pluginManager('join')->createInstance('standard', [
      'table' => self::URL_TABLE,
      'field' => 'entity_id',
      'left_table' => $this->tableAlias,
      'left_field' => 'nid',
      'type' => 'LEFT',
      'extra' => [['field' => 'deleted', 'value' => 0]],
    ]);
    $urlAlias = $query->addTable(self::URL_TABLE, $this->relationship, $join);
    $pattern = '%' . $this->connection->escapeLike($text) . '%';
    $like = $this->getConditionOperator('LIKE');
    $query->addWhere($this->options['group'], ($query->getConnection()->condition('OR'))
      ->condition("$this->tableAlias.title", $pattern, $like)
      ->condition("$urlAlias.field_repo_url_uri", $pattern, $like));
  }

}
