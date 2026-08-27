<?php

/**
 * @file
 * Deployment functions for the ood_software module.
 */

use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\media\Entity\Media;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

/**
 * Add terms.
 */
function ood_software_deploy_10000_terms() {
  $terms = [
    'appverse_app_type' => [
      'batch_connect',
      'dashboard',
      'passenger_app',
      'widget',
    ],
    'appverse_implementation_tags' => [
      'docker',
      'containerized',
      'singularity',
      'classroom',
    ],
    'appverse_license' => [
      'Commercial License',
      'Open-Source License',
    ],
  ];

  foreach ($terms as $vocabulary_machine_name => $term_names) {
    $vocabulary = Vocabulary::load($vocabulary_machine_name);
    if ($vocabulary) {
      foreach ($term_names as $term_name) {
        $terms = \Drupal::entityTypeManager()
          ->getStorage('taxonomy_term')
          ->loadByProperties([
            'name' => $term_name,
            'vid' => $vocabulary_machine_name,
          ]);
        if (empty($terms)) {
          $term = Term::create([
            'name' => $term_name,
            'vid' => $vocabulary_machine_name,
          ]);
          $term->save();
        }
      }
    }
  }
}

/**
 * Add software via csv.
 */
function ood_software_deploy_10001_software() {
  $module_path = \Drupal::service('extension.list.module')->getPath('ood_software');
  $csv_file = $module_path . '/csv/software.csv';

  if (!file_exists($csv_file)) {
    \Drupal::logger('ood_software')->error('CSV file not found at @path', ['@path' => $csv_file]);
    return;
  }

  $handle = fopen($csv_file, 'r');
  if (!$handle) {
    \Drupal::logger('ood_software')->error('Unable to open CSV file');
    return;
  }

  $count = 0;
  while (($data = fgetcsv($handle)) !== FALSE) {
    // Map CSV columns: Name, Description, Software URL, Docs, License, Topic,
    // Tags.
    $name = $data[0] ?? '';
    $description = $data[1] ?? '';
    $software_url = $data[2] ?? '';
    $docs = $data[3] ?? '';
    $license = $data[4] ?? '';
    $topic = $data[5] ?? '';
    $tags = $data[6] ?? '';

    if (empty($name)) {
      continue;
    }

    // Check if node with this title already exists.
    $existing_nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'appverse_software',
        'title' => $name,
      ]);

    if (!empty($existing_nodes)) {
      \Drupal::logger('ood_software')->notice('Node already exists for: @name', ['@name' => $name]);
      continue;
    }

    // Process license.
    $license_tid = NULL;
    if (!empty($license)) {
      $license_tid = _ood_software_get_or_create_term($license, 'appverse_license');
    }

    // Process topics (comma-separated).
    $topic_tids = [];
    if (!empty($topic)) {
      $topics = array_map('trim', explode(',', $topic));
      foreach ($topics as $topic_name) {
        $tid = _ood_software_get_or_create_term($topic_name, 'appverse_science_domains');
        if ($tid) {
          $topic_tids[] = ['target_id' => $tid];
        }
      }
    }

    // Process tags (comma-separated).
    $tag_tids = [];
    if (!empty($tags)) {
      $tag_list = array_map('trim', explode(',', $tags));
      foreach ($tag_list as $tag_name) {
        $tid = _ood_software_get_or_create_term($tag_name, 'tags');
        if ($tid) {
          $tag_tids[] = ['target_id' => $tid];
        }
      }
    }

    // Create the node.
    $node = Node::create([
      'type' => 'appverse_software',
      'title' => $name,
      'uid' => 1985,
      'body' => [
        'value' => $description,
        'format' => 'markdown',
      ],
      'field_appverse_software_website' => [
        'uri' => $software_url,
        'title' => '',
      ],
      'field_appverse_software_doc' => [
        'uri' => $docs,
        'title' => '',
      ],
      'field_license' => $license_tid ? ['target_id' => $license_tid] : NULL,
      'field_appverse_topics' => $topic_tids,
      'field_tags' => $tag_tids,
      'status' => 1,
    ]);

    $node->save();
    $count++;
    \Drupal::logger('ood_software')->notice('Created node: @name', ['@name' => $name]);
  }

  fclose($handle);
  \Drupal::logger('ood_software')->notice('Imported @count software nodes', ['@count' => $count]);

  return t('Successfully imported @count software entries.', ['@count' => $count]);
}

/**
 * Helper function to get or create a taxonomy term.
 *
 * @param string $name
 *   The term name.
 * @param string $vocabulary
 *   The vocabulary machine name.
 *
 * @return int|null
 *   The term ID or NULL if failed.
 */
function _ood_software_get_or_create_term($name, $vocabulary) {
  if (empty($name)) {
    return NULL;
  }

  // Check if term exists.
  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'name' => $name,
      'vid' => $vocabulary,
    ]);

  if (!empty($terms)) {
    $term = reset($terms);
    return $term->id();
  }

  // Create new term.
  try {
    $term = Term::create([
      'name' => $name,
      'vid' => $vocabulary,
    ]);
    $term->save();
    return $term->id();
  }
  catch (\Exception $e) {
    \Drupal::logger('ood_software')->error('Failed to create term @name: @error', [
      '@name' => $name,
      '@error' => $e->getMessage(),
    ]);
    return NULL;
  }
}

