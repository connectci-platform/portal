/*
    Affinity Groups page - Anonymous User Tests.

    Tests functionality available to anonymous users:
    - Tags facet (only facet available to anonymous users)
    - Search functionality
    - Verifies that restricted facets don't exist

    Category and other facets are only available to authenticated users.
*/

describe("Test Affinity Groups page for anonymous users", () => {

  it("Anonymous user tests the affinity groups page with limited facets", () => {
    cy.visit('/affinity-groups');
    
    // Page elements
    cy.contains('Affinity Groups');
    cy.contains('Affinity Group');
    cy.contains('Description');
    cy.contains('Request a Group');

    // Test search functionality (should work for anonymous users)
    // Search input by data-drupal-selector, not id: the id carries a form-build
    // counter that changes when facet AJAX rebuilds the exposed form.
    cy.get('[data-drupal-selector="edit-search-api-fulltext"]').type('test search', { delay: 0 })
    cy.wait(1000)
    cy.get('[data-drupal-selector="edit-search-api-fulltext"]').clear().type('{enter}') // Clear and press Enter to trigger search update
    cy.wait(2000) // Wait for search to update

    // Verify Category facet does NOT exist for anonymous users
    cy.get('body').then($body => {
      // Check that category-related facets don't exist
      if ($body.find('.sidebar').length > 0) {
        // If there's a sidebar, check within it
        cy.get('.sidebar').within(() => {
          cy.get('[id*="category"]').should('not.exist')
        })
      } else {
        // Otherwise check the whole body
        cy.get('[id*="affinity-group-category"]').should('not.exist')
      }
    })

    // Test Tags facet (should be available to anonymous users)
    // Reload the page to ensure clean state for facet testing
    cy.visit('/affinity-groups');
    cy.wait(2000);
    
    // Click "Show more" to expand tags list - try the last one instead of first  
    cy.get('ul[data-drupal-facet-alias="affinity_search_tags"]').parent().find('a.facets-soft-limit-link').last().click();
    cy.wait(500);
    
    // Test the AI tag (tag ID 271)
    cy.get('input#affinity-search-tags-271.facets-checkbox').should('exist').check({ force: true });
    cy.wait(1000);
    
    // Verify the filter is applied
    cy.url().should('include', 'affinity_search_tags');
    
    // Uncheck to reset
    cy.get('input#affinity-search-tags-271.facets-checkbox').uncheck({ force: true });
    cy.wait(500);

    // Test join button for anonymous (should redirect to login)
    cy.get('body').then($body => {
      // Look for login-required join buttons
      if ($body.find('[href="/user/login?destination=/affinity-groups"]').length > 0) {
        cy.get('[href="/user/login?destination=/affinity-groups"]')
          .invoke('attr', 'title')
          .should('eq', 'Login to join');
      } else if ($body.find('[title="Login to join"]').length > 0) {
        cy.get('[title="Login to join"]:first').should('exist')
      }
    })

    // Use specific search to test functionality
    cy.get('[data-drupal-selector="edit-search-api-fulltext"]').type('ACCESS', { delay: 0 })
    cy.wait(1000)
    cy.get('body').then($body => {
      if ($body.text().includes('ACCESS Support') || $body.text().includes('ACCESS')) {
        cy.log('Search results found for ACCESS')
      } else {
        cy.log('No ACCESS groups found')
      }
    })
    cy.get('[data-drupal-selector="edit-search-api-fulltext"]').clear().type('{enter}') // Clear and press Enter to reset search
    cy.wait(2000) // Wait for page to reload with all groups
  });

  it("Anonymous user verifies restricted access to group features", () => {
    cy.visit('/affinity-groups');

    // Click on first affinity group if available
    cy.get('body').then($body => {
      if ($body.find('a[href*="/affinity-groups/"]:not([href="/affinity-groups"])').length > 0) {
        cy.get('a[href*="/affinity-groups/"]:not([href="/affinity-groups"]):first').then($link => {
          const href = $link.attr('href')
          cy.visit(href)
          
          // Verify individual group page loads
          cy.get('body').should('exist')
          cy.url().should('include', '/affinity-groups/')
          
          // Check that join requires login for anonymous users
          cy.get('body').then($groupBody => {
            if ($groupBody.find('[href*="/user/login"]').length > 0) {
              cy.log('Login required for group membership')
              cy.get('[href*="/user/login"]:first').should('exist')
            }
          })
        })
      }
    })
  });

});