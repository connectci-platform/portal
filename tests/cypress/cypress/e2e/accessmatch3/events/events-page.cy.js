/*
    This test is specifically focused on the Events page tested for an unauthenticated user.
    This test checks for major functions available to anonymous users:
    - Event Type facet (only facet available to anonymous users)
    - Headers
    - Search functionality
    - Testing created event

    Event Affiliation and Skill Level facets are only available to authenticated users.

*/
describe('Unauthenticated user tests the Events Page', () => {
  it('Should test the Events page for unauthenticated user', () => {
    cy.visit('/events')

    cy.contains('Upcoming Events')
    cy.contains('Past Events')
    cy.contains('My Events')
    cy.contains('+ Add Event')

    // Verify Event Affiliation facet does NOT exist for anonymous users
    cy.get('#custom-event-affiliation-community').should('not.exist')
    
    // Verify Event Skill Level facet does NOT exist for anonymous users  
    cy.get('#custom-event-skill-level-advanced').should('not.exist')
    
    // Verify reset buttons for restricted facets don't exist
    cy.get('#custom-event-affiliation-reset-all').should('not.exist')
    cy.get('#custom-event-skill-level-reset-all').should('not.exist')

    // Test Event Type facet (should be available for anonymous users)
    cy.get('#custom-event-type-conference').should('exist').check()
    cy.wait(1000)
    
    // Look for the anonymous test event that should be visible
    cy.contains('cypress anonymous test event')
      .closest('.views-row')
      .within(() => {
        cy.contains('Online');
        cy.get('.views-field-date-1')
          .find('.text-dark-teal')
          .within(() => {
            cy.get('div.text-4xl').should('have.text', '15');
            cy.get('div.text-xl').eq(1).should('have.text', 'Nov');
          });
      });

    // Reset event type facet
    cy.get('#custom-event-type-reset-all').check()
    cy.wait(1000)

    cy.get('#edit-search-api-fulltext--2').type('Random string', { delay: 0 })
    cy.wait(1000)
    cy.contains('No Events Found')

    cy.get('#edit-search-api-fulltext--2').clear()
    cy.wait(1000)
    cy.get('#edit-search-api-fulltext--2').type('cypress anonymous', { delay: 0 })

    // The event is created by events-add.cy.js and must be indexed by search_api
    // before it appears in these fulltext results. With index_directly the index
    // updates on save, but the eventinstance can lag the eventseries save, and
    // the Views AJAX search also needs to settle — so a fixed cy.wait races under
    // CI load. Retry the assertion until the event is searchable instead of
    // guessing a delay (the cause of intermittent "never found it" timeouts).
    cy.contains('cypress anonymous test event', { timeout: 15000 })
      .closest('.views-row')
      .within(() => {
        cy.contains('Online');
        cy.get('.views-field-date-1')
          .find('.text-dark-teal')
          .within(() => {
            cy.get('div.text-4xl').should('have.text', '15');
            cy.get('div.text-xl').eq(1).should('have.text', 'Nov');
          });
      });
    
    // Click on the event to test individual event page
    cy.contains('cypress anonymous test event').click()
    
    // Test individual event page
    cy.url().should('include', '/events/')


    // Event Date
    cy.get('.field--name-date').contains(
      '11/15/26'
    )

    // Event Location
    cy.get('.field--name-location').contains('Online')

    // Event Tags
    cy.get('.field__item.me-2 a').contains('training')

    //Event Contact
    cy.get('#block-asptheme-eventinstancesidebar').contains('Contact')
    cy.get('#block-asptheme-eventinstancesidebar').contains('Test Contact')

    // Registration Button
    cy.get('.field__item .btn').contains('Register')
      .should('have.attr', 'href')

    // Event Type (should be visible to anonymous users)
    cy.get('#block-asptheme-eventinstancesidebar').contains('Event Type')
    cy.get('#block-asptheme-eventinstancesidebar').contains('Conference')
    
    // Verify that Skill Level section does NOT exist for anonymous users
    // (since we didn't set a skill level for this test event)
    cy.get('#block-asptheme-eventinstancesidebar').should('not.contain', 'Skill Level')
  })
})
