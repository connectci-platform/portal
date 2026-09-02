/*
  Test Event Registration Surveys and Reminders features

  This feature allows event creators to configure three types of surveys:

  1. Screening Survey - Sent immediately when a user registers (before approval)
     - Fields: field_screen_survey_url, field_screen_survey_email_text
     - Purpose: Pre-screening registrants before event

  2. Pre-Survey - Sent when registration is approved
     - Fields: field_pre_survey_url, field_pre_survey_email_text
     - Purpose: Gather information before the event

  3. Post-Survey - Sent via cron 30 minutes before event ends + reminder 3 days after
     - Fields: field_post_survey_url, field_post_survey_email_text
     - Purpose: Collect feedback after event attendance
     - Has redirect route: /events/{entity}/post_survey/{user}

  4. Registration Reminders - Sent via cron before event starts
     - Configured per-event with timing (e.g., 1 day before)
     - Should NOT be sent if event has already started
     - Should render HTML properly (not escaped)
*/

describe('Event Registration Surveys and Reminders', () => {

  // Test event name used across tests
  const testEventName = 'cypress-survey-test-event';
  const testSurveyUrl = 'https://example.com/test-survey';
  const testReminderHtml = '<p>This is a <strong>reminder</strong> with HTML formatting.</p>';

  describe('Survey form fields on Event Series', () => {

    beforeEach(() => {
      cy.loginAs("administrator@amptesting.com", "b8QW]X9h7#5n");
    });

    it('Should display all survey fields on event series edit form', () => {
      cy.visit('/events/add');

      // Survey fields are only visible when registration is enabled
      cy.get('input[data-drupal-selector="edit-event-registration-0-registration"]').check();
      // Wait for JS to show the survey field groups
      cy.wait(500);

      // Expand the Screening Survey fieldset (look for it specifically by its field group ID)
      cy.get('#edit-group-screening-survey').should('be.visible');
      cy.get('#edit-group-screening-survey > summary').click();
      // Verify Screening Survey fields exist
      cy.get('#edit-field-screen-survey-url-0-uri').should('exist');

      // Expand the Pre Survey fieldset
      cy.get('#edit-group-surveys').should('be.visible');
      cy.get('#edit-group-surveys > summary').click();
      // Verify Pre-Survey fields exist
      cy.get('#edit-field-pre-survey-url-0-uri').should('exist');

      // Expand the Post Survey fieldset
      cy.get('#edit-group-post-survey').should('be.visible');
      cy.get('#edit-group-post-survey > summary').click();
      // Verify Post-Survey fields exist
      cy.get('#edit-field-post-survey-url-0-uri').should('exist');
    });

    it('Should display survey field descriptions with email templates', () => {
      cy.visit('/events/add');

      // Verify email body field descriptions with token documentation
      cy.contains('This is the full email body sent when someone registers').should('exist');
      cy.contains('This is the full email body sent when a registration is approved').should('exist');
      cy.contains('This is the full email body sent near the end of the event').should('exist');
    });

  });

  describe('Create test event with surveys configured', () => {

    before(() => {
      // cy.clearMailpit(); // Temporarily disabled to check email HTML
    });

    it('Should create event with all survey fields configured', () => {
      cy.loginAs("administrator@amptesting.com", "b8QW]X9h7#5n");
      cy.visit('/events/add');

      // Basic event details
      cy.get('#edit-title-0-value').type(testEventName, { delay: 0 });

      cy.get('.field--name-body .ck-content').then(el => {
        const editor = el[0].ckeditorInstance;
        editor.setData('This is a test event for survey functionality testing. It includes screening, pre, and post surveys.');
      });

      cy.get('#edit-summary-text').type('Survey test event summary.', { delay: 0 });

      // Date and Time - set to a future date. Computed dynamically (not
      // hardcoded) because registration closes once the event begins, so a
      // fixed past date silently breaks every registration-dependent survey
      // test once that date arrives. A few days out keeps the event in the
      // future for the whole register/approve/post-survey flow.
      const eventDate = new Date();
      eventDate.setDate(eventDate.getDate() + 7);
      const eventDateStr = eventDate.toISOString().split('T')[0];
      cy.get('#edit-recur-type-custom').click();
      cy.get('#edit-custom-date-0-value-date').type(eventDateStr, { delay: 0 });
      cy.get('#edit-custom-date-0-end-value-date').type(eventDateStr, { delay: 0 });
      cy.get('#edit-custom-date-0-value-time').type('10:00:00', { delay: 0 });
      cy.get('#edit-custom-date-0-end-value-time').type('12:00:00', { delay: 0 });

      // Event Location and Contact
      cy.get('#edit-field-location-0-value').type('Online - Zoom', { delay: 0 });
      cy.get('#edit-field-contact-0-value').type('Test Admin', { delay: 0 });

      // Event Tag
      cy.get('details.tags summary').click();
      cy.get('#tag-training').click();

      // Registration settings
      cy.get('input[data-drupal-selector="edit-event-registration-0-registration"]').check();
      cy.get('#edit-event-registration-0-capacity').type('10', { delay: 0 });
      cy.get('input[data-drupal-selector="edit-event-registration-0-waitlist"]').check();

      // Wait for JS to show survey field groups after registration is checked
      cy.wait(500);

      // Configure Screening Survey (scroll to and expand fieldset first)
      cy.get('#edit-group-screening-survey').should('be.visible');
      cy.get('#edit-group-screening-survey > summary').scrollIntoView().click();
      cy.get('#edit-field-screen-survey-url-0-uri').type(testSurveyUrl + '?type=screening', { delay: 0 });
      // Use CKEditor5 API for the text field
      cy.get('#edit-group-screening-survey .ck-content').then(el => {
        const editor = el[0].ckeditorInstance;
        editor.setData('Please complete this screening survey before we review your registration.');
      });

      // Configure Pre-Survey (scroll to and expand fieldset first)
      cy.get('#edit-group-surveys').should('be.visible');
      cy.get('#edit-group-surveys > summary').scrollIntoView().click();
      cy.get('#edit-field-pre-survey-url-0-uri').type(testSurveyUrl + '?type=pre', { delay: 0 });
      // Use CKEditor5 API for the text field
      cy.get('#edit-group-surveys .ck-content').then(el => {
        const editor = el[0].ckeditorInstance;
        editor.setData('Please complete this pre-event survey to help us prepare for the session.');
      });

      // Configure Post-Survey (scroll to and expand fieldset first)
      cy.get('#edit-group-post-survey').should('be.visible');
      cy.get('#edit-group-post-survey > summary').scrollIntoView().click();
      cy.get('#edit-field-post-survey-url-0-uri').type(testSurveyUrl + '?type=post', { delay: 0 });
      // Use CKEditor5 API for the text field
      cy.get('#edit-group-post-survey .ck-content').then(el => {
        const editor = el[0].ckeditorInstance;
        editor.setData('Thank you for attending! Please share your feedback in this survey.');
      });

      // Event Type and other required fields
      cy.get('#edit-field-event-type-training').click();
      cy.get('#edit-field-affiliation-community').click();
      cy.get('#edit-field-skill-level-beginner').click();

      // Publish
      cy.get('#edit-moderation-state-0-state').select('Published');
      cy.get('#edit-submit').click();

      // Verify success
      cy.contains('Successfully saved').should('be.visible');
    });

  });

  describe('Screening Survey (sent on registration)', () => {

    before(() => {
      // cy.clearMailpit(); // Temporarily disabled
    });

    it('Should send screening survey email when user registers for event with survey configured', () => {
      // cy.clearMailpit(); // Temporarily disabled

      cy.loginAs("walnut@pie.org", "Walnut");

      cy.visit('/events');
      cy.get('#edit-search-api-fulltext--2').type('survey-test', { delay: 0 });
      cy.wait(1000);
      cy.contains(testEventName).click();

      // Register for the event
      cy.contains('Register').click();
      cy.contains('Please confirm your registration below');
      cy.get('#edit-submit').click();

      // Verify registration message mentions survey
      cy.contains('You will receive a screening survey email shortly after registering').should('be.visible');

      // Check for screening survey email
      cy.waitForEmail({
        to: 'walnut@pie.org',
        subject: 'Please Complete a Pre-Screening Survey for ' + testEventName
      }).then((message) => {
        cy.assertEmailContent(message, {
          subject: 'Please Complete a Pre-Screening Survey for ' + testEventName,
          from: 'noreply@mg.support.access-ci.org',
          to: 'walnut@pie.org',
          // The full email body comes from the field content set via CKEditor
          htmlContains: [
            'Please complete this screening survey'
          ]
        });
      });
    });

  });

  describe('Pre-Survey (sent on approval)', () => {

    it('Should send pre-survey email when registration is approved', () => {
      // cy.clearMailpit(); // Temporarily disabled

      // Login as admin to approve registration
      cy.loginAs("administrator@amptesting.com", "b8QW]X9h7#5n");

      cy.visit('/events');
      cy.get('#edit-search-api-fulltext--2').type('survey-test', { delay: 0 });
      cy.wait(1000);
      cy.contains(testEventName).click();

      // Navigate to registrations and approve
      cy.contains('Registrations').click();
      cy.contains('Approve All').click();
      cy.wait(1000);

      // Check for pre-survey approval email sent to Walnut
      cy.waitForEmail({
        to: 'walnut@pie.org',
        subject: 'Registration accepted - please fill in survey before event for ' + testEventName
      }).then((message) => {
        cy.assertEmailContent(message, {
          subject: 'Registration accepted - please fill in survey before event for ' + testEventName,
          from: 'noreply@mg.support.access-ci.org',
          to: 'walnut@pie.org',
          // The full email body comes from the field content set via CKEditor
          htmlContains: [
            'Please complete this pre-event survey'
          ]
        });
      });
    });

  });

  describe('Post-Survey redirect route', () => {

    it('Should show error message for non-registered user accessing post-survey link', () => {
      // Login as Pecan who is not registered
      cy.loginAs("pecan@pie.org", "Pecan");

      // Get the event instance ID first
      cy.visit('/events');
      cy.get('#edit-search-api-fulltext--2').type('survey-test', { delay: 0 });
      cy.wait(1000);
      cy.contains(testEventName).click();

      // Get the event instance ID from the URL
      cy.url().then((url) => {
        const eventId = url.match(/events\/(\d+)/)[1];

        // Get Pecan's user ID
        cy.request('/user').then((response) => {
          // Visit the post-survey redirect URL
          cy.visit(`/events/${eventId}/post_survey/0`, { failOnStatusCode: false });

          // Should show not registered message
          cy.contains('You are not registered for this event').should('be.visible');
        });
      });
    });

    it('Should redirect registered user to post-survey URL', () => {
      // Login as Walnut who is registered
      cy.loginAs("walnut@pie.org", "Walnut");

      // Navigate to the event to get the ID
      cy.visit('/events');
      cy.get('#edit-search-api-fulltext--2').type('survey-test', { delay: 0 });
      cy.wait(1000);
      cy.contains(testEventName).click();

      // Get event instance ID and user ID
      cy.url().then((url) => {
        const eventMatch = url.match(/events\/(\d+)/);
        expect(eventMatch, `URL should contain event ID, got: ${url}`).to.not.be.null;
        const eventId = eventMatch[1];

        // Get Walnut's user ID from the profile edit page (which always uses numeric ID)
        cy.visit('/user');
        cy.get('a[href*="/edit"]').first().invoke('attr', 'href').then((editHref) => {
          const userMatch = editHref.match(/user\/(\d+)\/edit/);
          expect(userMatch, `Edit link should contain user ID, got: ${editHref}`).to.not.be.null;
          const userId = userMatch[1];

          // Visit the post-survey redirect URL
          // Note: This will redirect to external URL, so we check the redirect behavior
          cy.request({
            url: `/events/${eventId}/post_survey/${userId}`,
            followRedirect: false
          }).then((response) => {
            // Should redirect (302 or 303)
            expect(response.status).to.be.oneOf([302, 303]);
            // Redirect location should be the survey URL
            expect(response.headers.location).to.include(testSurveyUrl);
          });
        });
      });
    });

  });

  describe('Post-Survey cron emails and Reminder timing', () => {

    const pastEventName = 'cypress-past-survey-event';

    it('Should create event with post-survey AND reminder, register, change to past, trigger post-survey but NOT reminder', () => {
      cy.clearMailpit(); // Clear for this test to isolate emails

      cy.loginAs("administrator@amptesting.com", "b8QW]X9h7#5n");
      cy.visit('/events/add');

      // Basic event details
      cy.get('#edit-title-0-value').type(pastEventName, { delay: 0 });

      cy.get('.field--name-body .ck-content').then(el => {
        const editor = el[0].ckeditorInstance;
        editor.setData('This is an event for testing post-survey and reminder cron functionality.');
      });

      cy.get('#edit-summary-text').type('Event for post-survey and reminder testing.', { delay: 0 });

      // Set date to TOMORROW first (so we can register), will change to past after
      const tomorrow = new Date();
      tomorrow.setDate(tomorrow.getDate() + 1);
      const tomorrowStr = tomorrow.toISOString().split('T')[0];

      cy.get('#edit-recur-type-custom').click();
      cy.get('#edit-custom-date-0-value-date').type(tomorrowStr, { delay: 0 });
      cy.get('#edit-custom-date-0-end-value-date').type(tomorrowStr, { delay: 0 });
      cy.get('#edit-custom-date-0-value-time').type('10:00:00', { delay: 0 });
      cy.get('#edit-custom-date-0-end-value-time').type('12:00:00', { delay: 0 });

      // Event Location and Contact
      cy.get('#edit-field-location-0-value').type('Online', { delay: 0 });
      cy.get('#edit-field-contact-0-value').type('Test Admin', { delay: 0 });

      // Event Tag
      cy.get('details.tags summary').click();
      cy.get('#tag-training').click();

      // Registration settings
      cy.get('input[data-drupal-selector="edit-event-registration-0-registration"]').check();
      cy.get('#edit-event-registration-0-capacity').type('10', { delay: 0 });

      // Wait for JS to show survey field groups after registration is checked
      cy.wait(500);

      // Configure Post-Survey (scroll to and expand fieldset first)
      cy.get('#edit-group-post-survey').should('be.visible');
      cy.get('#edit-group-post-survey > summary').scrollIntoView().click();
      cy.get('#edit-field-post-survey-url-0-uri').type(testSurveyUrl + '?type=post-cron', { delay: 0 });
      cy.get('#edit-group-post-survey .ck-content').then(el => {
        const editor = el[0].ckeditorInstance;
        editor.setData('Please complete our post-event survey.');
      });

      // Configure Registration Reminder (to test that it does NOT send for past events)
      // The reminder widget creates a fieldset inside registration_reminders[0][data]
      cy.get('#edit-registration-reminders-0-data').scrollIntoView();
      cy.get('input[name="registration_reminders[0][data][reminder]"]').check();
      cy.get('input[name="registration_reminders[0][data][reminder_data][reminder_amount]"]').clear().type('1', { delay: 0 });
      cy.get('select[name="registration_reminders[0][data][reminder_data][reminder_units]"]').select('days');
      cy.get('#edit-registration-reminders-0-data .ck-content').then(el => {
        const editor = el[0].ckeditorInstance;
        editor.setData(testReminderHtml);
      });

      // Event Type and other required fields
      cy.get('#edit-field-event-type-training').click();
      cy.get('#edit-field-affiliation-community').click();
      cy.get('#edit-field-skill-level-beginner').click();

      // Publish
      cy.get('#edit-moderation-state-0-state').select('Published');
      cy.get('#edit-submit').click();

      cy.contains('Successfully saved').should('be.visible');

      // After save, we're on the eventseries page - click on the event instance link (shown as date)
      cy.contains('Event Instances').should('be.visible');
      cy.get('a[href*="/events/"]').filter(':visible').not('[href*="/series"]').not('[href*="/add"]').first().click();

      // Now register for the event (while it's still in the future)
      cy.contains('Register').click();
      cy.get('#edit-submit').click();

      // Approve registration (admin self-approve via registrations page)
      cy.contains('Registrations').click();
      cy.contains('Approve All').click();
      cy.wait(1000);

      // Now change the event date to past using drush to update the eventinstance
      // Get the event instance ID from the URL
      cy.url().then((url) => {
        const match = url.match(/events\/(\d+)/);
        if (match) {
          const eventInstanceId = match[1];
          // Calculate past date (2 days ago)
          const pastDate = new Date();
          pastDate.setDate(pastDate.getDate() - 2);
          const pastDateStr = pastDate.toISOString().split('T')[0];

          // Set reminder_date to yesterday (should trigger cron pickup)
          const yesterdayTimestamp = Math.floor((new Date().getTime() - 86400000) / 1000);

          // Update the event instance date to past using drush SQL
          // Must update both the field_data table AND the field_revision table
          cy.exec(`ddev drush sqlq "UPDATE eventinstance_field_data SET date__value = '${pastDateStr}T10:00:00', date__end_value = '${pastDateStr}T12:00:00', reminder_date = ${yesterdayTimestamp}, reminder_sent = NULL WHERE id = ${eventInstanceId}"`, { failOnNonZeroExit: false });
          cy.exec(`ddev drush sqlq "UPDATE eventinstance_field_revision SET date__value = '${pastDateStr}T10:00:00', date__end_value = '${pastDateStr}T12:00:00' WHERE id = ${eventInstanceId}"`, { failOnNonZeroExit: false });

          // Invalidate entity cache for this specific entity and clear all caches
          cy.exec(`ddev drush php-eval "\\Drupal::entityTypeManager()->getStorage('eventinstance')->resetCache([${eventInstanceId}]);"`, { failOnNonZeroExit: false });
          cy.exec('ddev drush cr', { failOnNonZeroExit: false });
          cy.exec('ddev drush search-api:index events --batch-size=50', { failOnNonZeroExit: false });
        }
      });

      // Run only the events cron hook (not full cron) to trigger post-survey emails and reminders
      cy.exec('ddev drush php-eval "access_events_cron();"', { failOnNonZeroExit: false, timeout: 60000 }).then((result) => {
        cy.log('Events cron output:', result.stdout);
      });

      // Wait a moment for emails to be processed
      cy.wait(2000);

      // Check for post-survey email (should be sent)
      cy.waitForEmail({
        to: 'administrator@amptesting.com',
        subject: 'Please participate in our survey'
      }).then((message) => {
        // Get full message to verify HTML content
        cy.getMailpitMessage(message.ID).then((fullMessage) => {
          const emailContent = fullMessage.HTML || fullMessage.Text;
          // The full email body comes from the field content set via CKEditor
          expect(emailContent).to.include('Please complete our post-event survey');
        });
      });

      // Verify NO reminder email was sent (because event has already started)
      cy.request({
        method: 'GET',
        url: Cypress.env('mailpitUrl') || 'http://127.0.0.1:8026/api/v1/search',
        qs: { query: 'to:administrator@amptesting.com subject:Reminder' },
        failOnStatusCode: false
      }).then((response) => {
        if (response.status === 200 && response.body.messages) {
          expect(response.body.messages.length).to.equal(0,
            'No reminder email should be sent for events that have already started');
        }
      });

      // Check logs to confirm the reminder skip was logged
      cy.exec('ddev drush watchdog:show --count=10 --type=access_events', { failOnNonZeroExit: false }).then((result) => {
        expect(result.stdout).to.include('Skipped reminder');
      });
    });

  });

  describe('Registration Reminder with HTML content', () => {

    const reminderEventName = 'cypress-reminder-html-event';

    it('Should send reminder with properly rendered HTML (not escaped)', () => {
      cy.clearMailpit();

      cy.loginAs("administrator@amptesting.com", "b8QW]X9h7#5n");
      cy.visit('/events/add');

      // Basic event details
      cy.get('#edit-title-0-value').type(reminderEventName, { delay: 0 });

      cy.get('.field--name-body .ck-content').then(el => {
        const editor = el[0].ckeditorInstance;
        editor.setData('This is a test event for reminder HTML testing.');
      });

      cy.get('#edit-summary-text').type('Reminder HTML test event.', { delay: 0 });

      // Set date to far future (7 days from now) - event will NOT have started
      const futureDate = new Date();
      futureDate.setDate(futureDate.getDate() + 7);
      const futureDateStr = futureDate.toISOString().split('T')[0];

      cy.get('#edit-recur-type-custom').click();
      cy.get('#edit-custom-date-0-value-date').type(futureDateStr, { delay: 0 });
      cy.get('#edit-custom-date-0-end-value-date').type(futureDateStr, { delay: 0 });
      cy.get('#edit-custom-date-0-value-time').type('14:00:00', { delay: 0 });
      cy.get('#edit-custom-date-0-end-value-time').type('16:00:00', { delay: 0 });

      // Event Location and Contact
      cy.get('#edit-field-location-0-value').type('Online - Zoom', { delay: 0 });
      cy.get('#edit-field-contact-0-value').type('Test Admin', { delay: 0 });

      // Event Tag
      cy.get('details.tags summary').click();
      cy.get('#tag-training').click();

      // Registration settings
      cy.get('input[data-drupal-selector="edit-event-registration-0-registration"]').check();
      cy.get('#edit-event-registration-0-capacity').type('10', { delay: 0 });

      cy.wait(500);

      // Configure Registration Reminder with HTML content
      // The reminder widget creates a fieldset inside registration_reminders[0][data]
      cy.get('#edit-registration-reminders-0-data').scrollIntoView();
      cy.get('input[name="registration_reminders[0][data][reminder]"]').check();
      cy.get('input[name="registration_reminders[0][data][reminder_data][reminder_amount]"]').clear().type('1', { delay: 0 });
      cy.get('select[name="registration_reminders[0][data][reminder_data][reminder_units]"]').select('days');
      cy.get('#edit-registration-reminders-0-data .ck-content').then(el => {
        const editor = el[0].ckeditorInstance;
        editor.setData(testReminderHtml);
      });

      // Event Type and other required fields
      cy.get('#edit-field-event-type-training').click();
      cy.get('#edit-field-affiliation-community').click();
      cy.get('#edit-field-skill-level-beginner').click();

      // Publish
      cy.get('#edit-moderation-state-0-state').select('Published');
      cy.get('#edit-submit').click();

      cy.contains('Successfully saved').should('be.visible');

      // Navigate to the event instance and register
      cy.contains('Event Instances').should('be.visible');
      cy.get('a[href*="/events/"]').filter(':visible').not('[href*="/series"]').not('[href*="/add"]').first().click();

      // Register for the event
      cy.contains('Register').click();
      cy.get('#edit-submit').click();

      // Approve registration
      cy.contains('Registrations').click();
      cy.contains('Approve All').click();
      cy.wait(1000);

      // Set reminder_date to past (yesterday) so cron will pick it up, but event stays in future
      cy.url().then((url) => {
        const match = url.match(/events\/(\d+)/);
        if (match) {
          const eventInstanceId = match[1];
          const yesterdayTimestamp = Math.floor((new Date().getTime() - 86400000) / 1000);

          cy.exec(`ddev drush sqlq "UPDATE eventinstance_field_data SET reminder_date = ${yesterdayTimestamp}, reminder_sent = NULL WHERE id = ${eventInstanceId}"`, { failOnNonZeroExit: false });
          cy.exec(`ddev drush cr`, { failOnNonZeroExit: false });
        }
      });

      // Run only the events cron hook (not full cron) to trigger reminder email
      cy.exec('ddev drush php-eval "access_events_cron();"', { failOnNonZeroExit: false, timeout: 60000 }).then((result) => {
        cy.log('Events cron output:', result.stdout);
      });

      cy.wait(2000);

      // Check for reminder email with proper HTML
      cy.waitForEmail({
        to: 'administrator@amptesting.com',
        subject: 'Reminder'
      }).then((message) => {
        cy.getMailpitMessage(message.ID).then((fullMessage) => {
          const emailContent = fullMessage.HTML || fullMessage.Text;

          // Verify the HTML content is NOT escaped (no &lt; or &gt;)
          expect(emailContent).to.not.include('&lt;p&gt;');
          expect(emailContent).to.not.include('&lt;strong&gt;');

          // Verify HTML is properly rendered
          expect(emailContent).to.include('<strong>reminder</strong>');
        });
      });
    });

  });

  describe('Cleanup test events', () => {

    it('Should delete test registrations and events', () => {
      cy.loginAs("administrator@amptesting.com", "b8QW]X9h7#5n");

      // Clean up survey test event registrations
      cy.visit('/events');
      cy.get('#edit-search-api-fulltext--2').type('survey-test', { delay: 0 });
      cy.wait(1000);

      cy.get('body').then($body => {
        if ($body.text().includes(testEventName)) {
          cy.contains(testEventName).click();

          // Delete registrations if any exist
          cy.get('body').then($eventBody => {
            if ($eventBody.text().includes('Registrations')) {
              cy.contains('Registrations').click();
              cy.wait(500);

              cy.get('body').then($regBody => {
                if ($regBody.find('table tbody tr').length > 0) {
                  // Delete each registration
                  const deleteRegistrations = () => {
                    cy.get('body').then($currentBody => {
                      if ($currentBody.find('table tbody tr').length > 0) {
                        cy.get('table tbody tr').first().within(() => {
                          cy.get('.dropbutton-toggle button').click({ force: true });
                          cy.wait(200);
                          cy.get('a[href*="/delete"]').first().click({ force: true });
                        });
                        cy.wait(1000);
                        // Handle either modal dialog or regular delete form page
                        cy.get('body').then($deleteBody => {
                          if ($deleteBody.find('.ui-dialog-buttonpane button.button--primary').length > 0) {
                            cy.get('.ui-dialog-buttonpane button.button--primary').click();
                          } else if ($deleteBody.find('#edit-submit').length > 0) {
                            cy.get('#edit-submit').click();
                          }
                        });
                        cy.wait(1000);
                        deleteRegistrations();
                      }
                    });
                  };
                  deleteRegistrations();
                }
              });
            }
          });

          // Delete the event series
          cy.visit('/events');
          cy.get('#edit-search-api-fulltext--2').clear().type('survey-test', { delay: 0 });
          cy.wait(1000);
          cy.contains(testEventName).click();
          cy.get('.region-content').contains('Edit').click();
          cy.contains('a', 'Edit the series').click();

          // Delete the series
          cy.get('a[href*="/delete"]:visible').first().click();
          cy.get('#edit-submit').click();
        }
      });

      // Clean up past survey event (use /events/past since it's a past event)
      cy.visit('/events/past');
      cy.get('#edit-search-api-fulltext--2').clear().type('past-survey-event', { delay: 0 });
      cy.wait(1000);

      cy.get('body').then($body => {
        if ($body.text().includes('cypress-past-survey-event')) {
          cy.contains('cypress-past-survey-event').click();

          // Similar cleanup process
          cy.get('body').then($eventBody => {
            if ($eventBody.text().includes('Registrations')) {
              cy.contains('Registrations').click();
              cy.wait(500);

              cy.get('body').then($regBody => {
                if ($regBody.find('table tbody tr').length > 0) {
                  // Delete each registration using recursive approach
                  const deletePastEventRegistrations = () => {
                    cy.get('body').then($currentBody => {
                      if ($currentBody.find('table tbody tr').length > 0) {
                        cy.get('table tbody tr').first().within(() => {
                          cy.get('.dropbutton-toggle button').click({ force: true });
                          cy.wait(200);
                          cy.get('a[href*="/delete"]').first().click({ force: true });
                        });
                        cy.wait(1000);
                        // Handle either modal dialog or regular delete form page
                        cy.get('body').then($deleteBody => {
                          if ($deleteBody.find('.ui-dialog-buttonpane button.button--primary').length > 0) {
                            cy.get('.ui-dialog-buttonpane button.button--primary').click();
                          } else if ($deleteBody.find('#edit-submit').length > 0) {
                            cy.get('#edit-submit').click();
                          }
                        });
                        cy.wait(1000);
                        deletePastEventRegistrations();
                      }
                    });
                  };
                  deletePastEventRegistrations();
                }
              });
            }
          });

          // Delete the series (use /events/past for past events)
          cy.visit('/events/past');
          cy.get('#edit-search-api-fulltext--2').clear().type('past-survey-event', { delay: 0 });
          cy.wait(1000);
          cy.contains('cypress-past-survey-event').click();
          cy.get('.region-content').contains('Edit').click();
          cy.contains('a', 'Edit the series').click();
          cy.get('a[href*="/delete"]:visible').first().click();
          cy.get('#edit-submit').click();
        }
      });

      // Clean up reminder HTML test event (future event)
      cy.visit('/events');
      cy.get('#edit-search-api-fulltext--2').clear().type('reminder-html-event', { delay: 0 });
      cy.wait(1000);

      cy.get('body').then($body => {
        if ($body.text().includes('cypress-reminder-html-event')) {
          cy.contains('cypress-reminder-html-event').click();

          // Delete registrations if any exist
          cy.get('body').then($eventBody => {
            if ($eventBody.text().includes('Registrations')) {
              cy.contains('Registrations').click();
              cy.wait(500);

              cy.get('body').then($regBody => {
                if ($regBody.find('table tbody tr').length > 0) {
                  const deleteReminderRegs = () => {
                    cy.get('body').then($currentBody => {
                      if ($currentBody.find('table tbody tr').length > 0) {
                        cy.get('table tbody tr').first().within(() => {
                          cy.get('.dropbutton-toggle button').click({ force: true });
                          cy.wait(200);
                          cy.get('a[href*="/delete"]').first().click({ force: true });
                        });
                        cy.wait(1000);
                        cy.get('body').then($deleteBody => {
                          if ($deleteBody.find('.ui-dialog-buttonpane button.button--primary').length > 0) {
                            cy.get('.ui-dialog-buttonpane button.button--primary').click();
                          } else if ($deleteBody.find('#edit-submit').length > 0) {
                            cy.get('#edit-submit').click();
                          }
                        });
                        cy.wait(1000);
                        deleteReminderRegs();
                      }
                    });
                  };
                  deleteReminderRegs();
                }
              });
            }
          });

          // Delete the event series
          cy.visit('/events');
          cy.get('#edit-search-api-fulltext--2').clear().type('reminder-html-event', { delay: 0 });
          cy.wait(1000);
          cy.contains('cypress-reminder-html-event').click();
          cy.get('.region-content').contains('Edit').click();
          cy.contains('a', 'Edit the series').click();
          cy.get('a[href*="/delete"]:visible').first().click();
          cy.get('#edit-submit').click();
        }
      });
    });

  });

});
