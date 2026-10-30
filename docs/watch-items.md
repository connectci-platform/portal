# Watch Items

Things we are waiting on that need to be re-assessed periodically. Run `/watch-items`
in Claude Code to check every entry and get a report.

Each entry needs:

- **Current** — what we run today (version, patch, workaround).
- **Trigger** — the condition that means it is time to act.
- **Check** — how to verify the trigger (URL, command, issue link).
- **Action** — what to do once the trigger is met.
- **Last checked** — date and result, updated on each run.

When an item is resolved, delete it from this file.

---

## openid_connect — waiting on a stable 3.x release

- **Current:** `drupal/openid_connect` 8.x-1.5 (`composer.json`: `^1.4`). Used by the
  custom `openid_connect_cilogon_client` module, which `drupal_seamless_cilogon`
  depends on.
- **Trigger:** a 3.x release with no `alpha`/`beta`/`rc` in the version (for example
  `3.0.0`).
- **Check:** `curl -s https://updates.drupal.org/release-history/openid_connect/current`
  and look at the `<version>` tags. Project page:
  https://www.drupal.org/project/openid_connect
- **Action:** draft a Jira ticket to upgrade from 1.x to 3.x. Review the 3.x upgrade
  path and the plugin API changes affecting `openid_connect_cilogon_client`.
- **Last checked:** 2026-10-13 — latest is 3.0.0-alpha9 (2026-08-31). Not triggered.

## domain_menu_access — waiting on issue #3557310 to be merged and released

- **Current:** `drupal/domain_menu_access` pinned to the issue fork branch
  `dev-3557310-incompatibility-between-domain#2594665dbd1797ce1122082300ceb0c7219f611a`,
  pulled from the `domain_menu_access` VCS repository in `composer.json`
  (`https://git.drupalcode.org/issue/domain_menu_access-3557310.git`). The fork fixes
  the incompatibility between Domain Menu Access 2.x and Domain 3.x.
- **Trigger:** the fix from issue #3557310 is committed upstream **and** included in a
  tagged release newer than 2.0.1 (the latest release when this was pinned).
- **Check:** issue status at
  https://www.drupal.org/project/domain_menu_access/issues/3557310 (or
  `curl -sL "https://www.drupal.org/api-d7/node.json?nid=3557310"` and read
  `field_issue_status`; `2` = fixed, `7` = closed (fixed)). Then check releases with
  `curl -s https://updates.drupal.org/release-history/domain_menu_access/current` and
  confirm the new release contains the fix.
- **Action:** remove the `domain_menu_access` entry from `repositories` in
  `composer.json`, change the requirement back to a normal constraint (for example
  `^2.0.2`), run `composer update drupal/domain_menu_access`, and verify domain-specific
  menu visibility still works.
- **Last checked:** 2026-10-14 — issue status is Needs review (`8`); latest release is
  2.0.1. Not triggered.

## email_change_verification — waiting on a Drupal 11 release (or deprecation)

- **Current:** `drupal/email_change_verification` 1.0.0 (`composer.json`: `^1.0@beta`),
  whose `.info.yml` and composer metadata only declare `^9.1 || ^10`. We carry
  `patches/email_change_verification-d11-3430085.patch` (from MR !7 on issue #3430085,
  which only adds `|| ^11` to `core_version_requirement`) and allow the package in
  `extra.drupal-lenient.allowed-list` via `mglaman/composer-drupal-lenient` so composer
  will install it alongside Drupal 11 core. A PHPStan deprecation scan of the module
  came back clean on 2026-10-27.
- **Trigger:** either (a) a tagged release whose core compatibility includes `^11`, or
  (b) the module is deprecated / marked unsupported in favor of Email Confirmer (see
  issue #3337087, "Deprecate in favour of Email Confirmer?").
- **Check:** `curl -s https://updates.drupal.org/release-history/email_change_verification/current`
  and read the `<version>` / `<core_compatibility>` tags. For the deprecation question,
  read issue #3337087 and the project page
  https://www.drupal.org/project/email_change_verification for a status change.
- **Action:** (a) update to the new release, remove the patch from
  `composer.patches.json`, delete the patch file, remove
  `drupal/email_change_verification` from `extra.drupal-lenient.allowed-list` (and drop
  `mglaman/composer-drupal-lenient` if the list is then empty), then run
  `composer patches-relock` and `composer patches-repatch`. (b) draft a Jira ticket to
  migrate to `drupal/email_confirmer` + `email_confirmer_user`: port the subject, body,
  message, and 8-hour timeout from `email_change_verification.config`, and decide how to
  handle pending `mail_change` values at cutover.
- **Last checked:** 2026-10-27 — latest release is 1.0.0 (`^9.1 || ^10`); MR !7 (D11)
  open and unmerged; issue #3337087 open. Not triggered.
