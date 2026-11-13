<?php

namespace Drupal\ood_software\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AnnounceCommand;
use Drupal\Core\Ajax\FocusFirstCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\Element\StatusMessages;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Controller\AppverseHubController;
use Drupal\ood_software\Service\ContributorReviewView;
use Drupal\ood_software\Service\DuplicateCheck;
use Drupal\ood_software\Service\FindingOverride;
use Drupal\ood_software\Service\RepoMemberApps;
use Drupal\ood_software\Service\RepoProgress;
use Drupal\ood_software\Service\ReviewAssignment;
use Drupal\ood_software\Service\ReviewDecision;
use Drupal\ood_software\Service\ReviewDecisionApplier;
use Drupal\ood_software\Service\ReviewFloors;
use Drupal\ood_software\Service\ReviewPageData;
use Drupal\ood_software\Service\ReviewProgress;
use Drupal\ood_software\Service\ReleaseWatcher;
use Drupal\ood_software\Service\ReviewSignals;
use Drupal\ood_software\Service\ReviewWorklist;
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
final class ReviewPageForm extends FormBase implements TrustedCallbackInterface {

  /**
   * The month-name date formats, unambiguous in any locale.
   */
  const DATE = 'M j, Y';
  // 12-hour: "Oct 2 2026 3:23 PM" rather than 15:23.
  const DATE_TIME = 'M j, Y g:i A';

  const REVIEWER_PERMISSION = 'administer appverse content';

  /**
   * Form state one request's handlers set for the next step of that request.
   *
   * Form state storage is cached with the form after an AJAX rebuild, so
   * these are cleared when each request's handling starts.
   */
  const REQUEST_STATE = [
    'arv_stamp',
    'arv_announce',
    'arv_focus',
    'arv_stale',
    'arv_confirm',
    'arv_deleted_from',
    'arv_duplicate_lines',
    'arv_duplicate_changed',
  ];

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
    protected RepoMemberApps $memberApps,
    protected RendererInterface $renderer,
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
      $container->get('ood_software.repo_member_apps'),
      $container->get('renderer'),
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
    // Always the review as it is now. A rebuild after an AJAX save passes the
    // node the route loaded for the first request, which is out of date.
    $fresh = $this->entityTypeManager->getStorage('node')->loadUnchanged($node->id());
    if (!$fresh instanceof NodeInterface) {
      throw new NotFoundHttpException();
    }
    $node = $fresh;
    $this->node = $node;
    $isReviewer = $this->currentUser->hasPermission(self::REVIEWER_PERMISSION);
    $repo = $node->get('field_arv_repo')->entity;
    $isContributor = $repo instanceof NodeInterface
      && $this->currentUser->isAuthenticated()
      && (int) $repo->getOwnerId() === (int) $this->currentUser->id();
    $mode = self::viewModeFor($isReviewer, $isContributor, $this->getRequest()->query->get('view'));
    $reviewerView = $mode === self::MODE_EDIT;

    // The duplicate check is the reviewer's alone.
    $page = $this->buildPage($node, $reviewerView);
    // Once the decision is sent the review is a record of it: the reviewer
    // keeps the internal notes, the assignee and the actions, and the rest is
    // read-only (the decision-sent view, appverse-planning#51, #56).
    $canEdit = $reviewerView && !$page['decision']['sent'];
    // ?update=1 reopens the decisions and the response of a sent decision,
    // while it can still be updated (appverse-planning#56).
    $updating = $reviewerView && $page['decision']['sent'] && $this->getRequest()->query->get('update')
      && $page['decision']['update_blocker'] === NULL;
    $form['#theme'] = match ($mode) {
      self::MODE_PUBLIC => 'appverse_review_summary',
      self::MODE_CONTRIBUTOR => 'appverse_review_contributor',
      default => 'appverse_review_form',
    };
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
      if ($mode === self::MODE_CONTRIBUTOR) {
        // Whether the decision is sent comes from the same place the page's
        // decision does, so the two cannot disagree.
        $view = ContributorReviewView::build($page, (bool) $page['decision']['sent']);
        $steps['contributor'] = $this->contributorSteps($steps['contributor'], $view['state']);
        $view['next_step'] = $view['state'] === 'decided'
          ? self::withoutStatusSentence(ReviewProgress::contributorSentence($steps['contributor']))
          : ReviewProgress::contributorSentence($steps['contributor']);
        $view['decision_apps'] = $this->contributorDecisionApps($page);
        // Names the band's tint: the strictest decision across the apps.
        $view['decision_key'] = ReviewProgress::strictestDecision(array_column($view['decision_apps'], 'key'));
        $view['resubmit'] = $this->contributorResubmit($view, $repo);
        if ($view['resubmit'] !== NULL) {
          // The form's CSRF token is per session.
          $contributorCache = TRUE;
        }
        $form['#contributor_view'] = $view;
      }
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
    // Recheck and the run status line are the reviewer's alone, so a
    // contributor's or visitor's page carries neither, nor any polling.
    $rerun = $this->rerunState($node, $repo instanceof NodeInterface ? $repo : NULL);
    if ($reviewerView && $repo instanceof NodeInterface) {
      if ($rerun['in_flight']) {
        // Read by the review-status poller (D8-2931).
        $form['#attached']['library'][] = 'ood_software/review_status';
        $form['#attached']['drupalSettings']['oodReviewStatus'] = [
          'url' => Url::fromRoute('ood_software.repo_review_status', ['node' => $repo->id()], ['query' => ['check' => 'github']])->toString(),
          'review' => (int) $node->id(),
          // The poller offers "New report ready" only for a review newer than
          // this one, so a failed run never points at a report already here.
          'latestAtLoad' => $rerun['latest_review'],
          'inFlight' => TRUE,
        ];
      }
    }
    else {
      unset($form['#page']['rerun'], $form['#page']['run_status']);
    }
    $form['#tree'] = TRUE;
    // The page differs by permission, by whether the viewer owns the repo,
    // and by ?view=public; a cached copy must never cross those lines.
    $form['#cache']['contexts'] = ['user.permissions', 'user', 'url.query_args:view'];
    if (!empty($contributorCache)) {
      $form['#cache']['contexts'][] = 'session';
    }
    // node_list: the history bar and the superseded banner depend on the
    // repo's other reviews, so a newly seeded review must invalidate this one.
    $form['#cache']['tags'] = array_merge($node->getCacheTags(), $repo instanceof NodeInterface ? $repo->getCacheTags() : [], ['node_list']);
    if ($reviewerView) {
      // The in-flight state ends by itself when the stale timeout passes, with
      // no save to invalidate the tags above. Bound the page's life so the
      // Rerun button comes back without a cache clear.
      $form['#cache']['max-age'] = $rerun['max_age'];
    }

