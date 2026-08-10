describe("verify specific links on homepage", () => {
  it("User is on the homepage", () => {
    cy.visit('/');
    cy.contains('a', 'Connect.CI')
      .should('have.attr', 'href', 'https://connectci.org/');
  });

  it("Verify the main logo goes to home page", () => {
    cy.visit('/');
    cy.get('.brand > a').click();
    cy.url().should('eq', Cypress.config().baseUrl + '/');
  });

  it("authenticated User is on the homepage", () => {
    cy.loginUser('authenticated@amptesting.com', '6%l7iF}6(4tI');
    cy.visit('/');
    cy.contains('a', 'Connect.CI')
      .should('have.attr', 'href', 'https://connectci.org/');
  });

  it("authenticated Verify the main logo goes to home page", () => {
    cy.loginUser('authenticated@amptesting.com', '6%l7iF}6(4tI');
    cy.visit('/');
    cy.get('.brand > a').click();
    cy.url().should('eq', Cypress.config().baseUrl + '/');
  });

});
