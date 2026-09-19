/*
    This test verifies that past events do not show the registration button.
    It creates an event, then changes the date to past via SQL, and verifies
    the Register button is no longer displayed.
*/

const EVENTS_SEARCH = '[data-drupal-selector="edit-search-api-fulltext"]';
describe('Past events should not show registration button', () => {
  const pastEventName = 'cypress-past-event-no-register';

  before(() => {
    // Create a future event first (we'll change it to past later)
    cy.loginAs("administrator@amptesting.com", "b8QW]X9h7#5n");
    cy.visit('/events/add');

    // Body
    cy.get('.field--name-body .ck-content').then(el => {
      const editor = el[0].ckeditorInstance;
      editor.setData('Test event for verifying past events hide registration button.');
    });

    // Summary
    cy.get('#edit-summary-text').type('Past event registration test.', { delay: 0 });

    // Title
    cy.get('#edit-title-0-value').type(pastEventName, { delay: 0 });

    // Date and Time - set to tomorrow initially (so form accepts it)
    cy.get('#edit-recur-type-custom').click();
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const tomorrowStr = tomorrow.toISOString().split('T')[0];

    cy.get('#edit-custom-date-0-value-date').type(tomorrowStr, { delay: 0 });
    cy.get('#edit-custom-date-0-end-value-date').type(tomorrowStr, { delay: 0 });
    cy.get('#edit-custom-date-0-value-time').type('10:00:00', { delay: 0 });
    cy.get('#edit-custom-date-0-end-value-time').type('12:00:00', { delay: 0 });

    // Event Location
    cy.get('#edit-field-location-0-value').type('Online', { delay: 0 });

    // Event Contact
    cy.get('#edit-field-contact-0-value').type('Test Contact', { delay: 0 });

    // Enable Registration
    cy.get('input[data-drupal-selector="edit-event-registration-0-registration"]').check();
    cy.get('#edit-event-registration-0-capacity').type('10', { delay: 0 });

    // Publish
    cy.get('#edit-moderation-state-0-state').select('Published');

    // Event Type
    cy.get('#edit-field-event-type-training').click();

    cy.get('#edit-submit').click();

    // Wait for event to be created
    cy.url().should('include', '/events/');
  });

  it('Should show Register button for future event', () => {
    cy.loginAs("administrator@amptesting.com", "b8QW]X9h7#5n");
    cy.visit('/events');
    cy.searchAndWait(EVENTS_SEARCH, pastEventName);
    cy.contains(pastEventName).click();

    // Verify Register button is visible for future event
    cy.get('#block-asptheme-eventinstancesidebar').contains('Register');
  });

  it('Should NOT show Register button for past event', () => {
    cy.loginAs("administrator@amptesting.com", "b8QW]X9h7#5n");
    cy.visit('/events');
    cy.searchAndWait(EVENTS_SEARCH, pastEventName);
    cy.contains(pastEventName).click();

    // Get event instance ID from URL and change date to past
    cy.url().then((url) => {
      const match = url.match(/events\/(\d+)/);
      if (match) {
        const eventInstanceId = match[1];

        // Calculate past date (2 days ago)
        const pastDate = new Date();
        pastDate.setDate(pastDate.getDate() - 2);
        const pastDateStr = pastDate.toISOString().split('T')[0];

        // Update the event instance date to past using drush SQL
        cy.exec(`ddev drush sqlq "UPDATE eventinstance_field_data SET date__value = '${pastDateStr}T10:00:00', date__end_value = '${pastDateStr}T12:00:00' WHERE id = ${eventInstanceId}"`, { failOnNonZeroExit: false });
        cy.exec(`ddev drush sqlq "UPDATE eventinstance_field_revision SET date__value = '${pastDateStr}T10:00:00', date__end_value = '${pastDateStr}T12:00:00' WHERE id = ${eventInstanceId}"`, { failOnNonZeroExit: false });

        // Invalidate entity cache and clear caches
        cy.exec(`ddev drush php-eval "\\Drupal::entityTypeManager()->getStorage('eventinstance')->resetCache([${eventInstanceId}]);"`, { failOnNonZeroExit: false });
        cy.exec('ddev drush cr', { failOnNonZeroExit: false });
      }
    });

    // Reload the page to see the changes
    cy.reload();

    // Verify Register button is NOT visible for past event
    cy.get('#block-asptheme-eventinstancesidebar').should('not.contain', 'Register');
  });

  after(() => {
    // Clean up: delete the test event
    cy.loginAs("administrator@amptesting.com", "b8QW]X9h7#5n");
    cy.visit('/events');

    // Switch to Past Events tab to find our event
    cy.contains('Past Events').click();
    cy.wait(1000);

    cy.searchAndWait(EVENTS_SEARCH, pastEventName);

    // Only try to delete if the event exists
    cy.get('body').then($body => {
      if ($body.text().includes(pastEventName)) {
        cy.contains(pastEventName).click();
        cy.get('.tabs a[href*="/edit"]').click();
        cy.get('#edit-delete').click();
        cy.get('.ui-dialog-buttonpane button.button--primary').click();
      }
    });
  });
});