    if (!$reviewerView) {
      return $form;
    }
    // What the findings' Save, Add and Delete replace in place.
    $form['#pre_render'][] = [self::class, 'showFieldErrors'];
    $form['#prefix'] = '<div id="arv-review-form">';
    $form['#suffix'] = '</div>';
    // The revision this page was built from: validateForm() refuses a save
    // from a page the review has moved on from in another window.
    $form['arv_revision'] = [
      '#type' => 'hidden',
      '#default_value' => (string) $node->getRevisionId(),
    ];
    // What the AJAX save just did, shown where it happened (in the finding's
    // row, or the list's add form for a delete). Only on the rebuild that
    // follows the save; the next action takes it away.
    $form['#arv_confirm'] = $form_state->get('arv_confirm');
    // A Confirm refused because the check changed (validatePage()).
    $form['#arv_duplicate_changed'] = $form_state->get('arv_duplicate_changed');
    // The reviewer's worklists: the repo's, then one per app, blocking first.
    $form['#worklist'] = ReviewWorklist::build($page, json_decode((string) ($node->get('field_arv_repo_criteria')->value ?? ''), TRUE) ?: []);
    if ($canEdit) {
      $this->addContentElements($form, $page, $node);
    }
    elseif ($updating) {
      $this->addDecisionElements($form, $page, $node);
      $this->addDuplicateElements($form, $page);
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
          'title' => $this->t('Re-submit the repo for its contributor, as their Re-submit button does: a new automated check on the latest commit, and round @n.', ['@n' => $this->repoProgress->facts($repo)['round'] + 1]),
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
    $this->addDuplicateElements($form, $page);
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
      foreach ($app['blocks'] as $block) {
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
    foreach ($page['repo_blocks'] as $block) {
      foreach ($block['groups'] as $group) {
        foreach ($group['findings'] as $finding) {
          $this->addProseElement($form, $finding);
        }
      }
      foreach ($block['dismissed'] ?? [] as $finding) {
        $this->addProseElement($form, $finding);
      }
    }
    // One add form per list, which picks the section.
    foreach ($form['#worklist'] ?? [] as $list) {
      $this->addNewFindingElements($form, $list);
    }
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
   * Each app's duplicate check (A4): on the editable page, and while a sent
   * decision is updated, since changing it to Accept needs one.
   *
   * Nothing is filled in for the reviewer: the radios and the note start as
   * stored. When the check found a Software entry no other published app
   * implements (clear), Confirm records "No other app" in one press.
   *
   * @param array<string, mixed> $form
   * @param array<string, mixed> $page
   */
  protected function addDuplicateElements(array &$form, array $page): void {
    $updating = !empty($form['#updating']);
    foreach ($page['apps'] as $app) {
      $pid = $app['pid'];
      $basis = $app['duplicate']['basis'];
      $form['duplicate'][$pid] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['duplicate-check']],
        'outcome' => [
          '#type' => 'radios',
          '#title' => $this->t('Duplicate check'),
          '#options' => $this->duplicateOptions(),
          '#default_value' => $app['duplicate']['outcome'],
          '#description' => $this->t('Compare the published apps for this software. Required to accept the app; it does not limit the decision.'),
        ],
        'note' => [
          '#type' => 'textarea',
          '#title' => $this->t('Rationale'),
          '#rows' => 2,
          '#default_value' => $app['duplicate']['note'],
          '#description' => match (TRUE) {
            !empty($app['catalog']['backed']) => $this->t('Required for a different approach or a duplicate: which apps you compared, and how this one differs or which one it duplicates.'),
            $basis['mode'] === 'compare' => $this->t('Required: which apps you compared, and how this one differs or which one it duplicates.'),
            $basis['mode'] === 'suggested' => $this->t("Required: the software is the review tool's suggestion, so say whether it is right and which apps you compared. If it is wrong, assign the right Software entry on the app."),
            default => $this->t('Required: no Software entry matched, so say which apps you compared by name, and how this one differs or which one it duplicates.'),
          },
        ],
      ];
      // A Confirm refused because the check changed: its message sits with
      // the outcome, as a refused save's error does (showFieldErrors()).
      if ((string) ($form['#arv_duplicate_changed'] ?? '') === (string) $pid) {
        $form['duplicate'][$pid]['outcome']['#errors'] = $this->duplicateChangedMessage();
      }
      if ($basis['mode'] === 'clear' && empty($app['duplicate']['outcome'])) {
        $form['duplicate'][$pid]['confirm'] = [
          '#type' => 'submit',
          '#value' => $this->t('Confirm'),
          '#name' => 'confirm_duplicate__' . $pid,
          '#duplicate_pid' => $pid,
          '#submit' => $updating
            ? ['::confirmDuplicate', '::saveUpdate', '::backToDuplicate']
            : ['::confirmDuplicate', '::submitForm'],
          '#ajax' => $this->ajaxSettings(),
          '#attributes' => [
            'class' => ['btn', 'btn-confirm-duplicate'],
            'title' => $this->t('Record "No other app for this software"'),
          ],
        ];
      }
    }
  }

  /**
   * First submit handler for a duplicate check's Confirm.
   *
   * Records "No other app" unless the reviewer picked an outcome, and the
   * check's line as the note unless they wrote one. Both are set in the
   * input too, so the rebuilt page shows them and the next save keeps them.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function confirmDuplicate(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement() ?? [];
    $pid = (string) ($trigger['#duplicate_pid'] ?? '');
    if ($pid === '') {
      return;
    }
    $posted = (array) ($form_state->getValue(['duplicate', $pid]) ?? []);
    if ((string) ($posted['outcome'] ?? '') === '') {
      self::setInputValue($form_state, ['duplicate', $pid, 'outcome'], 'none');
    }
    if (trim((string) ($posted['note'] ?? '')) === '') {
      // The line from the review as it is now (validatePage()), never the
      // cached form's. It is plain text, and so is the note.
      $line = (string) ($form_state->get('arv_duplicate_lines')[$pid]['line'] ?? '');
      self::setInputValue($form_state, ['duplicate', $pid, 'note'], 'Confirmed: ' . $line);
    }
    $form_state->set('arv_stamp', sprintf('Review page: %s recorded the duplicate check', $this->currentUser->getDisplayName()));
    $form_state->set('arv_announce', (string) $this->t('Duplicate check recorded.'));
    $form_state->set('arv_focus', 'duplicate-' . $pid);
  }

  /**
   * Last submit handler for Confirm while a sent decision is updated.
   *
   * Back on the duplicate check, still updating: not the send page that
   * "Send updated decision…" goes on to.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function backToDuplicate(array &$form, FormStateInterface $form_state): void {
    $focus = (string) $form_state->get('arv_focus');
    if ($this->isAjax()) {
      // As submitForm() does: the rebuilt page carries the new revision.
      $form_state->setRebuild();
      self::setInputValue($form_state, ['arv_revision'], (string) $this->node->getRevisionId());
      $form_state->set('arv_confirm', [
        'finding' => NULL,
        'list' => NULL,
        'text' => $form_state->get('arv_announce'),
      ]);
      return;
    }
    $this->messenger()->addStatus($this->t('Duplicate check recorded.'));
    $form_state->setRedirect('ood_software.review_page', ['node' => $this->node->id()], [
      'query' => ['update' => 1],
      'fragment' => $focus,
    ]);
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
      $changed = FALSE;
      if (array_key_exists($pid, $values['conclusion'] ?? [])) {
        $verdict->set('field_rvv_conclusion', $values['conclusion'][$pid] !== '' ? $values['conclusion'][$pid] : NULL);
        $changed = TRUE;
      }
      // Updating to Accept needs the duplicate check (A4).
      if (isset($values['duplicate'][$pid]) && $verdict->hasField('field_rvv_duplicate')) {
        $outcome = (string) ($values['duplicate'][$pid]['outcome'] ?? '');
        $verdict->set('field_rvv_duplicate', isset(DuplicateCheck::OUTCOMES[$outcome]) ? $outcome : NULL);
        $this->setText($verdict, 'field_rvv_duplicate_note', (string) ($values['duplicate'][$pid]['note'] ?? ''));
        $changed = TRUE;
      }
      if ($changed) {
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
    $duplicates = [];
    foreach ($page['apps'] as $app) {
      $decisions[(string) $app['pid']] = $app['conclusion'] ?? NULL;
      $duplicates[(string) $app['pid']] = $app['duplicate']['outcome'] ?? NULL;
    }
    $blocker = ReviewDecision::sendBlocker($decisions, (string) $page['response'])
      ?? (DuplicateCheck::problems($decisions, $duplicates) !== [] ? $this->t('Record the duplicate check for each app you accept first.') : NULL);
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
        'duplicate' => (string) $this->t('Record the duplicate check for each app you accept first.'),
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
    // An AJAX save leaves no message for the next page to show.
    $messages = $this->messenger()->messagesByType('status');
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
        if (isset($values['duplicate'][$pid]) && $verdict->hasField('field_rvv_duplicate')) {
          $outcome = (string) ($values['duplicate'][$pid]['outcome'] ?? '');
          $verdict->set('field_rvv_duplicate', isset(DuplicateCheck::OUTCOMES[$outcome]) ? $outcome : NULL);
          $this->setText($verdict, 'field_rvv_duplicate_note', (string) ($values['duplicate'][$pid]['note'] ?? ''));
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
    // An add or a delete names itself; it is the same one revision.
    $log = $form_state->get('arv_stamp') ?? 'Review page: saved by ' . $this->currentUser->getDisplayName();
    $this->stampRevision($node, $log . ($changes !== [] ? '; ' . implode('; ', $changes) : ''));
    $node->save();
    // The send button saves, then goes on to the email preview.
    if (($form_state->getTriggeringElement()['#name'] ?? '') === 'send_decision') {
      $this->messenger()->addStatus($this->t('Your edits are saved. Nothing is sent until you confirm.'));
      $form_state->setRedirect('ood_software.review_decision', ['node' => $node->id()]);
      return;
    }
    // Back to what the reviewer was working on: what a handler put in
    // arv_focus, else a finding's own Save returns to that finding.
    $pid = $form_state->getTriggeringElement()['#finding_pid'] ?? NULL;
    $focus = $form_state->get('arv_focus') ?? ($pid ? 'finding-' . $pid : NULL);
    if ($this->isAjax()) {
      // The page is rebuilt from what was posted, so everything typed stays.
      // What the save itself changed is brought into line: the note is saved
      // (posting it again would add it twice), the first save assigned the
      // saver, and the review has a new revision.
      $form_state->setRebuild();
      self::setInputValue($form_state, ['new_note'], '');
      if ($repo instanceof NodeInterface && array_key_exists('assignee', $form)) {
        // assign() saves its own copy of the repo.
        $saved = $this->entityTypeManager->getStorage('node')->loadUnchanged($repo->id());
        $assignee = $saved instanceof NodeInterface ? $this->reviewAssignment->assignee($saved) : NULL;
        self::setInputValue($form_state, ['assignee'], $assignee ? (string) $assignee->id() : '');
      }
      self::setInputValue($form_state, ['arv_revision'], (string) $node->getRevisionId());
      $form_state->set('arv_announce', $form_state->get('arv_announce') ?? (string) $this->t('Saved.'));
      $form_state->set('arv_focus', $focus);
      // A delete is confirmed at its list's add form, anything else in the
      // finding's row.
      $list = $form_state->get('arv_deleted_from');
      $finding = is_string($focus) && str_starts_with($focus, 'finding-') ? substr($focus, strlen('finding-')) : NULL;
      $form_state->set('arv_confirm', [
        'finding' => $list === NULL ? $finding : NULL,
        'list' => $list,
        'text' => $form_state->get('arv_announce'),
      ]);
      $this->messenger()->deleteByType('status');
      foreach ($messages as $message) {
        $this->messenger()->addStatus($message);
      }
      return;
    }
    $this->messenger()->addStatus($this->t('Review saved.'));
    $form_state->setRedirect('ood_software.review_page', ['node' => $node->id()], $focus ? ['fragment' => $focus] : []);
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['showFieldErrors'];
  }

  /**
   * Shows a refused save's error beside the field it names.
   *
   * Inline form errors is off, so the findings', add forms' and duplicate
   * checks' fields carry their message here: under the field, tied to it
   * with aria-describedby. The messages at the top of the form stay.
   *
   * @param array<string, mixed> $form
   *   The form, after validation set each field's errors.
   *
   * @return array<string, mixed>
   *   The form with each errored field's message after it.
   */
  public static function showFieldErrors(array $form): array {
    foreach (['prose', 'override', 'edit_finding', 'add_finding', 'duplicate'] as $key) {
      if (isset($form[$key]) && is_array($form[$key])) {
        self::markFieldErrors($form[$key]);
      }
    }
    return $form;
  }

