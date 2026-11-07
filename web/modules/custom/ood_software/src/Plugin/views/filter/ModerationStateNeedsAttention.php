<?php

namespace Drupal\ood_software\Plugin\views\filter;

use Drupal\content_moderation\Plugin\views\filter\ModerationStateFilter;
use Drupal\Core\Database\Query\ConditionInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Attribute\ViewsFilter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Core's moderation state filter, plus an optional "Needs attention" option.
 *
 * Offered as its own views data entry, node_field_data.ood_moderation_state
 * (see ood_software_views_data_alter()), so only views that pick it use it;
 * core's moderation_state filter is untouched. Besides seeing pending revisions
 * (below), it behaves like core's unless its needs_attention option is on and
 * the value includes "needs_attention" (D8-2931). Manage Repos does, as its
 * default queue.
 *
 * A repo needs attention when: its newest revision is ready_for_review; or a
 * member app's newest revision is ready_for_review (a live repo or app sent
 * for review stays published, with the review on a forward revision,
 * D8-2882); or its default revision is published or needs_adjustment and its
 * newest review (created DESC, nid DESC) is undecided by
 * ReleaseWatcher::isDecided()'s rule. Draft and archived repos are left out
 * of the review branch: nothing would ever decide an admin run.
 *
 * Selected states are pending-aware too: "is one of" matches a node whose
 * default revision is in the state, or whose newest revision is in it and is
 * newer than the default one (D8-2882). "Is not one of" is left to core.
 *
 * The option is matched with a nid IN (subquery) branch, ORed with core's own
 * condition for any states also selected, so a repo is never repeated and
 * nothing but appverse_repo nodes come back through the branch.
 *
 * @ingroup views_filter_handlers
 */
#[ViewsFilter('ood_software_moderation_state')]
class ModerationStateNeedsAttention extends ModerationStateFilter {

  /**
   * The value key of the Needs attention option.
   */
  const NEEDS_ATTENTION = 'needs_attention';

  /**
   * The workflow, and bundles, the option is about.
   */
  const WORKFLOW = 'appverse_editorial';

