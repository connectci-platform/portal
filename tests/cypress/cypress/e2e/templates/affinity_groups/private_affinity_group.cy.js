describe("Create private group and test visibility", () => {

  it("logged-in admin: Add a 'test-private-affinity-group'", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit('/node/add/affinity_group');
    cy.get('input[name="title[0][value]"]').type('test-private-affinity-group', { delay: 0 });
    cy.get('[data-tid="682"]').click(); // Login tag
    cy.get('input[name="field_group_slug[0][value]"]').type('test-private-affinity-group', { delay: 0 });
    cy.get('input[name="field_group_id[0][value]"]').type('test.private.group.id', { delay: 0 });
    cy.get('#edit-field-ag-private-value').check();
    cy.get('input[name="field_ag_private_users[0][target_id]"]').type('Pecan Pi', { delay: 300 });
    cy.wait(1000);
    cy.get('input[name="field_ag_private_users[0][target_id]"]').type('{downArrow}{enter}', { delay: 0 });
    cy.get('.node-affinity-group-form #edit-submit').click();
    cy.visit('/admin/content');
    cy.get('tbody > :nth-child(1) > .views-field-title > a').click();
    cy.contains('test-private-affinity-group');
  });

  it("Anon user does not see private group", () => {
    cy.visit('/affinity-groups');
    cy.get('input[name="search_api_fulltext"]').type('test', { delay: 50 });
    cy.contains('test-private-affinity-group').should('not.exist');
  });

  it("Pecan does see private group as group user", () => {
    cy.loginUser("pecan@pie.org", "Pecan");
    cy.visit('/affinity-groups');
    cy.get('input[name="search_api_fulltext"]').type('test', { delay: 50 });
    cy.contains('test-private-affinity-group');
  });

  it("logged-in admin: removes pecan from private group", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit('/admin/content');
    cy.get('tbody > :nth-child(1) > .views-field-title > a').click();
    cy.contains('test-private-affinity-group');
    cy.clickTopBarEdit();
    cy.get('input[name="field_ag_private_users[0][target_id]"]').clear();
    cy.get('.node-affinity-group-edit-form #edit-submit').click();
  });

  it("Pecan does not see private group once removed", () => {
    cy.visit('/affinity-groups');
    cy.get('input[name="search_api_fulltext"]').type('test', { delay: 50 });
    cy.contains('test-private-affinity-group').should('not.exist');
  });

});
