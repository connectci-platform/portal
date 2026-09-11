/*
    People page - Authenticated User Tests.

    Tests facet functionality that requires authentication:
    - Programs facet
    - Organization facet
    
    The Skills facet remains available to anonymous users.

    The view runs on AJAX, so the exposed form is addressed by
    data-drupal-selector rather than by id: its duplicate-id counter changes
    after the first interaction. Facet items are placed twice (desktop and a
    collapsed mobile copy) under the same ids, so they need `:visible` to
    resolve to the copy a user can click, and a facet's "Show more" link is
    reached by the facet's URL alias rather than by a theme-specific block
    class.
*/

describe("Test people page Card view with all facets for authenticated users", () => {

  beforeEach(() => {
    cy.loginWith('authenticated@amptesting.com', '6%l7iF}6(4tI')
  })

  it("Authenticated user tests the people page in Card View with all filters", () => {
    cy.visit('/people');
    
    // Page elements
    cy.contains('People');
    
    // All facets should be visible to authenticated users
    cy.contains('Programs');
    cy.contains('Skills')
    cy.contains('Organization')

    // Test search functionality
    cy.searchAndWait('[data-drupal-selector="edit-search-api-fulltext"]', 'testing123');
    cy.contains('No matches found')

    cy.clearSearchAndWait('[data-drupal-selector="edit-search-api-fulltext"]');
    cy.searchAndWait('[data-drupal-selector="edit-search-api-fulltext"]', 'julie');
    cy.contains('Julie Ma')

    cy.clearSearchAndWait('[data-drupal-selector="edit-search-api-fulltext"]');

    // Facet clicks go through cy.clickFacetAndWait: it waits for the widget's
    // `facets_filter` binding before clicking, and for the view plus the facet
    // blocks to settle after. A reset click re-renders the whole facet block,
    // so the "Show more" link that follows only exists once that lands.

    // Test Programs facet (requires authentication)
    cy.clickFacetAndWait('#program-308:visible');
    cy.contains('Programs Northeast')
    cy.clickFacetAndWait('#program-reset-all:visible');

    // Test Organization facet (requires authentication)
    cy.expandFacetSoftLimit('organization_cyberteam_people');
    cy.clickFacetAndWait('#organization-cyberteam-people-1931:visible');
    cy.contains('Harvard University')
    cy.clickFacetAndWait('#organization-cyberteam-people-reset-all:visible');

    // Test Skills facet (available to all users)
    cy.expandFacetSoftLimit('user_skills_cyberteam_people');
    cy.clickFacetAndWait('#user-skills-cyberteam-people-llm:visible');
    cy.contains('llm')
    cy.clickFacetAndWait('#user-skills-cyberteam-people-bash:visible');
  });

  it("Test multiple facets interaction for authenticated user", () => {
    cy.visit('/people');

    // Combining two facets. Each click is a separate AJAX round trip that
    // re-renders both facet blocks, so the second selection only adds to the
    // first if it acts on hrefs from the refreshed block. The `$body` snapshot
    // this used to branch on went stale the moment the first click landed, so
    // assert on the live DOM instead.
    cy.clickFacetAndWait('#program-308:visible');
    cy.url().should('include', 'program%3A308');

    // Harvard sits past the organization facet's soft limit of 5, so it is
    // hidden until the "Show more" link is clicked.
    cy.expandFacetSoftLimit('organization_cyberteam_people');
    cy.clickFacetAndWait('#organization-cyberteam-people-1931:visible');
    cy.url().should('include', 'program%3A308');
    cy.url().should('include', 'organization_cyberteam_people%3A1931');

    cy.clickFacetAndWait('#program-reset-all:visible');
    cy.clickFacetAndWait('#organization-cyberteam-people-reset-all:visible');
  });

});
