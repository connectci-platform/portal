const EVENTS_SEARCH = '[data-drupal-selector="edit-search-api-fulltext"]';

describe('Test the registration feature', () => {

  before(() => {
    // Clean up existing registrations and clear mailpit before tests
    cy.clearMailpit();

    cy.loginAs("administrator@amptesting.com", "b8QW]X9h7#5n");
    cy.visit('/events')
    cy.searchAndWait(EVENTS_SEARCH, 'example');
    cy.contains('cypress-example-event').click()

    // Delete all existing registrations
    cy.get('body').then($body => {
      if ($body.text().includes('Registrations')) {
        cy.contains('Registrations').click()
        cy.wait(1000)

        // Delete registrations if any exist
        cy.get('body').then($registrationsBody => {
          if ($registrationsBody.find('table tbody tr').length > 0) {
            cy.get('table tbody tr').each(() => {
              // Open the dropbutton in the first row
              cy.get('table tbody tr').first().within(() => {
                cy.get('.dropbutton-toggle button').click({force: true})
                cy.wait(200)
                cy.get('a[href*="/delete"]').first().click({force: true})
              })
              cy.wait(500)
              // Wait for the modal dialog and click the delete button
              cy.get('.ui-dialog-buttonpane button.button--primary').should('be.visible').click()
              cy.wait(1000)
            })
          }
        })
      }
    })
  })

  it('Approve registration as admin', () => {
    cy.clearMailpit();

    cy.loginAs("administrator@amptesting.com", "b8QW]X9h7#5n");

    cy.visit('/events')
    cy.searchAndWait(EVENTS_SEARCH, 'example');
    cy.contains('cypress-example-event').click()
    cy.contains('Register').click()

    cy.get('#edit-submit').click()

    cy.contains('You will receive an email after your registration is approved')
    cy.contains('Approved: No')

    // Check for pending registration email
    cy.waitForEmail({
      to: 'administrator@amptesting.com',
      subject: 'Registration Pending for cypress-example-event'
    }).then((message) => {
      cy.assertEmailContent(message, {
        subject: 'Registration Pending for cypress-example-event',
        from: 'noreply@mg.support.access-ci.org',
        to: 'administrator@amptesting.com',
        htmlContains: 'You will receive another email once your registration has been reviewed and approved. Approval typically takes 1-2 business days'
      });
    });

    cy.contains('Registrations').click()
    cy.contains('Approve All').click()
    cy.wait(1000)

    cy.contains('Cypress-example-event').click()

    cy.contains('Approved: Yes')

    // Check for approved registration email
    cy.waitForEmail({
      to: 'administrator@amptesting.com',
      subject: 'Registration Confirmed for cypress-example-event'
    }).then((message) => {
      cy.assertEmailContent(message, {
        subject: 'Registration Confirmed for cypress-example-event',
        from: 'noreply@mg.support.access-ci.org',
        to: 'administrator@amptesting.com',
        htmlContains: 'Congratulations! Your registration for'
      });
    });
  })

  it('Walnut register for event', () => {
    cy.loginAs("walnut@pie.org", "Walnut");

    cy.visit('/events')
    cy.searchAndWait(EVENTS_SEARCH, 'example');
    cy.contains('cypress-example-event').click()
    cy.contains('Register').click()
    cy.contains('Please confirm your registration below')
    cy.get('#edit-submit').click()

    cy.contains('Successfully registered to the waitlist.')
    cy.contains('Approved: No')
    cy.contains('Waitlist: Yes')

    // Check for waitlist registration email
    cy.waitForEmail({
      to: 'walnut@pie.org',
      subject: 'Waitlist Status for cypress-example-event'
    }).then((message) => {
      cy.assertEmailContent(message, {
        subject: 'Waitlist Status for cypress-example-event',
        from: 'noreply@mg.support.access-ci.org',
        to: 'walnut@pie.org',
        htmlContains: 'At this time, the session is full, and your registration has been placed on the waitlist'
      });
    });

  })

  it('Approve Walnut registration as admin', () => {
    cy.loginAs("administrator@amptesting.com", "b8QW]X9h7#5n");

    cy.visit('/events')
    cy.searchAndWait(EVENTS_SEARCH, 'example');
    cy.contains('cypress-example-event').click()

    cy.contains('Registrations').click()
    cy.contains('Approve All').click()
  })

  it('Walnut check registration status', () => {
    cy.loginAs("walnut@pie.org", "Walnut");

    cy.visit('/events')
    cy.searchAndWait(EVENTS_SEARCH, 'example');
    cy.contains('cypress-example-event').click()

    cy.contains('Approved: Yes')

    // Check for waitlist registration email
    cy.waitForEmail({
      to: 'walnut@pie.org',
      subject: 'Registration Confirmed for cypress-example-event'
    }).then((message) => {
      cy.assertEmailContent(message, {
        subject: 'Registration Confirmed for cypress-example-event',
        from: 'noreply@mg.support.access-ci.org',
        to: 'walnut@pie.org',
        htmlContains: 'has been confirmed'
      });
    });

  })

  it('Should verify registration page UI enhancements', () => {
    cy.loginAs("administrator@amptesting.com", "b8QW]X9h7#5n");

    cy.visit('/events')
    cy.searchAndWait(EVENTS_SEARCH, 'example');
    cy.contains('cypress-example-event').click()
    cy.contains('Registrations').click()

    // Verify event title appears in page title
    cy.title().should('include', 'cypress-example-event')

    // Verify # column exists in the registration table
    cy.get('table thead th').first().should('contain', '#')

    // Verify registration counts are displayed clearly
    cy.contains(/Capacity:\s*\d+/i).should('be.visible')
    cy.contains(/Approved:\s*\d+/i).should('be.visible')
    cy.contains(/Unapproved:\s*\d+/i).should('be.visible')
    cy.contains(/Waitlist:\s*\d+/i).should('be.visible')

    // Verify the registration table has numbered rows
    cy.get('table tbody tr').first().within(() => {
      cy.get('td').first().invoke('text').then(text => text.trim()).should('match', /^\d+$/)
    })
  })

})
