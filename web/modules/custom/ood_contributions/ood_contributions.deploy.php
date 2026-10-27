<?php

/**
 * @file
 */

use Drupal\ood_contributions\Plugin\CronManager;


/**
 * Create the Contributor Wall page node with layout builder sections.
 */
function ood_contributions_deploy_10000_cw() {
  // Idempotency: skip if the page already exists.
  $existing = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadByProperties(['title' => 'Contributor Wall', 'type' => 'page']);
  if (!empty($existing)) {
    return t('Contributor Wall page already exists, skipping.');
  }

  $uuid_service = \Drupal::service('uuid');

  // --- Create non-reusable inline blocks ---

  $inline_blocks_data = [
    'should_you_be' => [
      'info' => 'Should you be on the wall block',
      'body_value' => '<div class="border border-dark p-4" style="border-style:dashed !important;">
    <h2 class="text-center">
        Should you be on the wall?
    </h2>
    <p class="text-center">
        <a class="btn btn-sm btn-primary" href="/user/login"><span>Join the Hub</span></a>
    </p>
</div>',
      'body_format' => 'full_no_editor',
    ],
    'title_code_doc' => [
      'info' => 'TITLE :: code/doc contribs',
      'body_value' => '<div class="cw-title position-relative my-4"><h2>Code &amp; Documentation Contributions</h2><p><a class="btn btn-sm btn-primary py-2" href="https://www.openondemand.org/contribute-open-ondemand#interested"><span>Find out more</span></a></p></div>',
      'body_format' => 'full_html',
    ],
    'title_appverse' => [
      'info' => 'TITLE :: Appverse contributors & moderators',
      'body_value' => '<div class="cw-title position-relative my-4"><h2>The Appverse Contributors</h2><p><a class="btn btn-sm btn-primary py-2" href="/appverse"><span>Visit the Appverse</span></a></p></div>',
      'body_format' => 'full_html',
    ],
    'title_discourse' => [
      'info' => 'TITLE :: Discourse Participation',
      'body_value' => '<div class="cw-title position-relative my-4"><h2>Discourse Participation</h2><p><a class="btn btn-sm btn-primary py-2" href="https://discourse.openondemand.org"><span>Visit us on Discourse</span></a></p></div>',
      'body_format' => 'full_html',
    ],
    'title_committee' => [
      'info' => 'TITLE :: Committee Members',
      'body_value' => '<div class="cw-title position-relative my-4"><h2>Committee Members</h2><p><a class="btn btn-sm btn-primary py-2" href="https://www.openondemand.org/get-involved#committees"><span>Find out more</span></a></p></div>',
      'body_format' => 'full_html',
    ],
    'title_ag_coordinators' => [
      'info' => 'TITLE :: Affinity Group Coordinators',
      'body_value' => '<div class="cw-title position-relative my-4"><h2>Affinity Group Coordinators</h2><p><a class="btn btn-sm btn-primary py-2" href="/affinity-groups"><span>See Affinity Groups</span></a></p></div>',
      'body_format' => 'full_html',
    ],
    'title_good_conference' => [
      'info' => 'TITLE :: Good Conference Committee',
      'body_value' => '<div class="cw-title position-relative my-4"><h2>Good Conference Committee</h2><p><a class="btn btn-sm btn-primary py-2" href="https://www.good2026.openondemand.org"><span>Learn about GOOD</span></a></p></div>',
      'body_format' => 'full_html',
    ],
    'title_tips_tricks' => [
      'info' => 'TITLE :: Tips & Tricks Calls',
      'body_value' => '<div class="cw-title position-relative my-4"><h2>Tips &amp; Tricks Calls</h2><p><a class="btn btn-sm btn-primary py-2" href="/events"><span>Upcoming events</span></a></p></div>',
      'body_format' => 'full_html',
    ],
  ];

  $inline_blocks = [];
  foreach ($inline_blocks_data as $key => $data) {
    $block = \Drupal\block_content\Entity\BlockContent::create([
      'type' => 'basic',
      'info' => $data['info'],
      'reusable' => FALSE,
      'body' => [
        'value' => $data['body_value'],
        'summary' => '',
        'format' => $data['body_format'],
      ],
    ]);
    $block->save();
    $inline_blocks[$key] = $block;
  }

  // --- Ensure reusable blocks exist (create placeholders if missing) ---

  $reusable_blocks_data = [
    'cf23e8ac-13fa-4fb5-9ecc-8f7b8f27886b' => 'Github contribution graph',
    '61be2908-7646-484a-9877-f60266521aef' => 'Discourse Contribution Graph',
  ];

  foreach ($reusable_blocks_data as $block_uuid => $block_info) {
    $existing_blocks = \Drupal::entityTypeManager()
      ->getStorage('block_content')
      ->loadByProperties(['uuid' => $block_uuid]);
    if (empty($existing_blocks)) {
      $reusable_block = \Drupal\block_content\Entity\BlockContent::create([
        'uuid' => $block_uuid,
        'type' => 'basic',
        'info' => $block_info,
        'reusable' => TRUE,
        'body' => [
          'value' => '',
          'format' => 'full_no_editor',
        ],
      ]);
      $reusable_block->save();
    }
  }

  // --- Build layout section ---

  $component_styles = [
    'layout_builder_styles_style' => [
      '__div_h_full' => 0,
      'accordion_wrapper' => 0,
      'bg_light_teal' => 0,
      'mb_10' => 0,
      'mb_3' => 0,
      'mb_5' => 0,
      'md_teal_box' => 0,
      'page_title' => 0,
      'pb_4' => 0,
      'pe_3' => 0,
      'pt_4' => 0,
      'tags' => 0,
    ],
  ];

  $section = new \Drupal\layout_builder\Section('layout_twocol_section', [
    'label' => 'Wall layout',
    'column_widths' => '75-25',
    'layout_builder_styles_style' => [
      'bg_light_teal_overflow_section' => 0,
      'border_b_2' => 0,
      'border_gray' => 0,
      'mb_10_section' => 0,
      'md_order_1' => 0,
      'md_order_2' => 0,
      'mobile_row_reverse' => 0,
      'mt_4_section' => 0,
      'order_1' => 0,
      'order_2' => 0,
      'pt_20' => 0,
      '_layout__region_second_order_1' => 0,
    ],
    'context_mapping' => [],
  ]);

  // First region components (appended in weight order).

  // Weight 0: Body field block.
  $section->appendComponent(new \Drupal\layout_builder\SectionComponent(
    $uuid_service->generate(),
    'first',
    [
      'id' => 'field_block:node:page:body',
      'label_display' => '0',
      'context_mapping' => ['entity' => 'layout_builder.entity'],
      'formatter' => [
        'type' => 'text_default',
        'label' => 'hidden',
        'settings' => [],
        'third_party_settings' => [],
      ],
    ]
  ));

  // Weight 1: TITLE :: code/doc contribs.
  $section->appendComponent(new \Drupal\layout_builder\SectionComponent(
    $uuid_service->generate(),
    'first',
    [
      'id' => 'inline_block:basic',
      'label' => 'TITLE :: code/doc contribs',
      'label_display' => 0,
      'provider' => 'layout_builder',
      'view_mode' => 'full',
      'block_id' => $inline_blocks['title_code_doc']->id(),
      'block_revision_id' => $inline_blocks['title_code_doc']->getRevisionId(),
      'block_serialized' => NULL,
      'context_mapping' => [],
    ],
    $component_styles
  ));

  // Weight 2: Github contribution graph (reusable block).
  $section->appendComponent(new \Drupal\layout_builder\SectionComponent(
    $uuid_service->generate(),
    'first',
    [
      'id' => 'block_content:cf23e8ac-13fa-4fb5-9ecc-8f7b8f27886b',
      'label' => 'Github contribution graph',
      'label_display' => 0,
      'provider' => 'block_content',
      'status' => TRUE,
      'info' => '',
      'view_mode' => 'full',
      'context_mapping' => [],
    ],
    $component_styles
  ));

  // Weight 3: contributors_block.
  $section->appendComponent(new \Drupal\layout_builder\SectionComponent(
    $uuid_service->generate(),
    'first',
    [
      'id' => 'contributors_block',
      'label' => 'Code & Documentations Contributors',
      'label_display' => 0,
      'provider' => 'ood_contributions',
      'context_mapping' => [],
    ],
    $component_styles
  ));

  // Weight 4: TITLE :: Appverse contributors & moderators.
  $section->appendComponent(new \Drupal\layout_builder\SectionComponent(
    $uuid_service->generate(),
    'first',
    [
      'id' => 'inline_block:basic',
      'label' => 'TITLE :: Appverse contributors & moderators',
      'label_display' => 0,
      'provider' => 'layout_builder',
      'view_mode' => 'full',
      'block_id' => $inline_blocks['title_appverse']->id(),
      'block_revision_id' => $inline_blocks['title_appverse']->getRevisionId(),
      'block_serialized' => NULL,
      'context_mapping' => [],
    ],
    $component_styles
  ));

  // Weight 5: appverse_contributors_block.
  $section->appendComponent(new \Drupal\layout_builder\SectionComponent(
    $uuid_service->generate(),
    'first',
    [
      'id' => 'appverse_contributors_block',
      'label' => 'The Appverse Contributors & Moderators',
      'label_display' => 0,
      'provider' => 'ood_software',
      'context_mapping' => [],
    ],
    $component_styles
  ));

  // Weight 6: TITLE :: Discourse Participation.
  $section->appendComponent(new \Drupal\layout_builder\SectionComponent(
    $uuid_service->generate(),
    'first',
    [
      'id' => 'inline_block:basic',
      'label' => 'TITLE :: Discourse Participation',
      'label_display' => 0,
      'provider' => 'layout_builder',
      'view_mode' => 'full',
      'block_id' => $inline_blocks['title_discourse']->id(),
      'block_revision_id' => $inline_blocks['title_discourse']->getRevisionId(),
      'block_serialized' => NULL,
      'context_mapping' => [],
    ],
    $component_styles
  ));

  // Weight 7: Discourse Contribution Graph (reusable block).
  $section->appendComponent(new \Drupal\layout_builder\SectionComponent(
    $uuid_service->generate(),
    'first',
    [
      'id' => 'block_content:61be2908-7646-484a-9877-f60266521aef',
      'label' => 'Discourse Contribution Graph',
      'label_display' => 0,
      'provider' => 'block_content',
      'status' => TRUE,
      'info' => '',
      'view_mode' => 'full',
      'context_mapping' => [],
    ],
    $component_styles
  ));

  // Weight 8: discourse_participants_block.
  $section->appendComponent(new \Drupal\layout_builder\SectionComponent(
    $uuid_service->generate(),
    'first',
    [
      'id' => 'discourse_participants_block',
      'label' => 'Discourse Participants',
      'label_display' => 0,
      'provider' => 'ood_software',
      'context_mapping' => [],
    ],
    $component_styles
  ));

  // Weight 9: TITLE :: Committee Members.
  $section->appendComponent(new \Drupal\layout_builder\SectionComponent(
    $uuid_service->generate(),
    'first',
    [
      'id' => 'inline_block:basic',
      'label' => 'TITLE :: Committee Members',
      'label_display' => 0,
      'provider' => 'layout_builder',
      'view_mode' => 'full',
      'block_id' => $inline_blocks['title_committee']->id(),
      'block_revision_id' => $inline_blocks['title_committee']->getRevisionId(),
      'block_serialized' => NULL,
      'context_mapping' => [],
    ],
    $component_styles
  ));

  // Weight 10: committee_members_block.
  $section->appendComponent(new \Drupal\layout_builder\SectionComponent(
    $uuid_service->generate(),
    'first',
    [
      'id' => 'committee_members_block',
      'label' => 'Committee Members',
      'label_display' => 0,
      'provider' => 'ood_software',
      'context_mapping' => [],
    ],
    $component_styles
  ));

  // Weight 11: TITLE :: Affinity Group Coordinators.
  $section->appendComponent(new \Drupal\layout_builder\SectionComponent(
    $uuid_service->generate(),
    'first',
    [
      'id' => 'inline_block:basic',
      'label' => 'TITLE :: Affinity Group Coordinators',
      'label_display' => 0,
      'provider' => 'layout_builder',
      'view_mode' => 'full',
      'block_id' => $inline_blocks['title_ag_coordinators']->id(),
      'block_revision_id' => $inline_blocks['title_ag_coordinators']->getRevisionId(),
      'block_serialized' => NULL,
      'context_mapping' => [],
    ],
    $component_styles
  ));

  // Weight 12: ag_coordinators_block.
  $section->appendComponent(new \Drupal\layout_builder\SectionComponent(
    $uuid_service->generate(),
    'first',
    [
      'id' => 'ag_coordinators_block',
      'label' => 'Affinity Group Coordinators',
      'label_display' => 0,
      'provider' => 'ood_software',
      'context_mapping' => [],
    ],
    $component_styles
  ));

  // Weight 13: TITLE :: Good Conference Committee.
  $section->appendComponent(new \Drupal\layout_builder\SectionComponent(
    $uuid_service->generate(),
    'first',
    [
      'id' => 'inline_block:basic',
      'label' => 'TITLE :: Good Conference Committee',
      'label_display' => 0,
      'provider' => 'layout_builder',
      'view_mode' => 'full',
      'block_id' => $inline_blocks['title_good_conference']->id(),
      'block_revision_id' => $inline_blocks['title_good_conference']->getRevisionId(),
      'block_serialized' => NULL,
      'context_mapping' => [],
    ],
    $component_styles
  ));

  // Weight 14: good_conference_committee_block.
  $section->appendComponent(new \Drupal\layout_builder\SectionComponent(
    $uuid_service->generate(),
    'first',
    [
      'id' => 'good_conference_committee_block',
      'label' => 'GOOD Conference Committee',
      'label_display' => 0,
      'provider' => 'ood_software',
      'context_mapping' => [],
    ],
    $component_styles
  ));

  // Weight 15: TITLE :: Tips & Tricks Calls.
  $section->appendComponent(new \Drupal\layout_builder\SectionComponent(
    $uuid_service->generate(),
    'first',
    [
      'id' => 'inline_block:basic',
      'label' => 'TITLE :: Tips & Tricks Calls',
      'label_display' => 0,
      'provider' => 'layout_builder',
      'view_mode' => 'full',
      'block_id' => $inline_blocks['title_tips_tricks']->id(),
      'block_revision_id' => $inline_blocks['title_tips_tricks']->getRevisionId(),
      'block_serialized' => NULL,
      'context_mapping' => [],
    ],
    $component_styles
  ));

  // Weight 16: tips_tricks_calls_block.
  $section->appendComponent(new \Drupal\layout_builder\SectionComponent(
    $uuid_service->generate(),
    'first',
    [
      'id' => 'tips_tricks_calls_block',
      'label' => 'Tips & Tricks Calls',
      'label_display' => 0,
      'provider' => 'ood_software',
      'context_mapping' => [],
    ],
    $component_styles
  ));

  // Second region components (appended in weight order).

  // Weight 0: Should you be on the wall block.
  $section->appendComponent(new \Drupal\layout_builder\SectionComponent(
    $uuid_service->generate(),
    'second',
    [
      'id' => 'inline_block:basic',
      'label' => 'Should you be on the wall block',
      'label_display' => 0,
      'provider' => 'layout_builder',
      'view_mode' => 'full',
      'block_id' => $inline_blocks['should_you_be']->id(),
      'block_revision_id' => $inline_blocks['should_you_be']->getRevisionId(),
      'block_serialized' => NULL,
      'context_mapping' => [],
    ],
    $component_styles
  ));

  // Weight 1: community_spotlight_block.
  $section->appendComponent(new \Drupal\layout_builder\SectionComponent(
    $uuid_service->generate(),
    'second',
    [
      'id' => 'community_spotlight_block',
      'label' => 'Community Spotlight',
      'label_display' => 0,
      'provider' => 'ood_contributions',
      'context_mapping' => [],
    ],
    $component_styles
  ));

  // --- Create the node ---

  $node = \Drupal\node\Entity\Node::create([
    'type' => 'page',
    'title' => 'Contributor Wall',
    'status' => 1,
    'uid' => 1,
    'promote' => 0,
    'sticky' => 0,
    'langcode' => 'en',
    'body' => [
      'value' => '<h2>Thanks to our Community</h2><p class="font-weight-bold">Open OnDemand is powered by community collaboration, and this wall highlights the contributors who build, improve, and support the platform across the ecosystem.</p>',
      'summary' => '',
      'format' => 'full_html',
    ],
    'field_domain_all_affiliates' => 1,
  ]);
  $node->get('layout_builder__layout')->appendSection($section);
  $node->save();

  // --- Create the path alias ---

  $path_alias = \Drupal\path_alias\Entity\PathAlias::create([
    'path' => '/node/' . $node->id(),
    'alias' => '/contributor-wall',
    'langcode' => 'en',
  ]);
  $path_alias->save();

  return t('Created Contributor Wall page (node @nid).', ['@nid' => $node->id()]);
}