  /**
   * The entity field manager.
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityFieldManager = $container->get('entity_field.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The option definitions.
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['needs_attention'] = ['default' => FALSE];
    return $options;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The options form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state): void {
    parent::buildOptionsForm($form, $form_state);
    $form['needs_attention'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Offer "Needs attention (any state)"'),
      '#description' => $this->t('Adds an option matching Appverse repos awaiting review, with a resubmitted app, or with an undecided report on a live or sent-back repo.'),
      '#default_value' => !empty($this->options['needs_attention']),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * @return array<int|string, mixed>
   *   The options, Needs attention first when the option is on.
   */
  public function getValueOptions() {
    $options = parent::getValueOptions();
    if (!empty($this->options['needs_attention']) && !isset($options[self::NEEDS_ATTENTION])) {
      $options = [self::NEEDS_ATTENTION => $this->t('Needs attention (any state)')] + $options;
      $this->valueOptions = $options;
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  protected function opSimple(): void {
    $value = (array) $this->value;
    $wants = in_array(self::NEEDS_ATTENTION, $value, TRUE)
      && !empty($this->options['needs_attention'])
      && $this->operator === 'in';
    // Core splits every value on "-" as workflow-state, so it must never see
    // this key, whether or not the option applies.
    $this->value = array_diff($value, [self::NEEDS_ATTENTION]);

    /** @var \Drupal\views\Plugin\views\query\Sql $query */
    $query = $this->query;
    $group = $this->options['group'];
    $before = count($query->where[$group]['conditions'] ?? []);
    // Core's condition for any states selected; nothing when Needs attention
    // is selected alone.
    parent::opSimple();
    $parent = NULL;
    if (count($query->where[$group]['conditions'] ?? []) > $before) {
      $parent = array_pop($query->where[$group]['conditions']);
    }
    // Only "is one of" with real states is widened to pending revisions; the
    // no-moderated-bundle case adds a bare expression with nothing to widen.
    $widen = $this->operator === 'in' && $this->value && $parent !== NULL
      && $parent['field'] instanceof ConditionInterface;

    if (!$wants && !$widen) {
      if ($parent !== NULL) {
        $query->where[$group]['conditions'][] = $parent;
      }
      $this->value = $value;
      return;
    }

    $connection = $query->getConnection();
    $base = $query->ensureTable($this->table, $this->relationship);
    $any = $connection->condition('OR');
    if ($parent !== NULL) {
      if ($parent['field'] instanceof ConditionInterface) {
        $any->condition($parent['field']);
      }
      elseif ($parent['operator'] === 'formula') {
        $any->where($parent['field'], $parent['value']);
      }
      else {
        $any->condition($parent['field'], $parent['value'], $parent['operator']);
      }
    }
    if ($widen) {
      $any->condition($this->pendingRevisionCondition($base));
    }
    if ($wants) {
      $any->condition("$base.nid", $this->needsAttentionSubquery(), 'IN');
    }
    $query->addWhere($group, $any);
    $this->value = $value;
  }

  /**
   * Matches a node whose newest revision is in a selected state and pending.
   *
   * In appverse_editorial ready_for_review, needs_adjustment and declined are
   * not default revisions, so a live repo sent for review, sent back or
   * declined stays published with the state on a forward revision, which
   * core's filter (default revision only) cannot see (D8-2882).
   *
   * @param string $base
   *   The view's alias for node_field_data.
   */
  protected function pendingRevisionCondition(string $base): ConditionInterface {
    /** @var \Drupal\views\Plugin\views\query\Sql $query */
    $query = $this->query;
    $connection = $query->getConnection();
    $pending = $connection->condition('OR');
    foreach ((array) $this->value as $value) {
      [$workflowId, $stateId] = explode('-', (string) $value, 2) + [1 => ''];
      // Every value is bound as a parameter; the only interpolated text is
      // the view's own table alias.
      $exists = $connection->select('content_moderation_state_field_revision', 'pending_cms');
      $exists->addExpression('1');
      $exists
        ->condition('pending_cms.content_entity_type_id', 'node')
        ->condition('pending_cms.workflow', $workflowId)
        ->condition('pending_cms.moderation_state', $stateId);
      $exists->where("pending_cms.content_entity_id = $base.nid");
      $exists->where("pending_cms.content_entity_revision_id > $base.vid");
      $exists->where('pending_cms.content_entity_revision_id = (' . $this->latestVidSql("$base.nid") . ')');
      $pending->exists($exists);
    }
    return $pending;
  }

  /**
   * {@inheritdoc}
   *
   * The option belongs to the Appverse workflow, so a view whose only value
   * is "needs_attention" still depends on it.
   *
   * @return string[]
   *   The workflow IDs.
   */
  protected function getWorkflowIds() {
    $ids = parent::getWorkflowIds();
    $key = array_search(self::NEEDS_ATTENTION, $ids, TRUE);
    if ($key !== FALSE) {
      unset($ids[$key]);
      $ids[] = self::WORKFLOW;
    }
    return array_values(array_unique($ids));
  }

  /**
   * The nids of the appverse_repo nodes that need attention.
   */
  protected function needsAttentionSubquery(): SelectInterface {
    /** @var \Drupal\views\Plugin\views\query\Sql $query */
    $query = $this->query;
    $connection = $query->getConnection();
    $fields = $this->entityFieldManager->getFieldStorageDefinitions('node');

    $repos = $connection->select('node_field_data', 'ood_na_repo')->fields('ood_na_repo', ['nid']);
    $this->joinModerationState($repos, 'ood_na_repo', 'ood_na_repo_state');
    $this->joinModerationState($repos, 'ood_na_repo', 'ood_na_repo_latest', TRUE);
    $repos->condition('ood_na_repo.type', 'appverse_repo');

    $any = $connection->condition('OR')
      ->condition('ood_na_repo_latest.moderation_state', 'ready_for_review');

    // A member app awaiting review.
    if (isset($fields['field_appverse_repo'])) {
      $apps = $connection->select('node__field_appverse_repo', 'ood_na_app_repo');
      $apps->addExpression('1');
      $apps->innerJoin('node_field_data', 'ood_na_app', 'ood_na_app.nid = ood_na_app_repo.entity_id AND ood_na_app.vid = ood_na_app_repo.revision_id');
      $this->joinModerationState($apps, 'ood_na_app', 'ood_na_app_state', TRUE);
      $apps->where('ood_na_app_repo.field_appverse_repo_target_id = ood_na_repo.nid')
        ->condition('ood_na_app_repo.deleted', 0)
        ->condition('ood_na_app_state.moderation_state', 'ready_for_review');
      $any->exists($apps);
    }

    // A live or sent-back repo whose newest review is undecided.
    if (isset($fields['field_arv_repo'])) {
      $reviews = $connection->select('node__field_arv_repo', 'ood_na_rv_repo');
      $reviews->addExpression('1');
      $reviews->innerJoin('node_field_data', 'ood_na_rv', 'ood_na_rv.nid = ood_na_rv_repo.entity_id AND ood_na_rv.default_langcode = 1');
      $reviews->where('ood_na_rv_repo.field_arv_repo_target_id = ood_na_repo.nid')
        ->condition('ood_na_rv_repo.deleted', 0)
        ->condition('ood_na_rv.status', 0);
      foreach (['field_arv_decision_sent_at', 'field_arv_withdrawn_at'] as $i => $field) {
        if (isset($fields[$field])) {
          $alias = "ood_na_rv_ts$i";
          $reviews->leftJoin("node__$field", $alias, "$alias.entity_id = ood_na_rv.nid AND $alias.deleted = 0");
          $reviews->isNull("$alias.entity_id");
        }
      }
      $newer = $connection->select('node__field_arv_repo', 'ood_na_rv2_repo');
      $newer->addExpression('1');
      $newer->innerJoin('node_field_data', 'ood_na_rv2', 'ood_na_rv2.nid = ood_na_rv2_repo.entity_id AND ood_na_rv2.default_langcode = 1');
      $newer->where('ood_na_rv2_repo.field_arv_repo_target_id = ood_na_repo.nid')
        ->condition('ood_na_rv2_repo.deleted', 0)
        ->where('ood_na_rv2.created > ood_na_rv.created OR (ood_na_rv2.created = ood_na_rv.created AND ood_na_rv2.nid > ood_na_rv.nid)');
      $reviews->notExists($newer);

      $any->condition($connection->condition('AND')
        ->condition('ood_na_repo_state.moderation_state', ['published', 'needs_adjustment'], 'IN')
        ->exists($reviews));
    }

    $repos->condition($any);
    return $repos;
  }

  /**
   * Joins a node's Appverse moderation state.
   *
   * The default revision's, as core does, or with $latest the newest
   * revision's, where a live node's pending review or send-back is.
   */
  protected function joinModerationState(SelectInterface $select, string $node, string $alias, bool $latest = FALSE): void {
    $revision = $latest ? '(' . $this->latestVidSql("$node.nid") . ')' : "$node.vid";
    $select->innerJoin('content_moderation_state_field_revision', $alias, "$alias.content_entity_id = $node.nid AND $alias.content_entity_revision_id = $revision AND $alias.langcode = $node.langcode");
    $select->condition("$alias.content_entity_type_id", 'node')
      ->condition("$alias.workflow", self::WORKFLOW);
  }

  /**
   * SQL for the newest revision id of the node in $nid, for a correlated use.
   *
   * @param string $nid
   *   A column expression holding the node id, an alias owned by this class
   *   or the view, never user input.
   */
  protected function latestVidSql(string $nid): string {
    return "SELECT MAX(ood_latest_nr.vid) FROM {node_revision} ood_latest_nr WHERE ood_latest_nr.nid = $nid";
  }

}
