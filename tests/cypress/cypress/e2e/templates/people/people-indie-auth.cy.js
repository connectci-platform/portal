describe("Tested as an authenticated user the Individual Profile Page showcases", () => {

  it("Authenticated user tests the individual people page", () => {
    cy.loginUser("authenticated@amptesting.com", "6%l7iF}6(4tI");
    cy.visit('/people');
    cy.searchAndWait('[data-drupal-selector="edit-search-api-fulltext"]', 'julie');
    cy.contains('Julie Ma')
    cy.get('a[href="/community-persona/100"]').click();
    cy.contains('Julie Ma')

    cy.contains('Julie').click();
    cy.contains('Julie Ma');
    cy.contains('Massachusetts Green High Performance Computing Center');
    cy.contains('Skills');
    cy.contains('Affinity Groups');
    cy.contains('Contact');
    cy.contains('Interest');
    cy.contains('hardware');
    cy.contains('affinity-group');
    cy.contains('Send Email').click();
    cy.url().should('include', '/user/100/contact')
  });

});
