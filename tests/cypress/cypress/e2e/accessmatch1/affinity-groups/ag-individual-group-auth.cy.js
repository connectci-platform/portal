/**
 * Authenticated user tests the Individual Affinity Groups page.
 *
 * Only addition from anonymous is ability to see & email AG members (if
 * the user is a coordinator).
 */
describe("Authenticated user tests the Individual Affinity Groups", () => {
  it("Should test the Individual Affinity Groups page", () => {
    add_pecan_as_ag_coord();
    confirm_email_view_members();
  });
});

// Having weird logout issues, so splitting out.
describe("Authenticated user tests the Individual Affinity Groups", () => {
  it("Should test the Individual Affinity Groups page", () => {
    remove_pecan_as_ag_coord();
    confirm_no_email_view_members();
  });
});

// login as admin, add Pecan Pie as a coordinator
function add_pecan_as_ag_coord() {
  cy.loginAs('administrator@amptesting.com', 'b8QW]X9h7#5n');
  cy.visit("/affinity-groups/access-support");

  // click the Edit button in the Navigation top bar
  cy.getTopBarEdit()
    .should('have.attr', 'href', '/node/327/edit')
    .click();

  // add Pecan Pie as a coordinator
  cy.get('#edit-field-coordinator-1-target-id').clear();
  cy.get('#edit-field-coordinator-1-target-id')
    .type('Pecan Pie')
    .get('.ui-autocomplete')  // this is the dropdown that shows up
    .find('.ui-menu-item')
    .first() // take the first one
    .click();

  cy.get('#edit-field-group-id-0-value').clear().type('test.group.id');


  // submit changes
  cy.get('#edit-submit').click();
  cy.contains('has been updated');
  cy.get('.block-field-blocknodeaffinity-groupfield-coordinator')
    .contains('Pecan Pie');
}

// login as Pecan Pie, who is now a coordinator, and verify the email & view members buttons appear.
function confirm_email_view_members() {
  cy.loginAs('pecan@pie.org', 'Pecan');
  cy.visit("/affinity-groups/access-support");

  cy.contains("Email Affinity Group")
    .should('have.attr', 'href', '/form/affinity-group-contact?nid=327');

  cy.contains("View Members")
    .should('have.attr', 'href', '/affinity-groups/618/users/ACCESS Support?nid=327');
}

// login as admin, remove Pecan Pie as a coordinator
function remove_pecan_as_ag_coord() {
  cy.loginAs('administrator@amptesting.com', 'b8QW]X9h7#5n');
  cy.visit("/affinity-groups/access-support");

  // click the Edit button in the Navigation top bar
  cy.getTopBarEdit()
    .should('have.attr', 'href', '/node/327/edit')
    .click();

  // remove Pecan Pie as a coordinator
  cy.get('#edit-field-coordinator-1-target-id')
    .clear();

  // Set the slug to something unique
  cy.get('#edit-field-group-slug-0-value').clear().type('access-support');

    // submit changes
  cy.get('#edit-submit').click();
  cy.contains('has been updated');
  cy.get('.block-field-blocknodeaffinity-groupfield-coordinator').should('not.include.text', 'Pecan')
}

// login as Pecan Pie, who is now not a coordinator, and verify the email & view
// members buttons do not appear.
function confirm_no_email_view_members() {
  cy.loginAs('pecan@pie.org', 'Pecan');
  cy.visit("/affinity-groups/access-support");

  cy.contains("Email Affinity Group")
    .should('not.exist');

  cy.contains("View Members")
    .should('not.exist');
}
