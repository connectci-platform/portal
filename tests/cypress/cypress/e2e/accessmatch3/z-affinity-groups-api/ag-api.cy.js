describe("Test Affinity Group API", () => {

  it("logged-in admin: Add a 'test-affinity-group-api' for ai tag and verify it appears", () => {
    cy.loginAs('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit('/node/add/affinity_group');
    cy.get('input[name="title[0][value]"]').type('test-affinity-group-api', { delay: 0 });
    cy.get('[data-tid="271"]').click(); // AI tag
    cy.get('input[data-drupal-selector="edit-field-resources-entity-reference-0-target-id"]').type('1', { delay: 0 });
    cy.get('input[name="field_group_slug[0][value]"]').type('test-affinity-group-api', { delay: 0 });
    cy.get('input[data-drupal-selector="edit-field-affinity-events-0-target-id"]').type('cypress-example-event{downArrow}{enter}', {delay: 0});
    cy.get('input[name="field_group_id[0][value]"]').type('test.group.api', { delay: 0 });
    
    
    cy.screenshot('before-submit');
    cy.get('#edit-submit').click();

    cy.wait(1000);
    cy.screenshot('after-submit');
    cy.visit('/affinity-groups/test-affinity-group-api');
    cy.contains('test-affinity-group-api');

    cy.get('.font-medium > .block').contains('cypress-example-event').click();
    cy.get('.tabs > :nth-child(2) > a').contains('Edit').click();
    cy.contains('Edit the series').click();
    cy.get('input[data-drupal-selector="edit-field-affinity-group-node-0-target-id"]').clear();
    cy.get('input[data-drupal-selector="edit-field-affinity-group-node-0-target-id"]').type('test-affinity-group-api{downArrow}{enter}', {delay: 0});
    cy.get('#edit-submit').click();

    cy.request('/api/1.1/affinity_groups/test.group.api')
      .then((response) => {
        const ag = response.body;
        cy.screenshot('ag-api-response');
        expect(ag[0].title).to.eq('test-affinity-group-api');
        expect(ag[0].field_group_id).to.eq('test.group.api');
      });

    cy.request('/api/1.1/events/ag/test.group.api')
      .then((response) => {
        const ag = response.body;
        expect(ag[0].title).to.eq('cypress-example-event');
      });

    cy.request('/api/1.0/kb/test.group.api')
      .then((response) => {
        const ag = response.body;
        expect(ag[0].title).to.eq('dummy link for testing knowledge base');
      });

  });

});
