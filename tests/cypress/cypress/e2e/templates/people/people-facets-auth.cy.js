/*
    People page - Authenticated User Tests.

    Tests facet functionality that requires authentication:
    - Programs facet
    - Organization facet
    
    The Skills facet remains available to anonymous users.

    The view runs on AJAX, so nothing here is addressed by id: the exposed
    form's duplicate-id counter and the facet block wrapper ids both change
    after the first interaction. Facet blocks are placed twice (desktop and a
    collapsed mobile copy), so facet item ids need `:visible` to resolve to the
    copy a user can click.
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

    // Test Programs facet (requires authentication)
    cy.get('#program-308:visible').should('exist').click();
    cy.contains('Programs Northeast')
    cy.get('#program-reset-all:visible').click();

    // Test Organization facet (requires authentication)
    cy.get('.block-facet-blockorganization-cyberteam-people:visible .facets-soft-limit-link').should('exist').click();
    cy.get('#organization-cyberteam-people-1931:visible').should('exist').click();
    cy.contains('Harvard University')
    cy.get('#organization-cyberteam-people-reset-all:visible').click();

    // Test Skills facet (available to all users)
    cy.get('.block-facet-blockuser-skills-cyberteam-people:visible .facets-soft-limit-link').should('exist').click();
    cy.get('#user-skills-cyberteam-people-llm:visible').should('exist').click();
    cy.contains('llm')
    cy.get('#user-skills-cyberteam-people-bash:visible').click();
  });

  it("Test multiple facets interaction for authenticated user", () => {
    cy.visit('/people');

    // Test combining multiple facets
    cy.get('body').then($body => {
      // Check if program facet exists
      if ($body.find('#program-308').length > 0) {
        cy.get('#program-308:visible').click();
        cy.wait(1000)
        
        // Add organization filter on top of program filter
        if ($body.find('#organization-cyberteam-people-1931').length > 0) {
          cy.get('#organization-cyberteam-people-1931:visible').click();
          cy.wait(1000)
          
          // Reset all filters
          cy.get('#program-reset-all:visible').click();
          cy.wait(500)
          if ($body.find('#organization-cyberteam-people-reset-all').length > 0) {
            cy.get('#organization-cyberteam-people-reset-all:visible').click();
          }
        }
      }
    })
  });

});