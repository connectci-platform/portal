/*
  Hide Community Profile (D8-2728)

  TDD specs for the "hide me from public people surfaces" feature.
  When a user sets field_hide_community_profile = TRUE:
    - their public /community-persona/{uid} page shows a no-profile message
    - the page <title>/og:title do not contain their name
    - they do not appear in the public people directories that key off the
      cssn_directory search index, the users_field_data table, or
      taxonomy_term relationships
    - toggling the field back off restores them everywhere

  Pecan (uid 201) is seeded with field_region = [780, 901, 835, 308] so she
  appears in CSSN directory (780), OnDemand /people facet (901), and the
  region-scoped variants. She has interest flags on data-management-software
  and distributed-computing so she also appears on those tag pages.

  Several specs intentionally fail today and document the expected behavior:
    - title leak in titleCallback
    - og:title leak (same root cause)
    - directory-listing exposure across all three view backends
*/

const TEST_UID = 201;
const TEST_NAME = "Pecan Pie";
const ADMIN_USER = "administrator@amptesting.com";
const ADMIN_PASS = "b8QW]X9h7#5n";
const PECAN_LINK = `/community-persona/${TEST_UID}`;

// Most paths are relative to CYPRESS_BASE_URL (accessmatch).
// The OnDemand /people directory lives on the open-ondemand domain — the
// cyberteam_people_facets view filters by region 901 only on that domain.
// Use an absolute URL for that cross-host case.
const PERSONA_PATH = PECAN_LINK;
const CSSN_DIRECTORY_PATH = "/community/cssn/directory";
const OOD_PEOPLE_URL = "http://ondemand.ddev.site/people?search_api_fulltext=pecan&items_per_page=24";
// Pecan has the data-management-software interest flag (amp_dev fixture).
// Asserting on the persona link rather than the user's display name lets
// the test work against the sanitized DB artifact, where the indexed
// realname for uid 201 is not "Pecan Pie".
const TAG_INTEREST_PATH = "/tags/data-management-software/people-with-interest";

function setHide(value) {
  cy.exec(
    `ddev drush ev '$u = \\Drupal\\user\\Entity\\User::load(${TEST_UID}); $u->set("field_hide_community_profile", ${value ? 1 : 0}); $u->save();'`,
    { failOnNonZeroExit: false, timeout: 60000 }
  );
}

describe("Hide Community Profile", () => {

  after(() => {
    setHide(false);
  });

  describe("when hide flag is set via drush", () => {

    before(() => {
      setHide(true);
    });

    it("public persona page body shows the no-profile message", () => {
      cy.visit(PERSONA_PATH);
      cy.contains("No public profile available for this person.")
        .should("be.visible");
    });

    it("public persona page title does NOT contain the user's name", () => {
      cy.visit(PERSONA_PATH);
      cy.title().should("not.contain", TEST_NAME);
    });

    it("public persona page does NOT expose user's name in og:title meta", () => {
      cy.visit(PERSONA_PATH);
      cy.get('meta[property="og:title"]')
        .should("have.attr", "content")
        .and("not.contain", TEST_NAME);
    });

    it("user link does NOT appear when searching CSSN directory for pecan", () => {
      cy.visit(`${CSSN_DIRECTORY_PATH}?search_api_fulltext=pecan&items_per_page=24`);
      cy.get("body").find(`a[href*="${PECAN_LINK}"]`).should("not.exist");
    });

    it("user link does NOT appear in OnDemand /people search", () => {
      cy.visit(OOD_PEOPLE_URL);
      cy.get("body").find(`a[href*="${PECAN_LINK}"]`).should("not.exist");
    });

    it("user link does NOT appear on the tag-interest people listing", () => {
      cy.visit(TAG_INTEREST_PATH);
      cy.get("body").find(`a[href*="${PECAN_LINK}"]`).should("not.exist");
    });

  });

  describe("when hide flag is unset", () => {

    before(() => {
      setHide(false);
    });

    it("public persona page renders normally with the user's name", () => {
      cy.visit(PERSONA_PATH);
      cy.title().should("contain", TEST_NAME);
      cy.contains(TEST_NAME).should("be.visible");
      cy.contains("No public profile available")
        .should("not.exist");
    });

    it("user link appears on the tag-interest people listing", () => {
      cy.visit(TAG_INTEREST_PATH);
      cy.get("body").find(`a[href*="${PECAN_LINK}"]`).should("exist");
    });

    // The remaining "appears when not hidden" baselines pin down whether the
    // matching hidden-state assertions in the other describe() are passing
    // for the right reason. If pecan never appears on a given URL (e.g.,
    // because it 307s to OIDC for anonymous users, or pecan is paginated
    // out of view), the hidden-state assertion passes vacuously.
    it("user link appears when searching CSSN directory for pecan", () => {
      cy.visit(`${CSSN_DIRECTORY_PATH}?search_api_fulltext=pecan&items_per_page=24`);
      cy.get("body").find(`a[href*="${PECAN_LINK}"]`).should("exist");
    });

    it("user link appears in OnDemand /people search", () => {
      cy.visit(OOD_PEOPLE_URL);
      cy.get("body").find(`a[href*="${PECAN_LINK}"]`).should("exist");
    });

  });

  describe("user edit form path", () => {
    // Exercise the form-display config so we know the checkbox is reachable
    // for an admin editing another user. Other cases use drush for speed.

    before(() => {
      setHide(false);
    });

    after(() => {
      setHide(false);
    });

    it("admin can toggle Hide Community Profile from the user edit form", () => {
      cy.loginAs(ADMIN_USER, ADMIN_PASS);
      cy.visit(`/user/${TEST_UID}/edit`);
      cy.get('input[name="field_hide_community_profile[value]"]')
        .should("exist")
        .check();
      cy.get('input[type="submit"][value="Save"]').click();
      cy.contains("The changes have been saved").should("be.visible");

      // Confirm the field saved by visiting the public page in a fresh session.
      cy.clearCookies();
      cy.visit(PERSONA_PATH);
      cy.contains("No public profile available for this person.")
        .should("be.visible");
    });

  });

});
