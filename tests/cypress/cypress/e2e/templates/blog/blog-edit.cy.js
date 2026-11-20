describe("add a tag to a blog post ", () => {

  it("Add a 'test-blog-post' for 'login' tag and verify it appears", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit('/blog/mghpcc-co-leads-two-birds-feather-sessions-sc21');
    cy.clickTopBarEdit();
    cy.contains('Edit Blog post (region)');
    cy.get('select[name="field_tags[]"]').select('193');
    cy.get('input[name="op"]').contains('Save').click();
    cy.contains('has been updated');

    cy.visit('/blog');
    cy.contains('This year at SC21');
    cy.contains('cloud-commercial');
  });

});
