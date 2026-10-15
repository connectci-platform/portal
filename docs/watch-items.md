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
