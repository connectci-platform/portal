describe("Test registration page for becoming a campus champion", { retries: { runMode: 2, openMode: 0 } }, () => {
  const testUsername = 'cypress-cc-join-test';
  const testEmail = 'cypress-cc-join-test@no-reply.com';

  // Clean up any existing test user before running tests
  before(() => {
    cy.exec(`ddev drush user:cancel --delete-content -y ${testUsername}`, { failOnNonZeroExit: false });
  });

  // Clean up after tests complete
  after(() => {
    cy.exec(`ddev drush user:cancel --delete-content -y ${testUsername}`, { failOnNonZeroExit: false });
  });

  it("test the process of submitting a form to become a student campus champion", () => {
    cy.visit('/form/join-campus-champions');
    cy.contains('Join Campus Champions');
    cy.contains('To join please submit a collaboration letter from your institution using this template');
    cy.contains('a', 'using this template')
      .should('have.attr', 'href', 'https://drive.google.com/drive/folders/1-nrz2itseKhwQdOrFAOj71mSIw5WQ68y')
      .and('have.attr', 'target', '_blank')
      .and('have.attr', 'rel').and('include', 'noopener');
    cy.contains('a', 'using this template').within(() => {
      cy.get('i.bi-box-arrow-up-right').should('have.attr', 'aria-hidden', 'true');
      cy.get('.visually-hidden').should('have.text', '(opens in a new tab)');
    });
    cy.contains('Letter of Collaboration');

    // Upload the file and wait for it to be processed
    cy.get('#edit-letter-of-collaboration-upload')
      .selectFile('cypress/files/northern-lights.pdf');

    // Wait for file upload to complete - look for the Remove button to appear
    cy.contains('Remove', { timeout: 10000 });

    cy.get('input[name="username"]').type(testUsername);
    cy.get('input[name="user_first_name"]').type('Cypress');
    cy.get('input[name="user_last_name"]').type('CCJoinTest');
    cy.get('input[name="user_email"]').type(testEmail);
    cy.get('#edit-champion-user-type-user-champion').check();
    cy.get('input[name="supervisor_name"]').type('Bob');
    cy.get('input[name="supervisor_email"]').type('bob@no-reply.com');
    cy.get('input[name="field_access_organization"]').type('Arkansas for Medical');
    cy.get('.ui-autocomplete .ui-menu-item', { timeout: 10000 })
      .contains('University of Arkansas for Medical Sciences').click();
    cy.get('#webform-submission-join-campus-champions-add-form > #edit-actions > #edit-submit').contains('Submit').click();
    cy.contains('Campus Champions Application Submitted', { timeout: 30000 });
  });
});