/**
 * Add apps via csv.
 */
function ood_software_deploy_10002_apps() {
  $module_path = \Drupal::service('extension.list.module')->getPath('ood_software');
  $csv_file = $module_path . '/csv/apps.csv';

  if (!file_exists($csv_file)) {
    \Drupal::logger('ood_software')->error('CSV file not found at @path', ['@path' => $csv_file]);
    return;
  }

  $handle = fopen($csv_file, 'r');
  if (!$handle) {
    \Drupal::logger('ood_software')->error('Unable to open CSV file');
    return;
  }

  // Read and skip header row.
  fgetcsv($handle);

  $count = 0;
  while (($data = fgetcsv($handle)) !== FALSE) {
    // Map CSV columns.
    // Software, App Name, GitHub URL, Organization / Author,
    // App Type, License, Implementation Tags, Description, Stars, Last Commit,
    // Is Archived.
    $software_name = $data[0] ?? '';
    $app_name = $data[1] ?? '';
    $github_url = $data[2] ?? '';
    $organization = $data[3] ?? '';
    $app_type = $data[4] ?? '';
    $license = $data[5] ?? '';
    $implementation_tags = $data[6] ?? '';
    $description = $data[7] ?? '';
    $last_commit = $data[9] ?? '';
    $is_archived = $data[10] ?? '';

    if (empty($app_name)) {
      continue;
    }

    // Skip archived repositories.
    if ($is_archived === 'true') {
      \Drupal::logger('ood_software')->notice('Skipping archived repo: @url', ['@url' => $github_url]);
      continue;
    }

    // Check if node with this GitHub URL already exists.
    // Using GitHub URL instead of title to allow multiple apps with same name.
    if (!empty($github_url)) {
      $existing_nodes = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->loadByProperties([
          'type' => 'appverse_app',
          'field_appverse_github_url' => $github_url,
        ]);

      if (!empty($existing_nodes)) {
        \Drupal::logger('ood_software')->notice('App node already exists for URL: @url', ['@url' => $github_url]);
        continue;
      }
    }

    // Look up software node reference.
    $software_nid = NULL;
    if (!empty($software_name)) {
      $software_nodes = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->loadByProperties([
          'type' => 'appverse_software',
          'title' => $software_name,
        ]);

      if (!empty($software_nodes)) {
        $software_node = reset($software_nodes);
        $software_nid = $software_node->id();
      }
      else {
        \Drupal::logger('ood_software')->warning('Software node not found for: @name', ['@name' => $software_name]);
      }
    }

    // Process app type.
    $app_type_tid = NULL;
    if (!empty($app_type)) {
      $app_type_tid = _ood_software_get_or_create_term($app_type, 'appverse_app_type');
    }

    // Process organization.
    $organization_tid = NULL;
    if (!empty($organization)) {
      $organization_tid = _ood_software_get_or_create_term($organization, 'appverse_organization');
    }

    // Process license.
    $license_tid = NULL;
    if (!empty($license)) {
      $license_tid = _ood_software_get_or_create_term($license, 'appverse_license');
    }

    // Process implementation tags (comma-separated).
    $tag_tids = [];
    if (!empty($implementation_tags)) {
      $tag_list = array_map('trim', explode(',', $implementation_tags));
      foreach ($tag_list as $tag_name) {
        if (!empty($tag_name)) {
          $tid = _ood_software_get_or_create_term($tag_name, 'appverse_implementation_tags');
          if ($tid) {
            $tag_tids[] = ['target_id' => $tid];
          }
        }
      }
    }

    // Parse last commit date to timestamp if provided.
    $last_updated = NULL;
    if (!empty($last_commit)) {
      $last_updated = strtotime($last_commit);
    }

    // Create the node.
    $node = Node::create([
      'type' => 'appverse_app',
      'title' => $app_name,
      'uid' => 1985,
      'body' => !empty($description) ? [
        'value' => $description,
        'format' => 'markdown',
      ] : NULL,
      'field_appverse_software_implemen' => $software_nid ? ['target_id' => $software_nid] : NULL,
      'field_appverse_github_url' => [
        'uri' => $github_url,
        'title' => '',
      ],
      'field_appverse_organization' => $organization_tid ? ['target_id' => $organization_tid] : NULL,
      'field_appverse_app_type' => $app_type_tid ? ['target_id' => $app_type_tid] : NULL,
      'field_license' => $license_tid ? ['target_id' => $license_tid] : NULL,
      'field_add_implementation_tags' => $tag_tids,
      'field_appverse_lastupdated' => $last_updated,
      'status' => 1,
      'moderation_state' => 'published',
    ]);

    $node->save();
    $count++;
    \Drupal::logger('ood_software')->notice('Created app node: @name', ['@name' => $app_name]);
  }

  fclose($handle);
  \Drupal::logger('ood_software')->notice('Imported @count app nodes', ['@count' => $count]);

  return t('Successfully imported @count app entries.', ['@count' => $count]);
}

/**
 * Add logos to software nodes.
 */
