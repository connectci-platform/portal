describe("Unauthenticated user tests the CCI Hompage", () => {

  it("Verify the main logo goes to home page", () => {
    cy.visit('/');
    cy.url().should('include', '/');
    cy.get('.logo').click();
    cy.url().should('eq', Cypress.config().baseUrl + '/');
  });

  it("Verify the main page has expected content", () => {
    cy.visit('/'); // Adjust the URL as needed
    cy.contains('Connect. Learn. Advance.').should('be.visible');
    cy.get('a[href="https://support.access-ci.org/"]').should('contain', 'ACCESS Support');
    cy.get('a[href="https://careers-ct.cyberinfrastructure.org/"]').should('contain', 'CAREERS Cyberteam');
    cy.get('a[href="https://greatplains.cyberinfrastructure.org/"]').should('contain', 'Great Plains Cyberteam');
    cy.get('a[href="https://necyberteam.org/"]').should('contain', 'Northeast Cyberteam');
    cy.get('a[href="https://campuschampions.cyberinfrastructure.org/"]').should('contain', 'Campus Champions');
  });
  it("User is on the homepage and follows contact us", () => {
    cy.visit('/'); // Adjust the URL as needed
    cy.contains('Contact Us').click();
    cy.url().should('include', 'contact/connect_ci');
    cy.contains('Your name').should('be.visible');
  });
  it("Authenticated User is on the homepage and follows contact us", () => {
    cy.loginUser('authenticated@amptesting.com', '6%l7iF}6(4tI');
    cy.visit('/');
    cy.contains('Contact Us').click();
    cy.url().should('include', 'contact/connect_ci');
    cy.contains('Your name').should('be.visible');
  });

  it("Verify the main logo goes to home page", () => {
    cy.loginUser('authenticated@amptesting.com', '6%l7iF}6(4tI');
    cy.visit('/');
    cy.url().should('include', '/');
    cy.get('.logo').click();
    cy.url().should('eq', Cypress.config().baseUrl + '/');
  });

});