  /**
   * Puts each errored field's message after it, below $tree.
   *
   * @param array<string, mixed> $tree
   *   A group of elements, changed in place.
   */
  protected static function markFieldErrors(array &$tree): void {
    foreach (Element::children($tree) as $name) {
      $element = &$tree[$name];
      $message = trim(strip_tags((string) ($element['#errors'] ?? '')));
      if ($message !== '' && !empty($element['#id']) && ($element['#type'] ?? '') !== 'container') {
        $id = $element['#id'] . '--error';
        $element['#suffix'] = ($element['#suffix'] ?? '') . '<p class="arv-field-error" id="' . Html::escape($id) . '">' . Html::escape(Html::decodeEntities($message)) . '</p>';
        $element['#attributes']['aria-describedby'] = trim(($element['#attributes']['aria-describedby'] ?? '') . ' ' . $id);
      }
      self::markFieldErrors($element);
    }
  }

  /**
   * Whether the request is one of the page's AJAX saves.
   */
  public function isAjax(): bool {
    return $this->getRequest()->query->has(FormBuilderInterface::AJAX_FORM_REQUEST);
  }

  /**
   * Sets a value in both the form's values and the user input.
   *
   * A rebuild shows the user input, not #default_value, so a value a save
   * changed on the server is changed in both.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array<int, string|int> $parents
   *   The element's #parents.
   * @param mixed $value
   *   The value.
   */
  public static function setInputValue(FormStateInterface $form_state, array $parents, mixed $value): void {
    $form_state->setValue($parents, $value);
    $input = &$form_state->getUserInput();
    NestedArray::setValue($input, $parents, $value);
  }

  /**
   * Drops what was posted for an element, so a rebuild shows its default.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array<int, string|int> $parents
   *   The element's #parents.
   */
  protected static function unsetInputValue(FormStateInterface $form_state, array $parents): void {
    $input = &$form_state->getUserInput();
    NestedArray::unsetValue($input, $parents);
  }

  /**
   * The #ajax of the findings' Save, Add and Delete.
   *
   * @return array<string, mixed>
   *   The settings.
   */
  protected function ajaxSettings(): array {
    return [
      'callback' => '::ajaxRefresh',
      'wrapper' => 'arv-review-form',
      'progress' => ['type' => 'throbber', 'message' => $this->t('Saving…')],
    ];
  }

  /**
   * AJAX callback for the findings' Save, Add and Delete.
   *
   * Replaces the whole page's form, so every list, count and button reflects
   * the save, says what happened to a screen reader, and puts focus back on
   * what the reviewer was working on. A refused save shows its messages at
   * the top of the form and focuses the field to fix.
   *
   * @param array<string, mixed> $form
   *   The form, rebuilt after a save or as posted after an error.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function ajaxRefresh(array &$form, FormStateInterface $form_state): AjaxResponse {
    $errors = $form_state->getErrors();
    $stale = (bool) $form_state->get('arv_stale');
    $focus = (string) ($form_state->get('arv_focus') ?: 'arv-review-form');
    $build = $form;
    if ($errors !== [] || $stale) {
      $first = $errors !== [] ? reset($errors) : $this->staleMessage();
      $announce = (string) $this->t('Not saved: @error', ['@error' => Html::decodeEntities(strip_tags((string) $first))]);
      $priority = AnnounceCommand::PRIORITY_ASSERTIVE;
      // The messages go inside the wrapper, so the next replace takes them
      // away again.
      // Nothing was saved, so no confirmation from an earlier save.
      unset($build['#prefix'], $build['#suffix'], $build['#arv_confirm']);
      $build = [
        '#prefix' => $form['#prefix'] ?? '',
        '#suffix' => $form['#suffix'] ?? '',
        'messages' => $this->quietMessages(),
        'form' => $build,
      ];
      if (!$stale && ($field = $this->errorField($form, $form_state)) !== NULL) {
        $focus = $field;
      }
    }
    else {
      $announce = (string) ($form_state->get('arv_announce') ?? $this->t('Saved.'));
      $priority = $form_state->get('arv_duplicate_changed') ? AnnounceCommand::PRIORITY_ASSERTIVE : AnnounceCommand::PRIORITY_POLITE;
      // A finding's own Save leaves focus on its Edit control, not on the
      // first link in the row.
      $trigger = $form_state->getTriggeringElement() ?? [];
      if (isset($trigger['#finding_pid']) && $focus === 'finding-' . $trigger['#finding_pid']) {
        $focus .= ' .expand-hint';
      }
    }
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#arv-review-form', $build));
    $response->addCommand(new AnnounceCommand($announce, $priority));
    $response->addCommand(new FocusFirstCommand('#' . $focus));
    return $response;
  }

  /**
   * The page's messages with a group role instead of the alert role.
   *
   * The announcement already speaks the error assertively, and the theme's
   * role="alert" around an error would speak it again. A group keeps the
   * theme's aria-label valid.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  protected function quietMessages(): array {
    // Built directly: the element's lazy builder leaves only a placeholder.
    $messages = StatusMessages::renderMessages(NULL);
    $html = (string) $this->renderer->renderInIsolation($messages);
    return ['#markup' => Markup::create(preg_replace('/\brole=(["\'])alert\1/', 'role="group"', $html))];
  }

  /**
   * The id of the field to focus after a refused save.
   *
   * The first field with an error inside what was pressed (the finding whose
   * Save or Delete it was, or the add form), else the first errored field
   * anywhere in the form, so a save blocked by another finding lands on it.
   *
   * @param array<string, mixed> $form
   *   The processed form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return string|null
   *   The element id, or NULL when no errored field has one.
   */
  protected function errorField(array $form, FormStateInterface $form_state): ?string {
    $trigger = $form_state->getTriggeringElement() ?? [];
    $pid = isset($trigger['#finding_pid']) ? (string) $trigger['#finding_pid'] : NULL;
    $list = isset($trigger['#finding_list']) ? (string) $trigger['#finding_list'] : NULL;
    $anywhere = NULL;
    foreach (array_keys($form_state->getErrors()) as $name) {
      $parents = explode('][', (string) $name);
      $inside = ($pid !== NULL && in_array($parents[0], ['prose', 'override', 'edit_finding'], TRUE) && ($parents[1] ?? NULL) === $pid)
        || ($list !== NULL && $parents[0] === 'add_finding' && ($parents[1] ?? NULL) === $list);
      $element = $inside ? NestedArray::getValue($form, $parents) : NULL;
      if (is_array($element) && !empty($element['#id'])) {
        return (string) $element['#id'];
      }
      $any = NestedArray::getValue($form, $parents);
      if ($anywhere === NULL && is_array($any) && !empty($any['#id'])) {
        $anywhere = (string) $any['#id'];
      }
    }
    return $anywhere;
  }

