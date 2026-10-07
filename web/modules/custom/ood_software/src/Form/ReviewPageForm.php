<?php

namespace Drupal\ood_software\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Controller\AppverseHubController;
use Drupal\ood_software\Service\FindingOverride;
use Drupal\ood_software\Service\RepoProgress;
use Drupal\ood_software\Service\ReviewAssignment;
use Drupal\ood_software\Service\ReviewDecision;
use Drupal\ood_software\Service\ReviewDecisionApplier;
use Drupal\ood_software\Service\ReviewFloors;
use Drupal\ood_software\Service\ReviewPageData;
use Drupal\ood_software\Service\ReviewProgress;
use Drupal\ood_software\Service\ReviewSignals;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\ParagraphInterface;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ood_software\Service\AppverseReviewService;
use Drupal\user\UserInterface;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Entity\FieldableEntityInterface;

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

  /**
   * The month-name date formats, unambiguous in any locale.
   */
  const DATE = 'M j, Y';
  // 12-hour: "Oct 2 2026 3:23 PM" rather than 15:23.
  const DATE_TIME = 'M j, Y g:i A';

  const REVIEWER_PERMISSION = 'administer appverse content';

  // No security axis: security is findings only (schema 1.2), so there is no
  // level for a reviewer to override.
  const AXES = ['portability' => 'port', 'documentation' => 'docs'];

  const STATE_LABELS = [
    'draft' => 'Draft',
    'in_review' => 'In review',
    'published' => 'Published',
  ];


  /**
   * Who the page is rendered for (see viewModeFor()).
   */
  const MODE_EDIT = 'edit';
  const MODE_CONTRIBUTOR = 'contributor';
  const MODE_PUBLIC = 'public';

  protected NodeInterface $node;

  /**
   * The repo URL and reviewed commit findingArray() links evidence to.
   */
  protected string $linkRepoUrl = '';
  protected string $linkSha = '';

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
    protected RepoProgress $repoProgress,
    protected ReviewAssignment $reviewAssignment,
    protected AppverseReviewService $reviews,
    protected ClassResolverInterface $classResolver,
    protected ReviewDecisionApplier $applier,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('datetime.time'),
      $container->get('date.formatter'),
      $container->get('ood_software.repo_progress'),
      $container->get('ood_software.review_assignment'),
      $container->get('ood_software.review_dispatcher'),
      $container->get('class_resolver'),
      $container->get('ood_software.review_decision_applier'),
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

  /**
   * @param array<string, mixed> $form
   * @return array<mixed>
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if ($node !== NULL && $node->bundle() === 'appverse_repo') {
      // /appverse/review/{repo id}: go to that repo's newest review the
      // viewer may see, as the hub card's link does; none is a 404.
      $latest = $this->reviews->latestReviewFor($node);
      if ($latest === NULL) {
        throw new NotFoundHttpException();
      }
      throw new EnforcedResponseException(new RedirectResponse(Url::fromRoute('ood_software.review_page', ['node' => $latest->id()])->toString()));
    }
    if ($node === NULL || $node->bundle() !== 'appverse_review') {
      // Any other node id is simply not a review: 404, not a server error.
      throw new NotFoundHttpException();
    }
    $this->node = $node;
    $isReviewer = $this->currentUser->hasPermission(self::REVIEWER_PERMISSION);
    $repo = $node->get('field_arv_repo')->entity;
    $isContributor = $repo instanceof NodeInterface
      && $this->currentUser->isAuthenticated()
      && (int) $repo->getOwnerId() === (int) $this->currentUser->id();
    $mode = self::viewModeFor($isReviewer, $isContributor, $this->getRequest()->query->get('view'));
    $reviewerView = $mode === self::MODE_EDIT;

    $page = $this->buildPage($node);
    // Once the decision is sent the review is a record of it: the reviewer
    // keeps the internal notes, the assignee and the actions, and the rest is
    // read-only (the decision-sent view, appverse-planning#51, #56).
    $canEdit = $reviewerView && !$page['decision']['sent'];
    // ?update=1 reopens the decisions and the response of a sent decision,
    // while it can still be updated (appverse-planning#56).
    $updating = $reviewerView && $page['decision']['sent'] && $this->getRequest()->query->get('update')
      && $page['decision']['update_blocker'] === NULL;
    $form['#theme'] = $mode === self::MODE_PUBLIC ? 'appverse_review_summary' : 'appverse_review_form';
    $form['#attached']['library'][] = 'ood_software/appverse_review';
    $form['#page'] = $page;
    $form['#can_edit'] = $canEdit;
    $form['#is_reviewer'] = $reviewerView;
    $form['#can_decide'] = $canEdit || $updating;
    $form['#updating'] = $updating;
    // A reviewer looking at the public view (rather than a visitor) gets a
    // banner leading back to the full page.
    $form['#preview'] = $isReviewer && $mode === self::MODE_PUBLIC;
    // The repo's progress line (appverse-planning#31): five steps for
    // reviewers, four for the contributor, none on the public summary. It is
    // the repo's, so a superseded review shows where the repo is now.
    if ($mode !== self::MODE_PUBLIC && $repo instanceof NodeInterface) {
      $steps = $this->repoProgress->steps($repo);
      $form['#progress'] = [
        '#theme' => 'appverse_progress',
        '#steps' => $reviewerView ? $steps['reviewer'] : $steps['contributor'],
        '#variant' => 'steps',
      ];
      // The header pill, from the same steps the line draws, so the two cannot
      // disagree. It used to show the review node's own moderation state,
      // which said "In review" over a line reading "Changes requested ·
      // round 1": a different thing, and the less useful one now that
      // waiting_for_contributor is retired (appverse-planning#53).
      $form['#page']['chip'] = ReviewProgress::chip($canEdit ? $steps['reviewer'] : $steps['contributor']);
      // The old hint here described plumbing ("Your first save starts the
      // Review step"), which is not something the reviewer has to do or can
      // act on. What the page should say is where things end up, and that
      // lives next to the assessment and the decision instead.
    }
    $form['#tree'] = TRUE;
    // The page differs by permission, by whether the viewer owns the repo,
    // and by ?view=public; a cached copy must never cross those lines.
    $form['#cache']['contexts'] = ['user.permissions', 'user', 'url.query_args:view'];
    // node_list: the history bar and the superseded banner depend on the
    // repo's other reviews, so a newly seeded review must invalidate this one.
    $form['#cache']['tags'] = array_merge($node->getCacheTags(), $repo instanceof NodeInterface ? $repo->getCacheTags() : [], ['node_list']);

    if (!$reviewerView) {
      return $form;
    }
    if ($canEdit) {
      $this->addContentElements($form, $page, $node);
    }
    elseif ($updating) {
      $this->addDecisionElements($form, $page, $node);
    }

    // The repo's reviewer, kept across rounds (appverse-planning#33).
    if ($repo instanceof NodeInterface) {
      $assignee = $this->reviewAssignment->assignee($repo);
      $form['assignee'] = [
        '#type' => 'select',
        '#title' => $this->t('Reviewer'),
        '#title_display' => 'invisible',
        '#options' => $this->reviewAssignment->reviewers(),
        '#empty_option' => $this->t('- Unassigned -'),
        '#default_value' => $assignee ? $assignee->id() : '',
      ];
    }

    $form['new_note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Add a note'),
      '#title_display' => 'invisible',
      '#rows' => 2,
      '#attributes' => ['placeholder' => $this->t('Add a note…')],
    ];

    $form['actions']['#type'] = 'actions';
    if ($updating) {
      $form['actions']['send_update'] = [
        '#type' => 'submit',
        '#value' => $this->t('Send updated decision…'),
        '#attributes' => ['class' => ['btn', 'primary']],
        '#submit' => ['::saveUpdate', '::goToUpdate'],
      ];
      $form['actions']['cancel_update'] = [
        '#type' => 'link',
        '#title' => $this->t('Cancel'),
        '#url' => Url::fromRoute('ood_software.review_page', ['node' => $node->id()]),
        '#attributes' => ['class' => ['btn', 'ghost']],
      ];
    }
    elseif ($page['decision']['sent'] && $page['decision']['update_blocker'] === NULL) {
      $form['actions']['update_decision'] = [
        '#type' => 'link',
        '#title' => $this->t('Update decision'),
        '#url' => Url::fromRoute('ood_software.review_page', ['node' => $node->id()], ['query' => ['update' => 1]]),
        '#attributes' => ['class' => ['btn', 'ghost'], 'title' => $this->t('Change the decision or the response; the contributor is told it replaces the one sent.')],
      ];
    }
    // "Start the next round" (appverse-planning#56): when the contributor says
    // by email that they have fixed things, the reviewer re-submits for them,
    // exactly as the contributor's Re-submit does. Only on the repo's newest
    // review while the repo is back with the contributor.
    if ($page['decision']['sent'] && empty($page['superseded_by']) && $repo instanceof NodeInterface
      && ($repo->get('moderation_state')->value ?? '') === 'needs_adjustment') {
      $form['actions']['next_round'] = [
        '#type' => 'submit',
        '#value' => $this->t('Start the next round'),
        '#attributes' => [
          'class' => ['btn', 'primary'],
          'title' => $this->t('Re-submit the repo for its contributor, as their Re-submit button does: a new AI report on the latest commit, and round @n.', ['@n' => $this->repoProgress->facts($repo)['round'] + 1]),
        ],
        '#submit' => ['::startNextRound'],
        '#limit_validation_errors' => [],
      ];
    }
    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $canEdit ? $this->t('Save draft') : $this->t('Save note'),
      '#access' => !$updating,
      '#attributes' => ['class' => ['btn', 'ghost']],
    ];
    // The decision is sent from here (appverse-planning#29): it saves the page,
    // then previews the email on the confirm page. Not on a
    // superseded review (a newer one of the same repo exists; deciding on
    // stale findings is the wrong review), and not once a decision is sent.
    if (!$page['decision']['sent'] && $page['state'] !== 'published' && empty($page['superseded_by']) && !$page['withdrawn']) {
      $form['actions']['send_decision'] = $this->sendButton($page, $form_state);
    }
    return $form;
  }

  /**
   * The decision controls: each app's decision, no milder than its floor
   * allows (appverse-planning#30), and the response to the contributor. On
   * an undecided review, and again while a sent decision is being updated
   * (appverse-planning#56).
   *
   * @param array<string, mixed> $form
   * @param array<string, mixed> $page
   */
  protected function addDecisionElements(array &$form, array $page, NodeInterface $node): void {
    // A saved decision below its floor is not offered, so it shows as not
    // decided until the reviewer picks again.
    $floors = ReviewFloors::forReview($node);
    foreach ($page['apps'] as $app) {
      $pid = $app['pid'];
      $floor = $floors[(string) $pid] ?? NULL;
      $form['conclusion'][$pid] = [
        '#type' => 'select',
        '#title' => $this->t('Decision'),
        '#title_display' => 'invisible',
        '#options' => array_intersect_key($this->conclusionOptions(), array_flip(ReviewFloors::choices($floor['decision'] ?? NULL))),
        '#empty_option' => $this->t('- Not decided -'),
        '#default_value' => $app['conclusion'] ?? '',
        '#description' => $floor ? $this->t('At least @d: @reason.', [
          '@d' => ReviewProgress::decisionLabel($floor['decision']),
          '@reason' => $floor['reason'],
        ]) : NULL,
      ];
    }
    $form['response'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Email to contributor'),
      '#title_display' => 'invisible',
      '#rows' => 8,
      '#default_value' => $page['response'],
      // Where it ends up, first: the contributor is emailed this when the
      // decision is sent (appverse-planning#53). The rest says what not to
      // write, because the email already greets them, lists each app's
      // decision and says how to re-submit (appverse-planning#48).
      // The next screen previews this email; it does not offer to skip it.
      // Sending the decision sends the email (appverse-planning#53).
      '#description' => $this->t('Sent to the contributor with your decision. The next screen previews it before it goes.'),
      // Above the field, not below it: it says what the field is for, which
      // is worth knowing before you start typing rather than after
      // (appverse-planning#53).
      '#description_display' => 'before',
    ];
  }

  /**
   * The review's content controls: decisions, levels, findings, response and
   * assessment. Only until the decision is sent (appverse-planning#56).
   *
   * @param array<string, mixed> $form
   * @param array<string, mixed> $page
   */
  protected function addContentElements(array &$form, array $page, NodeInterface $node): void {
    $this->addDecisionElements($form, $page, $node);
    foreach ($page['apps'] as $app) {
      $pid = $app['pid'];
      foreach (self::AXES as $axis => $prefix) {
        $block = $app['blocks'][$axis];
        $form['level'][$pid][$axis] = [
          '#type' => 'select',
          '#title' => $this->t('@axis level', ['@axis' => $block['title']]),
          '#title_display' => 'invisible',
          '#options' => $this->levelOptions($axis),
          // Starts at the automated rating. Only a review with no rating at
          // all (an older or hand-seeded one) offers an empty choice, so a
          // save never invents a level.
          '#empty_option' => ($block['level'] ?? NULL) || ($block['tool_level'] ?? NULL) ? NULL : $this->t('- Not rated -'),
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
        // A dismissed finding keeps its note and its change, so it can be
        // undone.
        foreach ($block['dismissed'] ?? [] as $finding) {
          $this->addProseElement($form, $finding);
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
      foreach ($block['dismissed'] ?? [] as $finding) {
        $this->addProseElement($form, $finding);
      }
    }
    $this->addNewFindingElements($form, $page['maintenance']['target'], 'maintenance');
    $maint = $page['maintenance'];
    $form['maint_level'] = [
      '#type' => 'select',
      '#title' => $this->t('Upkeep level'),
      '#title_display' => 'invisible',
      '#options' => $this->levelOptions('maintenance'),
      '#empty_option' => ($maint['level'] ?? NULL) || ($maint['tool_level'] ?? NULL) ? NULL : $this->t('- Not rated -'),
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
    foreach ($maint['dismissed'] ?? [] as $finding) {
      $this->addProseElement($form, $finding);
    }

    $form['assessment'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Public summary'),
      '#title_display' => 'invisible',
      '#rows' => 5,
      '#default_value' => $page['assessment'],
      '#attributes' => ['placeholder' => $this->t('The published assessment, in your words…')],
      // Where it ends up (appverse-planning#53): this one is public, which the
      // placeholder alone did not make clear.
      '#description' => $this->t('Published on the public review when the app is published.'),
      '#description_display' => 'before',
    ];
  }

  /**
   * "Send updated decision…": saves the new decisions and response, which
   * take effect only once confirmed (the sent record stays until then).
   *
   * @param array<string, mixed> $form
   */
  public function saveUpdate(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    foreach ($this->node->get('field_arv_verdicts')->referencedEntities() as $verdict) {
      $pid = $verdict->id();
      if (array_key_exists($pid, $values['conclusion'] ?? [])) {
        $verdict->set('field_rvv_conclusion', $values['conclusion'][$pid] !== '' ? $values['conclusion'][$pid] : NULL);
        $verdict->save();
      }
    }
    $this->setText($this->node, 'field_arv_contributor_response', $values['response'] ?? '');
    $this->node->setNewRevision(TRUE);
    $this->stampRevision($this->node, 'Review page: decision update drafted by ' . $this->currentUser->getDisplayName());
    $this->node->save();
  }

  /**
   * Second submit handler for "Send updated decision…": confirm.
   *
   * @param array<string, mixed> $form
   */
  public function goToUpdate(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('ood_software.review_decision', ['node' => $this->node->id()], ['query' => ['update' => 1]]);
  }

  /**
   * "Start the next round": the contributor's Re-submit, done by a reviewer.
   *
   * @param array<string, mixed> $form
   */
  public function startNextRound(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('ood_software.review_page', ['node' => $this->node->id()]);
    $ref = $this->node->get('field_arv_repo')->target_id;
    $repo = $ref ? $this->entityTypeManager->getStorage('node')->loadUnchanged($ref) : NULL;
    if (!$repo instanceof NodeInterface || ($repo->get('moderation_state')->value ?? '') !== 'needs_adjustment') {
      $this->messenger()->addError($this->t('The repo is no longer waiting on its contributor, so there is no next round to start.'));
      return;
    }
    // The hub's send-for-review: the transition, its notification to
    // reviewers and the AI run it dispatches.
    $this->classResolver->getInstanceFromDefinition(AppverseHubController::class)->sendForReview($repo);
  }

  /**
   * The send button: it names the decision and stays disabled, with the
   * reason beside it, until the decision is complete (appverse-planning#52).
   *
   * The page's script relabels it as the reviewer chooses. Drupal knows which
   * submit was pressed by its posted label, so on submit the label is the one
   * posted. Disabled is a plain attribute, not #disabled, which would make
   * Drupal ignore the press once the script had enabled the button.
   *
   * @param array<string, mixed> $page
   *   The page data.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state, for the label posted with a press.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  protected function sendButton(array $page, FormStateInterface $form_state): array {
    $decisions = [];
    foreach ($page['apps'] as $app) {
      $decisions[(string) $app['pid']] = $app['conclusion'] ?? NULL;
    }
    $blocker = ReviewDecision::sendBlocker($decisions, (string) $page['response']);
    $single = [];
    foreach (ReviewProgress::DECISIONS as $d) {
      $single[$d] = (string) ReviewDecision::sendLabel(['x' => $d]);
    }
    $attributes = [
      'class' => ['btn', 'primary', 'arv-send'],
      // What the script needs to relabel the button as the reviewer chooses.
      'data-labels' => json_encode([
        'none' => (string) ReviewDecision::sendLabel([]),
        'single' => $single,
        'mixed' => (string) $this->t('Send decisions (@summary)'),
        'count' => [
          'accept' => (string) $this->t('@count accepted'),
          'accept_with_suggestions' => (string) $this->t('@count accepted with suggestions'),
          'request_changes' => (string) $this->t('@count changes requested'),
          'reject' => (string) $this->t('@count declined'),
        ],
      ]),
      'data-reasons' => json_encode([
        'undecided' => (string) $this->t('Choose a decision for every app first.'),
        'response' => (string) $this->t('Write the response to the contributor first.'),
      ]),
      'aria-describedby' => 'arv-send-reason',
    ];
    if ($blocker !== NULL) {
      $attributes['disabled'] = 'disabled';
    }
    return [
      'button' => [
        '#type' => 'submit',
        '#name' => 'send_decision',
        '#value' => is_string($posted = $form_state->getUserInput()['send_decision'] ?? NULL) && $posted !== ''
          ? $posted
          : ReviewDecision::sendLabel($decisions),
        '#attributes' => $attributes,
      ],
      'reason' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $blocker ?? '',
        '#attributes' => ['id' => 'arv-send-reason', 'class' => ['arv-send-reason']],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $form
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $node = $this->node;
    $values = $form_state->getValues();
    // Changes to automated findings, for the revision log.
    $changes = [];
    // Once the decision is sent only the notes and the assignee are saved:
    // the rest is the record of what was sent (appverse-planning#51, #56).
    if (ReviewDecisionApplier::sent($node) === NULL) {
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
          $changes[] = $this->saveOverride($finding, $values);
        }
      }
      foreach ($node->get('field_arv_repo_findings')->referencedEntities() as $finding) {
        $this->saveProse($finding, $values);
        $this->saveReviewerEdits($finding, $values);
        $changes[] = $this->saveOverride($finding, $values);
      }

      if (isset($values['maint_level'])) {
        $node->set('field_arv_maint_level', $values['maint_level'] !== '' ? $values['maint_level'] : NULL);
        $this->setText($node, 'field_arv_maint_level_note', $values['maint_level_note'] ?? '');
      }
      $this->setText($node, 'field_arv_contributor_response', $values['response'] ?? '');
      $this->setText($node, 'field_arv_assessment', $values['assessment'] ?? '');
    }
    $repo = $node->get('field_arv_repo')->entity;
    if ($repo instanceof NodeInterface) {
      $uid = ($values['assignee'] ?? '') !== '' ? (int) $values['assignee'] : NULL;
      // The first save of the review assigns the saver when nobody is
      // assigned (appverse-planning#47); assign() refuses a non-reviewer.
      if ($uid === NULL && ($node->get('moderation_state')->value ?? '') === 'draft' && $this->reviewAssignment->assignee($repo) === NULL) {
        $uid = (int) $this->currentUser->id();
      }
      if (array_key_exists('assignee', $values) || $uid !== NULL) {
        $this->reviewAssignment->assign($repo, $uid);
      }
    }

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

    // The reviewer's first save starts the Review step: an imported (draft)
    // review moves to In Review (REVIEW-STATES.md, situation 5 → 6).
    if (($node->get('moderation_state')->value ?? '') === 'draft') {
      $node->set('moderation_state', 'in_review');
    }
    $node->setNewRevision(TRUE);
    $changes = array_values(array_filter($changes));
    $this->stampRevision($node, 'Review page: saved by ' . $this->currentUser->getDisplayName() . ($changes !== [] ? '; ' . implode('; ', $changes) : ''));
    $node->save();
    // The send button saves, then goes on to the email preview.
    if (($form_state->getTriggeringElement()['#name'] ?? '') === 'send_decision') {
      $this->messenger()->addStatus($this->t('Your edits are saved. Nothing is sent until you confirm.'));
      $form_state->setRedirect('ood_software.review_decision', ['node' => $node->id()]);
      return;
    }
    $this->messenger()->addStatus($this->t('Review saved.'));
    // A finding's own Save returns to that finding.
    $pid = $form_state->getTriggeringElement()['#finding_pid'] ?? NULL;
    $form_state->setRedirect('ood_software.review_page', ['node' => $node->id()], $pid ? ['fragment' => 'finding-' . $pid] : []);
  }

  /**
   * Everything the template needs, as plain arrays.
   *
   * @return array<mixed>
   */
  protected function buildPage(NodeInterface $node): array {
    $repo = $node->get('field_arv_repo')->entity;
    $repo = $repo instanceof NodeInterface ? $repo : NULL;
    $sha = (string) ($node->get('field_arv_sha')->value ?? '');
    $reviewedAt = (int) ($node->get('field_arv_reviewed_at')->value ?? 0);
    $state = (string) ($node->get('moderation_state')->value ?? 'draft');

    // Evidence links point at the repo on GitHub at the reviewed commit.
    $this->linkRepoUrl = $repo && $repo->hasField('field_repo_url') && !$repo->get('field_repo_url')->isEmpty()
      ? (string) ($repo->get('field_repo_url')->first()->getValue()['uri'] ?? '') : '';
    $this->linkSha = $sha;

    $previous = $this->previousReviews($node, $repo);
    $history = ReviewPageData::historyPosition($this->reviewHistory($repo), (int) $node->id());

    // Once a decision is sent, the page shows what was sent, not what the
    // fields hold now (appverse-planning#51).
    $sent = ReviewDecisionApplier::sent($node);
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
        'conclusion' => $sent !== NULL ? ($sent['apps'][(string) $verdict->id()] ?? NULL) : $verdict->get('field_rvv_conclusion')->value,
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
        'at' => $this->formatDate((int) ($note->get('field_rvn_at')->value ?? 0), 'datetime'),
        'text' => (string) ($note->get('field_rvn_text')->value ?? ''),
      ];
    }
    $notes = array_reverse($notes);

    $repoGates = ReviewPageData::gatePills(json_decode((string) ($node->get('field_arv_repo_criteria')->value ?? ''), TRUE) ?: []);

    return [
      'nid' => $node->id(),
      'title' => $repo ? $repo->label() : $node->label(),
      'repo_label' => $repo ? $repo->label() : '',
      'repo_url' => $repo && $repo->hasField('field_repo_url') && !$repo->get('field_repo_url')->isEmpty() ? $repo->get('field_repo_url')->first()->getValue()['uri'] : '',
      'maintainer' => $repo && $repo->hasField('field_repo_maintainer_name') ? (string) ($repo->get('field_repo_maintainer_name')->value ?? '') : '',
      // Who submitted the repo, linked to their community persona as the hub
      // card links it. NULL when the account has since been deleted.
      'contributor' => $this->contributorLink($repo),
      'sha' => $sha,
      'sha7' => substr($sha, 0, 7),
      'ref' => (string) ($node->get('field_arv_ref')->value ?? ''),
      // Plain wording for the header, not the stored value ("inferred single").
      'shape' => match ((string) ($node->get('field_arv_repo_shape')->value ?? '')) {
        'inferred_single' => (string) $this->t('Single app (no appverse.yml)'),
        'declared_single' => (string) $this->t('Single app'),
        'declared_monorepo' => (string) $this->t('Monorepo'),
        default => str_replace('_', ' ', (string) ($node->get('field_arv_repo_shape')->value ?? '')),
      },
      'reviewed_at' => $reviewedAt ? $this->formatDate($reviewedAt, 'date') : '',
      'tool_version' => (string) ($node->get('field_arv_tool_version')->value ?? ''),
      'state' => $state,
      'state_label' => self::STATE_LABELS[$state] ?? ucfirst($state),
      'previous' => $previous,
      // What moved since the round before (appverse-planning#54). Whole-review
      // sets, because that is the level stable ids are stored at: a finding
      // that moved between blocks has not been resolved.
      'round_delta' => ReviewPageData::roundDelta($this->stableIds($node), $previous),
      // Step 1 of the Reviewer Process: the repo gates (pass/fail stored since
      // the first import; the report's evidence per row and the Catalog
      // checks only on reviews imported since they were parsed).
      'repo_gates' => $repoGates,
      // The same gates as one line: a count of the passes, with anything that
      // is not a pass held out in full (appverse-planning#54).
      'repo_gate_summary' => ReviewPageData::gateSummary($repoGates),
      // Everything the gates box used to hold, for the Structure block that
      // now carries it (appverse-planning#53).
      'repo_gate_block' => ReviewPageData::gateSummary($repoGates) + [
        'rows' => $this->gateRows($node),
        'catalog_html' => $node->hasField('field_arv_catalog_checks')
          ? $this->renderMarkdown((string) ($node->get('field_arv_catalog_checks')->value ?? '')) : NULL,
      ],
      'gate_rows' => $this->gateRows($node),
      'catalog_html' => $node->hasField('field_arv_catalog_checks') ? $this->renderMarkdown((string) ($node->get('field_arv_catalog_checks')->value ?? '')) : NULL,
      'history' => $history,
      'superseded_by' => $history['newest'] ?? NULL,
      'decision' => $this->decisionInfo($node),
      // The contributor withdrew this round (appverse-planning#34).
      'withdrawn' => $node->hasField('field_arv_withdrawn_at') && !$node->get('field_arv_withdrawn_at')->isEmpty()
        ? $this->formatDate((int) $node->get('field_arv_withdrawn_at')->value, 'datetime') : NULL,
      'recommendation' => $node->get('field_arv_recommendation')->value,
      'recommendation_label' => $this->conclusionOptions()[$node->get('field_arv_recommendation')->value] ?? '',
      'recommendation_note' => (string) ($node->get('field_arv_recommendation_note')->value ?? ''),
      // Only worth saying once a decision differs from what the tool
      // suggested; while they agree, the caption repeats the value above it
      // (appverse-planning#53). An app not yet decided does not differ.
      'recommendation_differs' => self::decisionDiffersFromSuggestion(
        (string) ($node->get('field_arv_recommendation')->value ?? ''),
        $apps
      ),
      'apps' => $apps,
      'maintenance' => $repoSection['maintenance'],
      'repo_blocks' => $repoSection['blocks'],
      // Per axis, keyed like the blocks (portability, documentation,
      // maintenance), for the badges and captions.
      'level_labels' => [
        'portability' => $this->levelOptions('portability'),
        'documentation' => $this->levelOptions('documentation'),
        'maintenance' => $this->levelOptions('maintenance'),
      ],
      'conclusion_labels' => $this->conclusionOptions(),
      'response' => $sent !== NULL ? $sent['response'] : (string) ($node->get('field_arv_contributor_response')->value ?? ''),
      'assessment' => (string) ($node->get('field_arv_assessment')->value ?? ''),
      'notes' => $notes,
      'edit_url' => Url::fromRoute('entity.node.edit_form', ['node' => $node->id()])->toString(),
      'full_url' => Url::fromRoute('ood_software.review_page', ['node' => $node->id()])->toString(),
      'public_url' => Url::fromRoute('ood_software.review_page', ['node' => $node->id()], ['query' => ['view' => self::MODE_PUBLIC]])->toString(),
    ];
  }

  /**
   * A finding paragraph as the array ReviewPageData works on.
   *
   * @return array<mixed>
   */
  protected function findingArray(ParagraphInterface $p): array {
    $source = $p->hasField('field_rvf_source') ? (string) ($p->get('field_rvf_source')->value ?? '') : '';
    $isReviewer = $source === 'reviewer';
    $author = $isReviewer ? $p->get('field_rvf_author')->entity : NULL;
    $toolSeverity = (string) ($p->get('field_rvf_severity')->value ?? '');
    $change = FindingOverride::stored($p);
    $changedBy = $p->hasField('field_rvf_override_by') ? $p->get('field_rvf_override_by')->entity : NULL;
    return [
      // Findings seeded before field_rvf_source existed are automated.
      'source' => $isReviewer ? 'reviewer' : 'ai',
      // A reviewer finding stays in the block it was added in (see
      // ReviewPageData::BLOCK_FIELDS); NULL lets the rule code decide.
      'block' => $isReviewer ? ReviewPageData::blockFromFields($p->get('field_rvf_aspect')->value, $p->get('field_rvf_category')->value) : NULL,
      'author' => $author instanceof UserInterface ? $author->getDisplayName() : '',
      'created' => $isReviewer ? $this->formatDate((int) ($p->get('field_rvf_created')->value ?? 0), 'datetime') : '',
      'pid' => $p->id(),
      'rule' => (string) ($p->get('field_rvf_rule')->value ?? ''),
      // The reviewer's severity when they changed it; the tool's is kept for
      // the "changed from" marker (A1, FindingOverride).
      'severity' => FindingOverride::effectiveSeverity($toolSeverity, $change['severity']),
      'tool_severity' => $toolSeverity,
      'dismissed' => $change['dismissed'],
      'override' => FindingOverride::isChanged($change) ? [
        'severity' => $change['severity'],
        'edited' => $change['summary'] !== NULL || $change['evidence'] !== NULL,
        'by' => $changedBy instanceof UserInterface ? $changedBy->getDisplayName() : '',
        'at' => $this->formatDate((int) ($p->get('field_rvf_override_at')->value ?? 0), 'medium'),
      ] : NULL,
      // The tool's own wording, shown to the reviewer once they edit it.
      'original' => $change['summary'] !== NULL || $change['evidence'] !== NULL ? [
        'summary' => (string) ($p->get('field_rvf_summary')->value ?? ''),
        'evidence' => (string) ($p->get('field_rvf_evidence')->value ?? ''),
      ] : NULL,
      // FAIL / WARN / PASS / NOT CHECKED. Rows seeded before the result was
      // stored have none and count as findings, as they always have.
      'result' => strtoupper(str_replace('_', ' ', (string) ($p->hasField('field_rvf_result') ? ($p->get('field_rvf_result')->value ?? '') : ''))) ?: 'FAIL',
      'stable_id' => (string) ($p->get('field_rvf_stable_id')->value ?? ''),
      // The reviewer's wording when they edited it, else the tool's.
      'summary' => $change['summary'] ?? (string) ($p->get('field_rvf_summary')->value ?? ''),
      'evidence' => $change['evidence'] ?? (string) ($p->get('field_rvf_evidence')->value ?? ''),
      'evidence_parts' => ReviewPageData::evidenceParts($change['evidence'] ?? (string) ($p->get('field_rvf_evidence')->value ?? ''), $this->linkRepoUrl, $this->linkSha),
      'defect_key' => (string) ($p->get('field_rvf_defect_key')->value ?? ''),
      // unintentional / potentially_malicious on a security finding, else ''.
      'tag' => $p->hasField('field_rvf_tag') ? (string) ($p->get('field_rvf_tag')->value ?? '') : '',
      'prose' => (string) ($p->get('field_rvf_reviewer_prose')->value ?? ''),
    ];
  }

  /**
   * The repo's submitter, with a link to their community persona.
   *
   * @return array{name: string, url: string}|null
   */
  protected function contributorLink(?NodeInterface $repo): ?array {
    if (!$repo instanceof NodeInterface) {
      return NULL;
    }
    $owner = $repo->getOwner();
    // getOwner() returns NULL when the account was deleted but the repo
    // remains, whatever the stub says.
    // @phpstan-ignore-next-line booleanNot.alwaysFalse
    if (!$owner) {
      return NULL;
    }
    return [
      'name' => $owner->getDisplayName(),
      'url' => '/community-persona/' . $owner->id(),
    ];
  }

  /**
   * Whether any app was decided differently from the tool's suggestion.
   *
   * @param array<int, array<string, mixed>> $apps
   */
  protected static function decisionDiffersFromSuggestion(string $suggestion, array $apps): bool {
    if ($suggestion === '') {
      return FALSE;
    }
    foreach ($apps as $app) {
      $conclusion = (string) ($app['conclusion'] ?? '');
      if ($conclusion !== '' && $conclusion !== $suggestion) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Every stable id in one review, repo-level findings and per-app alike.
   *
   * @return array<int, string>
   */
  protected function stableIds(NodeInterface $node): array {
    $paragraphs = $node->get('field_arv_repo_findings')->referencedEntities();
    foreach ($node->get('field_arv_verdicts')->referencedEntities() as $verdict) {
      $paragraphs = array_merge($paragraphs, $verdict->get('field_rvv_findings')->referencedEntities());
    }
    $ids = [];
    foreach ($paragraphs as $p) {
      $ids[] = (string) ($p->get('field_rvf_stable_id')->value ?? '');
    }
    return array_values(array_filter($ids));
  }

  /**
   * Other reviews of the same repo, newest first, with their stable ids.
   *
   * @return array<mixed>
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
      // accessCheck(TRUE) does not drop drafts a viewer may not see; this
      // does (see AppverseReviewService::latestReviewFor()).
      if (!$other->access('view')) {
        continue;
      }
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
        'label' => $this->formatDate($at, 'date') . ' · ' . substr((string) $other->get('field_arv_sha')->value, 0, 7),
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
   *
   * @return array<mixed>
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
      // A visitor's history counts published reviews only: the query's access
      // check does not drop drafts, so check view access per review.
      if (!$review->access('view')) {
        continue;
      }
      $at = (int) ($review->get('field_arv_reviewed_at')->value ?? $review->getCreatedTime());
      $sha = (string) $review->get('field_arv_sha')->value;
      $sent = $review->hasField('field_arv_decision_sent_at') && !$review->get('field_arv_decision_sent_at')->isEmpty();
      $by = $sent ? $review->get('field_arv_decision_sent_by')->entity : NULL;
      $decision = $sent
        ? ReviewProgress::strictestDecision(array_values(ReviewDecisionApplier::sent($review)['apps'] ?? []))
        : NULL;
      $history[] = [
        'nid' => (int) $review->id(),
        'label' => $this->formatDate($at, 'date') . ' · ' . substr($sha, 0, 7),
        'url' => Url::fromRoute('ood_software.review_page', ['node' => $review->id()])->toString(),
        'sha' => $sha,
        'at' => $this->formatDate($at, 'date'),
        'sha7' => substr($sha, 0, 7),
        'decision' => ReviewProgress::decisionLabel($decision),
        'reviewer' => $by instanceof UserInterface ? $by->getDisplayName() : '',
        // Filled in below, once the entry before it is known.
        'is_rerun' => FALSE,
      ];
    }
    // A review of the same commit as the one before it is a rerun, not a new
    // round: nothing changed in the repo between them (appverse-planning#54).
    foreach ($history as $i => $entry) {
      if ($i > 0 && $entry['sha'] !== '' && $entry['sha'] === $history[$i - 1]['sha']) {
        $history[$i]['is_rerun'] = TRUE;
      }
    }
    return $history;
  }

  /**
   * The sent decision, if any, for the sidebar and the header's Publish.
   *
   * @return array{sent: bool, label: string, by: string, at: string, publish_url: ?string, waiting_on: string, email: array<string, mixed>|null, to: string, update_blocker: mixed, updated_from: string}
   */
  protected function decisionInfo(NodeInterface $node): array {
    $sent = ReviewDecisionApplier::sent($node);
    if ($sent === NULL) {
      return ['sent' => FALSE, 'label' => '', 'by' => '', 'at' => '', 'publish_url' => NULL, 'waiting_on' => '', 'email' => NULL, 'to' => '', 'update_blocker' => $this->t('No decision has been sent on this review yet.'), 'updated_from' => ''];
    }
    $overall = ReviewProgress::strictestDecision(array_values($sent['apps']));
    $by = $node->get('field_arv_decision_sent_by')->entity;
    $repo = $node->get('field_arv_repo')->entity;
    $owner = $repo instanceof NodeInterface && $repo->getOwnerId() ? $repo->getOwner()->getDisplayName() : (string) $this->t('the contributor');
    $publishPending = ReviewPublishConfirmForm::canPublish($node);
    return [
      'sent' => TRUE,
      'label' => ReviewProgress::decisionLabel($overall),
      'by' => $by instanceof UserInterface ? $by->getDisplayName() : '',
      'at' => $this->formatDate((int) $node->get('field_arv_decision_sent_at')->value, 'datetime'),
      // After an Accept with suggestions: "Publish app and review".
      'publish_url' => $publishPending
        ? Url::fromRoute('ood_software.review_publish', ['node' => $node->id()])->toString()
        : NULL,
      // The decision-sent view (appverse-planning#56): who the repo waits on,
      // and the email as it was sent (NULL for a review sent before emails
      // were stored).
      'waiting_on' => ReviewDecision::waitingOn($sent['apps'], $publishPending, $owner),
      // Rendered when it was sent (ReviewDecisionApplier::renderSent()). The
      // stored field is editable on the node form, so it is filtered again.
      'email' => isset($sent['email']['html']) ? [
        'subject' => (string) ($sent['email']['subject'] ?? ''),
        'html' => Markup::create(Xss::filter((string) $sent['email']['html'], ['p', 'br', 'blockquote', 'ul', 'ol', 'li', 'strong', 'em', 'code', 'pre', 'a', 'h1', 'h2', 'h3', 'h4'])),
      ] : NULL,
      'to' => $owner,
      // Why "Update decision" is not offered, or NULL when it is.
      'update_blocker' => $this->applier->updateBlocker($node),
      // "Updated from Request changes on 6 October 2026" after an update.
      'updated_from' => $this->updatedFrom($sent['history']),
    ];
  }

  /**
   * "Updated from Request changes on 6 October 2026", from the decision an
   * update replaced; empty when the decision was never updated.
   *
   * @param array<int, array<string, mixed>> $history
   */
  protected function updatedFrom(array $history): string {
    $last = end($history);
    if (!is_array($last)) {
      return '';
    }
    $label = ReviewProgress::DECISION_LABELS[ReviewProgress::strictestDecision(array_values((array) ($last['apps'] ?? [])))] ?? '';
    return (string) $this->t('Updated from @d on @at', ['@d' => $label, '@at' => $this->formatDate((int) ($last['at'] ?? 0), 'datetime')]);
  }

  /**
   * The report's repo-level gate rows, each with its evidence rendered.
   *
   * @return array<mixed>
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
  protected function renderMarkdown(string $markdown): ?MarkupInterface {
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

  /**
   * The edit box for one finding: its fields, the note to the contributor,
   * and a Save beside it (saves the whole page, as Save draft does, and
   * returns to the finding).
   *
   * A tool finding takes a new severity, a dismissal or the reviewer's
   * wording, beside the tool's values (A1, FindingOverride); the note is the
   * reason for a new severity or a dismissal. A reviewer's own finding is
   * edited directly and can be deleted.
   *
   * @param array<string, mixed> $form
   * @param array<mixed> $finding
   */
  protected function addProseElement(array &$form, array $finding): void {
    $pid = $finding['pid'];
    $form['prose'][$pid] = [
      '#type' => 'textarea',
      '#title' => $this->t('Note to the contributor'),
      '#rows' => 2,
      '#default_value' => $finding['prose'],
    ];
    $form['save_finding'][$pid] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#name' => 'save_finding__' . $pid,
      '#finding_pid' => $pid,
      // The box's own action: the page's default button, so it reads above
      // Cancel without competing with Send decision, the one red button.
      '#attributes' => ['class' => ['btn', 'btn-save-finding']],
    ];
    if ($finding['source'] !== 'reviewer') {
      if (!FindingOverride::applies($finding)) {
        return;
      }
      $override = $finding['override'] ?? NULL;
      $form['prose'][$pid]['#description'] = $this->t('Required if you change the severity or dismiss it.');
      $form['override'][$pid] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['finding-fields']],
        'severity' => [
          '#type' => 'select',
          '#title' => $this->t('Severity'),
          '#options' => $this->severityOptions(),
          '#empty_option' => $this->t('@s (as reviewed)', ['@s' => ucfirst((string) $finding['tool_severity'])]),
          '#empty_value' => '',
          '#default_value' => $override['severity'] ?? '',
        ],
        'dismissed' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Not a finding'),
          '#default_value' => !empty($finding['dismissed']),
        ],
        'summary' => ['#type' => 'textarea', '#title' => $this->t('Summary'), '#rows' => 2, '#maxlength' => 512, '#default_value' => $finding['summary']],
        'evidence' => ['#type' => 'textfield', '#title' => $this->t('Evidence'), '#maxlength' => 512, '#default_value' => $finding['evidence']],
      ];
      return;
    }
    $form['edit_finding'][$pid] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['finding-fields']],
      'rule' => ['#type' => 'textfield', '#title' => $this->t('Rule'), '#size' => 10, '#maxlength' => 32, '#default_value' => $finding['rule']],
      'severity' => ['#type' => 'select', '#title' => $this->t('Severity'), '#options' => $this->severityOptions(), '#default_value' => $finding['severity']],
      'summary' => ['#type' => 'textarea', '#title' => $this->t('Summary'), '#rows' => 2, '#maxlength' => 512, '#default_value' => $finding['summary']],
      'evidence' => ['#type' => 'textfield', '#title' => $this->t('Evidence'), '#maxlength' => 512, '#default_value' => $finding['evidence'], '#attributes' => ['placeholder' => 'path/to/file:line — what is there']],
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
   *
   * @param array<string, mixed> $form
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
        '#attributes' => ['class' => ['btn']],
      ],
    ];
  }

  /**
   * The finding a reviewer typed into one block's add form, as paragraph
   * values, or NULL with the error set on the form.
   *
   * @param array<mixed> $input
   * @return array<mixed>
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

  /**
   * @param array<string, mixed> $form
   */
  public function validateAddFinding(array &$form, FormStateInterface $form_state): void {
    $target = $form_state->getTriggeringElement()['#finding_target'];
    $this->newFindingFromInput($form_state->getValue(['add_finding', $target]) ?? [], $target, $form_state);
  }

  /**
   * Submit handler for a block's "Add finding": a review_finding paragraph
   * marked as the reviewer's, appended to that app's verdict (or the repo's
   * findings), saved as a new revision of the review.
   *
   * @param array<string, mixed> $form
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
      'field_rvf_result' => 'fail',
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
   *
   * @param array<string, mixed> $form
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
   *
   * @param array<mixed> $values
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

  /**
   * Stores a reviewer's change to an automated finding.
   *
   * @param array<mixed> $values
   *
   * @return string|null
   *   The revision log line, or NULL when nothing changed.
   */
  protected function saveOverride(ParagraphInterface $finding, array $values): ?string {
    $pid = $finding->id();
    if (!isset($values['override'][$pid]) || ($finding->get('field_rvf_source')->value ?? '') === 'reviewer') {
      return NULL;
    }
    $change = FindingOverride::normalize($values['override'][$pid], self::toolValues($finding));
    if (FindingOverride::needsNote($change, (string) ($values['prose'][$pid] ?? ''))) {
      // validateForm() reported it.
      return NULL;
    }
    $line = FindingOverride::apply($finding, $change, (int) $this->currentUser->id(), $this->time->getRequestTime());
    if ($line !== NULL) {
      $finding->save();
    }
    return $line;
  }

  /**
   * @param array<string, mixed> $form
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    foreach ($form_state->getValue('edit_finding') ?? [] as $pid => $input) {
      if (trim((string) ($input['summary'] ?? '')) === '' || trim((string) ($input['rule'] ?? '')) === '') {
        $form_state->setErrorByName('edit_finding][' . $pid . '][summary', $this->t('A finding needs a rule and a summary.'));
      }
    }
    $overrides = $form_state->getValue('override') ?? [];
    if ($overrides !== []) {
      $tool = [];
      $findings = $this->node->get('field_arv_repo_findings')->referencedEntities();
      foreach ($this->node->get('field_arv_verdicts')->referencedEntities() as $verdict) {
        $findings = array_merge($findings, $verdict->get('field_rvv_findings')->referencedEntities());
      }
      foreach ($findings as $finding) {
        $tool[$finding->id()] = self::toolValues($finding);
      }
      $notes = $form_state->getValue('prose') ?? [];
      foreach ($overrides as $pid => $input) {
        if (!isset($tool[$pid])) {
          continue;
        }
        if (FindingOverride::needsNote(FindingOverride::normalize((array) $input, $tool[$pid]), (string) ($notes[$pid] ?? ''))) {
          $form_state->setErrorByName('prose][' . $pid, $this->t('Add a note saying why: it is required to change the severity of an automated finding or dismiss it.'));
        }
      }
    }
  }

  /**
   * The tool's own values on a finding, as FindingOverride compares them.
   *
   * @return array{severity: string, summary: string, evidence: string}
   */
  protected static function toolValues(ParagraphInterface $finding): array {
    return [
      'severity' => (string) ($finding->get('field_rvf_severity')->value ?? ''),
      'summary' => (string) ($finding->get('field_rvf_summary')->value ?? ''),
      'evidence' => (string) ($finding->get('field_rvf_evidence')->value ?? ''),
    ];
  }

  /**
   * @return array<mixed>
   */
  protected function severityOptions(): array {
    return [
      'critical' => $this->t('Critical'),
      'high' => $this->t('High'),
      'medium' => $this->t('Medium'),
      'low' => $this->t('Low'),
      'info' => $this->t('Info'),
    ];
  }

  /**
   * @param array<mixed> $values
   */
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
  protected function setText(FieldableEntityInterface $entity, string $field, string $value): void {
    if (!$entity->hasField($field)) {
      return;
    }
    $format = $entity->get($field)->format ?? 'plain_text';
    $entity->set($field, ['value' => $value, 'format' => $format]);
  }

  /**
   * An axis's levels in the words people see (appverse-planning#12).
   *
   * @return array<string, string>
   *   Stored level => its label for this axis.
   */
  protected function levelOptions(string $axis): array {
    // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
    return array_map(fn (string $label): string => (string) $this->t($label), ReviewSignals::LEVEL_LABELS[$axis] ?? []);
  }

  /**
   * @return array<mixed>
   */
  protected function conclusionOptions(): array {
    return [
      'accept' => $this->t('Accept'),
      'accept_with_suggestions' => $this->t('Accept with suggestions'),
      'request_changes' => $this->t('Request changes'),
      'reject' => $this->t('Reject'),
    ];
  }

  /**
   * A date in the one format this UI uses.
   *
   * Drupal's own short and medium render 10/07/2026, which reads as 10 July
   * outside the US; the page also had 10-07-26 and "7 October 2026" in
   * places. "Oct 7, 2026" cannot be misread whatever order a reader expects
   * (appverse-planning#53).
   *
   * @param string $type
   *   'date' for the date alone, 'datetime' for the date and time.
   */
  protected function formatDate(int $ts, string $type): string {
    if (!$ts) {
      return '';
    }
    return $this->dateFormatter->format($ts, 'custom', $type === 'date' ? self::DATE : self::DATE_TIME);
  }

}
