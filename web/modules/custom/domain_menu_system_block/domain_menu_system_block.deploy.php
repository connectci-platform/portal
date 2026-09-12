<?php

/**
 * @file
 * Deployment functions for domain_menu_system_block.
 *
 * Migrates menu link visibility off CSS classes and onto domain_menu_access
 * fields, so links are filtered server-side instead of being sent to every
 * user and hidden in the browser. See D8-2795.
 *
 * ORDERING MATTERS. menu_item_extras turns each menu into its own
 * menu_link_content bundle. domain_menu_access attaches its fields per bundle,
 * so uninstalling menu_item_extras AFTER tagging destroys both the fields and
 * every value stored in them. It must go first, while there is nothing to lose.
 *
 * That ordering is enforced across three phases of `drush deploy`, not just
 * within this file:
 *
 *   updatedb       ood_software_update_10506 clears the view_mode column,
 *                  which otherwise blocks the uninstall.
 *   config:import  core.extension.yml omits menu_item_extras and adds
 *                  domain_menu_access, so the swap happens here.
 *   deploy:hook    the hooks below, which assume both are already true and
 *                  verify it in 10001.
 *
 * WHAT THE DOMAIN FIELD MEANS. It says the link is *allowed on* a domain, not
 * that it *appears on* one. Three layers decide whether a user sees a menu
 * link, and they are independent:
 *
 *   1. Theme        — each domain uses one theme, and blocks are placed per
 *                     theme.
 *   2. Block domain — most menu blocks carry a `domain` visibility condition
 *      conditions     deciding whether that menu renders at all on a domain.
 *                     Several are negated against amp_cyberinfrastructure.
 *   3. This field   — which links survive inside a menu that did render.
 *
 * So a link can list a domain whose theme never places that menu, and it will
 * still never be seen there. "News" is an example: it was `hide-ccmnet`, so it
 * migrated to the other twelve domains including amp_cyberinfrastructure,
 * where the main menu block is negated out entirely.
 *
 * That looks wrong when reading the field alone, and it is tempting to
 * "correct" it by computing the intersection with block placement during
 * migration. Do not. It would couple link data to block configuration, so
 * moving a block later would silently make the link data wrong with nothing
 * to flag it. Each layer answering its own question is the maintainable shape;
 * the field is accurate about permission, block conditions about placement.
 */

use Drupal\domain_access\DomainAccessManagerInterface;

/**
 * Map of CSS class suffix to domain entity id.
 *
 * `display-open-ondemand` and `hide-open-ondemand` both key on
 * 'open-ondemand'.
 *
 * This is hand-maintained on purpose. There are two related-but-different
 * domain slugs in play and neither yields the other:
 *
 *   - The BODY class, from Html::getClass('[domain:name]') — see
 *     SiteTools::getDomain() in access_misc, and the preprocess in
 *     nect.theme. That gives the long form, e.g. 'careers-cyberteam'.
 *   - The ELEMENT class an editor typed, which is the short form, e.g.
 *     'display-careers'.
 *
 * The `region` taxonomy's field_region_connected_domain stores the long form
 * per region, so it looks like the map could come from there — but only 4 of
 * the 10 suffixes match it exactly. The other 6 differ by a '-cyberteam'
 * suffix that is applied inconsistently ('campus-champions' and 'pa-science'
 * have none, 'careers' does), so there is no rule to derive one from the
 * other. Hence the explicit list.
 *
 * Three suffixes in the menu data (trecis, rmacc, sweeter) have no domain
 * record, though the region taxonomy does still carry those regions. They are
 * dropped here, and every link using them also carries a valid suffix, so
 * nothing loses its last domain.
 */
const DOMAIN_MENU_SYSTEM_BLOCK_CLASS_MAP = [
  'campus-champions' => 'campuschampions_cyberinfrastructure_org',
  'careers' => 'careersct_cyberinfrastucture_org',
  'ccmnet' => 'ccmnet_org',
  'coco' => 'coco_cyberinfrastructure_org',
  'great-plains' => 'greatplains_wpi_edu',
  'kentucky' => 'kycyberteam_cyberinfrastructure_org',
  'mines' => 'mines_cyberinfrastructure_org',
  'northeast' => 'nectd8_wpi_edu',
  'open-ondemand' => 'openondemand_cyberinfrastructure_org',
  'pa-science' => 'pasciencedmz_connectci_org',
];

