/**
 * Appverse review navigation: live report status (D8-2931).
 *
 * A reviewer who opens the review page (or the Manage Repos hub) while the
 * automated check is running sees it finish in place, with no reload:
 *  - the review page's status line goes "Automated check queued" ->
 *    "Automated check finished" and the Recheck link appears, or, when the run produced a newer
 *    review, a "New report ready" link to it; a failed run offers neither;
 *  - the hub card's step label updates, its review link points at the review
 *    the run produced, and its hidden Run automated check form is revealed.
 *
 * Fixtures come from fixtures/scripts/appverse-seed.php (a repo, optionally
 * with one review) and appverse-review-status.php (sets the run status and
 * can land a new review, as a finished run does). cy.clock() is installed
 * before cy.visit() so the page's 30 second poll can be ticked.
 */

const ADMIN_EMAIL = 'administrator@amptesting.com';
const ADMIN_PASS = 'b8QW]X9h7#5n';
const SEED_SCRIPT = '/var/www/html/tests/cypress/cypress/fixtures/scripts/appverse-seed.php';
const STATUS_SCRIPT = '/var/www/html/tests/cypress/cypress/fixtures/scripts/appverse-review-status.php';

function seedRepo(title, extras = '') {
  const cmd = `ddev drush php:script ${SEED_SCRIPT} -- '${title}' ready_for_review 0 draft ${extras}`.trim();
  return cy.exec(cmd, { timeout: 90000 }).then((res) => {
    const match = (res.stdout || '').match(/^APPVERSE_SEED_RESULT:(\{.*\})\s*$/m);
    expect(match, `seed output: ${res.stdout} ${res.stderr}`).to.not.equal(null);
    return JSON.parse(match[1]);
  });
}

function setRunStatus(nid, status) {
  return cy.exec(`ddev drush php:script ${STATUS_SCRIPT} -- ${nid} ${status}`, { timeout: 60000 })
    .its('stdout').should('contain', 'APPVERSE_REVIEW_STATUS:ok');
}

// Lands a new review for the repo, as a finished run does, and sets the run
// status. Yields the new review's nid.
function landReview(nid, status) {
  return cy.exec(`ddev drush php:script ${STATUS_SCRIPT} -- ${nid} ${status} new-review`, { timeout: 60000 })
    .then((res) => {
      expect(res.stdout).to.contain('APPVERSE_REVIEW_STATUS:ok');
      const match = res.stdout.match(/^APPVERSE_NEW_REVIEW:(\d+)\s*$/m);
      expect(match, `status output: ${res.stdout}`).to.not.equal(null);
      return Number(match[1]);
    });
}