function ood_software_deploy_10003_logos() {
  $module_path = \Drupal::service('extension.list.module')->getPath('ood_software');
  $logos_dir = $module_path . '/logos';
  $mapping_file = $logos_dir . '/mapping.php';

  if (!file_exists($mapping_file)) {
    \Drupal::logger('ood_software')->error('Logo mapping file not found at @path', ['@path' => $mapping_file]);
    return;
  }

  $mapping = include $mapping_file;
  $file_system = \Drupal::service('file_system');
  $count = 0;

  foreach ($mapping as $filename => $software_name) {
    $logo_path = $logos_dir . '/' . $filename;

    if (!file_exists($logo_path)) {
      \Drupal::logger('ood_software')->warning('Logo file not found: @file', ['@file' => $filename]);
      continue;
    }

    // Find the software node.
    $software_nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'appverse_software',
        'title' => $software_name,
      ]);

    if (empty($software_nodes)) {
      \Drupal::logger('ood_software')->warning('Software node not found for: @name', ['@name' => $software_name]);
      continue;
    }

    $software_node = reset($software_nodes);

    // Check if logo already exists.
    if (!$software_node->get('field_appverse_logo')->isEmpty()) {
      \Drupal::logger('ood_software')->notice('Logo already exists for: @name', ['@name' => $software_name]);
      continue;
    }

    // Determine media bundle based on file extension.
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $is_svg = ($extension === 'svg');

    // Copy file to public files.
    $destination_dir = 'public://appverse-logos';
    $file_system->prepareDirectory($destination_dir, FileSystemInterface::CREATE_DIRECTORY);
    $destination = $destination_dir . '/' . $filename;

    $file_data = file_get_contents($logo_path);
    $file = \Drupal::service('file.repository')->writeData($file_data, $destination, FileExists::Replace);

    if (!$file) {
      \Drupal::logger('ood_software')->error('Failed to copy logo: @file', ['@file' => $filename]);
      continue;
    }

    // Create media entity.
    if ($is_svg) {
      $media = Media::create([
        'bundle' => 'svg',
        'name' => $software_name . ' logo',
        'uid' => 1985,
        'field_media_image_1' => [
          'target_id' => $file->id(),
          'alt' => $software_name . ' logo',
        ],
      ]);
    }
    else {
      $media = Media::create([
        'bundle' => 'image',
        'name' => $software_name . ' logo',
        'uid' => 1985,
        'field_media_image' => [
          'target_id' => $file->id(),
          'alt' => $software_name . ' logo',
        ],
      ]);
    }

    $media->save();

    // Attach media to software node.
    $software_node->set('field_appverse_logo', ['target_id' => $media->id()]);
    $software_node->save();

    $count++;
    \Drupal::logger('ood_software')->notice('Added logo for: @name', ['@name' => $software_name]);
  }

  \Drupal::logger('ood_software')->notice('Added @count logos to software nodes', ['@count' => $count]);

  return t('Successfully added @count logos.', ['@count' => $count]);
}

/**
 * Add menu links.
 */
function ood_software_deploy_10004_menus() {
  $my_apps = [
    'title' => 'My Apps',
    'link' => ['uri' => 'internal:/appverse/my-apps'],
    'menu_name' => 'account',
    'weight' => 46,
    'expanded' => FALSE,
    'enabled' => TRUE,
    'parent' => 'user.page',
    'options' => [
      'attributes' => [
        'class' => ['region-specific', 'display-open-ondemand'],
        'roles' => ['authenticated'],
      ],
    ],
  ];
  ood_software_links($my_apps);

  $manage_apps = [
    'title' => 'Manage Appverse Apps',
    'link' => ['uri' => 'internal:/appverse/manage-apps'],
    'menu_name' => 'account',
    'weight' => 46,
    'expanded' => FALSE,
    'enabled' => TRUE,
    'parent' => 'user.page',
    'options' => [
      'attributes' => [
        'class' => ['region-specific', 'display-open-ondemand'],
        'roles' => ['administrator', 'appverse_pm'],
      ],
    ],
  ];
  ood_software_links($manage_apps);
}

/**
 * Clear last updated field for apps missing body/readme to force GitHub sync.
 */
function ood_software_deploy_10005_force_app_sync() {
  $node_storage = \Drupal::entityTypeManager()->getStorage('node');

  // Find all appverse_app nodes.
  $nids = $node_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'appverse_app')
    ->execute();

  $count = 0;
  foreach ($nids as $nid) {
    $node = $node_storage->load($nid);
    if (!$node) {
      continue;
    }

    // Check if body or readme is empty.
    $body = $node->get('body')->value;
    $readme = $node->get('field_appverse_readme')->value;

    if (empty($body) || empty($readme)) {
      // Clear the last updated field to force sync on next cron.
      $node->set('field_appverse_lastupdated', NULL);
      $node->save();
      $count++;
      \Drupal::logger('ood_software')->notice('Cleared last updated for: @title', ['@title' => $node->getTitle()]);
    }
  }

  \Drupal::logger('ood_software')->notice('Cleared last updated for @count apps to force GitHub sync', ['@count' => $count]);

  return t('Cleared last updated for @count apps. Run cron to sync from GitHub.', ['@count' => $count]);
}

