# Domain Menu Access for core menu blocks

Applies `domain_menu_access` filtering to core's `system_menu_block`.

## Why this exists

`domain_menu_access` filters menu links by domain, but only inside its own
block plugin and a submodule for `menu_block`. Core's `SystemMenuBlock`
hardcodes its manipulator list, so menus placed with the core plugin get no
domain filtering at all.

This module implements `hook_block_alter()` to repoint the
`system_menu_block:*` derivatives at a subclass that inserts
`domain_menu_access`'s `checkDomain` manipulator. No block configuration
changes; the swap is invisible to editors. Only `class` is altered, never
`provider` — the `.module` file explains why that matters on uninstall.

It also declares the `domain` cache context, which keys on the negotiated
domain rather than the hostname. Note this does not currently reduce cache
fragmentation: `checkDomain()` adds `url.site` to its access results and that
bubbles into the block. Declaring `domain` still states the block's real
dependency rather than relying on a contrib module's incidental cacheability,
but collapsing the fragmentation needs an upstream change.

## Reading the domain field

The field on a menu link says the link is **allowed on** those domains. It
does not say the link **appears on** them. Three independent layers decide
what a user actually sees:

| Layer | Decides |
|---|---|
| Theme | Which blocks exist, since blocks are placed per theme and each domain uses one theme |
| Block `domain` visibility condition | Whether a menu renders at all on a domain |
| Menu link domain field | Which links survive inside a menu that did render |

So a link can list a domain whose theme never places that menu — the field is
answering a different question from block placement, not contradicting it.

It is tempting to "fix" that by intersecting link domains with block placement
during migration. Don't. It couples link data to block configuration, so
moving a block later would silently invalidate the link data with nothing to
flag it.

## The CSS is still load-bearing

The `region-specific` / `display-*` / `hide-*` rules in nect-theme look dead
once menu links stop using them. They are not. Editor-authored body content
uses the same classes for per-domain visibility, and nect-theme's `global.js`
removes `hide-*` elements outright.

Removing those rules would make that content appear on every domain at once.
Grep the `node__body` and `block_content__body` tables for `region-specific`
before touching them. D8-2799 covers what body content should do instead.

There are two related slugs and neither derives the other. The body class is
the domain label slugified — `Html::getClass('[domain:name]')`, see
`SiteTools::getDomain()` in access_misc — giving the long form
`careers-cyberteam`. The element class editors typed is the short
`display-careers`. The `region` taxonomy's `field_region_connected_domain`
stores the long form, so it looks usable as a map, but only some suffixes
match; the rest differ by an inconsistently-applied `-cyberteam`. Hence the
explicit map in the deploy file.

## menu_item_extras stays in composer

This change uninstalls `menu_item_extras` but leaves it in `composer.json` and
on disk, deliberately. Its `hook_uninstall()` collapses the per-menu bundles
back to one and drops two columns, and `config:import` can only run that hook
if the code is still there. Removing the package in the same change would
uninstall against missing PHP and leave the entity half-converted.

Dropping it from composer is a follow-up once this has deployed — D8-2798.

## How the migration is sequenced

`menu_item_extras` must be gone before `domain_menu_access` is installed — it
makes each menu its own bundle, and `domain_menu_access` attaches its fields
per bundle, so removing it afterwards destroys the fields and everything
stored in them.

That ordering spans all three phases of `drush deploy`, not just this module's
deploy hooks:

| Phase | What happens |
|---|---|
| `updatedb` | `ood_software_update_10506` clears the `view_mode` column |
| `config:import` | `core.extension.yml` swaps the modules — uninstalls `menu_item_extras`, installs `domain_menu_access` |
| `deploy:hook` | The hooks below migrate the data |

The `updatedb` step is not optional. `menu_item_extras` has an uninstall
validator that refuses while any link has a non-NULL `view_mode`, so without
it `config:import` fails outright and the deploy stops. It lives in
`ood_software` because this module is not installed yet when `updatedb` runs,
so its own update hooks would not fire.

| Hook | Does |
|---|---|
| `10001` | Verify `menu_item_extras` is gone and there is one bundle |
| `10002` | Verify `domain_menu_access` installed and created its fields |
| `10003` | Migrate links from CSS classes to domain fields |
| `10003b` | Mark unscoped links as available on all domains |
| `10004` | Strip the now-redundant visibility classes |
| `10005` | Enable role checks on internal links |

10001 and 10002 only check. If an earlier phase did not do its job they throw
rather than let the migration write fields onto bundles that are about to
disappear, or report success having silently written nothing.

`10003b` matters more than it looks. `domain_menu_access` treats an empty
domain list as matching nothing, so a link that was never scoped would
disappear from every domain rather than appearing on all of them.

`10005` activates role restrictions that were configured but never enforced —
`menu_item_role_access` only checks non-routed links unless
`overwrite_internal_link_target_access` is TRUE. Expect some links to become
hidden from people who previously saw them and got an access-denied page.

Links whose bundle no longer exists have no domain fields attached; `10003b`
skips those and reports their ids rather than failing.

## Tests

`TreeParametersDriftTest` guards the menu parameter logic in
`DomainSystemMenuBlock::treeParameters()`. Core builds and renders the tree in
one method with no seam to reuse, so that logic is duplicated here. The test
fails if core's version changes, meaning go and reconcile the two.

See D8-2795.
