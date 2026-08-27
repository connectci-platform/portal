/**
 * Campus Champions Decline Action Tests
 *
 * Tests the DeclineCCAction VBO action that:
 * - Sets submission status to 'declined'
 * - Does NOT assign any champion roles to the user
 * - Does NOT set field_is_cc
 *
 * Test user: pecan@pie.org / Pecan (campuschampionsadmin role)
 */
describe('Campus Champions Decline Action', () => {

  const timestamp = Date.now();

  /**
   * Helper to submit the join campus champions form
   */
  const submitJoinForm = (options) => {
    const {
      username,
      firstName,
      lastName,
      email,
      type = 'champion',
      carnegieCode = '166683'
    } = options;

    cy.visit('/form/join-campus-champions');

    cy.get('#edit-letter-of-collaboration-upload')
      .selectFile('cypress/files/northern-lights.pdf');
    cy.contains(/northern-lights.*\.pdf/, { timeout: 10000 }).should('be.visible');

    cy.get('input[name="username"]').type(username);
    cy.get('input[name="user_first_name"]').type(firstName);
    cy.get('input[name="user_last_name"]').type(lastName);
    cy.get('input[name="user_email"]').type(email);

    if (type === 'champion') {
      cy.get('#edit-champion-user-type-user-champion').check();
      cy.get('input[name="supervisor_name"]').type('Dr. Test Supervisor');
      cy.get('input[name="supervisor_email"]').type('supervisor@no-reply.com');
    } else if (type === 'student') {
      cy.get('#edit-champion-user-type-user-student').check();
      // Wait for student section to become visible
      cy.get('#edit-student-info', { timeout: 5000 }).should('be.visible');
      cy.get('input[name="graduation_year"]').type('2026');
      cy.get('#edit-degree-type-user-graduate').check();
      cy.get('input[name="study_field"]').type('Computer Science');
      // Mentor must be existing Campus Champion - use Pecan Pie
      cy.get('input[name="mentor_name"]').type('Pecan');
      cy.get('.ui-autocomplete .ui-menu-item', { timeout: 5000 }).first().click();
      cy.get('input[name="mentor_email"]').type('pecan@pie.org');
    }

    cy.get('input[name="field_access_organization"]').type('Arkansas for Medical');
    cy.get('.ui-autocomplete .ui-menu-item', { timeout: 10000 })
      .contains('University of Arkansas for Medical Sciences').click();

    cy.get('#webform-submission-join-campus-champions-add-form > #edit-actions > #edit-submit')
      .contains('Submit').click();

    cy.contains('Campus Champions Application Submitted');
  };

  /**
   * Helper to decline an application by first name
   */
  const declineApplication = (firstName) => {
    cy.loginUser('pecan@pie.org', 'Pecan');
    cy.visit('/cc-applications');

    cy.contains('td', firstName, { timeout: 10000 }).should('be.visible');
    cy.contains('tr', firstName)
      .first()
      .find('input[type="checkbox"]')
      .check();

    cy.get('select[name="action"]').first().select('Decline');
    cy.get('input[type="submit"][value="Apply to selected items"]').first().click();

    cy.get('body').then($body => {
      if ($body.find('input[type="submit"][value="Execute action"]').length > 0) {
        cy.get('input[type="submit"][value="Execute action"]').first().click();
      }
    });

    // Wait for action to complete
    cy.wait(1000);
  };

  describe('Decline Submission Status', () => {
    const declineEmail = `decline-status-${timestamp}@no-reply.com`;
    const declineUsername = `decline-status-${timestamp}`;

    it('should change submission status to declined', () => {
      // Submit form
      submitJoinForm({
        username: declineUsername,
        firstName: 'DeclineStatus',
        lastName: 'Test',
        email: declineEmail,
        type: 'champion'
      });

      // Decline the application
      declineApplication('DeclineStatus');

      // Verify the submission now shows as declined in the filtered view
      cy.visit('/cc-applications');
      cy.get('select[name="webform_submission_value"]').select('declined');
      cy.get('#edit-submit-cc-applications').click();

      cy.contains('DeclineStatus').should('be.visible');
    });
  });

  describe('Declined Users Should Not Get Campus Champion Status', () => {
    const noStatusEmail = `decline-nostatus-${timestamp}@no-reply.com`;
    const noStatusUsername = `decline-nostatus-${timestamp}`;

    it('should NOT set field_is_cc for declined users', () => {
      // Submit form
      submitJoinForm({
        username: noStatusUsername,
        firstName: 'DeclineNoStatus',
        lastName: 'Test',
        email: noStatusEmail,
        type: 'champion'
      });

      // Decline the application
      declineApplication('DeclineNoStatus');

      // Verify user does NOT have field_is_cc set
      // Note: User WILL have research_computing_facilitator role because
      // CreateUserHandler assigns it on form submission. The decline action
      // only changes the submission status, not the user's roles.
      cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
      cy.visit(`/admin/people?user=${encodeURIComponent(noStatusEmail)}`);
      cy.get('#edit-submit-user-admin-people').click();

      // Click edit from the operations dropdown
      cy.get('table tbody tr').first().find('li.edit a, a[href*="/edit"]').first().click({ force: true });

      // Wait for edit form to load
      cy.url().should('include', '/edit');

      // User has the basic role from form submission, but should NOT be marked as CC
      cy.get('#edit-roles-research-computing-facilitator').should('be.checked');

      // The key difference: field_is_cc should NOT be set for declined users
      cy.get('#edit-field-is-cc-value').should('not.be.checked');
    });
  });

  describe('Declined Submissions Filter', () => {
    const filterEmail = `decline-filter-${timestamp}@no-reply.com`;
    const filterUsername = `decline-filter-${timestamp}`;

    it('should remove declined submission from New status filter', () => {
      // Submit form
      submitJoinForm({
        username: filterUsername,
        firstName: 'DeclineFilter',
        lastName: 'Test',
        email: filterEmail,
        type: 'champion'
      });

      // Login and verify it shows in New filter
      cy.loginUser('pecan@pie.org', 'Pecan');
      cy.visit('/cc-applications');
      cy.get('select[name="webform_submission_value"]').select('new');
      cy.get('#edit-submit-cc-applications').click();
      cy.contains('DeclineFilter').should('be.visible');

      // Decline it
      cy.contains('tr', 'DeclineFilter')
        .first()
        .find('input[type="checkbox"]')
        .check();

      cy.get('select[name="action"]').first().select('Decline');
      cy.get('input[type="submit"][value="Apply to selected items"]').first().click();

      cy.get('body').then($body => {
        if ($body.find('input[type="submit"][value="Execute action"]').length > 0) {
          cy.get('input[type="submit"][value="Execute action"]').first().click();
        }
      });

      // Verify it no longer shows in New filter
      cy.visit('/cc-applications');
      cy.get('select[name="webform_submission_value"]').select('new');
      cy.get('#edit-submit-cc-applications').click();
      cy.contains('DeclineFilter').should('not.exist');

      // But it should show in Declined filter
      cy.get('select[name="webform_submission_value"]').select('declined');
      cy.get('#edit-submit-cc-applications').click();
      cy.contains('DeclineFilter').should('be.visible');
    });
  });
});
