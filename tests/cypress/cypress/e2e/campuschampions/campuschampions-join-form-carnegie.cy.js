/**
 * Join Campus Champions Form - Institution / Carnegie type-ahead
 *
 * With the ACCESS Organization field as the primary input, the Carnegie code
 * is derived from the selected organization. The manual institution + Carnegie
 * entry only appears in the "Other" flow, where the institution name is a
 * type-ahead against the Carnegie institution list that fills the code on
 * selection. These tests cover that type-ahead behavior in its current home.
 *
 * MSI/HBCU/HSI classification logic is covered by
 * campuschampions-msi-classification.cy.js and
 * campuschampions-carnegie-validation.cy.js. The org-field reveal itself is
 * covered by campuschampions-join-form-organization.cy.js.
 */
describe('Join Campus Champions Form - Institution type-ahead (Other flow)', () => {

  const chooseOther = () => {
    cy.get('input[name="field_access_organization"]').type('Other');
    cy.get('.ui-autocomplete .ui-menu-item', { timeout: 10000 }).contains(/^Other/).click();
  };

  beforeEach(() => {
    cy.visit('/form/join-campus-champions');
    chooseOther();
  });

  it('shows institution suggestions when typing an institution name', () => {
    cy.get('input[name="institution_name"]').type('Alabama A');
    cy.get('.ui-autocomplete', { timeout: 10000 }).should('be.visible');
    cy.get('.ui-autocomplete .ui-menu-item').should('have.length.at.least', 1);
  });

  it('fills the Carnegie code when an institution is selected', () => {
    cy.get('input[name="institution_name"]').type('Massachusetts Inst');
    cy.get('.ui-autocomplete .ui-menu-item', { timeout: 10000 })
      .contains('Massachusetts Institute of Technology').click();
    cy.get('input[name="carnegie_classification"]').should('have.value', '166683');
  });

  it('finds HBCU institutions in the type-ahead', () => {
    cy.get('input[name="institution_name"]').type('Howard Univ');
    cy.get('.ui-autocomplete', { timeout: 10000 }).should('be.visible');
    cy.contains('.ui-autocomplete .ui-menu-item', 'Howard University').should('be.visible');
  });

  it('finds HSI institutions in the type-ahead', () => {
    cy.get('input[name="institution_name"]').type('University of Texas at El Paso');
    cy.get('.ui-autocomplete', { timeout: 10000 }).should('be.visible');
    cy.contains('.ui-autocomplete .ui-menu-item', 'El Paso').should('be.visible');
  });

  it('allows a free-text institution with no Carnegie match', () => {
    // Institutions not in the Carnegie list (international, companies) are
    // still accepted as free text; the Carnegie code simply stays blank.
    cy.get('input[name="institution_name"]').type('Some Company LLC That Is Not Listed');
    cy.get('input[name="carnegie_classification"]').should('have.value', '');
  });
});