describe('Appverse live report status', () => {
  let repoNid;
  let reviewNid;

  beforeEach(() => {
    cy.clearCookies();
    cy.loginUser(ADMIN_EMAIL, ADMIN_PASS);
  });

  describe('on a repo that has a review', () => {
    beforeEach(() => {
      seedRepo('Review Nav Live Status', 'review').then((seeded) => {
        repoNid = seeded.nid;
        reviewNid = seeded.review_nid;
        setRunStatus(repoNid, 'pending');
      });
    });

    it('finishes the status line on the review page without a reload', () => {
      cy.clock();
      cy.visit(`/appverse/review/${reviewNid}`);
      cy.get('[data-review-run-status]').should('contain.text', 'Automated check queued');
      cy.get('[data-review-rerun]').should('not.be.visible');

      setRunStatus(repoNid, 'complete');
      // The page must not reload: a marker set on window survives only if so.
      cy.window().then((win) => { win.__noReload = true; });
      cy.tick(30000);

      cy.get('[data-review-run-status]').should('contain.text', 'Automated check finished');
      cy.get('[data-review-rerun]').should('be.visible');
      cy.get('[data-review-new]').should('not.be.visible');
      cy.window().its('__noReload').should('eq', true);
    });

    it('offers the new report when the run lands one', () => {
      cy.clock();
      cy.visit(`/appverse/review/${reviewNid}`);
      cy.get('[data-review-new]').should('not.be.visible');

      cy.window().then((win) => { win.__noReload = true; });
      landReview(repoNid, 'complete').then((newNid) => {
        expect(newNid).to.be.greaterThan(reviewNid);
        cy.tick(30000);
        cy.get('[data-review-run-status]').should('contain.text', 'Automated check finished');
        cy.get('[data-review-new]').should('be.visible')
          .and('have.attr', 'href').and('match', new RegExp(`/appverse/review/${newNid}$`));
        // This review is superseded now, so it offers no rerun of its own.
        cy.get('[data-review-rerun]').should('not.be.visible');
      });
      cy.window().its('__noReload').should('eq', true);
    });

    it('offers no new report on a superseded review when the run fails', () => {
      // A newer review already exists when the older one is opened, so the
      // poller must not mistake it for the run's result.
      landReview(repoNid, 'pending').then(() => {
        cy.clock();
        cy.visit(`/appverse/review/${reviewNid}`);
        cy.get('[data-review-run-status]').should('contain.text', 'Automated check queued');

        setRunStatus(repoNid, 'error');
        cy.tick(30000);

        cy.get('[data-review-run-status]').should('contain.text', 'Automated check failed');
        cy.get('[data-review-new]').should('not.be.visible');
      });
    });

    it('points an in-flight hub card at the review the run lands', () => {
      cy.clock();
      cy.visit('/appverse/manage-repos?title=Review+Nav+Live+Status&moderation_state=All');
      cy.get(`#appverse-repo-${repoNid}`).as('card');
      cy.get('@card').should('have.attr', 'data-review-status-url');
      cy.get('@card').find('[data-review-run]').should('not.be.visible');
      cy.get('@card').find('[data-review-link]')
        .should('have.attr', 'href').and('match', new RegExp(`/appverse/review/${reviewNid}$`));
      cy.get('@card').find('[data-review-step]').invoke('text').should('not.contain', 'Ready');

      cy.window().then((win) => { win.__noReload = true; });
      landReview(repoNid, 'complete').then((newNid) => {
        cy.tick(30000);
        cy.get('@card').find('[data-review-step]').should('contain.text', 'Ready');
        cy.get('@card').find('[data-review-run]').should('be.visible');
        cy.get('@card').find('[data-review-link]').should('be.visible')
          .and('have.attr', 'href').and('match', new RegExp(`/appverse/review/${newNid}$`));
      });
      cy.window().its('__noReload').should('eq', true);
    });
  });

  it('gives a first submission\'s card its review link when the report lands', () => {
    seedRepo('Review Nav First Run').then((seeded) => {
      setRunStatus(seeded.nid, 'pending');
      cy.clock();
      cy.visit('/appverse/manage-repos?title=Review+Nav+First+Run&moderation_state=All');
      cy.get(`#appverse-repo-${seeded.nid}`).as('card');
      cy.get('@card').find('[data-review-link]').should('not.be.visible');
      cy.get('@card').find('[data-review-step]').invoke('text').should('not.contain', 'Ready');

      cy.window().then((win) => { win.__noReload = true; });
      landReview(seeded.nid, 'complete').then((newNid) => {
        cy.tick(30000);
        cy.get('@card').find('[data-review-step]').should('contain.text', 'Ready');
        cy.get('@card').find('[data-review-link]').should('be.visible')
          .and('have.attr', 'href').and('match', new RegExp(`/appverse/review/${newNid}$`));
        cy.get('@card').find('[data-review-run]').should('be.visible');
      });
      cy.window().its('__noReload').should('eq', true);
    });
  });

  it('assigns another reviewer from the card in place', () => {
    const cmd = `ddev drush php:script ${SEED_SCRIPT} -- 'Review Nav Assign' ready_for_review 0 draft pm2`;
    cy.exec(cmd, { timeout: 90000 }).then((res) => {
      const match = (res.stdout || '').match(/^APPVERSE_SEED_RESULT:(\{.*\})\s*$/m);
      expect(match, `seed output: ${res.stdout} ${res.stderr}`).to.not.equal(null);
      const seeded = JSON.parse(match[1]);

      cy.visit('/appverse/manage-repos?title=Review+Nav+Assign&moderation_state=All');
      cy.url().then((before) => {
        cy.window().then((win) => { win.__noReload = true; });
        cy.get(`#appverse-repo-${seeded.nid}`).as('card');
        // The picker is the card's reviewer line for an admin.
        cy.get('@card').find('.appverse-hub-card__assignee').should('not.exist');
        cy.get('@card').find('select[data-hub-assign-select]').should('have.value', '');
        cy.get('@card').find('select[data-hub-assign-select]').select(String(seeded.pm2_uid));
        cy.get('@card').find('[data-hub-assign-status]').should('contain.text', 'appverse_pm_two');
        cy.get('@card').find('select[data-hub-assign-select]').should('have.value', String(seeded.pm2_uid));
        cy.url().should('eq', before);
        cy.window().its('__noReload').should('eq', true);
      });
    });
  });
});

