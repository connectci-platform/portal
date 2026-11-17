describe("for a new tag, verify all blocks display a no entries message", () => {

  it("Authenticated user fills out the tags form", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit('admin/structure/taxonomy/manage/tags/add');
    cy.get('input[name="name[0][value]"]').type('behat_test_tag');
    cy.contains('summary', 'URL alias').click();
    cy.get('input[name="path[0][pathauto]"]').check();
    cy.get('input[name="status[value]"]').check();
    cy.get('input#edit-submit').click();
    cy.contains('Created new term');
  });

  it("Unauthenticated user checks for created tag", () => {
    cy.visit('/tags/behattesttag');
    cy.contains('behat_test_tag');
    cy.contains('There are no Mentors and Regional Facilitators associated with this topic.');
    cy.contains('There are no Affinity Groups associated with this topic.');
    cy.contains('There are no Users associated with this topic.');
    cy.contains('There are no Resources associated with this topic.');
    cy.contains('There are no projects associated with this topic.');
    cy.contains('There are no Blog Entries associated with this topic.');
  });

});
