describe("Visitor tests 'Request a Group' page", () => {

  it("Authenticated user fills out affinity group form", () => {
    cy.loginUser("authenticated@amptesting.com", "6%l7iF}6(4tI");
    cy.visit('/affinity-groups');
    cy.contains('Request a Group').click();
    cy.url().should('include', 'form/affinity-group-request');
    cy.contains('Affinity Group Request');
    cy.contains('Affinity Group Name');
    cy.contains('Affinity Group Image');
    cy.contains('Coordinators');
    cy.contains('Type a few letters of the name and then select from the list of names presented');
    cy.contains('Tags');
    cy.contains('If you are an ACCESS RP, select one (or more) related CiDeR resources');
    cy.contains('Summary');
    cy.contains('Provide a short description that will appear on the Affinity Groups directory page.');
    cy.contains('Maximum 160 Characters Allowed');
    cy.contains('Description');
    cy.contains('Please include information about the intent of the group, what type of communications you expect the group will use, and how often.');
    cy.contains('Slack');
    cy.contains('Provide a link to an associated Slack group, if there is one');
    cy.contains('Q&A Platform');
    cy.contains('Provide a link to Ask.CI, StackExchange, or other Q&A platform specific to the Affinity Group.');
    cy.contains('Github Organization');
    cy.contains('Provide a link to Github if applicable.');
    cy.contains('Email List or Contact');
    cy.contains('Provide a full URL to your email list or email contact for the Affinity');
    cy.get('input[name="affinity_group_name"]').type('TEST', { delay: 0 });
    cy.get('.form-item-tags .select2-search__field').type('Login', { delay: 0 });
    cy.get('li.select2-results__option').click();
    cy.get('textarea[name="short_description"]').type('TEST', { delay: 0 });
    cy.get('textarea[name="project_description"]').type('TEST', { delay: 0 });
    cy.get('input[name="op"]').contains('Submit').click();
    cy.contains('Thank you for your submission');
  });

});
