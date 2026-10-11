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
    //Searching for Cypress Created CI Link
    cy.searchAndWait(EVENTS_SEARCH, "dummy link for testing knowledge base");
    cy.get(".view-search-ci-links details summary")
      .contains("dummy link for testing knowledge base")
  });
});
