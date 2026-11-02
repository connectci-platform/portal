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
 * The views data alter in ood_software.module puts this plugin on every
 * node view's moderation_state filter, so it must behave exactly like core's
 * unless a view turns its needs_attention option on and the value includes
 * "needs_attention" (D8-2931). Manage Repos does, as its default queue.
 *
 * A repo needs attention when, reading the default revision's state as core's
 * filter does: it is ready_for_review; or a member app is ready_for_review (a
 * live repo resubmitted whole stays published); or it is published or
 * needs_adjustment and its newest review (created DESC, nid DESC) is
 * undecided by ReleaseWatcher::isDecided()'s rule. Draft and archived repos
 * are left out of the review branch: nothing would ever decide an admin run.
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
    $wants = in_array(self::NEEDS_ATTENTION, $value, TRUE);
    // Core splits every value on "-" as workflow-state, so it must never see
    // this key, whether or not the option applies.
    $this->value = array_diff($value, [self::NEEDS_ATTENTION]);
    if (!$wants || empty($this->options['needs_attention']) || $this->operator !== 'in') {
      parent::opSimple();
      $this->value = $value;
      return;
    }

    /** @var \Drupal\views\Plugin\views\query\Sql $query */
    $query = $this->query;
    $group = $this->options['group'];
    $before = count($query->where[$group]['conditions'] ?? []);
    // Core's condition for any states also selected; nothing when Needs
    // attention is selected alone.
    parent::opSimple();
    $parent = NULL;
    if (count($query->where[$group]['conditions'] ?? []) > $before) {
      $parent = array_pop($query->where[$group]['conditions']);
    }

    $connection = $query->getConnection();
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
    $base = $query->ensureTable($this->table, $this->relationship);
    $any->condition("$base.nid", $this->needsAttentionSubquery(), 'IN');
    $query->addWhere($group, $any);
    $this->value = $value;
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
    $repos->condition('ood_na_repo.type', 'appverse_repo');

    $any = $connection->condition('OR')
      ->condition('ood_na_repo_state.moderation_state', 'ready_for_review');

    // A member app awaiting review.
    if (isset($fields['field_appverse_repo'])) {
      $apps = $connection->select('node__field_appverse_repo', 'ood_na_app_repo');
      $apps->addExpression('1');
      $apps->innerJoin('node_field_data', 'ood_na_app', 'ood_na_app.nid = ood_na_app_repo.entity_id AND ood_na_app.vid = ood_na_app_repo.revision_id');
      $this->joinModerationState($apps, 'ood_na_app', 'ood_na_app_state');
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
   * Joins a node's default-revision Appverse moderation state, as core does.
   */
  protected function joinModerationState(SelectInterface $select, string $node, string $alias): void {
    $select->innerJoin('content_moderation_state_field_revision', $alias, "$alias.content_entity_id = $node.nid AND $alias.content_entity_revision_id = $node.vid AND $alias.langcode = $node.langcode");
    $select->condition("$alias.content_entity_type_id", 'node')
      ->condition("$alias.workflow", self::WORKFLOW);
  }

}
