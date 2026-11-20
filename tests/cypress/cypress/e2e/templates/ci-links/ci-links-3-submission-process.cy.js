describe("test KB Resource submission page as an administrator user", () => {

  it("Administrator user Test KB Resource submission page", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit('/admin/structure/webform/manage/resource/results/submissions');
    cy.contains('Test CI Link Title 2');
    cy.contains('#');
    cy.contains('Title');
    cy.contains('Approved');
    cy.contains('Category');
    cy.contains('User');
    cy.contains('Created');
    cy.contains('Changed');
    cy.contains('Link to Resource');
    cy.contains('Operations');
    cy.get('table.webform-results-table > tbody > tr:nth-child(1) > :nth-child(2) > a').click();
    cy.clickTopBarEdit();
    cy.get('#edit-approved').check();
    cy.get('.form-item--domain').find('.select2-search__field').type('Careers{enter}');
    cy.get('input[name="op"]').contains('Save').click();
    cy.contains('Submission updated in Knowledge Base Resources.');
  });

});
