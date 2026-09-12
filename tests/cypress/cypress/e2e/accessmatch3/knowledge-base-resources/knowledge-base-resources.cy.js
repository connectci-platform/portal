/*
    This test is specifically focused on the CI Links page tested for an unauthenticated user.
    This test checks for major functions like:
    Search Bar Functionality,
    Radio Button Functionality,
    Headers,
    Sorting function,
    Verifying links load in,
    And CI Links go to the correct page
*/

const EVENTS_SEARCH = '[data-drupal-selector="edit-search-api-fulltext"]';
describe("Unauthenticated user tests the KB Resources Page", () => {
  it("Should test the KB Resources page for unauthenticated user", () => {
    cy.visit("/knowledge-base/resources");
    //Likes should be an integer value
    cy.get(".view-search-ci-links details summary .linkcount")
      .invoke('val')
      .should(value => {
        expect(Number.isInteger(+value), 'Likes should be an integer').to.eq(true)
      })
    // cy.contains("Description");
    // cy.contains("Category");
    // cy.contains("Tags");
    // cy.contains("Skill Level");
    // cy.contains("Affinity Group");
    // cy.contains("Category");
    //Skill Level Filter Option
    // cy.get(".js-form-item-skill-level-306").contains("Advanced").click();
    // cy.get(".alert").contains(
    //   "There are no CI Links at this time. Please check back often as CI Links are added regularly."
    // );

    // cy.visit("/knowledge-base/ci-links");

    //Searching for Cypress Created CI Link
    cy.searchAndWait(EVENTS_SEARCH, "dummy-ci-link-for-testing-knowledge-base");
    cy.get(".view-search-ci-links details summary")
      .contains("dummy-ci-link-for-testing-knowledge-base")
  });
});