/**
 * Retry adding logos to software nodes (fixes field config to accept images).
 */
function ood_software_deploy_10006_logos_retry() {
  $module_path = \Drupal::service('extension.list.module')->getPath('ood_software');
  $logos_dir = $module_path . '/logos';
  $mapping_file = $logos_dir . '/mapping.php';

  if (!file_exists($mapping_file)) {
    \Drupal::logger('ood_software')->error('Logo mapping file not found at @path', ['@path' => $mapping_file]);
    return;
  }

  $mapping = include $mapping_file;
  $file_system = \Drupal::service('file_system');
  $count = 0;

  foreach ($mapping as $filename => $software_name) {
    $logo_path = $logos_dir . '/' . $filename;

    if (!file_exists($logo_path)) {
      \Drupal::logger('ood_software')->warning('Logo file not found: @file', ['@file' => $filename]);
      continue;
    }

    // Find the software node.
    $software_nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'appverse_software',
        'title' => $software_name,
      ]);

    if (empty($software_nodes)) {
      \Drupal::logger('ood_software')->warning('Software node not found for: @name', ['@name' => $software_name]);
      continue;
    }

    $software_node = reset($software_nodes);

    // Check if logo already exists.
    if (!$software_node->get('field_appverse_logo')->isEmpty()) {
      \Drupal::logger('ood_software')->notice('Logo already exists for: @name', ['@name' => $software_name]);
      continue;
    }

    // Determine media bundle based on file extension.
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $is_svg = ($extension === 'svg');

    // Copy file to public files.
    $destination_dir = 'public://appverse-logos';
    $file_system->prepareDirectory($destination_dir, FileSystemInterface::CREATE_DIRECTORY);
    $destination = $destination_dir . '/' . $filename;

    $file_data = file_get_contents($logo_path);
    $file = \Drupal::service('file.repository')->writeData($file_data, $destination, FileExists::Replace);

    if (!$file) {
      \Drupal::logger('ood_software')->error('Failed to copy logo: @file', ['@file' => $filename]);
      continue;
    }

    // Create media entity.
    if ($is_svg) {
      $media = Media::create([
        'bundle' => 'svg',
        'name' => $software_name . ' logo',
        'uid' => 1985,
        'field_media_image_1' => [
          'target_id' => $file->id(),
          'alt' => $software_name . ' logo',
        ],
      ]);
    }
    else {
      $media = Media::create([
        'bundle' => 'image',
        'name' => $software_name . ' logo',
        'uid' => 1985,
        'field_media_image' => [
          'target_id' => $file->id(),
          'alt' => $software_name . ' logo',
        ],
      ]);
    }

    $media->save();

    // Attach media to software node.
    $software_node->set('field_appverse_logo', ['target_id' => $media->id()]);
    $software_node->save();

    $count++;
    \Drupal::logger('ood_software')->notice('Added logo for: @name', ['@name' => $software_name]);
  }

  \Drupal::logger('ood_software')->notice('Added @count logos to software nodes', ['@count' => $count]);

  return t('Successfully added @count logos.', ['@count' => $count]);
}

/**
 * Replace logos with color versions.
 */
function ood_software_deploy_10007_color_logos() {
  $module_path = \Drupal::service('extension.list.module')->getPath('ood_software');
  $logos_dir = $module_path . '/color-logos';
  $mapping_file = $logos_dir . '/mapping.php';

  if (!file_exists($mapping_file)) {
    \Drupal::logger('ood_software')->error('Color logo mapping file not found at @path', ['@path' => $mapping_file]);
    return;
  }

  $mapping = include $mapping_file;
  $file_system = \Drupal::service('file_system');
  $count = 0;

  foreach ($mapping as $filename => $software_name) {
    $logo_path = $logos_dir . '/' . $filename;

    if (!file_exists($logo_path)) {
      \Drupal::logger('ood_software')->warning('Color logo file not found: @file', ['@file' => $filename]);
      continue;
    }

    // Find the software node.
    $software_nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'appverse_software',
        'title' => $software_name,
      ]);

    if (empty($software_nodes)) {
      \Drupal::logger('ood_software')->warning('Software node not found for: @name', ['@name' => $software_name]);
      continue;
    }

    $software_node = reset($software_nodes);

    // Determine media bundle based on file extension.
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $is_svg = ($extension === 'svg');

    // Copy file to public files.
    $destination_dir = 'public://appverse-logos';
    $file_system->prepareDirectory($destination_dir, FileSystemInterface::CREATE_DIRECTORY);
    $destination = $destination_dir . '/' . $filename;

    $file_data = file_get_contents($logo_path);
    $file = \Drupal::service('file.repository')->writeData($file_data, $destination, FileExists::Replace);

    if (!$file) {
      \Drupal::logger('ood_software')->error('Failed to copy color logo: @file', ['@file' => $filename]);
      continue;
    }

    // Create media entity.
    if ($is_svg) {
      $media = Media::create([
        'bundle' => 'svg',
        'name' => $software_name . ' logo',
        'uid' => 1985,
        'field_media_image_1' => [
          'target_id' => $file->id(),
          'alt' => $software_name . ' logo',
        ],
      ]);
    }
    else {
      $media = Media::create([
        'bundle' => 'image',
        'name' => $software_name . ' logo',
        'uid' => 1985,
        'field_media_image' => [
          'target_id' => $file->id(),
          'alt' => $software_name . ' logo',
        ],
      ]);
    }

    $media->save();

    // Attach media to software node (replacing any existing logo).
    $software_node->set('field_appverse_logo', ['target_id' => $media->id()]);
    $software_node->save();

    $count++;
    \Drupal::logger('ood_software')->notice('Added color logo for: @name', ['@name' => $software_name]);
  }

  \Drupal::logger('ood_software')->notice('Added @count color logos to software nodes', ['@count' => $count]);

  return t('Successfully added @count color logos.', ['@count' => $count]);
}

