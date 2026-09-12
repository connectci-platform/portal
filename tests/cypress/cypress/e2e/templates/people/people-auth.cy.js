// The search input is addressed by data-drupal-selector, never by id: the view
// runs on AJAX, so the exposed form's duplicate-id counter changes after the
// first interaction. Facet items are placed twice (desktop and a collapsed
// mobile copy) under the same ids, so `:visible` picks the one a user can
// actually click, and a facet's "Show more" link is reached by the facet's URL
// alias rather than by a theme-specific block class.
const SEARCH = '[data-drupal-selector="edit-search-api-fulltext"]';

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

    // Facet clicks go through cy.clickFacetAndWait: it waits for the widget's
    // `facets_filter` binding before clicking, and for the view plus the facet
    // blocks to settle after. A reset click re-renders the whole facet block,
    // so the "Show more" link that follows only exists once that lands.
    cy.clickFacetAndWait('#program-308:visible');
    cy.contains('Programs Northeast');
    cy.clickFacetAndWait('#program-reset-all:visible');

    cy.expandFacetSoftLimit('organization_cyberteam_people');
    cy.clickFacetAndWait('#organization-cyberteam-people-1931:visible');
    cy.contains('Harvard University');
    cy.clickFacetAndWait('#organization-cyberteam-people-reset-all:visible');

    cy.expandFacetSoftLimit('user_skills_cyberteam_people');
    cy.clickFacetAndWait('#user-skills-cyberteam-people-llm:visible');
    cy.contains('llm');
    cy.clickFacetAndWait('#user-skills-cyberteam-people-bash:visible');
  });
});
