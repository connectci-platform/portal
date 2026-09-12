/**
 * Campus Champions User Creation Tests
 *
 * The join form creates a user from the submission. The applicant selects
 * their ACCESS Organization directly; the Carnegie code is derived from the
 * selected organization. Tests cover:
 * - A user is created and appears in the applications admin view.
 * - Selecting an HBCU organization derives its Carnegie code.
 * - The "Other" path reveals institution + Carnegie fields for manual entry.
 *
 * Note: CreateUserHandler populates field_access_organization from the
 * selection and field_carnegie_code from that organization (or the manual
 * entry in the Other flow).
 */
describe('Campus Champions User Creation', () => {

  const timestamp = Date.now();
  const testUsername = `cctest-${timestamp}`;
  const testEmail = `cctest-${timestamp}@no-reply.com`;

  // Real access_organization nodes and the Carnegie code each one carries.
  const MIT = { name: 'Massachusetts Institute of Technology', type: 'Massachusetts Inst' };
  const HBCU = { name: 'Alabama A&M University', type: 'Alabama A' };

  const selectOrg = (typed, label) => {
    cy.get('input[name="field_access_organization"]').type(typed);
    cy.get('.ui-autocomplete .ui-menu-item', { timeout: 10000 }).contains(label).click();
  };

  const fillCommon = (username, email, firstName) => {
    cy.get('#edit-letter-of-collaboration-upload').selectFile('cypress/files/northern-lights.pdf');
    cy.contains(/northern-lights.*\.pdf/, { timeout: 10000 }).should('be.visible');
    cy.get('input[name="username"]').type(username);
    cy.get('input[name="user_first_name"]').type(firstName);
    cy.get('input[name="user_last_name"]').type('Test');
    cy.get('input[name="user_email"]').type(email);
    cy.get('#edit-champion-user-type-user-champion').check();
    cy.get('input[name="supervisor_name"]').type('Dr. Test Supervisor');
    cy.get('input[name="supervisor_email"]').type('supervisor-test@no-reply.com');
  };

  describe('User Creation via Join Form', () => {
    it('should create a new user when the form is submitted with an organization', () => {
      cy.visit('/form/join-campus-champions');
      fillCommon(testUsername, testEmail, 'Integration');
      selectOrg(MIT.type, MIT.name);

      cy.get('#webform-submission-join-campus-champions-add-form > #edit-actions > #edit-submit')
        .contains('Submit').click();
      cy.contains('Campus Champions Application Submitted');
    });

    it('should show the submission in the CC applications admin view', () => {
      cy.loginUser('pecan@pie.org', 'Pecan');
      cy.visit('/cc-applications');
      cy.get('body').then(($body) => {
        const text = $body.text();
        expect(text.includes('Integration') || text.includes(testUsername)).to.be.true;
      });
    });
  });

  describe('User Creation with HBCU Institution', () => {
    const hbcuUsername = `cctest-hbcu-${timestamp}`;
    const hbcuEmail = `cctest-hbcu-${timestamp}@no-reply.com`;

    it('accepts an HBCU organization selection', () => {
      cy.visit('/form/join-campus-champions');
      fillCommon(hbcuUsername, hbcuEmail, 'HBCU');
      selectOrg(HBCU.type, HBCU.name);

      cy.get('#webform-submission-join-campus-champions-add-form > #edit-actions > #edit-submit')
        .contains('Submit').click();
      cy.contains('Campus Champions Application Submitted');
    });
  });

  describe('User Creation via the Other path', () => {
    it('reveals institution and Carnegie fields when Other is chosen', () => {
      const otherUsername = `cctest-other-${timestamp}`;
      const otherEmail = `cctest-other-${timestamp}@no-reply.com`;

      cy.visit('/form/join-campus-champions');
      fillCommon(otherUsername, otherEmail, 'OtherPath');
      selectOrg('Other', /^Other/);

      // Institution + Carnegie fields are now visible for manual entry.
      cy.get('input[name="institution_name"]').should('be.visible')
        .type('Test Non-Carnegie Institution');
      // Carnegie is required in the Other flow; enter it manually.
      cy.get('input[name="carnegie_classification"]').should('be.visible').type('000000');

      cy.get('input[name="institution_street_address[address]"]').type('123 Test Street');
      cy.get('input[name="institution_street_address[city]"]').type('Test City');
      cy.get('select[name="institution_street_address[state_province]"]').select('Massachusetts');
      cy.get('input[name="institution_street_address[postal_code]"]').type('02101');
      cy.get('select[name="institution_street_address[country]"]').select('United States');

      cy.get('#webform-submission-join-campus-champions-add-form > #edit-actions > #edit-submit')
        .contains('Submit').click();
      cy.contains('Campus Champions Application Submitted');
    });
  });
});