/**
 * Normalize app type term names to match APP_TYPE_MANIFEST_MAP.
 */
function ood_software_deploy_10008_normalize_app_types() {
  $renames = [
    'batch_connect' => 'batch-connect-basic',
    'dashboard'     => 'dashboards',
    'passenger_app' => 'companion_app',
    'widget'        => 'widgets',
  ];
  $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  $count = 0;
  foreach ($renames as $old_name => $new_name) {
    $existing = $storage->loadByProperties(['name' => $new_name, 'vid' => 'appverse_app_type']);
    if (!empty($existing)) {
      continue;
    }
    $old = $storage->loadByProperties(['name' => $old_name, 'vid' => 'appverse_app_type']);
    if (!empty($old)) {
      $term = reset($old);
      $term->setName($new_name);
      $term->save();
      $count++;
    }
  }
  // Ensure batch-connect-VNC exists.
  $vnc = $storage->loadByProperties(['name' => 'batch-connect-VNC', 'vid' => 'appverse_app_type']);
  if (empty($vnc)) {
    Term::create(['vid' => 'appverse_app_type', 'name' => 'batch-connect-VNC'])->save();
    $count++;
  }
  return t('Updated @count app type terms.', ['@count' => $count]);
}

/**
 * Queue all apps for re-sync to apply new app type logic.
 */
/**
 * Clean up orphan root-level tags created by CSV software import.
 *
 * The CSV import (deploy_10001) used _ood_software_get_or_create_term() which
 * creates flat terms with no parent. This reparents terms that belong in the
 * hierarchy and deletes duplicates/junk terms.
 */
function ood_software_deploy_10010_cleanup_orphan_tags() {
  $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  $node_storage = \Drupal::entityTypeManager()->getStorage('node');
  $messages = [];

  // 1. Reparent terms that should be children in the hierarchy.
  // Parent IDs: Analysis and Algorithms=668, Domain Tools=667,
  // Linux and Shell Scripting=674.
  $reparent = [
    'simulation' => 668,
    'modeling' => 668,
    'multiphysics' => 667,
    'automation' => 674,
  ];
  foreach ($reparent as $name => $parent_tid) {
    $terms = $term_storage->loadByProperties(['name' => $name, 'vid' => 'tags']);
    foreach ($terms as $term) {
      $parents = $term_storage->loadParents($term->id());
      if (empty($parents)) {
        $term->set('parent', ['target_id' => $parent_tid]);
        $term->save();
        $messages[] = "Reparented '$name' (id={$term->id()}) under parent $parent_tid";
      }
    }
  }

  // 2. Delete 'web' (id=988) and remove from node 11805.
  $web_terms = $term_storage->loadByProperties(['name' => 'web', 'vid' => 'tags']);
  foreach ($web_terms as $term) {
    $parents = $term_storage->loadParents($term->id());
    if (empty($parents)) {
      // Remove reference from any nodes first.
      $nids = \Drupal::entityQuery('node')
        ->condition('field_tags', $term->id())
        ->accessCheck(FALSE)
        ->execute();
      foreach ($nids as $nid) {
        $node = $node_storage->load($nid);
        if ($node) {
          $tags = $node->get('field_tags')->getValue();
          $tags = array_filter($tags, fn($v) => (int) $v['target_id'] !== (int) $term->id());
          $node->set('field_tags', array_values($tags));
          $node->save();
          $messages[] = "Removed 'web' tag from node $nid";
        }
      }
      $term->delete();
      $messages[] = "Deleted orphan term 'web' (id={$term->id()})";
    }
  }

  // 3. Delete 'earth sciences' (tags vocab duplicate) and retag node with
  // existing 'earth-sciences' (id=872) under Science Domains.
  $earth_terms = $term_storage->loadByProperties([
    'name' => 'earth sciences',
    'vid' => 'tags',
  ]);
  $earth_replacement = $term_storage->loadByProperties([
    'name' => 'earth-sciences',
    'vid' => 'tags',
  ]);
  $earth_replacement_tid = !empty($earth_replacement) ? reset($earth_replacement)->id() : NULL;
  foreach ($earth_terms as $term) {
    $nids = \Drupal::entityQuery('node')
      ->condition('field_tags', $term->id())
      ->accessCheck(FALSE)
      ->execute();
    foreach ($nids as $nid) {
      $node = $node_storage->load($nid);
      if ($node) {
        $tags = $node->get('field_tags')->getValue();
        $tags = array_filter($tags, fn($v) => (int) $v['target_id'] !== (int) $term->id());
        if ($earth_replacement_tid) {
          $tags[] = ['target_id' => $earth_replacement_tid];
        }
        $node->set('field_tags', array_values($tags));
        $node->save();
        $messages[] = "Retagged node $nid: 'earth sciences' -> 'earth-sciences' ($earth_replacement_tid)";
      }
    }
    $term->delete();
    $messages[] = "Deleted orphan term 'earth sciences' (id={$term->id()})";
  }

  // 4. Delete unused junk terms.
  $delete_names = ['astronomy and planetary sciences', 'physics', 'Tags'];
  foreach ($delete_names as $name) {
    $terms = $term_storage->loadByProperties(['name' => $name, 'vid' => 'tags']);
    foreach ($terms as $term) {
      $parents = $term_storage->loadParents($term->id());
      if (empty($parents)) {
        $term->delete();
        $messages[] = "Deleted orphan term '$name' (id={$term->id()})";
      }
    }
  }

  $summary = implode("\n", $messages);
  \Drupal::logger('ood_software')->notice("Tag cleanup:\n$summary");
  return t("Cleaned up orphan tags:\n@summary", ['@summary' => $summary]);
}

