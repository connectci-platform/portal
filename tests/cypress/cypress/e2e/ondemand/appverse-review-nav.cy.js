/**
 * Appverse review navigation: live report status (D8-2931).
 *
 * A reviewer who opens the review page (or the Manage Repos hub) while an AI
 * report is running sees it finish in place, with no reload:
 *  - the review page's status line goes "AI report queued" -> "AI report
 *    finished" and the Rerun link appears;
 *  - the hub card's step label updates and its hidden Run AI report form is
 *    revealed.
 *
 * Fixtures come from fixtures/scripts/appverse-seed.php (a repo with one
 * review) and appverse-review-status.php (sets the run status). cy.clock() is
 * installed before cy.visit() so the page's 30 second poll can be ticked.
 */

const ADMIN_EMAIL = 'administrator@amptesting.com';
const ADMIN_PASS = 'b8QW]X9h7#5n';
const SEED_SCRIPT = '/var/www/html/tests/cypress/cypress/fixtures/scripts/appverse-seed.php';
const STATUS_SCRIPT = '/var/www/html/tests/cypress/cypress/fixtures/scripts/appverse-review-status.php';

function seedRepoWithReview(title) {
  const cmd = `ddev drush php:script ${SEED_SCRIPT} -- '${title}' ready_for_review 0 draft review`;
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

describe('Appverse live report status', () => {
  let repoNid;
  let reviewNid;

  beforeEach(() => {
    cy.clearCookies();
    cy.loginUser(ADMIN_EMAIL, ADMIN_PASS);
    seedRepoWithReview('Review Nav Live Status').then((seeded) => {
      repoNid = seeded.nid;
      reviewNid = seeded.review_nid;
      setRunStatus(repoNid, 'pending');
    });
  });

  it('finishes the status line on the review page without a reload', () => {
    cy.clock();
    cy.visit(`/appverse/review/${reviewNid}`);
    cy.get('[data-review-run-status]').should('contain.text', 'AI report queued');
    cy.get('[data-review-rerun]').should('not.be.visible');

    setRunStatus(repoNid, 'complete');
    // The page must not reload: a marker set on window survives only if so.
    cy.window().then((win) => { win.__noReload = true; });
    cy.tick(30000);

    cy.get('[data-review-run-status]').should('contain.text', 'AI report finished');
    cy.get('[data-review-rerun]').should('be.visible');
    cy.window().its('__noReload').should('eq', true);
  });

  it('flips an in-flight hub card in place', () => {
    cy.clock();
    cy.visit(`/appverse/manage-repos?title=Review+Nav+Live+Status&moderation_state=All`);
    cy.get(`#appverse-repo-${repoNid}`).as('card');
    cy.get('@card').should('have.attr', 'data-review-status-url');
    cy.get('@card').find('[data-review-run]').should('not.be.visible');

    setRunStatus(repoNid, 'complete');
    cy.window().then((win) => { win.__noReload = true; });
    cy.tick(30000);

    cy.get('@card').find('[data-review-run]').should('be.visible');
    cy.get('@card').find('[data-review-link]').should('be.visible')
      .and('have.attr', 'href').and('match', new RegExp(`/appverse/review/${reviewNid}$`));
    cy.window().its('__noReload').should('eq', true);
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
        cy.get('@card').find('[data-hub-assignee]').should('contain.text', 'Unassigned');
        cy.get('@card').find('select[data-hub-assign-select]').select(String(seeded.pm2_uid));
        cy.get('@card').find('[data-hub-assignee]').should('contain.text', 'appverse_pm_two');
        cy.get('@card').find('[data-hub-assign-status]').should('contain.text', 'appverse_pm_two');
        cy.get('@card').find('select[data-hub-assign-select]').should('have.value', String(seeded.pm2_uid));
        cy.url().should('eq', before);
        cy.window().its('__noReload').should('eq', true);
      });
    });
  });
});