/**
 * Confirm menu_item_extras is gone before anything depends on that.
 *
 * The uninstall itself happens earlier in the deploy: core.extension.yml no
 * longer lists the module, so config:import performs it, and
 * ood_software_update_10506 clears the view_mode column during updatedb so
 * that uninstall is not blocked. Both run before deploy hooks.
 *
 * This only verifies the outcome. If the module is still here, the migration
 * below would write domain fields onto per-menu bundles that are about to
 * collapse, destroying them — better to stop.
 */
function domain_menu_system_block_deploy_10001_verify_menu_item_extras_gone(): string {
  if (\Drupal::service('module_handler')->moduleExists('menu_item_extras')) {
    throw new \RuntimeException(
      'menu_item_extras is still installed. It should have been uninstalled by '
      . 'config:import earlier in this deploy. Check that core.extension.yml omits '
      . 'it and that ood_software_update_10506 ran. Migrating menu links now would '
      . 'attach domain fields to bundles that are about to disappear.'
    );
  }

  $bundles = \Drupal::service('entity_type.bundle.info')->getBundleInfo('menu_link_content');
  if (count($bundles) !== 1) {
    throw new \RuntimeException(sprintf(
      'menu_link_content still has %d bundles; expected 1. Something else is '
      . 'splitting the entity by menu.',
      count($bundles)
    ));
  }

  return (string) t('menu_item_extras is gone and menu_link_content has a single bundle.');
}

/**
 * Confirm domain_menu_access installed and created its fields.
 *
 * config:import installs it (core.extension.yml lists it), and its hook_install
 * creates the two domain fields. That hook fails silently if menu_link_content
 * still has multiple bundles, leaving the module registered with no fields —
 * in which case the migration below would write nothing and report success.
 */
function domain_menu_system_block_deploy_10002_verify_domain_fields(): string {
  if (!\Drupal::service('module_handler')->moduleExists('domain_menu_access')) {
    throw new \RuntimeException(
      'domain_menu_access is not installed. config:import should have installed it; '
      . 'check core.extension.yml.'
    );
  }

  $fields = \Drupal::service('entity_field.manager')
    ->getFieldDefinitions('menu_link_content', 'menu_link_content');
  if (!isset($fields[DomainAccessManagerInterface::DOMAIN_ACCESS_FIELD])) {
    throw new \RuntimeException(
      'domain_menu_access is installed but its domain fields are missing, so the '
      . 'migration would silently do nothing. Its hook_install bails when '
      . 'menu_link_content has more than one bundle.'
    );
  }

  return (string) t('domain_menu_access is installed and its domain fields are present.');
}

/**
 * Move visibility from CSS classes onto the domain fields.
 *
 * Allowlist links (`region-specific` + `display-*`) map to the named domains.
 * Denylist links (`hide-*` only) are inverted to every other domain, which is
 * what the CSS did. Links with neither are left alone.
 *
 * @param array<string, mixed> $sandbox
 *   Batch state carried between invocations of this migration.
 *
 * @return string
 *   A summary once finished, or an empty string while batching.
 */
