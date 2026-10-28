<?php

namespace Drupal\ood_software\Plugin\views\filter;

use Drupal\ood_software\Service\ReviewProgress;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\InOperator;
use Drupal\views\Views;

/**
 * The hub's "AI report" filter: the state of a repo's latest AI report.
 *
 * The options use the words of the card's "AI report" step (ReviewProgress),
 * so the filter and the card agree. They read the repo's run status and, for
 * a complete run, its recommendation. A rerun sets the status back to pending
 * and clears the recommendation, so a repo being rerun is Queued, not Not
 * started; a withdrawn review clears the status, so it is Not started
 * (D8-2927).
 *
 * @ingroup views_filter_handlers
 */
#[ViewsFilter('ood_software_ai_report_state')]
class AiReportState extends InOperator {

  /**
   * Run status for each run-state option; NULL is no status at all.
   */
  const STATUS = [
    'not_started' => NULL,
    'queued' => 'pending',
    'running' => 'in_progress',
    'failed' => 'error',
  ];

  /**
   * The stored recommendation for each suggestion option.
   */
  const RECOMMENDATION = [
    'accept' => 'accepted',
    'accept_with_suggestions' => 'accepted_with_suggestions',
    'request_changes' => 'changes_requested',
    'reject' => 'rejected',
  ];

  /**
   * {@inheritdoc}
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  public function getValueOptions() {
    $this->valueOptions = [
      'not_started' => $this->t('Not started'),
      'queued' => $this->t('Queued'),
      'running' => $this->t('Running'),
      'failed' => $this->t('Failed'),
    ];
    foreach (array_keys(self::RECOMMENDATION) as $key) {
      $this->valueOptions[$key] = $this->t('Suggests @decision', ['@decision' => ReviewProgress::SUGGESTION_LABELS[$key]]);
    }
    return $this->valueOptions;
  }

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    $selected = array_intersect(array_keys(self::STATUS + self::RECOMMENDATION), (array) $this->value);
    if ($selected === []) {
      return;
    }
    $this->ensureMyTable();
    /** @var \Drupal\views\Plugin\views\query\Sql $query */
    $query = $this->query;
    $status = $this->joinField('field_review_status') . '.field_review_status_value';
    $recommendation = $this->joinField('field_review_recommendation') . '.field_review_recommendation_value';

    $connection = $query->getConnection();
    $any = $connection->condition('OR');
    foreach ($selected as $option) {
      if (array_key_exists($option, self::STATUS)) {
        self::STATUS[$option] === NULL
          ? $any->isNull($status)
          : $any->condition($status, self::STATUS[$option]);
      }
      else {
        $any->condition($connection->condition('AND')
          ->condition($status, 'complete')
          ->condition($recommendation, self::RECOMMENDATION[$option]));
      }
    }
    $query->addWhere($this->options['group'], $any);
  }

  /**
   * LEFT joins a single-value repo field table and returns its alias.
   */
  protected function joinField(string $field): string {
    $table = "node__$field";
    $join = Views::pluginManager('join')->createInstance('standard', [
      'table' => $table,
      'field' => 'entity_id',
      'left_table' => $this->tableAlias,
      'left_field' => 'nid',
      'type' => 'LEFT',
      'extra' => [['field' => 'deleted', 'value' => 0]],
    ]);
    /** @var \Drupal\views\Plugin\views\query\Sql $query */
    $query = $this->query;
    return $query->addTable($table, $this->relationship, $join);
  }

}
