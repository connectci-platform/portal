/**
 * Campus Champions Approve Action Tests
 *
 * Tests the ApproveCCAction VBO action that:
 * - Sets submission status to 'approved'
 * - Assigns correct role (student_champion or research_computing_facilitator)
 * - Sets field_is_cc to 1
 * - Adds Campus Champions program to field_region
 *
 * Test user: pecan@pie.org / Pecan (campuschampionsadmin role)
 */
describe('Campus Champions Approve Action', () => {

  const timestamp = Date.now();

  /**
   * Helper to submit the join campus champions form
   * @param {Object} options - Form options
   * @param {string} options.username - Username
   * @param {string} options.firstName - First name
   * @param {string} options.lastName - Last name
   * @param {string} options.email - Email
   * @param {string} options.type - 'champion' or 'student'
   * @param {string} options.carnegieCode - Carnegie code (default: 166683)
   */
  const submitJoinForm = (options) => {
    const {
      username,
      firstName,
      lastName,
      email,
      type,
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
      // Champion type requires supervisor info
      cy.get('input[name="supervisor_name"]').type('Dr. Test Supervisor');
      cy.get('input[name="supervisor_email"]').type('supervisor@no-reply.com');
    } else if (type === 'student') {
      cy.get('#edit-champion-user-type-user-student').check();
      // Wait for student section to become visible
      cy.get('#edit-student-info', { timeout: 5000 }).should('be.visible');
      // Student type requires graduation year, degree type, and mentor info
      cy.get('input[name="graduation_year"]').type('2026');
      cy.get('#edit-degree-type-user-graduate').check();
      cy.get('input[name="study_field"]').type('Computer Science');
      // Mentor must be existing Campus Champion - use Pecan Pie who is a CC admin
      cy.get('input[name="mentor_name"]').type('Pecan');
      cy.get('.ui-autocomplete .ui-menu-item', { timeout: 5000 }).first().click();
      cy.get('input[name="mentor_email"]').type('pecan@pie.org');
    }

    // Select the ACCESS Organization (required). A real org derives the
    // Carnegie code server-side, so the Carnegie field stays hidden.
    cy.get('input[name="field_access_organization"]').type('Arkansas for Medical');
    cy.get('.ui-autocomplete .ui-menu-item', { timeout: 10000 })
      .contains('University of Arkansas for Medical Sciences').click();

    cy.get('#webform-submission-join-campus-champions-add-form > #edit-actions > #edit-submit')
      .contains('Submit').click();

    cy.contains('Campus Champions Application Submitted');
  };

  /**
   * Helper to approve an application by first name
   */
  const approveApplication = (firstName) => {
    cy.loginUser('pecan@pie.org', 'Pecan');
    cy.visit('/cc-applications');

    cy.contains('td', firstName, { timeout: 10000 }).should('be.visible');
    cy.contains('tr', firstName)
      .first()
      .find('input[type="checkbox"]')
      .check();

    cy.get('select[name="action"]').first().select('Approve');
    cy.get('input[type="submit"][value="Apply to selected items"]').first().click();

    cy.get('body').then($body => {
      if ($body.find('input[type="submit"][value="Execute action"]').length > 0) {
        cy.get('input[type="submit"][value="Execute action"]').first().click();
      }
    });

    cy.contains('Campus Champion', { timeout: 10000 });
  };

  /**
   * Helper to verify user has specific role and field_is_cc set
   */
  const verifyUserRoleAndFields = (email, expectedRole) => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    // Search for user by email
    cy.visit(`/admin/people?user=${encodeURIComponent(email)}`);
    cy.get('#edit-submit-user-admin-people').click();

    // Click edit link - find the edit link in the first row
    cy.get('table tbody tr').first().find('li.edit a, a[href*="/edit"]').first().click({ force: true });

    // Wait for edit form to load
    cy.url().should('include', '/edit');

    // Check expected role is assigned
    cy.get(`#edit-roles-${expectedRole.replace(/_/g, '-')}`).should('be.checked');

    // Check field_is_cc is set
    cy.get('#edit-field-is-cc-value').should('be.checked');
  };

  describe('Approve Champion User Type', () => {
    const championEmail = `approve-champ-${timestamp}@no-reply.com`;
    const championUsername = `approve-champ-${timestamp}`;

    it('should assign research_computing_facilitator role and set field_is_cc when approving champion type', () => {
      // Submit form as champion type
      submitJoinForm({
        username: championUsername,
        firstName: 'ChampApprove',
        lastName: 'Test',
        email: championEmail,
        type: 'champion'
      });

      // Approve the application
      approveApplication('ChampApprove');

      // Verify role and fields
      verifyUserRoleAndFields(championEmail, 'research_computing_facilitator');
    });
  });

  describe('Approve Student User Type', () => {
    const studentEmail = `approve-stud-${timestamp}@no-reply.com`;
    const studentUsername = `approve-stud-${timestamp}`;

    it('should assign student_champion role and set field_is_cc when approving student type', () => {
      // Submit form as student type
      submitJoinForm({
        username: studentUsername,
        firstName: 'StudApprove',
        lastName: 'Test',
        email: studentEmail,
        type: 'student'
      });

      // Approve the application
      approveApplication('StudApprove');

      // Verify role and fields
      verifyUserRoleAndFields(studentEmail, 'student_champion');
    });
  });

  describe('Submission Status Update', () => {
    const statusEmail = `approve-status-${timestamp}@no-reply.com`;
    const statusUsername = `approve-status-${timestamp}`;

    it('should change submission status to approved', () => {
      // Submit form
      submitJoinForm({
        username: statusUsername,
        firstName: 'StatusApprove',
        lastName: 'Test',
        email: statusEmail,
        type: 'champion'
      });

      // Approve the application
      approveApplication('StatusApprove');

      // Verify the submission now shows as approved in the filtered view
      cy.visit('/cc-applications');
      cy.get('select[name="webform_submission_value"]').select('approved');
      cy.get('#edit-submit-cc-applications').click();

      cy.contains('StatusApprove').should('be.visible');
    });
  });
});
