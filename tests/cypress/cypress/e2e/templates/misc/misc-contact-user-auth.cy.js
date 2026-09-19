describe("As an authenticated visitor test the Contact Page", () => {
  it("Authenticated user tests the contact user page", () => {
    cy.loginUser("authenticated@amptesting.com", "6%l7iF}6(4tI");
    // Reach the profile through the faceted people page rather than
    // /people/list. That page places one exposed form per people_list_view
    // display and the old `.order-2` scope pointed at the duplicate
    // exposed-form block that has been removed, leaving the search input
    // ambiguous. /people carries a single exposed form and runs on AJAX.
    cy.visit('/people');
    cy.searchAndWait('[data-drupal-selector="edit-search-api-fulltext"]', 'Julie Ma');
    cy.contains('Julie Ma');
    cy.get('a[href="/community-persona/100"]').click();
    cy.contains('Julie').click();
    cy.contains('Julie Ma');
    cy.contains('Send Email').click();
    cy.url().should('include', '/user/100/contact');
    cy.contains('Contact Julie Ma');
    cy.get('a[data-drupal-selector="edit-name"]').contains('Julie Ma').click();
    cy.url().should('include', '/user/julie-ma');
    cy.visit('/user/100/contact');
    cy.get('input[name="subject[0][value]"]').type('Test');
    cy.get('textarea[name="message[0][value]"]').type('Test');
    cy.get('input#edit-copy').check();
    cy.contains('reCaptcha').should('not.exist');
    cy.get('input[name="op"]').click();
    cy.contains('Your message has been sent');
  });

});
