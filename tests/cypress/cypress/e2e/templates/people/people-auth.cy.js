// Search input and facet blocks are addressed by data-drupal-selector and by
// block class, never by id: the view runs on AJAX, so the exposed form's
// duplicate-id counter and the facet block wrapper ids both change after the
// first interaction. Facet blocks are placed twice (desktop and a collapsed
// mobile copy), so `:visible` picks the one a user can actually click.
const SEARCH = '[data-drupal-selector="edit-search-api-fulltext"]';
const ORG_FACET = '.block-facet-blockorganization-cyberteam-people:visible';
const SKILLS_FACET = '.block-facet-blockuser-skills-cyberteam-people:visible';

describe("test people page w/ filters", () => {
  it("Authenticated user tests the people page and filter", () => {
    cy.loginUser("authenticated@amptesting.com", "6%l7iF}6(4tI");
    cy.visit('/people');
    cy.contains('People');
    cy.contains('Programs');
    cy.contains('Skills');
    cy.contains('Organization');

    cy.searchAndWait(SEARCH, 'testing123');
    cy.contains('No matches found');

    cy.clearSearchAndWait(SEARCH);
    cy.searchAndWait(SEARCH, 'julie');
    cy.contains('Julie Ma');

    cy.clearSearchAndWait(SEARCH);

    cy.get('#program-308:visible').click();
    cy.contains('Programs Northeast');
    cy.get('#program-reset-all:visible').click();

    cy.get(`${ORG_FACET} .facets-soft-limit-link`).click();
    cy.get('#organization-cyberteam-people-1931:visible').click();
    cy.contains('Harvard University');
    cy.get('#organization-cyberteam-people-reset-all:visible').click();

    cy.get(`${SKILLS_FACET} .facets-soft-limit-link`).click();
    cy.get('#user-skills-cyberteam-people-llm:visible').click();
    cy.contains('llm');
    cy.get('#user-skills-cyberteam-people-bash:visible').click();
  });
});
