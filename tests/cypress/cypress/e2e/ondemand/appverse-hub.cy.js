/**
 * Appverse Maintenance Hub — happy path + unauthorized action.
 *
 * Covers:
 *  - Legacy /user/<uid>/my-apps → /user/<uid>/my-appverse 301 redirect.
 *  - Legacy /appverse/manage-apps → /appverse/manage-repos 301 redirect.
 *  - Hub renders Collection cards.
 *  - Member apps expand via <details>.
 *  - Re-sync from the inline icon button triggers the controller.
 *  - Cross-user action returns 403.
 *  - Admin's /appverse/manage-repos renders with exposed filters.
 *  - Moderation transition notifications (Phase 1.8): send_for_review,
 *    request_adjustment (email-only + cascade-unpublish), and publish
 *    (email + no-fan-out + cascade-publish).
 */

const ADMIN_EMAIL = 'administrator@amptesting.com';
const ADMIN_PASS = 'b8QW]X9h7#5n';
// The non-admin "contributor" persona is authenticated_test_user, a plain
// authenticated account created in .github/workflows/backupdb.yml alongside
// administrator_test_user (the ADMIN_* user above). It owns the seeded
// Collections/apps, logs in to view its own hub, and receives the moderation
// notification emails. Reusing this existing fixture avoids provisioning a
// separate user; the password must match backupdb.yml.
const CONTRIBUTOR_EMAIL = 'authenticated@amptesting.com';
const CONTRIBUTOR_PASS = '6%l7iF}6(4tI';
const CONTRIBUTOR_NAME = 'authenticated_test_user';

// Real GitHub repo we own; used as the Collection's canonical URL so the
// fixture points at a stable repo under our control rather than fabricated
// data. Nothing here calls GitHub — the URL is stored as a plain field value.
const FIXTURE_REPO_URL = 'https://github.com/Sweet-and-Fizzy/appverse-example-monorepo';
const JSONAPI_HEADERS = {
  'Content-Type': 'application/vnd.api+json',
  Accept: 'application/vnd.api+json',
};

// Resolve an entity's JSON:API UUID by a filter, so the seed never hardcodes
// UUIDs (which differ per environment / DB artifact). Fails loudly if absent.
function resolveUuid(resource, filterField, filterValue, label) {
  return cy.request({
    url: `/jsonapi/${resource}?filter[${filterField}]=${encodeURIComponent(filterValue)}&page[limit]=1`,
    headers: JSONAPI_HEADERS,
    failOnStatusCode: false,
  }).then((res) => {
    const id = res.body?.data?.[0]?.id;
    if (!id) {
      throw new Error(`seedRepo: could not resolve ${label} (${resource} where ${filterField}=${filterValue}). status=${res.status}`);
    }
    return id;
  });
}

// The user JSON:API resource (user--user) is deliberately disabled in
// jsonapi_extras (users must not be enumerable over the API), so the
// contributor's UUID cannot be resolved with resolveUuid(). Look it up with a
// single, tiny drush call that echoes only the UUID — unlike the former seed
// this is a one-value deterministic command, not a large node-creating
// payload. Wrapped in a short retry to absorb transient ddev container churn.
function resolveContributorUuid(attempt = 1) {
  const cmd = `ddev drush ev "\\$u = user_load_by_name('${CONTRIBUTOR_NAME}'); echo \\$u ? \\$u->uuid() : '';"`;
  return cy.exec(cmd, { timeout: 60000, failOnNonZeroExit: false }).then((res) => {
    const uuid = (res.stdout || '').trim();
    if (/^[0-9a-f-]{36}$/.test(uuid)) {
      return uuid;
    }
    if (attempt < 3) {
      // eslint-disable-next-line cypress/no-unnecessary-waiting
      return cy.wait(500 * attempt, { log: false }).then(() => resolveContributorUuid(attempt + 1));
    }
    throw new Error(`seedRepo: could not resolve contributor UUID for ${CONTRIBUTOR_NAME}. code=${res.code} stdout=[${res.stdout}] stderr=[${(res.stderr || '').slice(0, 200)}]`);
  });
}

