<?php

namespace Drupal\ood_software\Plugin\views\filter;

use Drupal\content_moderation\Plugin\views\filter\ModerationStateFilter;
use Drupal\Core\Database\Query\ConditionInterface;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\query\Sql;

/**
 * Core's moderation state filter, also matching a pending revision's state.
 *
 * Core's filter reads the state of the node's default revision. In
 * appverse_editorial ready_for_review, needs_adjustment and declined are not
 * default revisions, so a published repo sent for review (or sent back, or
 * declined) stays published and carries a forward revision the filter cannot
 * see: the review queue would never list it (D8-2882).
 *
 * This widens a selected state to "the default revision is in it, OR the
 * node's latest revision is in it and is newer than the default one". It has
 * its own plugin id and is not swapped over core's moderation_state_filter:
 * only a view that names ood_software_pending_aware_moderation_state (the
 * my_appverse reviewer queue) gets it, every other view keeps core's
 * behavior. Only the "is one of" operator is widened; "is not one of" is left
 * to core.
 *
 * @ingroup views_filter_handlers
 */
#[ViewsFilter("ood_software_pending_aware_moderation_state")]
class PendingAwareModerationState extends ModerationStateFilter {

  /**
   * {@inheritdoc}
   */
  protected function opSimple(): void {
    $query = $this->query;
    assert($query instanceof Sql);
    $group = $this->options['group'];
    $before = count($query->where[$group]['conditions'] ?? []);
    parent::opSimple();
    $conditions = $query->where[$group]['conditions'] ?? [];
    // Nothing added (no value, or no moderated bundle), or not "is one of".
    if ($this->operator !== 'in' || count($conditions) <= $before) {
      return;
    }

    // The condition core just added for this filter; wrap it.
    $added = array_pop($query->where[$group]['conditions']);
    // The no-moderated-bundle case adds a bare "1 = 0" expression instead;
    // there is nothing to widen, so put it back.
    if (!$added['field'] instanceof ConditionInterface) {
      $query->where[$group]['conditions'][] = $added;
      return;
    }
    $connection = $query->getConnection();
    $nodeAlias = $this->relationship ?: $this->table;

    $pending = $connection->condition('OR');
    foreach ((array) $this->value as $value) {
      [$workflowId, $stateId] = explode('-', $value, 2);
      // The newest revision of the node is in the selected state and is not
      // the default one. Every value is bound as a parameter; the only
      // interpolated text is the view's own table alias.
      $latest = $connection->select('node_revision', 'pending_nr');
      $latest->addExpression('MAX(pending_nr.vid)');
      $latest->where('pending_nr.nid = ' . $nodeAlias . '.nid');

      $exists = $connection->select('content_moderation_state_field_revision', 'pending_cms');
      $exists->addExpression('1');
      $exists
        ->condition('pending_cms.content_entity_type_id', 'node')
        ->condition('pending_cms.workflow', $workflowId)
        ->condition('pending_cms.moderation_state', $stateId);
      $exists->where('pending_cms.content_entity_id = ' . $nodeAlias . '.nid');
      $exists->where('pending_cms.content_entity_revision_id > ' . $nodeAlias . '.vid');
      $exists->where('pending_cms.content_entity_revision_id = (' . $latest . ')');
      $pending->exists($exists);
    }

    $widened = $connection->condition('OR');
    $widened->condition($added['field']);
    $widened->condition($pending);
    $query->addWhere($group, $widened);
  }

}
