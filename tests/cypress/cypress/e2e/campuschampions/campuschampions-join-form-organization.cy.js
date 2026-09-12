/**
 * Join Campus Champions Form - ACCESS Organization field (D8-2789)
 *
 * The applicant selects their ACCESS Organization directly. Institution and
 * Carnegie fields are hidden until "Other" is chosen. In the Other flow the
 * institution name is a type-ahead that fills the Carnegie code on selection
 * and clears it on edit.
 *
 * Runs on the campus champions domain (CYPRESS_BASE_URL=campuschampions).
 */
describe('Join Campus Champions Form - ACCESS Organization', () => {

  const ORG_FIELD = 'input[name="field_access_organization"]';
  const CARNEGIE = 'input[name="carnegie_classification"]';
  const INSTITUTION = 'input[name="institution_name"]';
  // A real access_organization node used across the fixtures.
  const REAL_ORG = 'University of Arkansas for Medical Sciences';

  beforeEach(() => {
    cy.visit('/form/join-campus-champions');
  });

  describe('Field display', () => {
    it('shows the ACCESS Organization field and requires it', () => {
      cy.get(ORG_FIELD).should('exist').and('be.visible');
      cy.get(ORG_FIELD).should('have.attr', 'required');
    });

    it('hides institution and Carnegie fields by default', () => {
      cy.get(INSTITUTION).should('not.be.visible');
      cy.get(CARNEGIE).should('not.be.visible');
    });
  });

  describe('Prefill for a logged-in user with an organization', () => {
    it('prefills the organization field from the account', () => {
      // pecan@pie.org is a fixture account that has an organization set. Assert
      // the field is prefilled with an entity-reference value (Label (nid))
      // rather than a specific institution, since the fixture's org may change.
      cy.loginUser('pecan@pie.org', 'Pecan');
      cy.visit('/form/join-campus-champions');
      cy.get(ORG_FIELD).invoke('val').should('match', /\(\d+\)\s*$/);
    });
  });

  describe('Selecting a real organization', () => {
    it('keeps institution and Carnegie hidden', () => {
      cy.get(ORG_FIELD).type('Arkansas for Medical');
      cy.get('.ui-autocomplete .ui-menu-item', { timeout: 10000 })
        .contains(REAL_ORG).click();
      cy.get(ORG_FIELD).should('contain.value', 'Arkansas');
      cy.get(INSTITUTION).should('not.be.visible');
      cy.get(CARNEGIE).should('not.be.visible');
    });
  });

  describe('Selecting Other', () => {
    beforeEach(() => {
      cy.get(ORG_FIELD).type('Other');
      cy.get('.ui-autocomplete .ui-menu-item', { timeout: 10000 })
        .contains(/^Other/).click();
    });

    it('reveals institution and Carnegie fields', () => {
      cy.get(INSTITUTION).should('be.visible');
      cy.get(CARNEGIE).should('be.visible');
    });

    it('fills the Carnegie code from an institution type-ahead selection', () => {
      cy.get(INSTITUTION).type('Stanford Univ');
      cy.get('.ui-autocomplete .ui-menu-item', { timeout: 10000 })
        .contains('Stanford University').click();
      // Stanford's Carnegie unitid.
      cy.get(CARNEGIE).should('have.value', '243744');
    });

    it('clears a stale Carnegie code when the institution is edited', () => {
      cy.get(INSTITUTION).type('Stanford Univ');
      cy.get('.ui-autocomplete .ui-menu-item', { timeout: 10000 })
        .contains('Stanford University').click();
      cy.get(CARNEGIE).should('have.value', '243744');
      // Editing the institution invalidates the prior code.
      cy.get(INSTITUTION).clear().type('Some Company LLC');
      cy.get(CARNEGIE).should('have.value', '');
    });
  });
});