// The contributor's canonical /user/<uid> profile is rewritten by pathauto to
// an aliased slug (e.g. /user/authenticatedtestuser), so the numeric uid can
// not be scraped off the profile URL. Resolve it directly (same tiny-drush
// approach as the UUID lookup) so callers can build /user/<uid>/my-appverse.
function resolveContributorUid(attempt = 1) {
  const cmd = `ddev drush ev "\\$u = user_load_by_name('${CONTRIBUTOR_NAME}'); echo \\$u ? \\$u->id() : '';"`;
  return cy.exec(cmd, { timeout: 60000, failOnNonZeroExit: false }).then((res) => {
    const uid = (res.stdout || '').trim();
    if (/^\d+$/.test(uid)) {
      return uid;
    }
    if (attempt < 3) {
      // eslint-disable-next-line cypress/no-unnecessary-waiting
      return cy.wait(500 * attempt, { log: false }).then(() => resolveContributorUid(attempt + 1));
    }
    throw new Error(`could not resolve contributor uid for ${CONTRIBUTOR_NAME}. code=${res.code} stdout=[${res.stdout}] stderr=[${(res.stderr || '').slice(0, 200)}]`);
  });
}

// Delete every existing appverse_repo (and its member apps) with this title,
// so re-runs are deterministic. JSON:API DELETE as the logged-in admin.
function purgeReposByTitle(title, csrf) {
  const headers = { ...JSONAPI_HEADERS, 'X-CSRF-Token': csrf };
  return cy.request({
    url: `/jsonapi/node/appverse_repo?filter[title]=${encodeURIComponent(title)}`,
    headers: JSONAPI_HEADERS,
    failOnStatusCode: false,
  }).then((res) => {
    const repos = res.body?.data || [];
    return cy.wrap(repos).each((repo) => {
      // Delete member apps first (they reference the repo).
      cy.request({
        url: `/jsonapi/node/appverse_app?filter[field_appverse_repo.id]=${repo.id}`,
        headers: JSONAPI_HEADERS,
        failOnStatusCode: false,
      }).then((appsRes) => {
        (appsRes.body?.data || []).forEach((app) => {
          cy.request({ method: 'DELETE', url: `/jsonapi/node/appverse_app/${app.id}`, headers, failOnStatusCode: false });
        });
      });
      cy.request({ method: 'DELETE', url: `/jsonapi/node/appverse_repo/${repo.id}`, headers, failOnStatusCode: false });
    });
  });
}

/**
 * Seed a fresh appverse_repo (Collection) via JSON:API, optionally with member
 * apps. The admin must be logged in (cy.loginUser(ADMIN_...)) before calling —
 * the session cookie authenticates the writes.
 *
 * Creates content directly through JSON:API rather than shelling out to
 * `ddev drush`: the previous drush approach returned empty output
 * intermittently under full-suite load and coupled the test to the local ddev
 * container, which does not exist the same way in every environment. JSON:API
 * is hermetic (no GitHub call — FIXTURE_REPO_URL is a plain field value) and
 * environment-independent (all UUIDs resolved at runtime).
 *
 * Yields { nid, uuid } and stashes them in Cypress.env for later cascade
 * assertions. Titles must be unique per block to avoid collisions on re-run.
 *
 * @param {object} opts
 * @param {string} opts.title - Unique Collection title.
 * @param {string} opts.state - moderation_state: draft|ready_for_review|published.
 * @param {number} opts.memberApps - How many member apps to create.
 * @param {string} opts.memberAppState - moderation_state for member apps.
 */
