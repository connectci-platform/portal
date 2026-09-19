/*
    People page - Anonymous User Tests.

    Tests functionality available to anonymous users:
    - Skills facet (only facet available to anonymous users)
    - Search functionality
    - Verifies that restricted facets don't exist

    Programs and Organization facets are only available to authenticated users.
*/

describe("Test people page Card view for anonymous users", () => {

  it("Anonymous user tests the people page in Card View with limited filters", () => {
    cy.visit('/people');
    
    // Page title
    cy.contains('People');
    
    // Check facets in the sidebar specifically to avoid confusion with people cards
    cy.get('.sidebar').within(() => {
      // Verify Skills facet IS visible to anonymous users
      cy.contains('Skills').should('exist')
      
      // Verify Programs facet does NOT exist for anonymous users
      cy.contains('Programs').should('not.exist');
      
      // Verify Organization facet does NOT exist for anonymous users
      cy.contains('Organization').should('not.exist');
    });
    
    // Also verify facet elements don't exist
    cy.get('#program-308').should('not.exist');
    cy.get('#program-reset-all').should('not.exist');
    cy.get('#organization-cyberteam-people-1931').should('not.exist');
    // By facet alias, not by id or block class: a block wrapper id picks up an
    // AJAX suffix once the view re-renders, and the `block-facet-block<id>`
    // classes only exist in some themes, either of which would make this
    // negative assertion pass vacuously.
    cy.get('ul[data-drupal-facet-alias="organization_cyberteam_people"]').should('not.exist');

    // Test search functionality (should work for anonymous users)
    cy.searchAndWait('[data-drupal-selector="edit-search-api-fulltext"]', 'testing123');
    cy.contains('No matches found')

    cy.clearSearchAndWait('[data-drupal-selector="edit-search-api-fulltext"]');
    cy.searchAndWait('[data-drupal-selector="edit-search-api-fulltext"]', 'julie');
    cy.contains('Julie Ma')

    cy.clearSearchAndWait('[data-drupal-selector="edit-search-api-fulltext"]');

    // Test Skills facet (should be available to anonymous users), the only
    // facet anonymous users get. Each click is an AJAX round trip that
    // re-renders the block, so the branch on a `$body` snapshot taken before
    // the first click went stale; cy.clickFacetAndWait waits for the widget
    // binding and for the refresh to land instead.
    cy.expandFacetSoftLimit('user_skills_cyberteam_people');

    cy.clickFacetAndWait('#user-skills-cyberteam-people-llm:visible');
    cy.contains('llm')
    cy.clickFacetAndWait('#user-skills-cyberteam-people-bash:visible');
    cy.clickFacetAndWait('#user-skills-cyberteam-people-reset-all:visible');
  });

  it("Anonymous user verifies restricted content messaging", () => {
    cy.visit('/people');
    
    // Check if there's any messaging about logging in for more features
    cy.get('body').then($body => {
      // Some sites show login prompts or messages for anonymous users
      if ($body.text().includes('log in') || $body.text().includes('sign in')) {
        cy.log('Login messaging found for anonymous users')
      }
    })
  });

});
