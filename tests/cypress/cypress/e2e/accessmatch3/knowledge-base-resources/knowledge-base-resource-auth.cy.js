const TITLE = "dummy link for testing knowledge base";

//
// As an authenticated user, verify auth features for the ci link "cypress-ci-link-for-testing"
//
describe("Authenticated user tests a ci link", () => {
  // No cleanup: knowledge-base-resource-unauth.cy.js and
  // z-affinity-groups-api/ag-api.cy.js use the resource created here.
  it("verify auth features of a ci link", () => {
    // first create a dummy ci-links so can reference one of them in the AG.
    create_dummy_ci_link().then((sid) => {
      // login user with the "authenticated" role
      cy.loginAs("authenticated@amptesting.com", "6%l7iF}6(4tI");
      // Navigating to the cypress created ci link. The deep link lists only
      // that resource, so the checks below hold if a rerun leaves an older
      // resource with the same title in the index.
      cy.visit(`/knowledge-base/resources?f%5B0%5D=submission_id%3A${sid}`);
      const item = () => cy.contains(".view-search-ci-links .details-wrap", TITLE);
      const likeCount = () => item().find("details.search-ci-links > summary .linkcount").then(($count) => Number($count.text().trim()));

      item().find("details.search-ci-links > summary").click();

      //Flag Feature.When one of the selections in the dropdown is clicked I am brough to an access denied page. Section is disabled for now
      cy.contains("Flag as")
      cy.get(".view-search-ci-links details .md--col-span-1 ul li:nth-of-type(2) a").contains("Not useful");

      // Vote feature: liking adds one like and turns the Like link into an
      // Unlike link. The flag link is a plain link: the server flags, indexes
      // the item, then redirects back to the page the link was rendered on,
      // query string included. The browser follows the redirect, so assert on
      // the page it lands on, with no further visit: the count there must
      // already be current.
      cy.intercept("GET", "**/flag/*/upvote/**").as("upvote");
      likeCount().then((before) => {
        item().find(".flag-upvote.action-flag a").click();
        cy.wait("@upvote").its("response.statusCode").should("eq", 302);
        cy.location("search").should((search) => expect(decodeURIComponent(search)).to.contain(`f[0]=submission_id:${sid}`));
        item().find(".flag-upvote.action-unflag a").should("exist");
        item().find(".flag-upvote.action-flag a").should("not.exist");
        likeCount().should("eq", before + 1);

        // Unlike to restore the original count and leave the user as found.
        item().find("details.search-ci-links > summary").click();
        item().find(".flag-upvote.action-unflag a").click();
        cy.wait("@upvote").its("response.statusCode").should("eq", 302);
        cy.location("search").should((search) => expect(decodeURIComponent(search)).to.contain(`f[0]=submission_id:${sid}`));
        item().find(".flag-upvote.action-flag a").should("exist");
        likeCount().should("eq", before);
      });
    });
  });
});
// helper function to create a ci-link
function create_dummy_ci_link() {
  cy.loginAs("administrator@amptesting.com", "b8QW]X9h7#5n");
  // Clear search api index
  cy.visit("/admin/config/search/search-api/index/ci_links")
  cy.get("#edit-clear").click()
  cy.get("#edit-submit").click()

  cy.visit("/form/resource");
  cy.get("#edit-approved").check();
  cy.get("#edit-title").type("dummy link for testing knowledge base");
  cy.get("#edit-category").select("Learning");
  cy.get("#edit-skill-level-304").check(); // beginner level
  cy.get('.form-item-description-html-value .ck-content').then(el => {
    const editor = el[0].ckeditorInstance
    editor.setData("Dummy description for ci-link 'dummy link for testing knowledge base'")
  });
  // tag "ACCESS-account" is selected
  cy.get(".tags").contains("ACCESS-account").click();
  cy.get('.form-item-domain').find('.select2-search__field').type('ACCESS{enter}');
  cy.get("#edit-submit").click();
  // The confirmation page is /webform/resource/submissions/<sid>.
  return cy.location("pathname")
    .should("match", /\/webform\/resource\/submissions\/\d+$/)
    .then((path) => {
      const sid = Number(path.split("/").pop());
      cy.drupalLogout();
      return cy.wrap(sid);
    });
}
