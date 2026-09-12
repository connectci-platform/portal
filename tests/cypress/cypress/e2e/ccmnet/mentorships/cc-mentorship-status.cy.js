/*
  CC Mentorship Status page — access control tests.

  The /mentorships/cc-status page (Views display mentorship_status:page_2)
  is restricted to the administrator, champions_mentorship_admin, and
  campuschampionsadmin roles. It filters to mentorships in the
  "Campus Champions AI Mentorship" program.

  Users and content come from amp_dev fixtures (not prod accounts, whose roles
  drift when the CI DB is rebuilt from prod):
    test_mentorship_admin — champions_mentorship_admin (can view)
    test_cc_admin         — campuschampionsadmin (can view)
    test_ccmnet_pm_only   — ccmnet_pm only (no access to cc-status)
  The fixture mentorship "[CC-AI] AMP Test Mentorship" is tagged to the ccmnet
  domain and the Campus Champions AI Mentorship program, so it appears in the
  listing on ccmnet.ddev.site.
*/

const STATUS_PATH = "/mentorships/cc-status";
const BASE_STATUS_PATH = "/mentorships/status";

// The amp_dev fixture mentorship, present in the cc-status listing.
const KNOWN_CC_TITLE = "[CC-AI] AMP Test Mentorship";

/**
 * Log in as a fixture user by name via drush user:login (one-time login URL).
 * Runs on the host via cy.exec (ddev). Asserts the resulting session is
 * actually that user, so a missing/misnamed fixture fails loudly instead of
 * silently logging in as uid 1 (drush falls back to the superuser when the
 * name does not resolve, which would pass access tests for the wrong reason).
 */
function loginByName(name) {
  const uri = Cypress.config("baseUrl");
  cy.exec(
    `ddev drush user:login --name=${name} --uri=${uri} /`,
    { failOnNonZeroExit: false }
  ).then((result) => {
    // The URL is the last non-empty line of stdout
    const url = result.stdout.trim().split("\n").pop();
    cy.visit(url);
  });
  // Confirm login actually succeeded. A failed drush user:login (missing/wrong
  // --name) yields no valid one-time-login URL and the visit lands on the login
  // form; a successful one lands on the user's own account page. Assert we are
  // NOT on the login page, so a mis-provisioned fixture fails loudly here rather
  // than silently running the access test as the wrong (or anonymous) user.
  cy.visit("/user");
  cy.url().should("not.include", "/user/login");
  cy.get("body").should("have.class", "logged_in");
}

describe("CC Mentorship Status page — access control", () => {

  it("anonymous request to /mentorships/cc-status redirects to login (307)", () => {
    // Drupal redirects unauthenticated users with a 307 to the login flow.
    // Asserting the exact status keeps the test honest — a future change
    // that returns 200 (over-permissive) or 500 (broken) would fail loudly.
    cy.request({
      url: STATUS_PATH,
      failOnStatusCode: false,
      followRedirect: false,
    })
      .its("status")
      .should("eq", 307);
  });

  it("champions_mentorship_admin can view /mentorships/cc-status", () => {
    loginByName("test_mentorship_admin");
    cy.visit(STATUS_PATH);

    // Page title contains "Campus Champions"
    cy.get("h1").should("contain.text", "Campus Champions");

    // Load-bearing: the fixture must appear as a ROW in the results table, not
    // just anywhere on the page — an authorized-but-empty listing must fail,
    // not pass (the h1 renders on any authorized 200 regardless of rows).
    cy.get(".views-element-container table")
      .should("contain", KNOWN_CC_TITLE);
  });

  it("campuschampionsadmin can view /mentorships/cc-status", () => {
    loginByName("test_cc_admin");
    cy.visit(STATUS_PATH);

    cy.get("h1").should("contain.text", "Campus Champions");
    cy.get(".views-element-container table")
      .should("contain", KNOWN_CC_TITLE);
  });

  it("ccmnet_pm-only user gets 403 at /mentorships/cc-status", () => {
    loginByName("test_ccmnet_pm_only");
    cy.request({
      url: STATUS_PATH,
      failOnStatusCode: false,
    }).then((resp) => {
      expect(resp.status).to.eq(403);
    });
  });

  it("regression: ccmnet_pm-only user can still load /mentorships/status", () => {
    loginByName("test_ccmnet_pm_only");
    cy.request(BASE_STATUS_PATH).its("status").should("eq", 200);
  });

});