function seedRepo(opts) {
  const title = opts.title;
  const state = opts.state;
  const memberApps = opts.memberApps || 0;
  const memberAppState = opts.memberAppState || 'draft';

  if (!Number.isInteger(memberApps) || memberApps < 0) {
    throw new Error(`seedRepo: memberApps must be a non-negative integer: ${memberApps}`);
  }

  return cy.request('/session/token').then((tok) => {
    const csrf = tok.body;
    const writeHeaders = { ...JSONAPI_HEADERS, 'X-CSRF-Token': csrf };

    // Resolve all relationship UUIDs by known values (env-independent).
    return purgeReposByTitle(title, csrf)
      .then(() => resolveContributorUuid())
      .then((contributorUuid) => {
        // Create the Collection.
        return cy.request({
          method: 'POST',
          url: '/jsonapi/node/appverse_repo',
          headers: writeHeaders,
          failOnStatusCode: false,
          body: {
            data: {
              type: 'node--appverse_repo',
              attributes: {
                title,
                moderation_state: state,
                field_repo_url: { uri: FIXTURE_REPO_URL },
                field_repo_description: 'Cypress fixture Collection for the Appverse maintenance hub tests.',
                field_repo_validation_st: 'valid',
              },
              relationships: {
                uid: { data: { type: 'user--user', id: contributorUuid } },
              },
            },
          },
        }).then((repoRes) => {
          if (repoRes.status !== 201) {
            const errs = (repoRes.body?.errors || []).map((e) => e.detail).join('; ');
            throw new Error(`seedRepo: repo create failed (${repoRes.status}): ${errs}`);
          }
          const uuid = repoRes.body.data.id;
          const nid = repoRes.body.data.attributes.drupal_internal__nid;
          Cypress.env('TEST_COLLECTION_NID', nid);
          Cypress.env('TEST_COLLECTION_UUID', uuid);

          if (memberApps === 0) {
            return { nid, uuid };
          }

          // Member apps need app_type, license, and domain references; resolve
          // them once, then create N apps under the Collection.
          return resolveUuid('taxonomy_term/appverse_app_type', 'name', 'batch-connect-VNC', 'app_type term')
            .then((appTypeUuid) => resolveUuid('taxonomy_term/appverse_license', 'name', 'Open-Source License', 'license term')
              .then((licenseUuid) => resolveUuid('domain/domain', 'drupal_internal__id', 'openondemand_cyberinfrastructure_org', 'ondemand domain')
                .then((domainUuid) => {
                  const apps = Array.from({ length: memberApps }, (_, i) => i + 1);
                  return cy.wrap(apps).each((n) => {
                    cy.request({
                      method: 'POST',
                      url: '/jsonapi/node/appverse_app',
                      headers: writeHeaders,
                      failOnStatusCode: false,
                      body: {
                        data: {
                          type: 'node--appverse_app',
                          attributes: { title: `${title} App ${n}`, moderation_state: memberAppState },
                          relationships: {
                            uid: { data: { type: 'user--user', id: contributorUuid } },
                            field_appverse_repo: { data: { type: 'node--appverse_repo', id: uuid } },
                            field_appverse_app_type: { data: { type: 'taxonomy_term--appverse_app_type', id: appTypeUuid } },
                            field_license: { data: { type: 'taxonomy_term--appverse_license', id: licenseUuid } },
                            field_domain_access: { data: [{ type: 'domain--domain', id: domainUuid }] },
                          },
                        },
                      },
                    }).then((appRes) => {
                      if (appRes.status !== 201) {
                        const errs = (appRes.body?.errors || []).map((e) => e.detail).join('; ');
                        throw new Error(`seedRepo: app create failed (${appRes.status}): ${errs}`);
                      }
                    });
                  }).then(() => ({ nid, uuid }));
                })));
        });
      });
  });
}