/**
 * Create badge taxonomy terms in the open_ondemand_badges vocabulary.
 */
function ood_contributions_deploy_10001_badges() {
  _ood_contributions_create_badge_terms();
}

/**
 * Helper: ensure badge taxonomy terms exist in open_ondemand_badges.
 */
function _ood_contributions_create_badge_terms() {
  $vocabulary = 'open_ondemand_badges';

  $terms = [
    [
      'name' => 'Affinity Group Coordinator',
      'description' => 'Coordinates an Affinity Group. Affinity Groups are communities of practice for topical discussion, curating links to communication channels, key documentation, news, events, email lists, and sharing infrastructure-related updates for members.<br><br>See: <a href="https://openondemand.connectci.org/affinity-groups">Affinity Groups</a>',
    ],
    [
      'name' => 'GOOD25',
      'description' => 'Attended the first annual Global Open OnDemand Conference at Harvard University in 2025.<br><br>Find our more about GOOD: <a href="https://www.good2026.openondemand.org/">https://www.good2026.openondemand.org/</a>',
    ],
    [
      'name' => 'GOOD26',
      'description' => 'Attended the second annual Global Open OnDemand Conference at the University of Utah in 2026.<br><br>Find our more about GOOD: <a href="https://www.good2026.openondemand.org/">https://www.good2026.openondemand.org/</a>',
    ],
    [
      'name' => 'GOOD Committee',
      'description' => 'Served on the GOOD Committee to help put on the Global Open OnDemand Conference.<br><br>Find our more about GOOD: <a href="https://www.good2026.openondemand.org/">https://www.good2026.openondemand.org/</a>',
    ],
    [
      'name' => 'Committee member',
      'description' => 'Served on one of the Open OnDemand committees, Technical Committee​, User Documentation​, Contributor Guide​ or Community Engagement​.<br><br>See: <a href="https://www.openondemand.org/get-involved#committees">Get Involved</a>',
    ],
    [
      'name' => 'Tips & Tricks',
      'description' => 'Presented or assisted with the monthly Tips &amp; Tricks Call, where the community shares best practices of all things Open OnDemand.<br><br>See: <a href="https://openondemand.connectci.org/events">Upcoming Events</a>',
    ],
    [
      'name' => 'Appverse App Reviewer',
      'description' => 'Reviews apps submitted to the Appverse, helping keep the quality of the Appverse high.<br><br>See: <a href="https://openondemand.connectci.org/appverse">The Appverse</a>',
    ],
  ];

  $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');

  foreach ($terms as $term_data) {
    $existing = $term_storage->loadByProperties([
      'vid' => $vocabulary,
      'name' => $term_data['name'],
    ]);

    if (empty($existing)) {
      $term = $term_storage->create([
        'vid' => $vocabulary,
        'name' => $term_data['name'],
        'description' => [
          'value' => $term_data['description'],
          'format' => 'full_html',
        ],
      ]);
      $term->save();
    }
  }
}

