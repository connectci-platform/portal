/*
    Test ACCESS Organization field on Campus Champions user edit form
*/
describe("ACCESS Organization field - Campus Champions", () => {
  // Test user edit form for authenticated Campus Champions users
  describe("User Edit Form", () => {
    it("Should have ACCESS Organization field on user edit form", () => {
      // Login as authenticated user
      cy.loginUser('authenticated@amptesting.com', '6%l7iF}6(4tI');
      
      // Visit user edit page
      cy.visit('/user/edit');
      
      // Check for Organization field
      cy.contains('Organization').should('exist');
      cy.get('#edit-field-access-organization-0-target-id')
        .should('exist')
        .should('be.visible')
        .should('have.attr', 'type', 'text');
    });

    it("Should reveal the Institution field when Other is selected on edit form", () => {
      // Log in as an administrator: the org field is read-only for a non-admin
      // who already has an organization (the D8-2789 change-institution guard),
      // so editing it to 'Other' to test the Institution reveal requires admin.
      cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
      cy.visit('/user/edit');

      // Institution is hidden until the org resolves to "Other" (node 3695) —
      // the #states condition is field_access_organization target_id == 3695.
      cy.get('input[name="field_institution[0][value]"]').should('not.be.visible');

      // Select the "Other" organization from the autocomplete so the field's
      // value is the real entity reference (3695), which drives the #states
      // reveal. Assert we actually picked it rather than accepting typed text.
      cy.get('#edit-field-access-organization-0-target-id').clear().type('Other');
      cy.get('.ui-autocomplete .ui-menu-item', { timeout: 10000 })
        .contains(/^Other/).click();
      cy.get('#edit-field-access-organization-0-target-id')
        .should('have.value', 'Other (3695)');

      // The Institution field must now be revealed and editable — a hard
      // assertion, not a conditional that passes when the field is absent.
      cy.get('input[name="field_institution[0][value]"]')
        .should('be.visible')
        .should('not.be.disabled')
        .clear()
        .type('Test Edit University Campus Champions')
        .should('have.value', 'Test Edit University Campus Champions');
    });

    it("Should keep the organization field read-only for a non-admin who has one", () => {
      // The D8-2789 change-institution guard makes field_access_organization
      // read-only/disabled on the campus champions domain for a non-admin who
      // already has an organization. authenticated_test_user has one (fixture),
      // so the field must be locked for them.
      cy.loginUser('authenticated@amptesting.com', '6%l7iF}6(4tI');
      cy.visit('/user/edit');
      cy.get('#edit-field-access-organization-0-target-id').should('exist');
      cy.get('#edit-field-access-organization-0-target-id').should('have.attr', 'readonly', 'readonly');
      cy.get('#edit-field-access-organization-0-target-id').should('be.disabled');
    });
  });
});