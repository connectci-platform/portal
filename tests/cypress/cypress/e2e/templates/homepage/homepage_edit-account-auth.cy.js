describe("For an authenticated user tests account, interest/skills pages", () => {

  it("Authenticated user tests the edit account page", () => {
    cy.loginUser("pecan@pie.org", "Pecan");
    cy.visit('/');
    cy.contains('My profile');
    cy.contains('Edit my account').click({ force: true });
    cy.contains('Pecan Pie');
    cy.contains('View');
    cy.contains('Edit');
    cy.contains('TFA');
    cy.contains('Program');
    cy.contains('Email address');
    cy.get('#edit-mail').should('have.value', 'pecan@pie.org');
    cy.contains('Password strength');
    cy.contains('Confirm password');
    cy.contains('First Name');
    cy.contains('Last Name');
    //cy.get('#edit-field-user-preferred-pronouns-0-value').should('have.value', 'they/them');

    cy.get('.field--name-field-user-bio .ck-content').should('contain', 'research computing facilitator');

    cy.contains('Picture');
    cy.get('#edit-field-access-organization-0-target-id').should('have.value', 'MGHPCC (4300)');
    cy.contains('CV');
    cy.contains('Time zone');
    cy.contains('Citizenships')
  });

  it("Authenticated user tests the add interest/skills page", () => {
    cy.loginUser("pecan@pie.org", "Pecan");
    cy.visit('/community-persona');
    cy.contains('ACCESS-account').should('not.exist');

    cy.visit('/community-persona/add-interest');
    cy.contains('Add Interest');
    cy.contains('ACCESS-account').click();
    cy.wait(500);
    cy.visit('/community-persona');
    cy.contains('ACCESS-account');

    cy.visit('/community-persona/add-interest');
    cy.contains('ACCESS-account').click();
    cy.wait(500);
    cy.visit('/community-persona');
    cy.contains('ACCESS-account').should('not.exist');

    cy.visit('/community-persona/add-skill');
    cy.contains('ACCESS-account').click();
    cy.wait(500);
    cy.visit('/community-persona');
    cy.contains('ACCESS-account');

    cy.visit('/community-persona/add-skill');
    cy.contains('ACCESS-account').click();
    cy.wait(500);
    cy.visit('/community-persona');
    cy.contains('ACCESS-account').should('not.exist');
  });

});
