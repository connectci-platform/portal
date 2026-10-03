<?php

namespace Drupal\ood_software\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Service\ReviewPageData;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\ParagraphInterface;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The review page: one appverse_review, laid out as the moderation wireframe.
 *
 * A form whose render array is the whole page (theme hook
 * appverse_review_form): the machine half of the review is rendered read-only,
 * and the reviewer's fields — per-finding prose, per-axis level and note, the
 * per-app conclusion, the response, the assessment, internal notes — are form
 * elements placed into that layout by the template. Viewers without the
 * reviewer permission get the same page with the controls omitted.
 *
 * Data shaping (which block a finding lands in, severity groups, "also flagged
 * in") lives in ReviewPageData and is unit-tested; this class only moves data
 * between entities and arrays.
 */
final class ReviewPageForm extends FormBase {

  const REVIEWER_PERMISSION = 'administer appverse content';

  // No security axis: security is findings only (schema 1.2), so there is no
  // level for a reviewer to override.
  const AXES = ['portability' => 'port', 'documentation' => 'docs'];

  const STATE_LABELS = [
    'draft' => 'Draft',
    'in_review' => 'In review',
    'waiting_for_contributor' => 'Waiting for contributor',
    'published' => 'Published',
  ];

  /**
   * The moderation path to Published from each state, in order.
   */
  const PUBLISH_PATH = [
    'draft' => ['in_review', 'published'],
    'in_review' => ['published'],
    'waiting_for_contributor' => ['in_review', 'published'],
    'published' => [],
  ];

  /**
   * Who the page is rendered for (see viewModeFor()).
   */
  const MODE_EDIT = 'edit';
  const MODE_CONTRIBUTOR = 'contributor';
  const MODE_PUBLIC = 'public';

  protected NodeInterface $node;

  /**
   * The view a viewer gets.
   *
   * Reviewers get the editable page, or the public summary when they ask for
   * it (?view=public) to see what visitors see. The repo's owner gets the full
   * page read-only, including the response written to them. Everyone else gets
   * the public summary: no evidence, no response, no internal notes.
   */
  public static function viewModeFor(bool $isReviewer, bool $isContributor, ?string $requested): string {
    if ($isReviewer) {
      return $requested === self::MODE_PUBLIC ? self::MODE_PUBLIC : self::MODE_EDIT;
    }
    return $isContributor ? self::MODE_CONTRIBUTOR : self::MODE_PUBLIC;
  }

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
    protected TimeInterface $time,
    protected DateFormatterInterface $dateFormatter,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('datetime.time'),
      $container->get('date.formatter'),
      $container->get('file_url_generator'),
    );
  }

  public function getFormId(): string {
    return 'appverse_review_page_form';
  }

  /**
   * Route title callback.
   */
  public static function title(NodeInterface $node): string {
    $repo = $node->hasField('field_arv_repo') ? $node->get('field_arv_repo')->entity : NULL;
    return 'Review of ' . ($repo ? $repo->label() : $node->label());
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if ($node === NULL || $node->bundle() !== 'appverse_review') {
      throw new \InvalidArgumentException('The review page needs an appverse_review node.');
    }
    $this->node = $node;
    $isReviewer = $this->currentUser->hasPermission(self::REVIEWER_PERMISSION);
    $repo = $node->get('field_arv_repo')->entity;
    $isContributor = $repo instanceof NodeInterface
      && $this->currentUser->isAuthenticated()
      && (int) $repo->getOwnerId() === (int) $this->currentUser->id();
    $mode = self::viewModeFor($isReviewer, $isContributor, $this->getRequest()->query->get('view'));
    $canEdit = $mode === self::MODE_EDIT;

    $page = $this->buildPage($node);
    $form['#theme'] = $mode === self::MODE_PUBLIC ? 'appverse_review_summary' : 'appverse_review_form';
    $form['#attached']['library'][] = 'ood_software/appverse_review';
    $form['#page'] = $page;
    $form['#can_edit'] = $canEdit;
    // A reviewer looking at the public view (rather than a visitor) gets a
    // banner leading back to the full page.
    $form['#preview'] = $isReviewer && $mode === self::MODE_PUBLIC;
    $form['#tree'] = TRUE;
    // The page differs by permission, by whether the viewer owns the repo,
    // and by ?view=public; a cached copy must never cross those lines.
    $form['#cache']['contexts'] = ['user.permissions', 'user', 'url.query_args:view'];
    // node_list: the history bar and the superseded banner depend on the
    // repo's other reviews, so a newly seeded review must invalidate this one.
    $form['#cache']['tags'] = array_merge($node->getCacheTags(), $repo instanceof NodeInterface ? $repo->getCacheTags() : [], ['node_list']);

    if (!$canEdit) {
      return $form;
    }

    $levelOptions = $this->levelOptions();
    foreach ($page['apps'] as $app) {
      $pid = $app['pid'];
      $form['conclusion'][$pid] = [
        '#type' => 'select',
        '#title' => $this->t('Decision'),
        '#title_display' => 'invisible',
        '#options' => $this->conclusionOptions(),
        '#empty_option' => $this->t('- Not decided -'),
        '#default_value' => $app['conclusion'] ?? '',
      ];
      foreach (self::AXES as $axis => $prefix) {
        $block = $app['blocks'][$axis];
        $form['level'][$pid][$axis] = [
          '#type' => 'select',
          '#title' => $this->t('@axis level', ['@axis' => $block['title']]),
          '#title_display' => 'invisible',
          '#options' => $levelOptions,
          '#empty_option' => $this->t('- None -'),
          // An empty level (an older or hand-seeded review) starts at the
          // automated rating rather than "None".
          '#default_value' => ($block['level'] ?? NULL) ?: ($block['tool_level'] ?? ''),
        ];
        $form['level_note'][$pid][$axis] = [
          '#type' => 'textarea',
          '#title' => $this->t('Reason for changing the automated rating'),
          '#title_display' => 'invisible',
          '#rows' => 2,
          '#default_value' => $block['note'],
          '#attributes' => ['placeholder' => $this->t('Reason for changing the automated rating…')],
        ];
      }
      foreach ($app['blocks'] as $key => $block) {
        $this->addNewFindingElements($form, $block['target'], $key);
        foreach ($block['groups'] as $group) {
          foreach ($group['findings'] as $finding) {
            $this->addProseElement($form, $finding);
          }
        }
      }
    }
    foreach ($page['repo_blocks'] as $key => $block) {
      $this->addNewFindingElements($form, $block['target'], $key);
      foreach ($block['groups'] as $group) {
        foreach ($group['findings'] as $finding) {
          $this->addProseElement($form, $finding);
        }
      }
    }
    $this->addNewFindingElements($form, $page['maintenance']['target'], 'maintenance');
    $maint = $page['maintenance'];
    $form['maint_level'] = [
      '#type' => 'select',
      '#title' => $this->t('Maintenance level'),
      '#title_display' => 'invisible',
      '#options' => $levelOptions,
      '#empty_option' => $this->t('- None -'),
      '#default_value' => ($maint['level'] ?? NULL) ?: ($maint['tool_level'] ?? ''),
    ];
    $form['maint_level_note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reason for changing the automated rating'),
      '#title_display' => 'invisible',
      '#rows' => 2,
      '#default_value' => $maint['note'],
      '#attributes' => ['placeholder' => $this->t('Reason for changing the automated rating…')],
    ];
    foreach ($maint['groups'] as $group) {
      foreach ($group['findings'] as $finding) {
        $this->addProseElement($form, $finding);
      }
    }

    $form['response'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Response to contributor'),
      '#title_display' => 'invisible',
      '#rows' => 8,
      '#default_value' => $page['response'],
    ];
    $form['assessment'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reviewer assessment'),
      '#title_display' => 'invisible',
      '#rows' => 5,
      '#default_value' => $page['assessment'],
      '#attributes' => ['placeholder' => $this->t('The published assessment, in your words…')],
    ];
    $form['new_note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Add a note'),
      '#title_display' => 'invisible',
      '#rows' => 2,
      '#attributes' => ['placeholder' => $this->t('Add a note…')],
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save draft'),
      '#attributes' => ['class' => ['btn', 'ghost']],
    ];
    // A superseded review (a newer one of the same repo exists) can still be
    // saved, but not published: publishing it would put stale findings in
    // front of the newer review. The banner links to the newer one.
    if ($page['state'] !== 'published' && empty($page['superseded_by'])) {
      $form['actions']['publish'] = [
        '#type' => 'submit',
        '#value' => $this->t('Publish review'),
        '#attributes' => ['class' => ['btn', 'primary']],
        '#submit' => ['::submitForm', '::publish'],
      ];
    }
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $node = $this->node;
    $values = $form_state->getValues();

    foreach ($node->get('field_arv_verdicts')->referencedEntities() as $verdict) {
      $pid = $verdict->id();
      $changed = FALSE;
      if (array_key_exists($pid, $values['conclusion'] ?? [])) {
        $verdict->set('field_rvv_conclusion', $values['conclusion'][$pid] !== '' ? $values['conclusion'][$pid] : NULL);
        $changed = TRUE;
      }
      foreach (self::AXES as $axis => $prefix) {
        if (isset($values['level'][$pid][$axis])) {
          $verdict->set("field_rvv_{$prefix}_level", $values['level'][$pid][$axis] !== '' ? $values['level'][$pid][$axis] : NULL);
          $this->setText($verdict, "field_rvv_{$prefix}_level_note", $values['level_note'][$pid][$axis] ?? '');
          $changed = TRUE;
        }
      }
      if ($changed) {
        $verdict->save();
      }
      foreach ($verdict->get('field_rvv_findings')->referencedEntities() as $finding) {
        $this->saveProse($finding, $values);
        $this->saveReviewerEdits($finding, $values);
      }
    }
    foreach ($node->get('field_arv_repo_findings')->referencedEntities() as $finding) {
      $this->saveProse($finding, $values);
      $this->saveReviewerEdits($finding, $values);
    }

    if (isset($values['maint_level'])) {
      $node->set('field_arv_maint_level', $values['maint_level'] !== '' ? $values['maint_level'] : NULL);
      $this->setText($node, 'field_arv_maint_level_note', $values['maint_level_note'] ?? '');
    }
    $this->setText($node, 'field_arv_contributor_response', $values['response'] ?? '');
    $this->setText($node, 'field_arv_assessment', $values['assessment'] ?? '');

    $noteText = trim((string) ($values['new_note'] ?? ''));
    if ($noteText !== '') {
      $note = Paragraph::create([
        'type' => 'review_note',
        'field_rvn_author' => $this->currentUser->id(),
        'field_rvn_at' => $this->time->getRequestTime(),
        'field_rvn_text' => ['value' => $noteText, 'format' => 'plain_text'],
      ]);
      $note->save();
      $notes = $node->get('field_arv_internal_notes')->getValue();
      $notes[] = ['target_id' => $note->id(), 'target_revision_id' => $note->getRevisionId()];
      $node->set('field_arv_internal_notes', $notes);
    }

    $node->setNewRevision(TRUE);
    $this->stampRevision($node, 'Review page: saved by ' . $this->currentUser->getDisplayName());
    $node->save();
    $this->messenger()->addStatus($this->t('Review saved.'));
    $form_state->setRedirect('ood_software.review_page', ['node' => $node->id()]);
  }

  /**
   * Second submit handler for Publish: walk the moderation path to Published.
   */
  public function publish(array &$form, FormStateInterface $form_state): void {
    $storage = $this->entityTypeManager->getStorage('node');
    $fresh = $storage->loadUnchanged($this->node->id());
    $state = $fresh->get('moderation_state')->value ?? 'draft';
    foreach (self::PUBLISH_PATH[$state] ?? [] as $next) {
      $fresh = $storage->loadUnchanged($this->node->id());
      $fresh->set('moderation_state', $next);
      $fresh->setNewRevision(TRUE);
      $this->stampRevision($fresh, sprintf('Review page: moved to %s by %s', $next, $this->currentUser->getDisplayName()));
      if (method_exists($fresh, 'setValidationRequired')) {
        $fresh->setValidationRequired(FALSE);
      }
      $fresh->save();
    }
    $this->messenger()->addStatus($this->t('Review published.'));
  }

  /**
   * Everything the template needs, as plain arrays.
   */
  protected function buildPage(NodeInterface $node): array {
    $repo = $node->get('field_arv_repo')->entity;
    $sha = (string) ($node->get('field_arv_sha')->value ?? '');
    $reviewedAt = (int) ($node->get('field_arv_reviewed_at')->value ?? 0);
    $state = (string) ($node->get('moderation_state')->value ?? 'draft');

    $previous = $this->previousReviews($node, $repo);
    $reportHtml = $this->reportHtmlUrl($node);
    $history = ReviewPageData::historyPosition($this->reviewHistory($repo), (int) $node->id());

    $apps = [];
    foreach ($node->get('field_arv_verdicts')->referencedEntities() as $verdict) {
      $levels = [];
      $toolLevels = json_decode((string) ($verdict->get('field_rvv_indicators_default')->value ?? ''), TRUE) ?: [];
      foreach (self::AXES as $axis => $prefix) {
        $levels[$axis] = [
          'level' => $verdict->get("field_rvv_{$prefix}_level")->value,
          'tool_level' => $toolLevels[$axis]['level'] ?? NULL,
          'summary' => (string) ($verdict->get("field_rvv_{$prefix}_summary")->value ?? ''),
          'anchor' => (string) ($verdict->get("field_rvv_{$prefix}_anchor")->value ?? ''),
          'note' => (string) ($verdict->get("field_rvv_{$prefix}_level_note")->value ?? ''),
        ];
      }
      $appRef = $verdict->get('field_rvv_app_ref')->entity;
      $app = ReviewPageData::buildApp([
        'app_id' => $verdict->get('field_rvv_app_id')->value ?? 'root',
        'name' => $appRef ? $appRef->label() : ($verdict->get('field_rvv_app_id')->value ?? 'App'),
        'criteria' => json_decode((string) ($verdict->get('field_rvv_criteria')->value ?? '{}'), TRUE) ?: [],
        'conclusion' => $verdict->get('field_rvv_conclusion')->value,
        'levels' => $levels,
        'findings' => array_map([$this, 'findingArray'], $verdict->get('field_rvv_findings')->referencedEntities()),
      ], $previous);
      $app['pid'] = $verdict->id();
      // Where an "Add finding" in each block puts the new finding.
      foreach (array_keys($app['blocks']) as $key) {
        $app['blocks'][$key]['target'] = 'app:' . $verdict->id() . ':' . $key;
      }
      $apps[] = $app;
    }

    $repoSection = ReviewPageData::buildRepo(
      array_map([$this, 'findingArray'], $node->get('field_arv_repo_findings')->referencedEntities()),
      [
        'level' => $node->get('field_arv_maint_level')->value,
        'tool_level' => (json_decode((string) ($node->get('field_arv_indicators_default')->value ?? ''), TRUE) ?: [])['maintenance']['level'] ?? NULL,
        'summary' => (string) ($node->get('field_arv_maint_summary')->value ?? ''),
        'anchor' => (string) ($node->get('field_arv_maint_anchor')->value ?? ''),
        'note' => (string) ($node->get('field_arv_maint_level_note')->value ?? ''),
      ],
      $previous,
    );
    foreach (array_keys($repoSection['blocks']) as $key) {
      $repoSection['blocks'][$key]['target'] = 'repo:' . $key;
    }
    $repoSection['maintenance']['target'] = 'repo:maintenance';

    $notes = [];
    foreach ($node->get('field_arv_internal_notes')->referencedEntities() as $note) {
      $author = $note->get('field_rvn_author')->entity;
      $notes[] = [
        'author' => $author ? $author->getDisplayName() : $this->t('Unknown'),
        'at' => $this->formatDate((int) ($note->get('field_rvn_at')->value ?? 0), 'medium'),
        'text' => (string) ($note->get('field_rvn_text')->value ?? ''),
      ];
    }
    $notes = array_reverse($notes);

    return [
      'nid' => $node->id(),
      'title' => $repo ? $repo->label() : $node->label(),
      'repo_label' => $repo ? $repo->label() : '',
      'repo_url' => $repo && $repo->hasField('field_repo_url') && !$repo->get('field_repo_url')->isEmpty() ? $repo->get('field_repo_url')->first()->getValue()['uri'] : '',
      'maintainer' => $repo && $repo->hasField('field_repo_maintainer_name') ? (string) ($repo->get('field_repo_maintainer_name')->value ?? '') : '',
      'sha' => $sha,
      'sha7' => substr($sha, 0, 7),
      'ref' => (string) ($node->get('field_arv_ref')->value ?? ''),
      'shape' => str_replace('_', ' ', (string) ($node->get('field_arv_repo_shape')->value ?? '')),
      'reviewed_at' => $reviewedAt ? $this->formatDate($reviewedAt, 'short') : '',
      'tool_version' => (string) ($node->get('field_arv_tool_version')->value ?? ''),
      'state' => $state,
      'state_label' => self::STATE_LABELS[$state] ?? ucfirst($state),
      'previous' => $previous,
      // Step 1 of the Reviewer Process: the repo gates (pass/fail stored since
      // the first import; the report's evidence per row and the Catalog
      // checks only on reviews imported since they were parsed).
      'repo_gates' => ReviewPageData::gatePills(json_decode((string) ($node->get('field_arv_repo_criteria')->value ?? ''), TRUE) ?: []),
      'gate_rows' => $this->gateRows($node),
      'catalog_html' => $node->hasField('field_arv_catalog_checks') ? $this->renderMarkdown((string) ($node->get('field_arv_catalog_checks')->value ?? '')) : NULL,
      'history' => $history,
      'superseded_by' => $history['newest'] ?? NULL,
      'report_html' => $reportHtml,
      'report_pdf' => $this->fileUrl($node, 'field_arv_report_pdf'),
      'recommendation' => $node->get('field_arv_recommendation')->value,
      'recommendation_label' => $this->conclusionOptions()[$node->get('field_arv_recommendation')->value] ?? '',
      'recommendation_note' => (string) ($node->get('field_arv_recommendation_note')->value ?? ''),
      'apps' => $apps,
      'maintenance' => $repoSection['maintenance'],
      'repo_blocks' => $repoSection['blocks'],
      'level_labels' => $this->levelOptions(),
      'conclusion_labels' => $this->conclusionOptions(),
      'response' => (string) ($node->get('field_arv_contributor_response')->value ?? ''),
      'assessment' => (string) ($node->get('field_arv_assessment')->value ?? ''),
      'notes' => $notes,
      'edit_url' => Url::fromRoute('entity.node.edit_form', ['node' => $node->id()])->toString(),
      'full_url' => Url::fromRoute('ood_software.review_page', ['node' => $node->id()])->toString(),
      'public_url' => Url::fromRoute('ood_software.review_page', ['node' => $node->id()], ['query' => ['view' => self::MODE_PUBLIC]])->toString(),
    ];
  }

  /**
   * A finding paragraph as the array ReviewPageData works on.
   */
  protected function findingArray(ParagraphInterface $p): array {
    $source = $p->hasField('field_rvf_source') ? (string) ($p->get('field_rvf_source')->value ?? '') : '';
    $isReviewer = $source === 'reviewer';
    $author = $isReviewer ? $p->get('field_rvf_author')->entity : NULL;
    return [
      // Findings seeded before field_rvf_source existed are automated.
      'source' => $isReviewer ? 'reviewer' : 'ai',
      // A reviewer finding stays in the block it was added in (see
      // ReviewPageData::BLOCK_FIELDS); NULL lets the rule code decide.
      'block' => $isReviewer ? ReviewPageData::blockFromFields($p->get('field_rvf_aspect')->value, $p->get('field_rvf_category')->value) : NULL,
      'author' => $author ? $author->getDisplayName() : '',
      'created' => $isReviewer ? $this->formatDate((int) ($p->get('field_rvf_created')->value ?? 0), 'medium') : '',
      'pid' => $p->id(),
      'rule' => (string) ($p->get('field_rvf_rule')->value ?? ''),
      'severity' => (string) ($p->get('field_rvf_severity')->value ?? ''),
      // The paragraph has no result field (#11); everything seeded is a
      // finding until it does.
      'result' => 'FAIL',
      'stable_id' => (string) ($p->get('field_rvf_stable_id')->value ?? ''),
      'summary' => (string) ($p->get('field_rvf_summary')->value ?? ''),
      'evidence' => (string) ($p->get('field_rvf_evidence')->value ?? ''),
      'defect_key' => (string) ($p->get('field_rvf_defect_key')->value ?? ''),
      'prose' => (string) ($p->get('field_rvf_reviewer_prose')->value ?? ''),
    ];
  }

  /**
   * Other reviews of the same repo, newest first, with their stable ids.
   */
  protected function previousReviews(NodeInterface $node, ?NodeInterface $repo): array {
    if ($repo === NULL) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'appverse_review')
      ->condition('field_arv_repo', $repo->id())
      ->condition('nid', $node->id(), '<>')
      ->sort('created', 'DESC')
      ->range(0, 10)
      ->execute();
    $previous = [];
    foreach ($storage->loadMultiple($nids) as $other) {
      $ids = [];
      $paragraphs = $other->get('field_arv_repo_findings')->referencedEntities();
      foreach ($other->get('field_arv_verdicts')->referencedEntities() as $verdict) {
        $paragraphs = array_merge($paragraphs, $verdict->get('field_rvv_findings')->referencedEntities());
      }
      foreach ($paragraphs as $p) {
        $ids[] = (string) ($p->get('field_rvf_stable_id')->value ?? '');
      }
      $at = (int) ($other->get('field_arv_reviewed_at')->value ?? $other->getCreatedTime());
      $previous[] = [
        'label' => $this->formatDate($at, 'short') . ' · ' . substr((string) $other->get('field_arv_sha')->value, 0, 7),
        'url' => Url::fromRoute('ood_software.review_page', ['node' => $other->id()])->toString(),
        'stable_ids' => array_values(array_filter($ids)),
      ];
    }
    return $previous;
  }

  /**
   * Every review of the repo the viewer may see, oldest first.
   *
   * Access-checked, so a visitor's history counts published reviews only and
   * is never told about a draft. Each item is what the history bar and the
   * superseded banner show: nid, label (date · short SHA), url.
   */
  protected function reviewHistory(?NodeInterface $repo): array {
    if ($repo === NULL) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'appverse_review')
      ->condition('field_arv_repo', $repo->id())
      ->sort('created', 'ASC')
      ->sort('nid', 'ASC')
      ->execute();
    $history = [];
    foreach ($storage->loadMultiple($nids) as $review) {
      $at = (int) ($review->get('field_arv_reviewed_at')->value ?? $review->getCreatedTime());
      $history[] = [
        'nid' => (int) $review->id(),
        'label' => $this->formatDate($at, 'short') . ' · ' . substr((string) $review->get('field_arv_sha')->value, 0, 7),
        'url' => Url::fromRoute('ood_software.review_page', ['node' => $review->id()])->toString(),
      ];
    }
    return $history;
  }

  /**
   * The report's repo-level gate rows, each with its evidence rendered.
   */
  protected function gateRows(NodeInterface $node): array {
    if (!$node->hasField('field_arv_gate_evidence')) {
      return [];
    }
    $rows = json_decode((string) ($node->get('field_arv_gate_evidence')->value ?? ''), TRUE) ?: [];
    foreach ($rows as &$row) {
      // Evidence is one line of markdown (backticked values); render it
      // inline, without the paragraph wrapper.
      $html = (string) $this->renderMarkdown((string) ($row['evidence'] ?? ''));
      $row['evidence_html'] = Markup::create(preg_replace('#^\s*<p>(.*)</p>\s*$#s', '$1', $html));
    }
    return $rows;
  }

  /**
   * Markdown from the report, as HTML safe to print: raw HTML in the source
   * is stripped and unsafe links are dropped, so Markup is sound here.
   */
  protected function renderMarkdown(string $markdown): ?Markup {
    if (trim($markdown) === '') {
      return NULL;
    }
    $converter = new GithubFlavoredMarkdownConverter([
      'html_input' => 'strip',
      'allow_unsafe_links' => FALSE,
    ]);
    return Markup::create((string) $converter->convert($markdown));
  }

  /**
   * Records who made a revision and when.
   *
   * Without this every revision saved from the review page reads as
   * anonymous, all with the same time.
   */
  protected function stampRevision(NodeInterface $node, string $message): void {
    $node->setRevisionUserId((int) $this->currentUser->id());
    $node->setRevisionCreationTime($this->time->getCurrentTime());
    $node->setRevisionLogMessage($message);
  }

  protected function addProseElement(array &$form, array $finding): void {
    $form['prose'][$finding['pid']] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reviewer note'),
      '#title_display' => 'invisible',
      '#rows' => 2,
      '#default_value' => $finding['prose'],
      '#attributes' => ['placeholder' => $this->t('Reviewer note…')],
    ];
    if ($finding['source'] !== 'reviewer') {
      return;
    }
    // A reviewer's own finding is editable (saved with Save draft) and
    // deletable by any reviewer; automated findings are annotated only.
    $pid = $finding['pid'];
    $form['edit_finding'][$pid] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['finding-fields']],
      'rule' => ['#type' => 'textfield', '#title' => $this->t('Rule'), '#size' => 10, '#maxlength' => 32, '#default_value' => $finding['rule']],
      'severity' => ['#type' => 'select', '#title' => $this->t('Severity'), '#options' => $this->severityOptions(), '#default_value' => $finding['severity']],
      'summary' => ['#type' => 'textfield', '#title' => $this->t('Summary'), '#maxlength' => 255, '#default_value' => $finding['summary']],
      'evidence' => ['#type' => 'textfield', '#title' => $this->t('Evidence'), '#maxlength' => 255, '#default_value' => $finding['evidence'], '#attributes' => ['placeholder' => 'path/to/file:line — what is there']],
    ];
    $form['delete_finding'][$pid] = [
      '#type' => 'submit',
      '#value' => $this->t('Delete finding'),
      '#name' => 'delete_finding__' . $pid,
      '#finding_pid' => $pid,
      '#submit' => ['::deleteFinding'],
      '#limit_validation_errors' => [],
      '#attributes' => ['class' => ['btn', 'ghost', 'btn-delete-finding']],
    ];
  }

  /**
   * The "Add finding" form for one block: the fields the automated review
   * fills that need a reviewer's judgment. Its button validates only its own
   * fields, so an empty add form elsewhere never blocks Save draft.
   */
  protected function addNewFindingElements(array &$form, string $target, string $blockKey): void {
    $rules = [];
    foreach (ReviewPageData::BLOCK_RULES[$blockKey] ?? [] as $code => $title) {
      $rules[$code] = $code . ' — ' . $title;
    }
    $rules['_other'] = $this->t('Other…');
    $selector = ':input[name="add_finding[' . $target . '][rule]"]';
    $form['add_finding'][$target] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['finding-fields']],
      'rule' => ['#type' => 'select', '#title' => $this->t('Rule'), '#options' => $rules],
      'rule_other' => [
        '#type' => 'textfield',
        '#title' => $this->t('Rule code'),
        '#size' => 10,
        '#maxlength' => 32,
        '#states' => ['visible' => [$selector => ['value' => '_other']]],
      ],
      'severity' => ['#type' => 'select', '#title' => $this->t('Severity'), '#options' => $this->severityOptions(), '#default_value' => 'medium'],
      'summary' => ['#type' => 'textfield', '#title' => $this->t('Summary'), '#maxlength' => 255],
      'evidence' => ['#type' => 'textfield', '#title' => $this->t('Evidence'), '#maxlength' => 255, '#attributes' => ['placeholder' => 'path/to/file:line — what is there']],
      'add' => [
        '#type' => 'submit',
        '#value' => $this->t('Add finding'),
        '#name' => 'add_finding__' . $target,
        '#finding_target' => $target,
        '#validate' => ['::validateAddFinding'],
        '#submit' => ['::addFinding'],
        '#limit_validation_errors' => [['add_finding', $target]],
        '#attributes' => ['class' => ['btn', 'ghost']],
      ],
    ];
  }

  /**
   * The finding a reviewer typed into one block's add form, as paragraph
   * values, or NULL with the error set on the form.
   */
  protected function newFindingFromInput(array $input, string $target, FormStateInterface $form_state): ?array {
    [$kind, $first, $second] = array_pad(explode(':', $target), 3, NULL);
    $blockKey = $kind === 'app' ? $second : $first;
    $appId = 'root';
    if ($kind === 'app') {
      foreach ($this->node->get('field_arv_verdicts')->referencedEntities() as $verdict) {
        if ((string) $verdict->id() === (string) $first) {
          $appId = (string) ($verdict->get('field_rvv_app_id')->value ?? 'root');
        }
      }
    }
    $rule = ($input['rule'] ?? '') === '_other' ? ($input['rule_other'] ?? '') : ($input['rule'] ?? '');
    try {
      return ReviewPageData::reviewerFinding(['rule' => $rule] + $input, (string) $blockKey, $appId, 'manual-' . bin2hex(random_bytes(4)));
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setErrorByName('add_finding][' . $target . '][summary', $this->t('A new finding needs a rule, a severity and a summary.'));
      return NULL;
    }
  }

  public function validateAddFinding(array &$form, FormStateInterface $form_state): void {
    $target = $form_state->getTriggeringElement()['#finding_target'];
    $this->newFindingFromInput($form_state->getValue(['add_finding', $target]) ?? [], $target, $form_state);
  }

  /**
   * Submit handler for a block's "Add finding": a review_finding paragraph
   * marked as the reviewer's, appended to that app's verdict (or the repo's
   * findings), saved as a new revision of the review.
   */
  public function addFinding(array &$form, FormStateInterface $form_state): void {
    $target = $form_state->getTriggeringElement()['#finding_target'];
    $values = $this->newFindingFromInput($form_state->getValue(['add_finding', $target]) ?? [], $target, $form_state);
    if ($values === NULL) {
      return;
    }
    $node = $this->node;
    $finding = Paragraph::create([
      'type' => 'review_finding',
      'field_rvf_stable_id' => $values['stable_id'],
      'field_rvf_app_id' => $values['app_id'],
      'field_rvf_rule' => $values['rule'],
      'field_rvf_defect_key' => $values['defect_key'],
      'field_rvf_aspect' => $values['aspect'],
      'field_rvf_category' => $values['category'],
      'field_rvf_severity' => $values['severity'],
      'field_rvf_summary' => $values['summary'],
      'field_rvf_evidence' => $values['evidence'],
      'field_rvf_anchor' => $values['anchor'],
      'field_rvf_line' => $values['line'],
      'field_rvf_source' => 'reviewer',
      'field_rvf_author' => $this->currentUser->id(),
      'field_rvf_created' => $this->time->getCurrentTime(),
    ]);
    [$kind, $first] = explode(':', $target);
    if ($kind === 'app') {
      foreach ($node->get('field_arv_verdicts')->referencedEntities() as $verdict) {
        if ((string) $verdict->id() === (string) $first) {
          $finding->setParentEntity($verdict, 'field_rvv_findings');
          $finding->save();
          $verdict->get('field_rvv_findings')->appendItem($finding);
          $verdict->save();
        }
      }
    }
    else {
      $finding->setParentEntity($node, 'field_arv_repo_findings');
      $finding->save();
      $node->get('field_arv_repo_findings')->appendItem($finding);
    }
    $node->setNewRevision(TRUE);
    $this->stampRevision($node, sprintf('Review page: %s added finding %s', $this->currentUser->getDisplayName(), $values['rule']));
    $node->save();
    $this->messenger()->addStatus($this->t('Finding @rule added.', ['@rule' => $values['rule']]));
    $form_state->setRedirect('ood_software.review_page', ['node' => $node->id()]);
  }

  /**
   * Submit handler for "Delete finding" on a reviewer's finding. Removes the
   * reference (earlier revisions keep theirs); automated findings are never
   * deleted here.
   */
  public function deleteFinding(array &$form, FormStateInterface $form_state): void {
    $pid = (string) $form_state->getTriggeringElement()['#finding_pid'];
    $node = $this->node;
    $removed = NULL;
    $drop = function ($list) use ($pid, &$removed): bool {
      foreach ($list->referencedEntities() as $delta => $p) {
        if ((string) $p->id() === $pid && ($p->get('field_rvf_source')->value ?? '') === 'reviewer') {
          $removed = $p;
          $list->removeItem($delta);
          return TRUE;
        }
      }
      return FALSE;
    };
    foreach ($node->get('field_arv_verdicts')->referencedEntities() as $verdict) {
      if ($drop($verdict->get('field_rvv_findings'))) {
        $verdict->save();
        break;
      }
    }
    if ($removed === NULL) {
      $drop($node->get('field_arv_repo_findings'));
    }
    if ($removed === NULL) {
      $this->messenger()->addError($this->t('Only a finding a reviewer added can be deleted.'));
      return;
    }
    $node->setNewRevision(TRUE);
    $this->stampRevision($node, sprintf('Review page: %s deleted finding %s', $this->currentUser->getDisplayName(), $removed->get('field_rvf_rule')->value));
    $node->save();
    $this->messenger()->addStatus($this->t('Finding deleted.'));
    $form_state->setRedirect('ood_software.review_page', ['node' => $node->id()]);
  }

  /**
   * Saves the edit fields of a reviewer's finding, keeping its identity
   * (stable id, author, time added) and the block it was added in.
   */
  protected function saveReviewerEdits(ParagraphInterface $finding, array $values): void {
    $pid = $finding->id();
    if (($finding->get('field_rvf_source')->value ?? '') !== 'reviewer' || !isset($values['edit_finding'][$pid])) {
      return;
    }
    $block = ReviewPageData::blockFromFields($finding->get('field_rvf_aspect')->value, $finding->get('field_rvf_category')->value) ?? 'code_quality';
    try {
      $v = ReviewPageData::reviewerFinding($values['edit_finding'][$pid], $block, (string) $finding->get('field_rvf_app_id')->value, (string) $finding->get('field_rvf_stable_id')->value);
    }
    catch (\InvalidArgumentException $e) {
      // validateForm() reported it; leave the finding as it was.
      return;
    }
    foreach (['rule', 'severity', 'summary', 'evidence', 'anchor', 'line', 'defect_key'] as $key) {
      $finding->set('field_rvf_' . $key, $v[$key]);
    }
    $finding->save();
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    foreach ($form_state->getValue('edit_finding') ?? [] as $pid => $input) {
      if (trim((string) ($input['summary'] ?? '')) === '' || trim((string) ($input['rule'] ?? '')) === '') {
        $form_state->setErrorByName('edit_finding][' . $pid . '][summary', $this->t('A finding needs a rule and a summary.'));
      }
    }
  }

  protected function severityOptions(): array {
    return [
      'critical' => $this->t('Critical'),
      'high' => $this->t('High'),
      'medium' => $this->t('Medium'),
      'low' => $this->t('Low'),
      'info' => $this->t('Info'),
    ];
  }

  protected function saveProse(ParagraphInterface $finding, array $values): void {
    $pid = $finding->id();
    if (!array_key_exists($pid, $values['prose'] ?? [])) {
      return;
    }
    $current = (string) ($finding->get('field_rvf_reviewer_prose')->value ?? '');
    if (trim((string) $values['prose'][$pid]) === trim($current)) {
      return;
    }
    $this->setText($finding, 'field_rvf_reviewer_prose', $values['prose'][$pid]);
    $finding->save();
  }

  /**
   * Sets a text_long field, keeping its current format (plain_text if none).
   */
  protected function setText($entity, string $field, string $value): void {
    if (!$entity->hasField($field)) {
      return;
    }
    $format = $entity->get($field)->format ?? 'plain_text';
    $entity->set($field, ['value' => $value, 'format' => $format]);
  }

  protected function levelOptions(): array {
    $definitions = $this->entityTypeManager->getStorage('field_storage_config');
    $storage = $definitions->load('node.field_arv_maint_level');
    $allowed = $storage ? ($storage->getSetting('allowed_values') ?? []) : [];
    $options = [];
    // Config YAML lists {value, label} pairs; the loaded setting is keyed
    // value => label. Accept either shape.
    foreach ($allowed as $key => $item) {
      if (is_array($item)) {
        $options[$item['value']] = $item['label'];
      }
      else {
        $options[$key] = $item;
      }
    }
    return $options ?: ['solid' => 'Solid', 'some_notes' => 'Some notes', 'needs_attention' => 'Needs attention'];
  }

  protected function conclusionOptions(): array {
    return [
      'accept' => $this->t('Accept'),
      'accept_with_suggestions' => $this->t('Accept with suggestions'),
      'request_changes' => $this->t('Request changes'),
      'reject' => $this->t('Reject'),
    ];
  }

  protected function fileUrl(NodeInterface $node, string $field): string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return '';
    }
    $file = $node->get($field)->entity;
    return $file ? $this->fileUrlGenerator->generateString($file->getFileUri()) : '';
  }

  protected function reportHtmlUrl(NodeInterface $node): string {
    return $this->fileUrl($node, 'field_arv_report_html');
  }

  protected function formatDate(int $ts, string $type): string {
    return $ts ? $this->dateFormatter->format($ts, $type) : '';
  }

}