function domain_menu_system_block_deploy_10003_migrate_visibility(array &$sandbox): string {
  $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');

  if (!isset($sandbox['progress'])) {
    $sandbox['ids'] = array_values(\Drupal::entityQuery('menu_link_content')
      ->accessCheck(FALSE)
      ->execute());
    $sandbox['progress'] = 0;
    $sandbox['total'] = count($sandbox['ids']);
    $sandbox['migrated'] = 0;
    $sandbox['skipped'] = 0;
    $sandbox['conflicting'] = [];
  }

  $all_domains = array_keys(\Drupal::entityTypeManager()
    ->getStorage('domain')->loadMultiple());

  $slice = array_slice($sandbox['ids'], $sandbox['progress'], 25);
  foreach ($storage->loadMultiple($slice) as $link) {
    $sandbox['progress']++;

    $item = $link->link->first();
    $options = $item ? ($item->get('options')->getValue() ?: []) : [];
    $classes = _domain_menu_system_block_classes($options);
    if (!$classes) {
      $sandbox['skipped']++;
      continue;
    }

    $show = [];
    $hide = [];
    foreach ($classes as $class) {
      if (str_starts_with($class, 'display-')) {
        $key = substr($class, 8);
        if (isset(DOMAIN_MENU_SYSTEM_BLOCK_CLASS_MAP[$key])) {
          $show[] = DOMAIN_MENU_SYSTEM_BLOCK_CLASS_MAP[$key];
        }
      }
      elseif (str_starts_with($class, 'hide-')) {
        $key = substr($class, 5);
        if (isset(DOMAIN_MENU_SYSTEM_BLOCK_CLASS_MAP[$key])) {
          $hide[] = DOMAIN_MENU_SYSTEM_BLOCK_CLASS_MAP[$key];
        }
      }
    }

    if ($show) {
      // A link can carry both, e.g. `display-ccmnet hide-pa-science`. The
      // allowlist already excludes everything not named, so hide-* is
      // redundant there — but subtract it anyway rather than ignoring it, so
      // the two can never disagree, and record it since 10004 destroys the
      // original classes.
      $domains = array_values(array_diff(array_unique($show), $hide));
      if ($hide) {
        $sandbox['conflicting'][] = $link->id();
        \Drupal::logger('domain_menu_system_block')->notice(
          'Link @id (@title) had both display-* and hide-* classes; allowlist @allow minus @hide.',
          ['@id' => $link->id(), '@title' => $link->getTitle(),
           '@allow' => implode(',', $show), '@hide' => implode(',', $hide)]);
      }
      if (!$domains) {
        // hide-* cancelled every display-*. Nothing sensible to migrate;
        // leave the classes in place for a human rather than hiding it
        // everywhere.
        $sandbox['skipped']++;
        continue;
      }
    }
    elseif ($hide) {
      $domains = array_values(array_diff($all_domains, $hide));
    }
    else {
      // Only `region-specific` with no usable suffix, or only orphan
      // suffixes. Leaving it visible everywhere matches the current
      // behaviour on themes that lack the CSS.
      $sandbox['skipped']++;
      continue;
    }

    $link->set(DomainAccessManagerInterface::DOMAIN_ACCESS_FIELD,
      array_map(fn($id) => ['target_id' => $id], $domains));
    $link->set(DomainAccessManagerInterface::DOMAIN_ACCESS_ALL_FIELD, 0);
    $link->save();
    $sandbox['migrated']++;
  }

  $sandbox['#finished'] = $sandbox['total'] ? $sandbox['progress'] / $sandbox['total'] : 1;

  if ($sandbox['#finished'] < 1) {
    return '';
  }

  return (string) t('Migrated @m links to domain fields, skipped @s with no usable domain classes. @c link(s) carried both display-* and hide-*: @ids', [
      '@m' => $sandbox['migrated'],
      '@s' => $sandbox['skipped'],
      '@c' => count($sandbox['conflicting']),
      '@ids' => implode(', ', $sandbox['conflicting']) ?: 'none',
    ]);
}

/**
 * Mark every link that has no domain scoping as available on all domains.
 *
 * domain_menu_access forbids a link whenever `all_affiliates` is off and the
 * active domain is not in its domain list — and an empty list matches nothing,
 * so a link that was never scoped disappears from every domain rather than
 * appearing on all of them.
 *
 * Links the migration touched already carry an explicit domain list. This
 * covers everything else, which is the majority: they had no visibility
 * classes and were therefore visible everywhere.
 *
 * @param array<string, mixed> $sandbox
 *   Batch state carried between invocations of this pass.
 *
 * @return string
 *   A summary once finished, or an empty string while batching.
 */
function domain_menu_system_block_deploy_10003b_default_to_all_affiliates(array &$sandbox): string {
  $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');

  if (!isset($sandbox['progress'])) {
    $sandbox['ids'] = array_values(\Drupal::entityQuery('menu_link_content')
      ->accessCheck(FALSE)
      ->execute());
    $sandbox['progress'] = 0;
    $sandbox['total'] = count($sandbox['ids']);
    $sandbox['opened'] = 0;
    $sandbox['orphaned'] = [];
  }

  $slice = array_slice($sandbox['ids'], $sandbox['progress'], 25);
  foreach ($storage->loadMultiple($slice) as $link) {
    $sandbox['progress']++;

    // A handful of links keep a stale bundle value from menu_item_extras
    // (menu_item_extras made each menu a bundle; uninstalling it collapsed the
    // definitions but left these rows pointing at bundles that no longer
    // exist), so the domain fields are not attached to them. Nothing can scope
    // them and nothing needs to.
    if (!$link->hasField(DomainAccessManagerInterface::DOMAIN_ACCESS_FIELD)) {
      $sandbox['orphaned'][] = $link->id();
      continue;
    }

    // Leave anything the migration scoped to specific domains alone.
    if ($link->get(DomainAccessManagerInterface::DOMAIN_ACCESS_FIELD)->getValue()) {
      continue;
    }
    if ($link->get(DomainAccessManagerInterface::DOMAIN_ACCESS_ALL_FIELD)->value) {
      continue;
    }

    $link->set(DomainAccessManagerInterface::DOMAIN_ACCESS_ALL_FIELD, 1);
    $link->save();
    $sandbox['opened']++;
  }

  $sandbox['#finished'] = $sandbox['total'] ? $sandbox['progress'] / $sandbox['total'] : 1;

  if ($sandbox['#finished'] < 1) {
    return '';
  }

  return (string) t('Marked @count unscoped links as available on all domains. @orphans link(s) skipped for having a stale bundle with no domain field: @ids', [
      '@count' => $sandbox['opened'],
      '@orphans' => count($sandbox['orphaned']),
      '@ids' => implode(', ', $sandbox['orphaned']) ?: 'none',
    ]);
}