function ood_software_deploy_10009_resync_app_types() {
  $queue = \Drupal::queue('appverse_app_updater');
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $nids = \Drupal::entityQuery('node')
    ->condition('type', 'appverse_app')
    ->accessCheck(FALSE)
    ->execute();
  foreach ($nids as $nid) {
    $node = $storage->load($nid);
    if ($node) {
      $node->set('field_appverse_lastupdated', NULL);
      $node->save();
    }
    $queue->createItem(['nid' => $nid]);
  }
  return t('Queued @count apps for app type re-sync.', ['@count' => count($nids)]);
}

/**
 * Create menu item.
 */
function ood_software_links($menu_link) {
  $existing_links = \Drupal::entityTypeManager()
    ->getStorage('menu_link_content')
    ->loadByProperties([
      'title' => $menu_link['title'],
      'menu_name' => $menu_link['menu_name'],
    ]);
  if (empty($existing_links)) {
    $roles = $menu_link['options']['attributes']['roles'];
    // Added to variable to avoid saving roles directly to menu.
    unset($menu_link['options']['attributes']['roles']);

    // Move options into the link field structure.
    $menu_link['link']['options'] = $menu_link['options'];
    unset($menu_link['options']);

    $menu_link_entity = MenuLinkContent::create($menu_link);
    $menu_link_entity->save();
    $mid = $menu_link_entity->id();

    // Add roles that can see menu item.
    if (isset($roles)) {
      $delta = 0;
      foreach ($roles as $role) {
        \Drupal::database()->insert('menu_link_content__menu_item_roles')
          ->fields([
            'bundle' => 'account',
            'deleted' => 0,
            'entity_id' => $mid,
            'revision_id' => $mid,
            'langcode' => 'en',
            'delta' => $delta,
            'menu_item_roles_target_id' => $role,
          ])
          ->execute();
        $delta++;
      }
    }
    \Drupal::logger('ood_software')->notice('Added menu link: @title', ['@title' => $menu_link['title']]);
  }
  else {
    \Drupal::logger('ood_software')->notice('Menu link already exists: @title', ['@title' => $menu_link['title']]);
  }
}

/**
 * Add CSCfi (CSC - IT Center for Science) software and apps.
 *
 * Creates 3 new software entries (Desktop, SSH Terminal, Cloud Storage
 * Configuration) and 7 app nodes from CSCfi GitHub repos.
 */
