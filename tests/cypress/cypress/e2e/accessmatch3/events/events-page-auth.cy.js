/*
    This test is specifically focused on the Events page tested for an authenticated user.
    This test checks for facet functionality that requires authentication:
    - Event Affiliation facet
    - Event Skill Level facet
    
    The Event Type facet remains available to anonymous users.

    Test breadcrumbs and authenticated-only functionality
*/

const EVENTS_SEARCH = '[data-drupal-selector="edit-search-api-fulltext"]';
describe('Authenticated user tests the Events Page', () => {
    beforeEach(() => {
        cy.loginAs('authenticated@amptesting.com', '6%l7iF}6(4tI')
    })

    it('Should test the Events page facets for authenticated user', () => {
        cy.visit('/events')

        cy.contains('Upcoming Events')
        cy.contains('Past Events')
        cy.contains('My Events')
        cy.contains('+ Add Event')

        // Test Event Type facet (should still work for authenticated users)
        cy.get('#custom-event-type-training').should('exist').check()
        cy.wait(1000)
        
        // Test Event Affiliation facet (only available to authenticated users)
        cy.get('#affiliation-community').should('exist').check()
        cy.wait(1000)
        
        // Test Event Skill Level facet (only available to authenticated users)  
        cy.get('#custom-event-skill-level-advanced').should('exist').check()
        cy.wait(1000)
        
        cy.contains('cypress-example-event')
          .closest('.views-row')
          .within(() => {
            cy.contains('Zoom');
            cy.get('.views-field-date-1')
              .find('.text-dark-teal')
              .within(() => {
                cy.get('div.text-4xl').should('have.text', '12');
                cy.get('div.text-xl').eq(1).should('have.text', 'Dec');
              });
            cy.get('[href="/tags/login"]').contains('login');
          });

        // Reset all facets
        cy.get('#custom-event-type-reset-all').check()
        cy.wait(1000)
        cy.get('#affiliation-reset-all').check()
        cy.wait(1000)
        cy.get('#custom-event-skill-level-reset-all').check()
        cy.wait(1000)
    })

    it('Should test search functionality for authenticated user', () => {
        cy.visit('/events')

        // Test search functionality
        cy.searchAndWait(EVENTS_SEARCH, 'Random string');
        cy.contains('No Events Found')

        cy.clearSearchAndWait(EVENTS_SEARCH);
        cy.searchAndWait(EVENTS_SEARCH, 'example');
        cy.contains('cypress-example-event')
          .closest('.views-row')
          .within(() => {
            cy.contains('Zoom');
            cy.get('.views-field-date-1')
              .find('.text-dark-teal')
              .within(() => {
                cy.get('div.text-4xl').should('have.text', '12');
                cy.get('div.text-xl').eq(1).should('have.text', 'Dec');
              });
            cy.get('[href="/tags/login"]').contains('login');
          });
    })

    it('Should test individual event page for authenticated user', () => {
        cy.visit('/events')
        
        cy.searchAndWait(EVENTS_SEARCH, 'example');
        cy.contains('cypress-example-event').click()

        // Event Date
        cy.get('.field--name-date').contains(
          '12/12/26'
        )

        // Event Location
        cy.get('.field--name-location').contains('Zoom')

        // Event Tags
        cy.get('.field__item.me-2 a').contains('login')

        //Event Contact
        cy.get('#block-asptheme-eventinstancesidebar').contains('Contact')
        cy.get('#block-asptheme-eventinstancesidebar').contains('Pecan Pie')

        // Registration Button
        cy.get('.field__item .btn').contains('Register')
          .should('have.attr', 'href')

        // Event Skill Level
        cy.get('#block-asptheme-eventinstancesidebar').contains('Skill Level')
        cy.get('#block-asptheme-eventinstancesidebar img[alt="Advanced"]').should('be.visible')

        // Event Type
        cy.get('#block-asptheme-eventinstancesidebar').contains('Event Type')
        cy.get('#block-asptheme-eventinstancesidebar').contains('Training')
    })
})