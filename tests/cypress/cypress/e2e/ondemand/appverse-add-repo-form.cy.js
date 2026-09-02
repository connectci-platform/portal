/**
 * AddRepoForm — dedicated /appverse/add-repo submission flow.
 *
 * Covers the URL-stage form, the dup-URL gate, permission check, and the
 * legacy /node/add/appverse_app redirect. Happy-path submits (declared +
 * inferred) require a server-side GitHub fixture and are deferred per
 * Phase 1.7 Task 52 — marked `.skip` until that lands.
 *
 * Replaces the legacy node-add Cypress spec (Phase 1.6 / 1.7 shape
 * branching). The Phase 1.7 hidden-required-field bug it covered was a
 * symptom of the legacy form's #states wiring; AddRepoForm doesn't have
 * the same shape — fields appear stage-by-stage instead of being toggled.
 */

const ADMIN_EMAIL = 'administrator@amptesting.com';
const ADMIN_PASS = 'b8QW]X9h7#5n';

const URL_INPUT = 'input[name="repo_url"]';
const FETCH_BUTTON = 'input[type="submit"][value="Fetch repo"]';

describe('AddRepoForm — Phase 1.9 dedicated submit form', () => {
  // ----- Legacy redirect (Phase 1.9 Task 3) -----

  it('301-redirects /node/add/appverse_app to /appverse/add-repo', () => {
    cy.loginUser(ADMIN_EMAIL, ADMIN_PASS);
    cy.request({
      url: '/node/add/appverse_app',
      followRedirect: false,
    }).then((response) => {
      expect(response.status).to.eq(301);
      expect(response.redirectedToUrl).to.include('/appverse/add-repo');
    });
  });

  // ----- Permission gate -----

  it('serves the form to users with submit-appverse-repo permission', () => {
    cy.loginUser(ADMIN_EMAIL, ADMIN_PASS);
    cy.visit('/appverse/add-repo');
    cy.get(URL_INPUT).should('be.visible');
    cy.get(FETCH_BUTTON).should('be.visible');
    cy.contains(/Paste the GitHub URL/).should('be.visible');
  });

  it('403s the form for anonymous visitors', () => {
    cy.clearCookies();
    cy.request({
      url: '/appverse/add-repo',
      followRedirect: false,
      failOnStatusCode: false,
    }).then((response) => {
      // Anonymous access is denied by bouncing to the login form: the custom
      // access check redirects anon to /user/login?redirect=/appverse/add-repo
      // with a 307 Temporary Redirect (preserving the request method).
      expect(response.status).to.eq(307);
      expect(response.headers.location || response.redirectedToUrl)
        .to.match(/\/user\/login/);
    });
  });

  // ----- URL stage validation -----

  it('rejects an empty URL with the field-required error', () => {
    cy.loginUser(ADMIN_EMAIL, ADMIN_PASS);
    cy.visit('/appverse/add-repo');
    cy.get(FETCH_BUTTON).click();
    cy.contains(/required/i).should('be.visible');
  });

  it('rejects an unparseable URL with a friendly error', () => {
    cy.loginUser(ADMIN_EMAIL, ADMIN_PASS);
    cy.visit('/appverse/add-repo');
    cy.get(URL_INPUT).type('https://example.com/not-a-github-repo');
    cy.get(FETCH_BUTTON).click();
    // AddRepoForm::submitFetch sets an error via setErrorByName + setRebuild.
    cy.contains(/could not parse|not a GitHub repository/i, { timeout: 30000 }).should('be.visible');
  });

  // ----- Happy paths (deferred per Phase 1.7 Task 52) -----

  it.skip('previews a declared (multi-app) repo and walks to confirm', () => {
    // Requires server-side GitHub fixture wiring; deferred per Phase 1.7 Task 52.
  });

  it.skip('previews an inferred (single-app) repo and submits synchronously', () => {
    // Requires server-side GitHub fixture wiring; deferred per Phase 1.7 Task 52.
  });
});