function ood_software_deploy_10011_cscfi_apps() {
  $messages = [];

  // --- 1. Create new software entries ---
  $software_entries = [
    [
      'title' => 'Desktop',
      'body' => 'Linux desktop environment via VNC for remote visualization on HPC clusters.',
      'license' => 'Open-Source License',
      'tags' => ['Gateways and Portals'],
    ],
    [
      'title' => 'SSH Terminal',
      'body' => 'Persistent SSH shell access to compute nodes via Open OnDemand.',
      'license' => 'Open-Source License',
      'tags' => ['Linux and Shell Scripting'],
    ],
    [
      'title' => 'Cloud Storage Configuration',
      'body' => 'Cloud storage (Rclone) configuration tool for Open OnDemand. Allows users to configure access to cloud storage services.',
      'license' => 'Open-Source License',
      'tags' => ['Cloud', 'Data Storage'],
      'website' => 'https://github.com/CSCfi/ood-cloud-storage-conf',
    ],
  ];

  foreach ($software_entries as $entry) {
    // Skip if already exists.
    $existing = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'appverse_software',
        'title' => $entry['title'],
      ]);
    if (!empty($existing)) {
      $messages[] = "Software already exists: {$entry['title']}";
      continue;
    }

    // Resolve license term.
    $license_tid = _ood_software_get_or_create_term($entry['license'], 'appverse_license');

    // Resolve tag terms.
    $tag_tids = [];
    foreach ($entry['tags'] as $tag_name) {
      $tid = _ood_software_get_or_create_term($tag_name, 'tags');
      if ($tid) {
        $tag_tids[] = ['target_id' => $tid];
      }
    }

    $node_data = [
      'type' => 'appverse_software',
      'title' => $entry['title'],
      'uid' => 1985,
      'body' => [
        'value' => $entry['body'],
        'format' => 'markdown',
      ],
      'field_license' => $license_tid ? ['target_id' => $license_tid] : NULL,
      'field_tags' => $tag_tids,
      'status' => 1,
    ];

    if (!empty($entry['website'])) {
      $node_data['field_appverse_software_website'] = [
        'uri' => $entry['website'],
        'title' => '',
      ];
    }

    $node = Node::create($node_data);
    $node->save();
    $messages[] = "Created software: {$entry['title']} (nid={$node->id()})";
  }

  // --- 2. Create CSCfi organization term ---
  $org_tid = _ood_software_get_or_create_term('CSC - IT Center for Science', 'appverse_organization');
  $messages[] = "Organization term: CSC - IT Center for Science (tid=$org_tid)";

  // --- 3. Create app nodes ---
  $apps = [
    [
      'title' => 'Jupyter',
      'software' => 'jupyter',
      'github_url' => 'https://github.com/CSCfi/ood-base-jupyter',
      'app_type' => 'batch-connect-basic',
    ],
    [
      'title' => 'RStudio',
      'software' => 'RStudio',
      'github_url' => 'https://github.com/CSCfi/ood-rstudio',
      'app_type' => 'batch-connect-basic',
    ],
    [
      'title' => 'Visual Studio Code',
      'software' => 'Visual Studio Code',
      'github_url' => 'https://github.com/CSCfi/ood-vscode',
      'app_type' => 'batch-connect-basic',
    ],
    [
      'title' => 'TensorBoard',
      'software' => 'TensorBoard',
      'github_url' => 'https://github.com/CSCfi/ood-tensorboard',
      'app_type' => 'batch-connect-basic',
    ],
    [
      'title' => 'Desktop',
      'software' => 'Desktop',
      'github_url' => 'https://github.com/CSCfi/ood-vnc-util',
      'app_type' => 'batch-connect-VNC',
    ],
    [
      'title' => 'Compute Node Shell',
      'software' => 'SSH Terminal',
      'github_url' => 'https://github.com/CSCfi/ood-persistent-ssh',
      'app_type' => 'batch-connect-basic',
    ],
    [
      'title' => 'Cloud Storage Configuration',
      'software' => 'Cloud Storage Configuration',
      'github_url' => 'https://github.com/CSCfi/ood-cloud-storage-conf',
      'app_type' => 'companion_app',
    ],
  ];

  // Resolve MIT license term (used for all apps).
  $mit_tid = _ood_software_get_or_create_term('MIT', 'appverse_license');

  $app_count = 0;
  foreach ($apps as $app) {
    // Skip if GitHub URL already exists.
    $existing = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'appverse_app',
        'field_appverse_github_url' => $app['github_url'],
      ]);
    if (!empty($existing)) {
      $messages[] = "App already exists: {$app['title']} ({$app['github_url']})";
      continue;
    }

    // Look up software node.
    $software_nid = NULL;
    $software_nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'appverse_software',
        'title' => $app['software'],
      ]);
    if (!empty($software_nodes)) {
      $software_nid = reset($software_nodes)->id();
    }
    else {
      $messages[] = "WARNING: Software not found for app {$app['title']}: {$app['software']}";
    }

    // Resolve app type term.
    $app_type_tid = _ood_software_get_or_create_term($app['app_type'], 'appverse_app_type');

    $node = Node::create([
      'type' => 'appverse_app',
      'title' => $app['title'],
      'uid' => 1985,
      'field_appverse_software_implemen' => $software_nid ? ['target_id' => $software_nid] : NULL,
      'field_appverse_github_url' => [
        'uri' => $app['github_url'],
        'title' => '',
      ],
      'field_appverse_organization' => $org_tid ? ['target_id' => $org_tid] : NULL,
      'field_appverse_app_type' => $app_type_tid ? ['target_id' => $app_type_tid] : NULL,
      'field_license' => $mit_tid ? ['target_id' => $mit_tid] : NULL,
      'status' => 1,
      'moderation_state' => 'published',
    ]);

    $node->save();
    $app_count++;
    $messages[] = "Created app: {$app['title']} (nid={$node->id()})";
  }

  $summary = implode("\n", $messages);
  \Drupal::logger('ood_software')->notice("CSCfi import:\n$summary");
  return t("Created @count CSCfi apps:\n@summary", [
    '@count' => $app_count,
    '@summary' => $summary,
  ]);
}

/**
 * Remove parent-level tags from appverse_software nodes' field_tags.
 *
 * Some Software entries were assigned parent terms from the 'tags' vocabulary
 * (Cloud, Data Storage, Gateways and Portals, Linux and Shell Scripting, etc.)
 * when they should only carry leaf-level tags. This strips any term that has
 * children from field_tags on appverse_software nodes. Parent terms are kept
 * in the vocabulary — they're needed for the hierarchy — only the references
 * from Software nodes are removed.
 */