describe('Appverse hub filters and review header', () => {
  beforeEach(() => {
    cy.clearCookies();
    cy.loginUser(ADMIN_EMAIL, ADMIN_PASS);
  });

  it('shows every state chip for All and keeps them on Apply (D8-2930)', () => {
    cy.visit('/appverse/manage-repos?moderation_state=All');
    cy.get('#edit-moderation-state').find('option').then((options) => {
      const states = [...options].filter((o) => o.value.startsWith('appverse_editorial-')).length;
      expect(states).to.be.greaterThan(1);
      cy.get('#edit-moderation-state').find('option:selected').should('have.length', states);
      cy.get('.views-exposed-form .select2-selection__choice').filter(':visible').should('have.length.at.least', states);
      cy.get('.views-exposed-form input[type=submit]').first().click();
      cy.url().then((url) => {
        const count = (url.match(/moderation_state(%5B%5D|\[\])=appverse_editorial-/g) || []).length;
        expect(count).to.equal(states);
        expect(url).not.to.contain('needs_attention');
      });
    });
  });

  it('selects every state from the All link, with no Clear', () => {
    cy.visit('/appverse/manage-repos');
    cy.get('#edit-moderation-state').find('option:selected').should('have.length', 1);
    cy.get('[data-select-all="moderation_state"]').click();
    cy.get('#edit-moderation-state').find('option:selected').then((selected) => {
      expect(selected.length).to.be.greaterThan(1);
      expect([...selected].map((o) => o.value)).not.to.include('needs_attention');
    });
    cy.get('[data-select-clear="moderation_state"]').should('not.exist');
  });

  it('folds the filters behind a toggle on a phone, and not on desktop', () => {
    const form = '.views-exposed-form[data-drupal-selector*="my-appverse"]';
    cy.viewport(390, 844);
    cy.visit('/appverse/manage-repos');
    cy.get(form).should('not.be.visible');
    cy.get('.appverse-hub-filters-toggle')
      .should('be.visible')
      .and('have.attr', 'aria-expanded', 'false')
      .and('contain.text', 'Filters (1 set)')
      // At phone width the admin toolbar's tray opens over the page, which
      // only an admin sees; the toggle itself is asserted visible above.
      .click({ force: true });
    cy.get(form).should('be.visible');
    cy.get('.appverse-hub-filters-toggle').should('have.attr', 'aria-expanded', 'true');
    cy.get('.appverse-hub-field-help').should('be.visible').and('contain.text', 'Needs attention');

    cy.viewport(1280, 900);
    cy.get('.appverse-hub-filters-toggle').should('not.be.visible');
    cy.get('.appverse-hub-filters-toggle').click({ force: true });
    cy.get(form).should('be.visible');
  });

  it('limits the hub to the chosen items per page', () => {
    cy.visit('/appverse/manage-repos?moderation_state=All');
    cy.get('select[name="items_per_page"]').select('10');
    cy.get('.views-exposed-form input[type=submit]').first().click();
    cy.url().should('contain', 'items_per_page=10');
    cy.get('.appverse-hub-card').should('have.length.at.most', 10);
  });

  it('shows the breadcrumb, the repo link and the model on a review', () => {
    seedRepo('Review Nav Header', 'review').then((seeded) => {
      cy.visit(`/appverse/review/${seeded.review_nid}`);
      cy.get('.breadcrumb, nav[aria-label*="readcrumb"]').contains('a', 'Manage Repos');
      cy.contains('.arv-go-links a', 'Repo page');
      // The model is in the footer now, not the header.
      cy.contains('.arv-footnote', 'Qwen3.6-35B-A3B-FP8');
    });
  });
});
