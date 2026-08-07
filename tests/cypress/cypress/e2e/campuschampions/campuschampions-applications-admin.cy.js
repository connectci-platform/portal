/**
 * Campus Champions Applications Admin Tests
 *
 * Tests the /cc-applications admin view that allows Campus Champions admins
 * to review, approve, and decline Campus Champion applications.
 *
 * The view includes VBO (Views Bulk Operations) actions:
 * - Approve Campus Champion (campuschampions_approve_cc_action)
 * - Decline Campus Champion (campuschampions_approve_cc_decline)
 *
 * Access requires: administrator OR campuschampionsadmin role
 *
 * Test users:
 * - pecan@pie.org / Pecan - has campuschampionsadmin role (not administrator)
 * - administrator@amptesting.com - has administrator role
 * - authenticated@amptesting.com - regular authenticated user (no special roles)
 */
describe('Campus Champions Applications Admin', () => {
  const testUsername = 'cypress-cc-admin-test';
  const testEmail = 'cypress-cc-admin-test@no-reply.com';

  // Create a test application by submitting the join form
  before(() => {
    // Clean up any existing test user/submission
    cy.exec(`ddev drush user:cancel --delete-content -y ${testUsername}`, { failOnNonZeroExit: false });

    // Submit the join form to create an application
    cy.visit('/form/join-campus-champions');
    cy.get('#edit-letter-of-collaboration-upload')
      .selectFile('cypress/files/northern-lights.pdf');
    cy.contains('Remove', { timeout: 10000 });
    cy.get('input[name="username"]').type(testUsername);
    cy.get('input[name="user_first_name"]').type('CypressAdmin');
    cy.get('input[name="user_last_name"]').type('Test');
    cy.get('input[name="user_email"]').type(testEmail);
    cy.get('#edit-champion-user-type-user-champion').check();
    cy.get('input[name="supervisor_name"]').type('Test Supervisor');
    cy.get('input[name="supervisor_email"]').type('supervisor@test.com');
    cy.get('input[name="field_access_organization"]').type('Arkansas for Medical');
    cy.get('.ui-autocomplete .ui-menu-item', { timeout: 10000 })
      .contains('University of Arkansas for Medical Sciences').click();
    cy.get('#webform-submission-join-campus-champions-add-form > #edit-actions > #edit-submit').click();
    cy.contains('Campus Champions Application Submitted', { timeout: 30000 });
  });

  // Clean up after all tests
  after(() => {
    cy.exec(`ddev drush user:cancel --delete-content -y ${testUsername}`, { failOnNonZeroExit: false });
  });

  describe('Access Control', () => {
    it('should deny access to anonymous users', () => {
      cy.visit('/cc-applications', { failOnStatusCode: false });
      // Should redirect to login or show access denied
      cy.url().should('include', '/user/login');
    });

    it('should deny access to regular authenticated users', () => {
      cy.loginUser('authenticated@amptesting.com', '6%l7iF}6(4tI');
      cy.visit('/cc-applications', { failOnStatusCode: false });
      // Should show access denied
      cy.get('body').then($body => {
        const text = $body.text();
        const isDenied = text.includes('Access denied') ||
                        text.includes('not authorized') ||
                        text.includes('403');
        expect(isDenied).to.be.true;
      });
    });

    it('should allow access to users with campuschampionsadmin role', () => {
      // Pecan Pie has campuschampionsadmin role but NOT administrator
      cy.loginUser('pecan@pie.org', 'Pecan');
      cy.visit('/cc-applications');
      cy.contains('Campus Champion');
    });

    it('should allow access to administrators', () => {
      cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
      cy.visit('/cc-applications');
      cy.contains('Campus Champion');
    });
  });

  describe('Applications View Display', () => {
    beforeEach(() => {
      cy.loginUser('pecan@pie.org', 'Pecan');
      cy.visit('/cc-applications');
    });

    it('should display the applications view', () => {
      // The page should show Campus Champion Applications title
      cy.contains('Campus Champion Applications').should('exist');
    });

    it('should display VBO action dropdown', () => {
      // The VBO dropdown should be present
      cy.get('select[name="action"]').should('exist');
    });

    it('should have Approve and Decline actions available', () => {
      cy.get('select[name="action"]').then($select => {
        const options = $select.find('option').map((i, el) => el.text).get();
        const hasApprove = options.some(opt => opt.toLowerCase().includes('approve'));
        const hasDecline = options.some(opt => opt.toLowerCase().includes('decline'));
        expect(hasApprove).to.be.true;
        expect(hasDecline).to.be.true;
      });
    });

    it('should display application details columns', () => {
      // Check for expected column headers
      cy.get('body').then($body => {
        const text = $body.text().toLowerCase();
        // Should have columns for name, email, institution, status, etc.
        const hasNameColumn = text.includes('first name') || text.includes('last name') || text.includes('name');
        expect(hasNameColumn).to.be.true;
      });
    });
  });

  describe('VBO Actions UI', () => {
    beforeEach(() => {
      cy.loginUser('pecan@pie.org', 'Pecan');
      cy.visit('/cc-applications');
    });

    it('should have checkbox selection for bulk operations', () => {
      // Should have checkboxes for selecting submissions
      cy.get('input[type="checkbox"]').should('exist');
    });

    it('should have select all checkbox', () => {
      // Should have a "select all" checkbox in the header
      cy.get('.vbo-select-all, th input[type="checkbox"], .select-all').should('exist');
    });

    it('should have apply action button', () => {
      // Should have a button to apply the selected action
      cy.get('input[type="submit"], button[type="submit"]')
        .filter(':visible')
        .should('exist');
    });
  });

  describe('Applications Filtering', () => {
    beforeEach(() => {
      cy.loginUser('pecan@pie.org', 'Pecan');
      cy.visit('/cc-applications');
    });

    it('should have exposed filters', () => {
      // The view should have exposed filters for status
      cy.get('form.views-exposed-form, .views-exposed-form').should('exist');
    });
  });
});