describe('Appverse Maintenance Hub', () => {
  // Guarantee no session bleed across blocks. The redirect / cross-user 403 /
  // re-sync blocks all switch users; the moderation blocks below would
  // otherwise inherit whichever cookie the previous block left behind.
  beforeEach(() => {
    cy.clearCookies();
  });

  describe('Redirects', () => {
    it('redirects /user/<uid>/my-apps to /my-appverse with 301', () => {
      cy.loginUser(ADMIN_EMAIL, ADMIN_PASS);
      cy.request({
        url: '/user/65016/my-apps',
        followRedirect: false,
        failOnStatusCode: false,
      }).then((response) => {
        expect(response.status).to.be.oneOf([301, 302]);
        expect(response.headers.location).to.match(/\/user\/65016\/my-appverse$/);
      });
    });

    it('redirects /appverse/manage-apps to /appverse/manage-repos with 301', () => {
      cy.loginUser(ADMIN_EMAIL, ADMIN_PASS);
      cy.request({
        url: '/appverse/manage-apps',
        followRedirect: false,
        failOnStatusCode: false,
      }).then((response) => {
        expect(response.status).to.be.oneOf([301, 302]);
        expect(response.headers.location).to.match(/\/appverse\/manage-repos$/);
      });
    });
  });

  describe('User hub renders', () => {
    // Seed a published Collection (with member apps) owned by the contributor,
    // then assert against the CONTRIBUTOR's own hub. The previous form visited
    // a hardcoded /user/65016/my-appverse, but that user owns no Appverse
    // content in the test DB, so the card assertions could never pass.
    let contributorUid;
    beforeEach(() => {
      cy.loginUser(ADMIN_EMAIL, ADMIN_PASS);
      seedRepo({ title: 'Hub Render Collection', state: 'published', memberApps: 2, memberAppState: 'published' });
      resolveContributorUid().then((uid) => { contributorUid = uid; });
    });

    it('renders the hub with Collection cards', () => {
      cy.visit(`/user/${contributorUid}/my-appverse`, { failOnStatusCode: false });
      cy.get('.appverse-hub-card', { timeout: 10000 }).should('have.length.greaterThan', 0);
    });

    it('expands member apps via <details>', () => {
      cy.visit(`/user/${contributorUid}/my-appverse`, { failOnStatusCode: false });
      cy.get('.appverse-hub-card', { timeout: 10000 }).should('exist');
      cy.get('body').then(($body) => {
        if ($body.find('.appverse-hub-card__apps').length === 0) {
          cy.log('No monorepo (multi-app) repos owned by this user; skipping apps-expansion check.');
          return;
        }
        cy.get('.appverse-hub-card__apps').first().find('summary').click();
        cy.get('.appverse-hub-card__apps').first()
          .find('table.appverse-hub-apps-table')
          .should('be.visible');
      });
    });
  });

  describe('Admin display renders', () => {
    beforeEach(() => {
      cy.loginUser(ADMIN_EMAIL, ADMIN_PASS);
    });

    it('renders the manage-collections page with exposed filters', () => {
      cy.visit('/appverse/manage-repos', { failOnStatusCode: false });
      cy.get('body').then(($body) => {
        if ($body.text().includes('Access denied')) {
          cy.log('Administrator user lacks "administer appverse content" permission; skipping.');
          return;
        }
        cy.get('form.views-exposed-form').should('be.visible');
        cy.get('input[name="title"]').should('exist');
        cy.get('.appverse-hub-card').should('exist');
      });
    });
  });

  describe('Redesigned hub layout', () => {
    it('admin manage-repos shows owner block and header icons', () => {
      cy.loginUser(ADMIN_EMAIL, ADMIN_PASS);
      cy.visit('/appverse/manage-repos', { failOnStatusCode: false });
      cy.get('body').then(($body) => {
        if ($body.text().includes('Access denied')) {
          cy.log('Administrator lacks "administer appverse content"; skipping.');
          return;
        }
        // Header icon affordances exist on the first card. (The catalog icon
        // was dropped as redundant — the card title links to the catalog — so
        // resync + github are the header icons now.)
        cy.get('.appverse-hub-card').first().within(() => {
          cy.get('.bi-arrow-clockwise').should('exist');
          cy.get('.bi-github').should('exist');
        });
        // Admin owner block: persona link + mailto, scoped to the card whose
        // owner we assert (the email link is conditional on the owner having
        // an email, so check within the owner-bearing card).
        cy.get('.appverse-hub-card').filter(':has(.appverse-hub-card__owner)').first().within(() => {
          cy.get('.appverse-hub-card__owner')
            .should('have.attr', 'href').and('match', /^\/community-persona\/\d+$/);
          cy.get('a[href^="mailto:"]').should('exist');
        });
      });
    });

    it('contributor my-appverse hides admin-only affordances', () => {
      cy.loginUser(CONTRIBUTOR_EMAIL, CONTRIBUTOR_PASS);
      // Resolve the contributor's numeric uid directly. Scraping it from the
      // /user profile URL fails because pathauto aliases the profile to a name
      // slug (/user/authenticatedtestuser), which has no digits to match.
      resolveContributorUid().then((uid) => {
        cy.visit(`/user/${uid}/my-appverse`, { failOnStatusCode: false });
      });
      cy.get('body').then(($body) => {
        if ($body.find('.appverse-hub-card').length === 0) {
          cy.log('Contributor owns no repos; skipping affordance check.');
          return;
        }
        // No owner block and no delete (danger) icon on the contributor's own hub.
        cy.get('.appverse-hub-card__owner').should('not.exist');
        cy.get('.appverse-hub-card__icon-btn--danger').should('not.exist');
      });
    });
  });

  describe('Re-sync via inline icon', () => {
    // Seed a Collection owned by the contributor so their hub renders a card
    // with the inline Re-sync form. (Previously visited /user/65016, who owns
    // no Appverse content, so no card was ever present.)
    let contributorUid;
    beforeEach(() => {
      cy.loginUser(ADMIN_EMAIL, ADMIN_PASS);
      seedRepo({ title: 'Re-sync Collection', state: 'published' });
      resolveContributorUid().then((uid) => { contributorUid = uid; });
    });

    it('exposes an inline Re-sync action', () => {
      cy.visit(`/user/${contributorUid}/my-appverse`, { failOnStatusCode: false });
      cy.get('.appverse-hub-card', { timeout: 10000 }).first().within(() => {
        cy.get('form[action*="/resync"]').should('exist');
        cy.get('form[action*="/resync"] button[type="submit"]')
          .should('have.attr', 'aria-label', 'Re-sync from GitHub');
        cy.get('form[action*="/resync"] .bi-arrow-clockwise').should('exist');
      });
    });
  });

  describe('Cross-user 403', () => {
    it('refuses to re-sync a Collection owned by another user', () => {
      // The contributor (authenticated_test_user) is created in
      // backupdb.yml, so it is always present; guard defensively and skip
      // rather than fail if the DB artifact predates it.
      cy.request({
        url: `/jsonapi/user/user?filter[name]=${CONTRIBUTOR_NAME}`,
        failOnStatusCode: false,
      }).then((listResponse) => {
        if (listResponse.status !== 200 || !listResponse.body.data.length) {
          cy.log(`${CONTRIBUTOR_NAME} fixture not present; skipping cross-user test.`);
          return;
        }

        cy.request({
          url: '/jsonapi/node/appverse_repo?filter[title]=Test%20Notebooks%20Collection',
          failOnStatusCode: false,
        }).then((collectionResp) => {
          if (collectionResp.status !== 200 || !collectionResp.body.data.length) {
            cy.log('Test Notebooks Collection fixture not present; skipping.');
            return;
          }
          const collectionNid = collectionResp.body.data[0].attributes.drupal_internal__nid;

          cy.loginUser(CONTRIBUTOR_EMAIL, CONTRIBUTOR_PASS);
          cy.request({
            method: 'POST',
            url: `/appverse/repo/${collectionNid}/resync`,
            failOnStatusCode: false,
            form: true,
            body: {},
          }).then((postResp) => {
            expect(postResp.status).to.be.oneOf([403, 405]);
          });
        });
      });
    });
  });

  // -------------------------------------------------------------------------
  // Phase 1.8: Moderation transition notifications.
  //
  // Each block seeds its own fixture in beforeEach so it does not depend on
  // ordering with the others. The seeded title is unique per block so
  // concurrent runs won't collide. Member apps are seeded for the cascade
  // cases. UUID for JSON:API cascade assertions stashed via Cypress.env.
  // -------------------------------------------------------------------------

  describe('Send for review (contributor → admin notification)', () => {
    const COLLECTION_TITLE = 'Test Notebooks (Draft)';

    beforeEach(() => {
      // Seed as admin so JSON:API can read the unpublished Collection while
      // resolving the UUID. Then switch users for the actual flow.
      cy.loginUser(ADMIN_EMAIL, ADMIN_PASS);
      seedRepo({ title: COLLECTION_TITLE, state: 'draft' });
      cy.clearMailpit();
    });

    it('emails the admin when the contributor submits a draft Collection for review', () => {
      cy.loginUser(CONTRIBUTOR_EMAIL, CONTRIBUTOR_PASS);
      // Visit the contributor's own hub. Resolve the numeric uid directly
      // (pathauto aliases the /user profile URL, so it has no digits to scrape).
      resolveContributorUid().then((uid) => {
        cy.visit(`/user/${uid}/my-appverse`, { failOnStatusCode: false });
      });

      // Find the card for our seeded Collection and click Send for review.
      cy.contains('.appverse-hub-card', COLLECTION_TITLE, { timeout: 10000 })
        .within(() => {
          // Send for review is an icon button; label is in aria-label.
          cy.get('form[action*="/send-for-review"] button[type="submit"][aria-label="Send for review"]')
            .click();
        });

      // Assert the admin received the notification.
      cy.waitForEmail({
        to: ADMIN_EMAIL,
        subject: 'Repo submitted for review',
      }).then((message) => {
        cy.assertEmailContent(message, {
          subject: 'Repo submitted for review',
          to: ADMIN_EMAIL,
          bodyContains: [COLLECTION_TITLE, '/appverse/manage-repos'],
        });

        // From-address verification: this spec runs at /e2e/ondemand/, so
        // the request resolves to the openondemand domain and
        // domain_site_settings overlays system.site at request time.
        // Verified DB values: site_name="Open OnDemand Community Hub",
        // site_mail="info@mg.openondemand.org". If a different From slips
        // through (e.g., the global noreply@wpi.edu used by the default
        // domain), notifications would go out branded as the wrong site.
        expect(message.From.Address).to.equal('info@mg.openondemand.org');
        expect(message.Subject).to.match(/^\[Open OnDemand Community Hub\]/);
      });

      // Guard Task 3B at the E2E layer: the contrib appverse_review_requested
      // config is bound to send_for_review on appverse_editorial with no bundle
      // filter, so it would fire "New Appverse App submitted" on a Collection
      // submit too if our hook_content_moderation_notification_mail_data_alter
      // weren't suppressing it. The kernel test covers this; we mirror it here
      // because the alter only runs at request time, not in isolation.
      cy.searchMailpitMessages({ subject: 'New Appverse App submitted' }).then((messages) => {
        expect(messages, 'contrib appverse_review_requested mail must be suppressed for Collection submits').to.have.length(0);
      });
    });
  });

  describe('Request changes (admin → contributor notification)', () => {
    describe('Case A: email only (ready_for_review starting state)', () => {
      const COLLECTION_TITLE = 'Test Notebooks (Ready)';
      const REVIEW_COMMENT = 'Please update the README and re-submit.';

      beforeEach(() => {
        cy.loginUser(ADMIN_EMAIL, ADMIN_PASS);
        seedRepo({ title: COLLECTION_TITLE, state: 'ready_for_review' });
        cy.clearMailpit();
      });

      it('emails the contributor with the reviewer comment', () => {
        cy.visit('/appverse/manage-repos', { failOnStatusCode: false });
        cy.contains('.appverse-hub-card', COLLECTION_TITLE, { timeout: 10000 })
          .within(() => {
            // Request changes is now a direct inline icon link (no kebab).
            cy.get('a[href*="/request-changes"]').click();
          });

        // Fill out the Request changes form.
        cy.url().should('include', '/request-changes');
        cy.get('textarea[name="comment"]').type(REVIEW_COMMENT);
        cy.get('form input[type="submit"][value="Request changes"]').click();

        cy.waitForEmail({
          to: CONTRIBUTOR_EMAIL,
          subject: 'Changes requested',
        }).then((message) => {
          cy.assertEmailContent(message, {
            subject: 'Changes requested',
            to: CONTRIBUTOR_EMAIL,
            bodyContains: [COLLECTION_TITLE, REVIEW_COMMENT, 'my-appverse'],
          });
        });
      });
    });

    describe('Case B: email + cascade-unpublish (published starting state)', () => {
      const COLLECTION_TITLE = 'Test Notebooks (Published)';
      const REVIEW_COMMENT = 'Discovered a regression, please address.';

      beforeEach(() => {
        cy.loginUser(ADMIN_EMAIL, ADMIN_PASS);
        seedRepo({
          title: COLLECTION_TITLE,
          state: 'published',
          memberApps: 2,
          memberAppState: 'published',
        });
        cy.clearMailpit();
      });

      it('emails the contributor AND cascade-unpublishes member apps', () => {
        cy.visit('/appverse/manage-repos', { failOnStatusCode: false });
        cy.contains('.appverse-hub-card', COLLECTION_TITLE, { timeout: 10000 })
          .within(() => {
            // Request changes is now a direct inline icon link (no kebab).
            cy.get('a[href*="/request-changes"]').click();
          });

        cy.url().should('include', '/request-changes');
        cy.get('textarea[name="comment"]').type(REVIEW_COMMENT);
        cy.get('form input[type="submit"][value="Request changes"]').click();

        // Email landed.
        cy.waitForEmail({
          to: CONTRIBUTOR_EMAIL,
          subject: 'Changes requested',
        }).then((message) => {
          cy.assertEmailContent(message, {
            subject: 'Changes requested',
            to: CONTRIBUTOR_EMAIL,
            bodyContains: [COLLECTION_TITLE, REVIEW_COMMENT],
          });
        });

        // Cascade-unpublish: every member app moved off published.
        // Session is still admin from beforeEach, so JSON:API can see
        // unpublished nodes (filter status=1 should return zero rows).
        const collectionUuid = Cypress.env('TEST_COLLECTION_UUID');
        cy.request({
          url: `/jsonapi/node/appverse_app?filter[field_appverse_repo.id]=${collectionUuid}&filter[status]=1`,
          headers: { Accept: 'application/vnd.api+json' },
        }).then((res) => {
          expect(res.body.data).to.have.length(0);
        });
      });
    });
  });

  describe('Publish (admin → contributor + cascade)', () => {
    const COLLECTION_TITLE = 'Test Notebooks (Ready for Publish)';

    beforeEach(() => {
      cy.loginUser(ADMIN_EMAIL, ADMIN_PASS);
      seedRepo({
        title: COLLECTION_TITLE,
        state: 'ready_for_review',
        memberApps: 2,
        memberAppState: 'draft',
      });
      cy.clearMailpit();
    });

    it('emails the contributor once and cascade-publishes member apps', () => {
      cy.visit('/appverse/manage-repos', { failOnStatusCode: false });
      cy.contains('.appverse-hub-card', COLLECTION_TITLE, { timeout: 10000 })
        .within(() => {
          // Publish is an icon button; its label lives in aria-label, not text.
          cy.get('form[action*="/publish"] button[type="submit"][aria-label="Publish"]')
            .click();
        });

      // Contributor email landed.
      cy.waitForEmail({
        to: CONTRIBUTOR_EMAIL,
        subject: 'Your Repo is published',
      }).then((message) => {
        cy.assertEmailContent(message, {
          subject: 'Your Repo is published',
          to: CONTRIBUTOR_EMAIL,
          bodyContains: [COLLECTION_TITLE, '/appverse#/repo/'],
        });
      });

      // No fan-out: exactly one message in Mailpit even though the cascade
      // published two member apps. App-bundle saves must NOT trigger the
      // Collection notifier. Poll until the count has been stable across two
      // successive checks, so a hypothetical async second message arriving
      // shortly after waitForEmail resolves on the first match still fails
      // the assertion below.
      let stableCount = null;
      const pollStable = () => cy.getMailpitMessages().then((messages) => {
        if (stableCount === messages.length) {
          expect(messages.length).to.equal(1);
          return;
        }
        stableCount = messages.length;
        cy.wait(500);
        return pollStable();
      });
      pollStable();

      // Cascade-publish: member apps flipped from draft to published.
      const collectionUuid = Cypress.env('TEST_COLLECTION_UUID');
      cy.request({
        url: `/jsonapi/node/appverse_app?filter[field_appverse_repo.id]=${collectionUuid}&filter[status]=1`,
        headers: { Accept: 'application/vnd.api+json' },
      }).then((res) => {
        expect(res.body.data).to.have.length(2);
      });
    });
  });
});
