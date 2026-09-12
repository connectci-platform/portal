/**
 * Join Campus Champions Form - duplicate account validation
 *
 * An anonymous applicant who enters the email or username of an existing
 * account is told to log in first, instead of the form creating a second
 * account. Enforced by the join form's validate handler (campuschampions
 * form_alter attaches it to #validate).
 *
 * These assert the validation wiring stays intact — the handler is referenced
 * by string name in #validate, so a rename that misses a reference silently
 * disables this check.
 *
 * Uses existing fixture accounts to collide against:
 * - walnut@pie.org / jerobert
 *
 * Runs anonymously (the check only fires for anonymous users).
 */
describe('Join Campus Champions Form - duplicate account validation', () => {
  const EXISTING_EMAIL = 'walnut@pie.org';
  const EXISTING_USERNAME = 'jerobert';

  // Fill all required fields (including the Letter of Collaboration upload, which
  // is #required) so submission reaches the server-side validate handler,
  // overriding email/username per test.
  const fillAndSubmit = ({ email, username }) => {
    cy.visit('/form/join-campus-champions');
    cy.get('#edit-letter-of-collaboration-upload')
      .selectFile('cypress/files/northern-lights.pdf');
    cy.contains('Remove', { timeout: 10000 });
    cy.get('input[name="username"]').clear().type(username);
    cy.get('input[name="user_first_name"]').type('Dup');
    cy.get('input[name="user_last_name"]').type('Check');
    cy.get('input[name="user_email"]').clear().type(email);
    cy.get('#edit-champion-user-type-user-champion').check();
    cy.get('input[name="supervisor_name"]').type('Sup Ervisor');
    cy.get('input[name="supervisor_email"]').type('supervisor@no-reply.com');
    // field_access_organization is #required; without it the browser blocks the
    // submit before the server-side duplicate check runs.
    cy.get('input[name="field_access_organization"]').type('Arkansas for Medical');
    cy.get('.ui-autocomplete .ui-menu-item', { timeout: 10000 })
      .contains('University of Arkansas for Medical Sciences').click();
    cy.get('#webform-submission-join-campus-champions-add-form > #edit-actions > #edit-submit')
      .contains('Submit').click();
  };

  it('blocks an existing email and tells the applicant to log in', () => {
    fillAndSubmit({ email: EXISTING_EMAIL, username: 'brand-new-username-xyz' });
    cy.contains('There is an account associated with this email address', { timeout: 30000 })
      .should('be.visible');
    // The form did not proceed to the confirmation page.
    cy.contains('Campus Champions Application Submitted').should('not.exist');
  });

  it('blocks an existing username and tells the applicant to log in', () => {
    fillAndSubmit({ email: 'brand-new-email-xyz@no-reply.com', username: EXISTING_USERNAME });
    cy.contains('There is an account associated with this username', { timeout: 30000 })
      .should('be.visible');
    cy.contains('Campus Champions Application Submitted').should('not.exist');
  });
});