/**
 * Add the Appverse App Reviewers section to the Contributor Wall.
 *
 * Creates the "Appverse App Reviewer" badge term and places a title plus the
 * app_reviewers_block directly after the Appverse contributors block. Never
 * throws: if the section can't be placed, it returns a message instead.
 */
function ood_contributions_deploy_10002_app_reviewers() {
  // 1. The badge term (idempotent).
  _ood_contributions_create_badge_terms();

  // 2. The Contributor Wall node (same lookup as 10000).
  $nodes = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadByProperties(['title' => 'Contributor Wall', 'type' => 'page']);
  $node = reset($nodes);
  if (!$node || !$node->hasField('layout_builder__layout')) {
    return t('Contributor Wall page not found; created the badge term only. Place the "Appverse App Reviewers" blocks manually.');
  }

  // 3. Find the Appverse anchor and detect an existing reviewers block by
  // plugin id, not by weight or section delta.
  $anchor_section = NULL;
  $anchor_uuid = NULL;
  $anchor_region = NULL;
  $sections = $node->get('layout_builder__layout')->getSections();
  foreach ($sections as $section) {
    foreach ($section->getComponents() as $component) {
      $plugin_id = $component->getPluginId();
      if ($plugin_id === 'app_reviewers_block') {
        return t('The App Reviewers block is already on the Contributor Wall, skipping.');
      }
      if ($plugin_id === 'appverse_contributors_block' && $anchor_uuid === NULL) {
        $anchor_section = $section;
        $anchor_uuid = $component->getUuid();
        $anchor_region = $component->getRegion();
      }
    }
  }

  if ($anchor_section === NULL || $anchor_uuid === NULL || $anchor_region === NULL) {
    \Drupal::logger('ood_contributions')->warning('Could not place the App Reviewers section: the appverse_contributors_block component was not found on the Contributor Wall (node @nid).', ['@nid' => $node->id()]);
    return t('Could not find the Appverse contributors block on the Contributor Wall. Place the "Appverse App Reviewers" title and block manually.');
  }

  try {
    $component_styles = [
      'layout_builder_styles_style' => [
        '__div_h_full' => 0,
        'accordion_wrapper' => 0,
        'bg_light_teal' => 0,
        'mb_10' => 0,
        'mb_3' => 0,
        'mb_5' => 0,
        'md_teal_box' => 0,
        'page_title' => 0,
        'pb_4' => 0,
        'pe_3' => 0,
        'pt_4' => 0,
        'tags' => 0,
      ],
    ];

    // 4. Non-reusable inline title block (same pattern as 10000).
    $title_block = \Drupal\block_content\Entity\BlockContent::create([
      'type' => 'basic',
      'info' => 'TITLE :: Appverse App Reviewers',
      'reusable' => FALSE,
      'body' => [
        'value' => '<div class="cw-title position-relative my-4"><h2>Appverse App Reviewers</h2><p><a class="btn btn-sm btn-primary py-2" href="/appverse"><span>Visit the Appverse</span></a></p></div>',
        'summary' => '',
        'format' => 'full_html',
      ],
    ]);
    $title_block->save();

    $uuid_service = \Drupal::service('uuid');
    $title_component = new \Drupal\layout_builder\SectionComponent(
      $uuid_service->generate(),
      $anchor_region,
      [
        'id' => 'inline_block:basic',
        'label' => 'TITLE :: Appverse App Reviewers',
        'label_display' => 0,
        'provider' => 'layout_builder',
        'view_mode' => 'full',
        'block_id' => $title_block->id(),
        'block_revision_id' => $title_block->getRevisionId(),
        'block_serialized' => NULL,
        'context_mapping' => [],
      ],
      $component_styles
    );
    $reviewers_component = new \Drupal\layout_builder\SectionComponent(
      $uuid_service->generate(),
      $anchor_region,
      [
        'id' => 'app_reviewers_block',
        'label' => 'Appverse App Reviewers',
        'label_display' => 0,
        'provider' => 'ood_software',
        'context_mapping' => [],
      ],
      $component_styles
    );

    // Inserting re-weights the components that follow.
    $anchor_section->insertAfterComponent($anchor_uuid, $title_component);
    $anchor_section->insertAfterComponent($title_component->getUuid(), $reviewers_component);

    // 5. Save with a revision log message.
    $node->setNewRevision(TRUE);
    $node->setRevisionLogMessage('Added the Appverse App Reviewers section.');
    $node->save();
  }
  catch (\Throwable $e) {
    \Drupal::logger('ood_contributions')->warning('Could not place the App Reviewers section: @message', ['@message' => $e->getMessage()]);
    return t('Could not place the App Reviewers section (@message). Place the "Appverse App Reviewers" title and block manually.', ['@message' => $e->getMessage()]);
  }

  return t('Added the Appverse App Reviewers section to the Contributor Wall (node @nid).', ['@nid' => $node->id()]);
}


