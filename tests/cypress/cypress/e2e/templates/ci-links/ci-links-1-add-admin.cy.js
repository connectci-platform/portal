describe("test KB Resources form", () => {

  it("Administrator user fills out the KB Resources form", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit('/admin/config/search/search-api/index/ci_links');
    cy.get('#edit-clear').click();
    cy.get('#edit-submit').click();

    cy.visit('/knowledge-base/resources');
    cy.contains('Add a Resource').click();
    cy.url().should('include', '/form/resource');
    cy.contains('Title');
    cy.contains('Category');
    cy.contains('Tags');
    cy.contains('Skill Level');
    cy.contains('Description');
    cy.contains('Link to Resource');
    cy.contains('Link Title');
    cy.contains('Link URL');
    cy.contains('Add');
    cy.get('input[name="title"]').type('Test CI Link Title');
    cy.get('select[name="category"]').select('learning');
    cy.get('input[name="approved"]').check();
    cy.get('[data-tid="682"]').contains('login').click();
    cy.get('input[name="skill_level[304]"]').check();
    cy.get('.form-item-description-html-value .ck-content').then(el => {
      const editor = el[0].ckeditorInstance
      editor.setData('Test')
    });
    cy.get('input[name="link_to_resource[items][0][title]"]').type('Test');
    cy.get('input[name="link_to_resource[items][0][url]"]').type('http://example.com');
    cy.get('.form-item-domain').find('.select2-search__field').type('Careers{enter}');
    cy.get('input[name="op"]').contains('Submit').click();
    cy.contains('Test CI Link Title');
    cy.contains('Submission information');
    cy.contains('View');
    cy.contains('Edit');
    cy.contains('Title');
    cy.contains('Category');
    cy.contains('Tags');
    cy.contains('Skill Level');
    cy.contains('Description');
    cy.contains('Link to Resource');
    cy.contains('Submission information').click();
    cy.contains('Submission Number');
    cy.contains('Submission ID');
    cy.contains('Submission UUID');
    cy.contains('Submission URI');
    cy.contains('Created');
    cy.contains('Completed');
    cy.contains('Changed');
    cy.contains('Remote IP address');
    cy.contains('Submitted by');
    cy.contains('Language');
    cy.contains('Is draft');
    cy.contains('Webform');
    cy.contains('Delete submission');
    cy.get('.nav > :nth-child(2) > .nav-link').contains('Edit').click();
    cy.request('/form/resource').its('status').should('eq', 200);
  });

  it("Administrator creates a simple KB Resource", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit('/knowledge-base/resources');
    cy.contains('Add a Resource').click();
    cy.url().should('include', 'form/resource');
    cy.contains('Add');
    cy.get('input[name="title"]').type('test-login-resource');
    cy.get('select[name="category"]').select('learning');
    cy.get('input[name="approved"]').check();
    cy.get('[data-tid="682"]').contains('login').click();
    cy.get('.form-item-domain').find('.select2-search__field').type('Careers{enter}');
    cy.get('input[name="op"]').contains('Submit').click();
    cy.contains('test-login-resource');
  });

});
