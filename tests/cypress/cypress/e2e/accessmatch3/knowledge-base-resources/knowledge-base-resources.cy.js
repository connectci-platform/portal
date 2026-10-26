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

import { ADMIN, createKbResource, deleteSubmissions } from "../../../support/kb-resources";

const RUN = Date.now().toString();
const TITLE = `kbunauth-${RUN}`;
const created = [];
const EVENTS_SEARCH = '[data-drupal-selector="edit-search-api-fulltext"]';
describe("Unauthenticated user tests the KB Resources Page", () => {
  // The resource searched for below is created here, so the spec does not
  // depend on a resource another spec left behind.
  before(() => {
    cy.loginAs(...ADMIN);
    createKbResource({ title: TITLE, tags: ["xalt"] }).then((id) => { created.push(id); });
    cy.drupalLogout();
  });

  after(() => deleteSubmissions(created));

  it("Should test the KB Resources page for unauthenticated user", () => {
    cy.visit("/knowledge-base/resources");
    //Likes should be an integer value
    cy.get(".view-search-ci-links details summary .linkcount")
      .first()
      .invoke('text')
      .should(text => {
        expect(text.trim(), 'Likes should be an integer').to.match(/^\d+$/)
      })
    cy.contains("Login to like")
    //Searching for the resource created above
    // The fulltext filter reads a hyphen as minus, so search the bare run id.
    cy.searchAndWait(EVENTS_SEARCH, RUN);
    cy.get(".view-search-ci-links details summary")
      .contains(TITLE)
  });
});