/**
 * Backfill the Discourse daily post heatmap in site (New York) time.
 *
 * Re-buckets the whole heatmap year. Never throws, so a Discourse outage
 * cannot fail the deploy.
 */
function ood_contributions_deploy_10003_discourse_backfill() {
  $manual = 'Run manually: drush ood:discourse-stats --since=2025-10-01';
  try {
    $service = \Drupal::service('ood_contributions.discourse_stats');
    $result = $service->updateDailyPostStats('2025-10-01');
    if (empty($result['success'])) {
      return t('Discourse backfill failed (@message). @manual', [
        '@message' => $result['message'] ?? 'unknown error',
        '@manual' => $manual,
      ]);
    }
    $service->updateBlock();
    return t('Discourse daily stats backfilled: @message', ['@message' => $result['message']]);
  }
  catch (\Throwable $e) {
    \Drupal::logger('ood_contributions')->error('Discourse backfill deploy hook failed: @message', [
      '@message' => $e->getMessage(),
    ]);
    return t('Discourse backfill failed (@message). @manual', [
      '@message' => $e->getMessage(),
      '@manual' => $manual,
    ]);
  }
}

/**
 * Fill the Discourse persona stats and last_posted_at.
 *
 * Never throws, so a Discourse outage cannot fail the deploy.
 */
function ood_contributions_deploy_10004_discourse_contrib() {
  $manual = 'Run manually: drush ood:discourse-contrib';
  try {
    $summary = CronManager::discourseStoreContrib();
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['ood_disc_contrib']);
    if (!empty($summary['failed'])) {
      return t('Discourse contributions updated for @updated users, but @failed failed. @manual', [
        '@updated' => $summary['updated'],
        '@failed' => $summary['failed'],
        '@manual' => $manual,
      ]);
    }
    return t('Discourse contributions updated for @updated users.', ['@updated' => $summary['updated']]);
  }
  catch (\Throwable $e) {
    \Drupal::logger('ood_contributions')->error('Discourse contrib deploy hook failed: @message', [
      '@message' => $e->getMessage(),
    ]);
    return t('Discourse contributions update failed (@message). @manual', [
      '@message' => $e->getMessage(),
      '@manual' => $manual,
    ]);
  }
}