  /**
   * What a Confirm on a check that changed since the page loaded is told.
   */
  protected function duplicateChangedMessage(): MarkupInterface {
    return $this->t('The duplicate check changed since this page loaded. Compare the apps listed.');
  }

  /**
   * What a save from an out-of-date window is told.
   */
  protected function staleMessage(): MarkupInterface {
    return $this->t('This review changed in another window. Reload the page to see the latest version.');
  }

  /**
   * Whether this review can be rerun, and the repo's run state.
   *
   * @return array{eligible: bool, show: bool, in_flight: bool, status: ?string, max_age: int, latest_review: ?int}
   *   Eligible when this is the repo's newest review and it is undecided.
   *   Show adds that no run is in flight. Max age is the seconds until an
   *   in-flight run times out (Cache::PERMANENT when none is in flight).
   *   Latest review is the repo's newest review nid right now.
   */
  public function rerunState(NodeInterface $review, ?NodeInterface $repo): array {
    $state = [
      'eligible' => FALSE,
      'show' => FALSE,
      'in_flight' => FALSE,
      'status' => NULL,
      'max_age' => Cache::PERMANENT,
      'latest_review' => NULL,
    ];
    if ($repo === NULL) {
      return $state;
    }
    $latest = $this->reviews->latestRevision($repo);
    $state['in_flight'] = $this->reviews->isRunInFlight($latest);
    if ($latest->hasField('field_review_status') && !$latest->get('field_review_status')->isEmpty()) {
      $state['status'] = (string) $latest->get('field_review_status')->value;
    }
    if ($state['in_flight']) {
      $age = $this->time->getCurrentTime() - (int) $latest->get('field_review_dispatched_at')->value;
      $state['max_age'] = max(1, AppverseReviewService::STALE_TIMEOUT_SECONDS - $age);
    }
    $state['latest_review'] = $this->reviews->latestReviewId($repo);
    $state['eligible'] = $state['latest_review'] === (int) $review->id()
      && !ReleaseWatcher::isDecided($review);
    $state['show'] = $state['eligible'] && !$state['in_flight'];
    return $state;
  }

  /**
   * The model that wrote a review, from its run metadata.
   *
   * @return array{label: string, full: string}|null
   *   The label is the last path segment of the model id, and full is the id
   *   as stored. NULL when no model is recorded.
   */
  public static function modelLabel(?string $runMetaJson): ?array {
    $meta = json_decode((string) $runMetaJson, TRUE);
    $model = is_array($meta) && isset($meta['model']) && is_string($meta['model']) ? $meta['model'] : '';
    if ($model === '') {
      return NULL;
    }
    $segments = explode('/', $model);
    return ['label' => end($segments), 'full' => $model];
  }

  /**
   * Whether the repo's maintainer is worth naming beside the contributor.
   *
   * The maintainer is left out when it is empty or only repeats the
   * contributor's display name or the GitHub owner of the repo, ignoring case,
   * spaces, hyphens and underscores.
   *
   * @param string $maintainer
   *   The repo's maintainer name, possibly empty.
   * @param array<string, mixed>|null $contributor
   *   The contributor link (name, url), or NULL.
   * @param string $repoUrl
   *   The repo's GitHub URL.
   */
  public static function maintainerDiffers(string $maintainer, ?array $contributor, string $repoUrl): bool {
    $normalize = static fn (string $v): string => strtolower(str_replace([' ', '-', '_'], '', $v));
    $maintainer = $normalize($maintainer);
    if ($maintainer === '') {
      return FALSE;
    }
    $owner = explode('/', trim((string) parse_url($repoUrl, PHP_URL_PATH), '/'))[0];
    $known = [$normalize($owner), $normalize((string) ($contributor['name'] ?? ''))];
    return !in_array($maintainer, array_filter($known), TRUE);
  }

  /**
   * Whether this review was written by a different model than the last one.
   *
   * TRUE only when both name a model and the labels differ.
   */
  public static function modelChanged(?string $runMetaJson, ?string $previousRunMetaJson): bool {
    $current = self::modelLabel($runMetaJson);
    $previous = self::modelLabel($previousRunMetaJson);
    return $current !== NULL && $previous !== NULL && $current['label'] !== $previous['label'];
  }