/**
 * Strip the visibility classes now the domain fields carry the meaning.
 *
 * Only removes `region-specific`, `display-*` and `hide-*`. Other classes are
 * styling hooks and are preserved.
 *
 * @param array<string, mixed> $sandbox
 *   Batch state carried between invocations of this pass.
 *
 * @return string
 *   A summary once finished, or an empty string while batching.
 */
function domain_menu_system_block_deploy_10004_strip_visibility_classes(array &$sandbox): string {
  $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');

  if (!isset($sandbox['progress'])) {
    $sandbox['ids'] = array_values(\Drupal::entityQuery('menu_link_content')
      ->accessCheck(FALSE)
      ->execute());
    $sandbox['progress'] = 0;
    $sandbox['total'] = count($sandbox['ids']);
    $sandbox['stripped'] = 0;
  }

  $slice = array_slice($sandbox['ids'], $sandbox['progress'], 25);
  foreach ($storage->loadMultiple($slice) as $link) {
    $sandbox['progress']++;

    $item = $link->link->first();
    $options = $item ? ($item->get('options')->getValue() ?: []) : [];
    $classes = _domain_menu_system_block_classes($options);
    if (!$classes) {
      continue;
    }

    $keep = array_values(array_filter($classes, fn($c) =>
      $c !== 'region-specific'
      && !str_starts_with($c, 'display-')
      && !str_starts_with($c, 'hide-')));

    if (count($keep) === count($classes)) {
      continue;
    }

    if ($keep) {
      $options['attributes']['class'] = $keep;
    }
    else {
      unset($options['attributes']['class']);
      if (empty($options['attributes'])) {
        unset($options['attributes']);
      }
    }

    $item->set('options', $options);
    $link->save();
    $sandbox['stripped']++;
  }

  $sandbox['#finished'] = $sandbox['total'] ? $sandbox['progress'] / $sandbox['total'] : 1;

  if ($sandbox['#finished'] < 1) {
    return '';
  }

  return (string) t('Removed visibility classes from @count links.', [
      '@count' => $sandbox['stripped'],
    ]);
}

/**
 * Make menu_item_role_access actually check internal links.
 *
 * Its checkUrl() only applies role restrictions to non-routed links unless
 * this is TRUE, so every internal link has been skipping its menu_item_roles
 * entirely. This activates the restrictions already configured on 18 links.
 */
function domain_menu_system_block_deploy_10005_enable_internal_role_checks(): string {
  $config = \Drupal::configFactory()
    ->getEditable('menu_item_role_access.config');

  if ($config->get('overwrite_internal_link_target_access')) {
    return t('Internal link role checking is already enabled.');
  }

  $config->set('overwrite_internal_link_target_access', TRUE)->save();

  return (string) t('Enabled role checking for internal menu links.');
}

/**
 * Flatten a link's class attribute into a list of individual class names.
 *
 * The attribute is stored inconsistently: sometimes a list of single classes,
 * sometimes one string holding several space-separated classes.
 *
 * @param array<string, mixed> $options
 *   The link item's options array.
 *
 * @return list<string>
 *   Individual class names.
 */
function _domain_menu_system_block_classes(array $options): array {
  $raw = $options['attributes']['class'] ?? [];
  if (is_string($raw)) {
    $raw = [$raw];
  }

  $classes = [];
  foreach ((array) $raw as $chunk) {
    foreach (preg_split('/\s+/', trim((string) $chunk)) as $class) {
      if ($class !== '') {
        $classes[] = $class;
      }
    }
  }
  return $classes;
}