function ood_software_deploy_10012_remove_parent_tags_from_software() {
  $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  $node_storage = \Drupal::entityTypeManager()->getStorage('node');

  // Find all terms in 'tags' that have at least one child.
  $all_terms = $term_storage->loadTree('tags', 0, NULL, FALSE);
  $parent_tids = [];
  foreach ($all_terms as $term_data) {
    $children = $term_storage->loadChildren($term_data->tid);
    if (!empty($children)) {
      $parent_tids[(int) $term_data->tid] = $term_data->name;
    }
  }

  if (empty($parent_tids)) {
    \Drupal::logger('ood_software')->notice('No parent terms found in tags vocab; nothing to strip.');
    return t('No parent terms found in tags vocab.');
  }

  // Find appverse_software nodes referencing any parent term in field_tags.
  $nids = \Drupal::entityQuery('node')
    ->condition('type', 'appverse_software')
    ->condition('field_tags', array_keys($parent_tids), 'IN')
    ->accessCheck(FALSE)
    ->execute();

  $messages = [];
  $count = 0;
  foreach ($nids as $nid) {
    $node = $node_storage->load($nid);
    if (!$node) {
      continue;
    }

    $tags = $node->get('field_tags')->getValue();
    $stripped = [];
    $kept = [];
    foreach ($tags as $item) {
      $tid = (int) $item['target_id'];
      if (isset($parent_tids[$tid])) {
        $stripped[] = $parent_tids[$tid];
      }
      else {
        $kept[] = $item;
      }
    }
    if (!empty($stripped)) {
      $node->set('field_tags', $kept);
      $node->save();
      $messages[] = "Software '{$node->getTitle()}' (nid=$nid): removed [" . implode(', ', $stripped) . ']';
      $count++;
    }
  }

  $summary = $count ? implode("\n", $messages) : 'No Software nodes carried parent tags.';
  \Drupal::logger('ood_software')->notice("Parent-tag cleanup on Software:\n@summary", ['@summary' => $summary]);
  return t("Removed parent tags from @count Software nodes:\n@summary", [
    '@count' => $count,
    '@summary' => $summary,
  ]);
}

/**
 * Singularize the dashboards/widgets app type term names.
 *
 * The value names what a single app is, so singular is the correct form.
 * `dashboards`/`widgets` were the only plural outliers (10008 renamed them
 * to plural to match APP_TYPE_MANIFEST_MAP; that map is now singular too).
 * Renames in place, preserving the term ID, so every app node reference
 * follows automatically. Idempotent: re-runs no-op once the singular term
 * exists.
 */
function ood_software_deploy_10013_singularize_app_types() {
  $renames = [
    'dashboards' => 'dashboard',
    'widgets'    => 'widget',
  ];
  $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  $count = 0;
  foreach ($renames as $old_name => $new_name) {
    // Idempotency: if the singular term already exists, nothing to do.
    $existing = $storage->loadByProperties(['name' => $new_name, 'vid' => 'appverse_app_type']);
    if (!empty($existing)) {
      continue;
    }
    $old = $storage->loadByProperties(['name' => $old_name, 'vid' => 'appverse_app_type']);
    if (!empty($old)) {
      $term = reset($old);
      $term->setName($new_name);
      $term->save();
      $count++;
    }
  }
  return t('Singularized @count app type terms.', ['@count' => $count]);
}

/**
 * Backfill inferred repos for legacy Apps with NULL field_appverse_repo.
 *
 * Creates one appverse_repo per legacy appverse_app that has no
 * field_appverse_repo yet, links the App to it, and lets RepoSyncService
 * populate the repo's metadata. Chunked via $sandbox to bound memory.
 *
 * This is a deploy hook (not an update or post_update hook) on purpose: the
 * backfill queries field_appverse_repo and field_repo_url and writes the whole
 * field_repo_* family, all of which are defined only in config/default and are
 * created by config import. In `drush deploy`, deploy hooks run in the final
 * deploy:hook step — after config:import — so every field exists here. Running
 * this during the update phase (update_10503, or a post_update hook) was too
 * early and failed with "getColumns() on false" for every App.
 */
function ood_software_deploy_10014_backfill_inferred_repos(&$sandbox) {
  $entityTypeManager = \Drupal::entityTypeManager();

  if (!isset($sandbox['progress'])) {
    $sandbox['progress'] = 0;
    $sandbox['ids'] = array_values($entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appverse_app')
      ->notExists('field_appverse_repo')
      ->execute());
    $sandbox['total'] = count($sandbox['ids']);
  }

  if ($sandbox['total'] === 0) {
    $sandbox['#finished'] = 1;
    return t('No Apps needed backfill.');
  }

  $chunk = array_slice($sandbox['ids'], $sandbox['progress'], 25);
  $context = ['results' => []];
  \Drupal\ood_software\Commands\AppverseBackfillCommands::processChunk($chunk, $context);

  $sandbox['progress'] += count($chunk);
  $sandbox['#finished'] = $sandbox['progress'] / max(1, $sandbox['total']);

  return t('Backfilled @progress of @total Apps.', [
    '@progress' => $sandbox['progress'],
    '@total' => $sandbox['total'],
  ]);
}