  /**
   * Links to the repo's member apps, published or not.
   *
   * @return list<array{url: string, label: string}>
   *   The apps' canonical URLs, titled by the app.
   */
  protected function appLinks(NodeInterface $repo): array {
    $ids = $this->memberApps->queryMemberAppIds($repo);
    sort($ids);
    $links = [];
    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($ids) as $app) {
      $links[] = ['url' => $app->toUrl()->toString(), 'label' => (string) $app->label()];
    }
    return $links;
  }

  /**
   * Everything the template needs, as plain arrays.
   *
   * @return array<mixed>
   */
  protected function buildPage(NodeInterface $node, bool $duplicateCheck = TRUE): array {
    $repo = $node->get('field_arv_repo')->entity;
    $repo = $repo instanceof NodeInterface ? $repo : NULL;
    $sha = (string) ($node->get('field_arv_sha')->value ?? '');
    $reviewedAt = (int) ($node->get('field_arv_reviewed_at')->value ?? 0);
    $model = self::modelLabel((string) ($node->get('field_arv_run_meta')->value ?? ''));
    $state = (string) ($node->get('moderation_state')->value ?? 'draft');

    // Evidence links point at the repo on GitHub at the reviewed commit.
    $this->linkRepoUrl = $repo && $repo->hasField('field_repo_url') && !$repo->get('field_repo_url')->isEmpty()
      ? (string) ($repo->get('field_repo_url')->first()->getValue()['uri'] ?? '') : '';
    $this->linkSha = $sha;

    $rerun = $this->rerunState($node, $repo);
    $previous = $this->previousReviews($node, $repo);
    $historyList = $this->reviewHistory($repo);
    $history = ReviewPageData::historyPosition($historyList, (int) $node->id());
    // Where the review this one is compared with sits in the history.
    $comparedPosition = NULL;
    if ($previous !== []) {
      foreach (array_values($historyList) as $i => $item) {
        if ((int) $item['nid'] === $previous[0]['nid']) {
          $comparedPosition = $i + 1;
        }
      }
    }

    // Once a decision is sent, the page shows what was sent, not what the
    // fields hold now (appverse-planning#51).
    $sent = ReviewDecisionApplier::sent($node);
    // Once per page, for every app's same-software query; only the
    // reviewer's page compares the apps.
    $unpublishedRepos = $duplicateCheck ? $this->unpublishedRepoIds() : [];
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
      // The app's catalog checks as rows (A4b, schema 1.5); none on a review
      // from an older tool, which keeps the report's Catalog checks text.
      $checks = $verdict->hasField('field_rvv_catalog') ? (json_decode((string) ($verdict->get('field_rvv_catalog')->value ?? ''), TRUE) ?: []) : [];
      // What the duplicate check compares against: the software staff
      // assigned on the app, else what the checks found.
      $basis = ReviewPageData::duplicateBasis($duplicateCheck ? $this->assignedSoftware($verdict, $repo, $unpublishedRepos) : NULL, $checks);
      $app['catalog'] = [
        'rows' => ReviewPageData::catalogRows($checks),
        // "No other app" stands without a rationale only when the check found
        // a Software entry no other published app implements. An app with
        // nothing to go on is settled below.
        'backed' => $basis['mode'] === 'clear',
      ];
      // The reviewer's duplicate check (A4, DuplicateCheck).
      $outcome = $verdict->hasField('field_rvv_duplicate') ? $verdict->get('field_rvv_duplicate')->value : NULL;
      $app['duplicate'] = [
        'outcome' => $outcome,
        'label' => (string) ($this->duplicateOptions()[$outcome ?? ''] ?? ''),
        'note' => $verdict->hasField('field_rvv_duplicate_note') ? (string) ($verdict->get('field_rvv_duplicate_note')->value ?? '') : '',
        'basis' => $basis,
      ];
      // Whether the worklist carries the check rather than the slot under
      // the app's ratings.
      $app['duplicate']['in_worklist'] = ReviewWorklist::duplicateIsItem($app);
      $apps[] = $app;
    }

    $catalogSource = $node->hasField('field_arv_catalog') ? (json_decode((string) ($node->get('field_arv_catalog')->value ?? ''), TRUE) ?: []) : [];
    $withRows = count(array_filter($apps, static fn (array $a): bool => $a['catalog']['rows'] !== []));
    // The report's text stands unless every app has rows: an app whose
    // checks did not attach (an app id spelled differently) still needs it.
    $catalogRows = $apps !== [] && $withRows === count($apps);
    // An app with nothing to go on (legacy) is backed only on a review from
    // an older tool, which carries no catalog data for any app. On a review
    // that does, its "No other app" needs a rationale.
    if ($catalogSource === [] && $withRows === 0) {
      foreach ($apps as &$a) {
        if ($a['duplicate']['basis']['mode'] === 'legacy') {
          $a['catalog']['backed'] = TRUE;
        }
      }
      unset($a);
    }
    $catalogRead = isset($catalogSource['counts']['apps']) ? (string) $this->t('Read from the catalog: @apps published apps, @software Software entries, @types app types, @tags implementation tags.', [
      '@apps' => (int) $catalogSource['counts']['apps'],
      '@software' => (int) ($catalogSource['counts']['software'] ?? 0),
      '@types' => (int) ($catalogSource['counts']['app_types'] ?? 0),
      '@tags' => (int) ($catalogSource['counts']['implementation_tags'] ?? 0),
    ]) : '';

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

    $repoUrl = $repo && $repo->hasField('field_repo_url') && !$repo->get('field_repo_url')->isEmpty() ? (string) ($repo->get('field_repo_url')->first()->getValue()['uri'] ?? '') : '';
    $maintainer = $repo && $repo->hasField('field_repo_maintainer_name') ? (string) ($repo->get('field_repo_maintainer_name')->value ?? '') : '';
    $contributor = $this->contributorLink($repo);

    return [
      'nid' => $node->id(),
      // The catalog, where a duplicate check with nothing to go on looks.
      'catalog_url' => Url::fromUserInput('/appverse')->toString(),
      'title' => $repo ? $repo->label() : $node->label(),
      'repo_label' => $repo ? $repo->label() : '',
      'repo_url' => $repoUrl,
      'show_maintainer' => self::maintainerDiffers($maintainer, $contributor, $repoUrl),
      'compared_position' => $comparedPosition,
      'model_changed' => self::modelChanged((string) ($node->get('field_arv_run_meta')->value ?? ''), $previous[0]['run_meta'] ?? NULL),
      'maintainer' => $maintainer,
      // Who submitted the repo, linked to their community persona as the hub
      // card links it. NULL when the account has since been deleted.
      'contributor' => $contributor,
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
      'model' => $model['label'] ?? '',
      'model_full' => $model['full'] ?? '',
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
        // Rows per app when the review carries the checks (A4b); the report's
        // text otherwise.
        'catalog_html' => $node->hasField('field_arv_catalog_checks') && !$catalogRows
          ? $this->renderMarkdown(ReviewPageData::catalogChecks((string) ($node->get('field_arv_catalog_checks')->value ?? ''))) : NULL,
        'catalog_read' => $catalogRead,
      ],
      'gate_rows' => $this->gateRows($node),
      'catalog_html' => $node->hasField('field_arv_catalog_checks') ? $this->renderMarkdown(ReviewPageData::catalogChecks((string) ($node->get('field_arv_catalog_checks')->value ?? ''))) : NULL,
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
      'rerun' => $rerun['eligible'] ? [
        'url' => Url::fromRoute('ood_software.review_rerun_confirm', ['node' => $node->id()])->toString(),
        'show' => $rerun['show'],
      ] : NULL,
      'run_status' => [
        'in_flight' => $rerun['in_flight'],
        'label' => $rerun['in_flight'] ? ($rerun['status'] === 'in_progress' ? (string) $this->t('Automated check running') : (string) $this->t('Automated check queued')) : '',
      ],
      'repo_link' => $repo ? [
        'url' => $repo->toUrl()->toString(),
        'label' => (string) $this->t('Repo page'),
      ] : NULL,
      'app_links' => $repo ? $this->appLinks($repo) : [],
      'edit_url' => Url::fromRoute('entity.node.edit_form', ['node' => $node->id()])->toString(),
      'full_url' => Url::fromRoute('ood_software.review_page', ['node' => $node->id()])->toString(),
      'public_url' => Url::fromRoute('ood_software.review_page', ['node' => $node->id()], ['query' => ['view' => self::MODE_PUBLIC]])->toString(),
    ];
  }

  /**
   * The software assigned on the verdict's app, for duplicateBasis().
   *
   * The app is the verdict's app_ref, or, on a verdict seeded without one,
   * the repo's app with the verdict's app id.
   *
   * @param \Drupal\paragraphs\ParagraphInterface $verdict
   *   The review_verdict paragraph.
   * @param \Drupal\node\NodeInterface|null $repo
   *   The reviewed repo.
   * @param array<int, int> $unpublishedRepos
   *   The unpublished repos' ids (unpublishedRepoIds()).
   *
   * @return array{entry: string, apps: array<int, array{title: string, url: string}>}|null
   *   The Software entry's title and the other published apps with it, or
   *   NULL when the app has none assigned.
   */
  protected function assignedSoftware(ParagraphInterface $verdict, ?NodeInterface $repo, array $unpublishedRepos): ?array {
    $app = $verdict->get('field_rvv_app_ref')->entity;
    if (!$app instanceof NodeInterface && $repo !== NULL) {
      $app = $this->memberApps->appFor($repo, (string) ($verdict->get('field_rvv_app_id')->value ?? 'root'));
    }
    if (!$app instanceof NodeInterface || !$app->hasField('field_appverse_software_implemen')) {
      return NULL;
    }
    $software = $app->get('field_appverse_software_implemen')->entity;
    if (!$software instanceof NodeInterface) {
      return NULL;
    }
    return [
      'entry' => (string) $software->label(),
      'apps' => $this->sameSoftwareApps((int) $software->id(), $repo !== NULL ? (int) $repo->id() : 0, (int) $app->id(), $unpublishedRepos),
    ];
  }

  /**
   * The ids of the unpublished repos, whose apps the catalog hides.
   *
   * @return array<int, int>
   *   Node ids.
   */
  protected function unpublishedRepoIds(): array {
    return array_map('intval', array_values($this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appverse_repo')
      ->condition('status', 0)
      ->execute()));
  }

  /**
   * The published apps of other repos that implement a Software entry.
   *
   * As the catalog lists them: published, and not under an unpublished repo
   * (AppverseCacheService::applyRepoCascadeFilter()). An app with no repo
   * counts. Access is not checked, so Domain Access hides none.
   *
   * @param int $softwareNid
   *   The appverse_software node id.
   * @param int $repoNid
   *   The reviewed repo, whose own apps are not others; 0 for none.
   * @param int $appNid
   *   The app itself, never another, whatever its repo says.
   * @param array<int, int>|null $unpublishedRepos
   *   The unpublished repos' ids, when the caller has them; else they are
   *   looked up.
   *
   * @return array<int, array{title: string, url: string}>
   *   The apps by title, each with its page's URL.
   */
  public function sameSoftwareApps(int $softwareNid, int $repoNid, int $appNid, ?array $unpublishedRepos = NULL): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $exclude = $unpublishedRepos ?? $this->unpublishedRepoIds();
    if ($repoNid > 0) {
      $exclude[] = $repoNid;
    }
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appverse_app')
      ->condition('status', 1)
      ->condition('field_appverse_software_implemen', $softwareNid);
    if ($appNid > 0) {
      $query->condition('nid', $appNid, '<>');
    }
    if ($exclude !== []) {
      $query->condition($query->orConditionGroup()
        ->notExists('field_appverse_repo')
        ->condition('field_appverse_repo', $exclude, 'NOT IN'));
    }
    $apps = [];
    foreach ($storage->loadMultiple($query->execute()) as $app) {
      $apps[] = ['title' => (string) $app->label(), 'url' => $app->toUrl()->toString()];
    }
    usort($apps, static fn (array $a, array $b): int => strnatcasecmp($a['title'], $b['title']));
    return $apps;
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
      // The result as stored, '' when none was: ReviewFloors tells a stored
      // FAIL from a row seeded before results were (ReviewWorklist).
      'stored_result' => strtolower((string) ($p->hasField('field_rvf_result') ? ($p->get('field_rvf_result')->value ?? '') : '')),
      'aspect' => (string) ($p->get('field_rvf_aspect')->value ?? ''),
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
   * Earlier reviews of the same repo, newest first, with their stable ids.
   *
   * Only reviews created before this one count: a round is compared with the
   * round before it, never with a later one.
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
      ->sort('nid', 'DESC')
      ->execute();
    $previous = [];
    foreach ($storage->loadMultiple($nids) as $other) {
      // Ordered like reviewHistory(): by created, then nid.
      $created = (int) $other->getCreatedTime();
      if ($created > (int) $node->getCreatedTime() || ($created === (int) $node->getCreatedTime() && (int) $other->id() > (int) $node->id())) {
        continue;
      }
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
        'nid' => (int) $other->id(),
        'label' => $this->formatDate($at, 'date') . ' · ' . substr((string) $other->get('field_arv_sha')->value, 0, 7),
        'url' => Url::fromRoute('ood_software.review_page', ['node' => $other->id()])->toString(),
        'stable_ids' => array_values(array_filter($ids)),
        'run_meta' => (string) ($other->get('field_arv_run_meta')->value ?? ''),
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
   * A next-step sentence without its leading restatement of the decision.
   *
   * The decision band's heading already says "Changes requested", "Declined"
   * or "Accepted", so the sentence under it starts at what happens next. The
   * hub card keeps the full sentence (ReviewProgress::contributorSentence()).
   */
  public static function withoutStatusSentence(string $sentence): string {
    foreach ([t('Changes requested'), t('Declined'), t('Accepted')] as $status) {
      $prefix = $status . '. ';
      if (str_starts_with($sentence, $prefix)) {
        return substr($sentence, strlen($prefix));
      }
    }
    return $sentence;
  }

  /**
   * The contributor's steps, with the In review step's note while it is open.
   *
   * Before the decision the contributor can still push to GitHub, so the step
   * says what happens to those changes.
   *
   * @param array<int, array<string, mixed>> $steps
   *   The contributor's four steps.
   * @param string $state
   *   The contributor view's state (waiting, precheck or decided).
   *
   * @return array<int, array<string, mixed>>
   *   The steps.
   */
  protected function contributorSteps(array $steps, string $state): array {
    if ($state !== 'precheck') {
      return $steps;
    }
    foreach ($steps as &$step) {
      if ($step['key'] === 'in_review' && $step['state'] === ReviewProgress::CURRENT) {
        $step['label'] = (string) $this->t('Changes you push to GitHub now will be reviewed in the next round.');
        $step['show_label'] = TRUE;
      }
    }
    return $steps;
  }

  /**
   * Each app's name and decision label, for the contributor's decision band.
   *
   * @param array<mixed> $page
   *   The page from buildPage().
   *
   * @return array<int, array{key: string, name: string, label: string}>
   *   One entry per decided app.
   */
  protected function contributorDecisionApps(array $page): array {
    $apps = [];
    foreach ((array) ($page['apps'] ?? []) as $app) {
      $key = (string) ($app['conclusion'] ?? '');
      $label = ReviewProgress::decisionLabel($key);
      if ($label !== '') {
        $apps[] = ['key' => $key, 'name' => (string) $app['name'], 'label' => $label];
      }
    }
    return $apps;
  }

  /**
   * The contributor's Re-submit action, or NULL when it does not apply.
   *
   * Offered on the repo's newest review once its decision asks for changes and
   * the repo is where sendForReview() can act: needs_adjustment, or live with
   * apps sent back, published or still in review (the cases
   * AppverseHubController::sendForReview() handles). The URL carries the
   * route's CSRF token.
   *
   * @param array<string, mixed> $view
   *   The contributor view, with decision_apps.
   * @param \Drupal\node\NodeInterface $repo
   *   The repo the review is of.
   *
   * @return array{url: string}|null
   *   The tokened send-for-review URL, or NULL.
   */
  protected function contributorResubmit(array $view, NodeInterface $repo): ?array {
    if ($view['state'] !== 'decided' || $view['closed'] !== NULL
      || !in_array('request_changes', array_column($view['decision_apps'], 'key'), TRUE)) {
      return NULL;
    }
    $state = (string) ($repo->get('moderation_state')->value ?? '');
    // The predicate of sendForReview() and the hub card: a live repo, or one
    // in review, with apps sent back is re-submitted whole.
    if ($state !== 'needs_adjustment'
      && !(($repo->isPublished() || $state === 'ready_for_review') && $this->memberApps->sentBackCount($repo) > 0)) {
      return NULL;
    }
    return ['url' => Url::fromRoute('ood_software.repo_send_for_review', ['node' => $repo->id()])->toString()];
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
      '#ajax' => $this->ajaxSettings(),
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
      'rule' => ['#type' => 'textfield', '#title' => $this->t('Rule'), '#size' => 10, '#maxlength' => 16, '#default_value' => $finding['rule']],
      'severity' => ['#type' => 'select', '#title' => $this->t('Severity'), '#options' => $this->severityOptions(), '#default_value' => $finding['severity']],
      'summary' => ['#type' => 'textarea', '#title' => $this->t('Summary'), '#rows' => 2, '#maxlength' => 512, '#default_value' => $finding['summary']],
      'evidence' => ['#type' => 'textfield', '#title' => $this->t('Evidence'), '#maxlength' => 512, '#default_value' => $finding['evidence'], '#attributes' => ['placeholder' => 'path/to/file:line — what is there']],
    ];
    $form['delete_finding'][$pid] = [
      '#type' => 'submit',
      '#value' => $this->t('Delete finding'),
      '#name' => 'delete_finding__' . $pid,
      '#finding_pid' => $pid,
      // It saves the whole page, as Save does, so the page's checks run,
      // except on the finding going away.
      '#validate' => ['::validateDeleteFinding'],
      '#submit' => ['::deleteFinding', '::submitForm'],
      '#ajax' => $this->ajaxSettings(),
      '#attributes' => ['class' => ['btn', 'ghost', 'btn-delete-finding']],
    ];
  }

  /**
   * The sections a list's add form offers, key => title.
   *
   * An app's list offers the app's blocks. The repo's offers the sections
   * its list has (ReviewWorklist): its blocks, Structure when a repo gate
   * stands there, and Upkeep.
   *
   * @param array<string, mixed> $list
   *   A list from ReviewWorklist::build().
   *
   * @return array<string, string>
   *   Section key => title.
   */
  public static function addSections(array $list): array {
    if (($list['key'] ?? '') !== 'repo') {
      return ReviewPageData::APP_BLOCKS;
    }
    $sections = [];
    foreach ((array) ($list['sections'] ?? []) as $section) {
      $key = (string) ($section['key'] ?? '');
      $sections[$key] = $key === 'maintenance' ? 'Upkeep' : (ReviewPageData::APP_BLOCKS[$key] ?? $key);
    }
    return $sections;
  }

  /**
   * Where a finding added in a list's form is stored: app:<pid>:<section>
   * for an app's list, repo:<section> for the repo's.
   */
  public static function addTarget(string $listKey, string $section): string {
    return $listKey === 'repo' ? 'repo:' . $section : 'app:' . substr($listKey, strlen('app-')) . ':' . $section;
  }

  /**
   * The "Add finding" form for one list: the section it goes in, and the
   * fields the automated review fills that need a reviewer's judgment. Of
   * the add forms, its button checks only its own, so an empty add form
   * elsewhere never blocks it or Save draft.
   *
   * @param array<string, mixed> $form
   * @param array<string, mixed> $list
   *   A list from ReviewWorklist::build().
   */
  protected function addNewFindingElements(array &$form, array $list): void {
    $key = (string) $list['key'];
    $sections = self::addSections($list);
    // The rules grouped by section, and which section each belongs to, so
    // the page's script can move the section along with the rule.
    $rules = [];
    $ruleSections = [];
    foreach ($sections as $section => $title) {
      foreach (ReviewPageData::BLOCK_RULES[$section] ?? [] as $code => $ruleTitle) {
        $rules[$title][$code] = $code . ' — ' . $ruleTitle;
        $ruleSections[$code] = $section;
      }
    }
    $rules['_other'] = $this->t('Other…');
    $default = (string) ($list['default_section'] ?? '');
    $default = isset($sections[$default]) ? $default : (string) array_key_first($sections);
    $selector = ':input[name="add_finding[' . $key . '][rule]"]';
    $form['add_finding'][$key] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['finding-fields']],
      'section' => [
        '#type' => 'select',
        '#title' => $this->t('Section'),
        '#options' => $sections,
        '#default_value' => $default,
        '#attributes' => ['class' => ['arv-add-section']],
      ],
      'rule' => [
        '#type' => 'select',
        '#title' => $this->t('Rule'),
        '#options' => $rules,
        // The section's own first rule: the first option of all would be
        // STR-01, a gate that blocks the decision, under any section.
        '#default_value' => array_key_first(ReviewPageData::BLOCK_RULES[$default] ?? []) ?? '_other',
        '#attributes' => ['data-rule-sections' => json_encode($ruleSections)],
      ],
      'rule_other' => [
        '#type' => 'textfield',
        '#title' => $this->t('Rule code'),
        '#size' => 10,
        '#maxlength' => 16,
        '#states' => ['visible' => [$selector => ['value' => '_other']]],
      ],
      'severity' => ['#type' => 'select', '#title' => $this->t('Severity'), '#options' => $this->severityOptions(), '#default_value' => 'medium'],
      'summary' => ['#type' => 'textfield', '#title' => $this->t('Summary'), '#maxlength' => 255, '#attributes' => ['class' => ['arv-add-summary']]],
      'evidence' => ['#type' => 'textfield', '#title' => $this->t('Evidence'), '#maxlength' => 255, '#attributes' => ['placeholder' => 'path/to/file:line — what is there']],
      'add' => [
        '#type' => 'submit',
        '#value' => $this->t('Add finding'),
        '#name' => 'add_finding__' . $key,
        '#finding_list' => $key,
        // It saves the whole page, as Save does, so the page's checks run
        // too. They never look at the add forms, so one left empty elsewhere
        // blocks nothing.
        '#validate' => ['::validateForm', '::validateAddFinding'],
        '#submit' => ['::addFinding', '::submitForm'],
        '#ajax' => $this->ajaxSettings(),
        '#attributes' => ['class' => ['btn']],
      ],
    ];
  }

  /**
   * The finding a reviewer typed into one list's add form, as paragraph
   * values plus the target it is stored under, or NULL with the error set on
   * the form.
   *
   * @param array<mixed> $input
   *   The add form's values.
   * @param string $listKey
   *   The list's key: repo, or app-<pid>.
   * @param array<string, string> $sections
   *   The sections the list's form offers.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state, for the error.
   *
   * @return array<mixed>|null
   *   The values, with target, or NULL.
   */
  protected function newFindingFromInput(array $input, string $listKey, array $sections, FormStateInterface $form_state): ?array {
    $section = (string) ($input['section'] ?? '');
    if (!isset($sections[$section])) {
      $form_state->setErrorByName('add_finding][' . $listKey . '][section', $this->t('Choose a section for the new finding.'));
      return NULL;
    }
    $target = self::addTarget($listKey, $section);
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
      return ['target' => $target] + ReviewPageData::reviewerFinding(['rule' => $rule] + $input, (string) $blockKey, $appId, 'manual-' . bin2hex(random_bytes(4)));
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setErrorByName('add_finding][' . $listKey . '][summary', $this->t('A new finding needs a rule, a severity and a summary.'));
      return NULL;
    }
  }

  /**
   * The finding the pressed add button's form holds, or NULL.
   *
   * @param array<string, mixed> $form
   *
   * @return array<mixed>|null
   *   As newFindingFromInput() returns it.
   */
  protected function pressedAddFinding(array $form, FormStateInterface $form_state): ?array {
    $listKey = (string) $form_state->getTriggeringElement()['#finding_list'];
    $sections = (array) ($form['add_finding'][$listKey]['section']['#options'] ?? []);
    return $this->newFindingFromInput($form_state->getValue(['add_finding', $listKey]) ?? [], $listKey, $sections, $form_state);
  }

  /**
   * @param array<string, mixed> $form
   */
  public function validateAddFinding(array &$form, FormStateInterface $form_state): void {
    if ($form_state->get('arv_stale')) {
      return;
    }
    $this->pressedAddFinding($form, $form_state);
    $this->noteErrorFocus($form_state);
  }

  /**
   * Submit handler for a list's "Add finding": a review_finding paragraph
   * marked as the reviewer's, appended to that app's verdict (or the repo's
   * findings) under the section picked. submitForm() runs next and saves the
   * rest of the page with it, in one new revision of the review.
   *
   * @param array<string, mixed> $form
   */
  public function addFinding(array &$form, FormStateInterface $form_state): void {
    $values = $this->pressedAddFinding($form, $form_state);
    if ($values === NULL) {
      return;
    }
    $target = (string) $values['target'];
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
    $form_state->set('arv_stamp', sprintf('Review page: %s added finding %s', $this->currentUser->getDisplayName(), $values['rule']));
    $form_state->set('arv_announce', (string) $this->t('Finding added.'));
    // Back on the new finding.
    $form_state->set('arv_focus', 'finding-' . $finding->id());
    // Its add form starts empty again; any other keeps what was typed.
    self::unsetInputValue($form_state, ['add_finding', (string) $form_state->getTriggeringElement()['#finding_list']]);
  }

  /**
   * A reviewer's own finding on the review, with the list it is in.
   *
   * @return array{0: \Drupal\paragraphs\ParagraphInterface, 1: \Drupal\paragraphs\ParagraphInterface|null}|null
   *   The finding and its verdict (NULL for the repo's own findings), or NULL
   *   when the review has no reviewer's finding by that id.
   */
  protected function reviewerFinding(string $pid): ?array {
    $own = static fn ($p): bool => (string) $p->id() === $pid && ($p->get('field_rvf_source')->value ?? '') === 'reviewer';
    foreach ($this->node->get('field_arv_verdicts')->referencedEntities() as $verdict) {
      foreach ($verdict->get('field_rvv_findings')->referencedEntities() as $p) {
        if ($own($p)) {
          return [$p, $verdict];
        }
      }
    }
    foreach ($this->node->get('field_arv_repo_findings')->referencedEntities() as $p) {
      if ($own($p)) {
        return [$p, NULL];
      }
    }
    return NULL;
  }

  /**
   * Validates "Delete finding".
   *
   * The page's checks, except on the finding being deleted, and only a
   * reviewer's own finding.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateDeleteFinding(array &$form, FormStateInterface $form_state): void {
    $this->startRequest($form, $form_state);
    $pid = (string) ($form_state->getTriggeringElement()['#finding_pid'] ?? '');
    if (!$this->validatePage($form, $form_state, $pid)) {
      return;
    }
    if ($this->reviewerFinding($pid) === NULL) {
      $form_state->setErrorByName('delete_finding][' . $pid, $this->t('Only a finding a reviewer added can be deleted.'));
    }
    $this->noteErrorFocus($form_state);
  }

  /**
   * Submit handler for "Delete finding" on a reviewer's finding.
   *
   * Removes the reference (earlier revisions keep theirs); automated findings
   * are never deleted here (validateDeleteFinding()). submitForm() runs next
   * and saves the rest of the page with it, in one new revision.
   *
   * @param array<string, mixed> $form
   */
  public function deleteFinding(array &$form, FormStateInterface $form_state): void {
    $pid = (string) $form_state->getTriggeringElement()['#finding_pid'];
    $found = $this->reviewerFinding($pid);
    if ($found === NULL) {
      return;
    }
    [$removed, $verdict] = $found;
    $list = $verdict ? $verdict->get('field_rvv_findings') : $this->node->get('field_arv_repo_findings');
    foreach ($list->referencedEntities() as $delta => $p) {
      if ((string) $p->id() === $pid) {
        $list->removeItem($delta);
        break;
      }
    }
    if ($verdict) {
      $verdict->save();
    }
    $form_state->set('arv_stamp', sprintf('Review page: %s deleted finding %s', $this->currentUser->getDisplayName(), $removed->get('field_rvf_rule')->value));
    $form_state->set('arv_announce', (string) $this->t('Finding deleted.'));
    // The finding is gone, so focus goes to its list's add form.
    $listKey = $verdict ? 'app-' . $verdict->id() : 'repo';
    $form_state->set('arv_focus', 'fold-' . $listKey . '-add');
    $form_state->set('arv_deleted_from', $listKey);
    foreach (['prose', 'override', 'edit_finding'] as $key) {
      self::unsetInputValue($form_state, [$key, $pid]);
    }
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
    $this->startRequest($form, $form_state);
    if ($this->validatePage($form, $form_state)) {
      $this->noteErrorFocus($form_state);
    }
  }

  /**
   * Starts a request's handling with none of the last request's state.
   *
   * A form restored from the cache brings the form state storage and the
   * confirmation it was rebuilt with; neither belongs to this request.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function startRequest(array &$form, FormStateInterface $form_state): void {
    foreach (self::REQUEST_STATE as $key) {
      $form_state->set($key, NULL);
    }
    unset($form['#arv_confirm']);
  }

  /**
   * The page's checks, on the review as it is now.
   *
   * Every handler after this one works on the review this loads. A cached
   * form (after an AJAX save) carries the form object it was built with, and
   * that object's review is out of date.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string|null $skip
   *   A finding to leave out: the one being deleted.
   *
   * @return bool
   *   FALSE when the save was refused as coming from an out-of-date window,
   *   or as a Confirm on a duplicate check that changed since the page
   *   loaded, and nothing else was checked.
   */
  protected function validatePage(array $form, FormStateInterface $form_state, ?string $skip = NULL): bool {
    $fresh = $this->entityTypeManager->getStorage('node')->loadUnchanged($this->node->id());
    if ($fresh instanceof NodeInterface) {
      $this->node = $fresh;
    }
    // What was posted, not the element's value: a missing input would take the
    // element's default, which is the fresh revision. A programmed submit
    // (and a page without the element, the contributor's) has nothing to
    // compare.
    $posted = $form_state->getUserInput()['arv_revision'] ?? NULL;
    $stale = !$form_state->isProgrammed() && isset($form['arv_revision'])
      && (!is_scalar($posted) || (string) $posted !== (string) $this->node->getRevisionId());
    if (!$this->buttonPressed($form_state) || $stale) {
      $this->refuseStale($form, $form_state);
      return FALSE;
    }
    foreach ($form_state->getValue('edit_finding') ?? [] as $pid => $input) {
      if ((string) $pid === $skip) {
        continue;
      }
      if (trim((string) ($input['summary'] ?? '')) === '' || trim((string) ($input['rule'] ?? '')) === '') {
        $form_state->setErrorByName('edit_finding][' . $pid . '][summary', $this->t('A finding needs a rule and a summary.'));
      }
    }
    // From the review as it is now, never the cached form's #page.
    $backed = [];
    $stored = [];
    $lines = [];
    foreach ($this->buildPage($this->node)['apps'] ?? [] as $app) {
      $backed[$app['pid']] = $app['catalog']['backed'] ?? TRUE;
      $stored[$app['pid']] = (string) ($app['duplicate']['outcome'] ?? '');
      $lines[(string) $app['pid']] = [
        'line' => (string) ($app['duplicate']['basis']['line'] ?? ''),
        'mode' => (string) ($app['duplicate']['basis']['mode'] ?? ''),
      ];
    }
    // For Confirm's note.
    $form_state->set('arv_duplicate_lines', $lines);
    // Confirm records "No other app" only while the check is still clear:
    // software assigned, or another app with it published, since the page
    // loaded does not change the review's revision.
    $confirm = (string) ($form_state->getTriggeringElement()['#duplicate_pid'] ?? '');
    if ($confirm !== '' && ($lines[$confirm]['mode'] ?? '') !== 'clear') {
      // Nothing is saved: a rebuild set while validating skips the submit
      // handlers. It is not a form error, because core rebuilds a form only
      // when there is none, and the rebuild is what lists the apps to
      // compare. The message goes on the outcome (addDuplicateElements())
      // and at the top of the page.
      $form_state->set('arv_duplicate_changed', $confirm);
      $form_state->set('arv_focus', 'duplicate-' . $confirm);
      $form_state->set('arv_announce', (string) $this->t('Not saved: @error', ['@error' => $this->duplicateChangedMessage()]));
      if (!$this->isAjax()) {
        // Over AJAX the announcement says it, and nothing is left behind
        // for the next page.
        $this->messenger()->addError($this->duplicateChangedMessage());
      }
      $form_state->setRebuild();
      // Nothing else is checked: any form error would stop the rebuild and
      // redraw the stale check.
      return FALSE;
    }
    foreach ($form_state->getValue('duplicate') ?? [] as $pid => $input) {
      $outcome = (string) ($input['outcome'] ?? '');
      // A "No other app" saved before the check needed a rationale for it
      // (a suggestion was once clear) stands while it is left unchanged, so
      // it doesn't block saving the rest of the review.
      $kept = $outcome === 'none' && $outcome === ($stored[$pid] ?? NULL);
      if (!$kept && DuplicateCheck::needsNote($outcome, (string) ($input['note'] ?? ''), $backed[$pid] ?? TRUE)) {
        $form_state->setErrorByName('duplicate][' . $pid . '][note', $this->t('Give the rationale for the duplicate check: which apps you compared, and how this one differs or which one it duplicates.'));
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
        if (!isset($tool[$pid]) || (string) $pid === $skip) {
          continue;
        }
        if (FindingOverride::needsNote(FindingOverride::normalize((array) $input, $tool[$pid]), (string) ($notes[$pid] ?? ''))) {
          $form_state->setErrorByName('prose][' . $pid, $this->t('Add a note saying why: it is required to change the severity of an automated finding or dismiss it.'));
        }
      }
    }
    return TRUE;
  }

  /**
   * Whether the triggering element is the button the reviewer pressed.
   *
   * When the pressed button is not on the form as built from the review now
   * (the review was sent in another window, so its findings have no Save),
   * Drupal falls back to the form's first button, which can be "Start the
   * next round". That is never what was pressed.
   */
  protected function buttonPressed(FormStateInterface $form_state): bool {
    $element = $form_state->getTriggeringElement();
    if ($form_state->isProgrammed() || !is_array($element) || !isset($element['#name'])) {
      return TRUE;
    }
    $input = $form_state->getUserInput();
    if (($input['_triggering_element_name'] ?? NULL) === $element['#name']) {
      return TRUE;
    }
    // As core's buttonWasClicked() compares it.
    return isset($input[$element['#name']]) && (string) $input[$element['#name']] === Html::decodeEntities((string) ($element['#value'] ?? ''));
  }

  /**
   * Refuses a save from a window the review has moved on from.
   *
   * Nothing is saved: an error blocks the submit handlers, and so does the
   * rebuild for a button whose own errors are limited away. The page shown
   * keeps what was typed, which is the stale page's input, so it still
   * carries the old revision and later saves from it stay refused until the
   * reviewer reloads.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function refuseStale(array $form, FormStateInterface $form_state): void {
    $form_state->set('arv_stale', TRUE);
    $form_state->set('arv_focus', 'arv-review-form');
    $form_state->setError($form, $this->staleMessage());
    if ($form_state->getErrors() === []) {
      $this->messenger()->addError($this->staleMessage());
    }
    $form_state->setRebuild();
    // The fallback button has no #ajax, and an AJAX request needs a callback
    // to answer it.
    $trigger = $form_state->getTriggeringElement();
    if ($this->isAjax() && is_array($trigger) && empty($trigger['#ajax'])) {
      $trigger['#ajax'] = $this->ajaxSettings();
      $form_state->setTriggeringElement($trigger);
    }
  }

  /**
   * After a refused save, focus goes to what was pressed.
   *
   * That is the finding whose button it was, or the add form.
   */
  protected function noteErrorFocus(FormStateInterface $form_state): void {
    if ($form_state->getErrors() === [] || $form_state->get('arv_stale')) {
      return;
    }
    $trigger = $form_state->getTriggeringElement() ?? [];
    if (isset($trigger['#finding_pid'])) {
      $form_state->set('arv_focus', 'finding-' . $trigger['#finding_pid']);
    }
    elseif (isset($trigger['#finding_list'])) {
      $form_state->set('arv_focus', 'fold-' . $trigger['#finding_list'] . '-add');
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
   * The duplicate-check outcomes (DuplicateCheck::OUTCOMES), translated.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  protected function duplicateOptions(): array {
    return [
      'none' => $this->t('No other app for this software'),
      'distinct' => $this->t('Same software, a meaningfully different approach'),
      'duplicate' => $this->t('Duplicate of an existing app'),
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
