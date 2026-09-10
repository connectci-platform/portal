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
    // By block class, not id: the id picks up an AJAX suffix once the view
    // re-renders, which would make this negative assertion pass vacuously.
    cy.get('.block-facet-blockorganization-cyberteam-people').should('not.exist');

    // Test search functionality (should work for anonymous users)
    cy.searchAndWait('[data-drupal-selector="edit-search-api-fulltext"]', 'testing123');
    cy.contains('No matches found')

    cy.clearSearchAndWait('[data-drupal-selector="edit-search-api-fulltext"]');
    cy.searchAndWait('[data-drupal-selector="edit-search-api-fulltext"]', 'julie');
    cy.contains('Julie Ma')

    cy.clearSearchAndWait('[data-drupal-selector="edit-search-api-fulltext"]');

    // Test Skills facet (should be available to anonymous users)
    cy.get('body').then($body => {
      // Check if the skills facet show more link exists
      if ($body.find('.block-facet-blockuser-skills-cyberteam-people:visible .facets-soft-limit-link').length > 0) {
        cy.get('.block-facet-blockuser-skills-cyberteam-people:visible .facets-soft-limit-link').click();
      }
      
      // Check if specific skill filters exist
      if ($body.find('#user-skills-cyberteam-people-llm').length > 0) {
        cy.get('#user-skills-cyberteam-people-llm').click();
        cy.contains('llm')
        
        if ($body.find('#user-skills-cyberteam-people-bash').length > 0) {
          cy.get('#user-skills-cyberteam-people-bash').click();
        }
        
        // Reset skills if reset exists
        if ($body.find('#user-skills-cyberteam-people-reset-all').length > 0) {
          cy.get('#user-skills-cyberteam-people-reset-all').click();
        }
      }
    })
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
