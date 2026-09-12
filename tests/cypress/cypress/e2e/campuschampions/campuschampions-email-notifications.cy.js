/**
 * Campus Champions Email Notification Tests
 *
 * Tests that email notifications are sent correctly when:
 * 1. A user submits the Join Campus Champions form (welcome email)
 * 2. A CC admin approves an application (approval email)
 *
 * Uses Mailpit for email testing via custom Cypress commands.
 * See cypress/support/mailpit-commands.js for implementation.
 */
describe('Campus Champions Email Notifications', () => {

  const timestamp = Date.now();
  const testEmail = `email-test-${timestamp}@no-reply.com`;
  const testUsername = `email-test-${timestamp}`;

  beforeEach(() => {
    // Clear mailpit before each test to ensure clean state
    cy.clearMailpit();
  });

  describe('Join Form Welcome Email', () => {
    it('should send welcome email when join form is submitted', () => {
      cy.visit('/form/join-campus-champions');

      // Fill out the form
      cy.get('#edit-letter-of-collaboration-upload')
        .selectFile('cypress/files/northern-lights.pdf');

      cy.contains(/northern-lights.*\.pdf/, { timeout: 10000 }).should('be.visible');

      cy.get('input[name="username"]').type(testUsername);
      cy.get('input[name="user_first_name"]').type('EmailTest');
      cy.get('input[name="user_last_name"]').type('User');
      cy.get('input[name="user_email"]').type(testEmail);
      cy.get('#edit-champion-user-type-user-champion').check();
      cy.get('input[name="supervisor_name"]').type('Dr. Email Supervisor');
      cy.get('input[name="supervisor_email"]').type('email-supervisor@no-reply.com');
      cy.get('input[name="field_access_organization"]').type('Arkansas for Medical');
      cy.get('.ui-autocomplete .ui-menu-item', { timeout: 10000 })
        .contains('University of Arkansas for Medical Sciences').click();

      // Submit the form
      cy.get('#webform-submission-join-campus-champions-add-form > #edit-actions > #edit-submit')
        .contains('Submit').click();

      // Verify submission success
      cy.contains('Campus Champions Application Submitted');

      // Wait for and verify the welcome email
      cy.waitForEmail({
        to: testEmail,
        subject: 'Welcome to the Campus Champions Portal!'
      }).then((message) => {
        cy.assertEmailContent(message, {
          subject: 'Welcome to the Campus Champions Portal!',
          to: testEmail,
          htmlContains: [
            'Campus Champions',
            'Affinity Groups',
            'Champions Portal'
          ]
        });
      });
    });
  });

  describe('Approval Email', () => {
    const approvalTestEmail = `approval-test-${timestamp}@no-reply.com`;
    const approvalTestUsername = `approval-test-${timestamp}`;

    it('should send approval email when CC admin approves application', () => {
      // First create an application
      cy.visit('/form/join-campus-champions');

      cy.get('#edit-letter-of-collaboration-upload')
        .selectFile('cypress/files/northern-lights.pdf');

      cy.contains(/northern-lights.*\.pdf/, { timeout: 10000 }).should('be.visible');

      cy.get('input[name="username"]').type(approvalTestUsername);
      cy.get('input[name="user_first_name"]').type('ApprovalTest');
      cy.get('input[name="user_last_name"]').type('User');
      cy.get('input[name="user_email"]').type(approvalTestEmail);
      cy.get('#edit-champion-user-type-user-champion').check();
      cy.get('input[name="supervisor_name"]').type('Dr. Approval Supervisor');
      cy.get('input[name="supervisor_email"]').type('approval-supervisor@no-reply.com');
      cy.get('input[name="field_access_organization"]').type('Arkansas for Medical');
      cy.get('.ui-autocomplete .ui-menu-item', { timeout: 10000 })
        .contains('University of Arkansas for Medical Sciences').click();

      cy.get('#webform-submission-join-campus-champions-add-form > #edit-actions > #edit-submit')
        .contains('Submit').click();

      cy.contains('Campus Champions Application Submitted');

      // Clear mailpit before approval to isolate the approval email
      cy.clearMailpit();

      // Login as CC admin and approve the application
      cy.loginUser('pecan@pie.org', 'Pecan');
      cy.visit('/cc-applications');

      // View is now sorted by newest first, so our submission should be at the top
      cy.contains('td', 'ApprovalTest', { timeout: 10000 }).should('be.visible');

      // Select the checkbox for this application (use first() in case there are duplicates from previous runs)
      cy.contains('tr', 'ApprovalTest')
        .first()
        .find('input[type="checkbox"]')
        .check();

      // Select Approve action and apply (use first() as VBO may have multiple buttons)
      cy.get('select[name="action"]').first().select('Approve');
      cy.get('input[type="submit"][value="Apply to selected items"]').first().click();

      // Confirm if needed
      cy.get('body').then($body => {
        if ($body.find('input[type="submit"][value="Execute action"]').length > 0) {
          cy.get('input[type="submit"][value="Execute action"]').first().click();
        }
      });

      // Wait for and verify the approval email
      cy.waitForEmail({
        to: approvalTestEmail,
        subject: 'Campus Champions'
      }, 15000).then((message) => {
        cy.assertEmailContent(message, {
          to: approvalTestEmail,
          htmlContains: 'Campus Champion'
        });
      });
    });
  });

  describe('Email Content Structure', () => {
    it('should include account setup link in welcome email', () => {
      const linkTestEmail = `link-test-${timestamp}@no-reply.com`;
      const linkTestUsername = `link-test-${timestamp}`;

      cy.visit('/form/join-campus-champions');

      cy.get('#edit-letter-of-collaboration-upload')
        .selectFile('cypress/files/northern-lights.pdf');

      cy.contains(/northern-lights.*\.pdf/, { timeout: 10000 }).should('be.visible');

      cy.get('input[name="username"]').type(linkTestUsername);
      cy.get('input[name="user_first_name"]').type('LinkTest');
      cy.get('input[name="user_last_name"]').type('User');
      cy.get('input[name="user_email"]').type(linkTestEmail);
      cy.get('#edit-champion-user-type-user-champion').check();
      cy.get('input[name="supervisor_name"]').type('Dr. Link Supervisor');
      cy.get('input[name="supervisor_email"]').type('link-supervisor@no-reply.com');
      cy.get('input[name="field_access_organization"]').type('Arkansas for Medical');
      cy.get('.ui-autocomplete .ui-menu-item', { timeout: 10000 })
        .contains('University of Arkansas for Medical Sciences').click();

      cy.get('#webform-submission-join-campus-champions-add-form > #edit-actions > #edit-submit')
        .contains('Submit').click();

      cy.contains('Campus Champions Application Submitted');

      // Verify the email contains the password reset link
      cy.waitForEmail({
        to: linkTestEmail,
        subject: 'Welcome to the Campus Champions Portal!'
      }).then((message) => {
        cy.getMailpitMessage(message.ID).then((fullMessage) => {
          const html = fullMessage.HTML || '';
          // Email should contain a one-time login link
          expect(html).to.match(/user\/reset|pass|login/i);
        });
      });
    });
  });
});
