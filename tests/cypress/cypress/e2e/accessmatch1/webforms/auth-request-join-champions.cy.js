//
// Authenticated user requests to join campus champions
//
describe('Authenticated user requests to join campus champions', () => {
  it('should complete successfully', () => {
    cy.loginAs('authenticated@amptesting.com', '6%l7iF}6(4tI').then(() => {
      cy.visit('/form/join-campus-champions');
      // Name fields should be hidden if authenticated already.
      // cy.get("#edit-user-first-name").type("authenticated", { force: true });
      // cy.get("#edit-user-last-name").type("testuser", { force: true });
      cy.get('#edit-letter-of-collaboration-upload').click();
      cy.get('#edit-letter-of-collaboration-upload').selectFile('cypress/fixtures/dummy-file.txt');
      cy.get('#edit-champion-user-type-user-student').check();
      cy.get('#edit-graduation-year').type('2030');
      cy.get('#edit-degree-type-user-undergraduate').check();
      cy.get('#edit-study-field').type('math');
      cy.get('#edit-mentor-name').type('Pecan Pie (201)');
      cy.get('#edit-mentor-email').type('pecan@pie.com');
      // Select an ACCESS Organization (now required). The test fixture user has
      // no organization on their account, so select one explicitly rather than
      // relying on the logged-in prefill. Choosing a real org keeps the Carnegie
      // and Institution fields hidden (they only show for "Other"), which is why
      // the old carnegie_classification entry is gone.
      cy.get('input[name="field_access_organization"]').clear().type('Arkansas for Medical');
      cy.get('.ui-autocomplete .ui-menu-item', { timeout: 10000 })
        .contains('University of Arkansas for Medical Sciences').click();
      cy.get('#edit-submit').click();
      cy.contains('Your application to the Campus Champions program was successfully submitted. You should hear from us soon. Thank you!')
    });
  });
});
