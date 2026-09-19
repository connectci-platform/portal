/*
    Test /knowledge-base
 */
describe("Tests of the knowledge-base page", () => {
  it("Should complete successfully", () => {

    cy.visit("/knowledge-base");

    cy.get('#block-pagetitle').contains("Knowledge Base");

    // check breadcrumbs
    const crumbs = [
      ['Support', '/'],
      ['Knowledge Base', '/knowledge-base'],
      ['Overview', null]
    ];
    cy.checkBreadcrumbs(crumbs);

    cy.get('.btn.btn-primary.ms-0')
      .contains('Search')
      .should('have.attr', 'href', '/find');
    cy.contains('Create an account')
      .should('have.attr', 'href', 'https://operations.access-ci.org/identity/new-user');
    cy.contains('Video learning center')
      .should('have.attr', 'href', '/video-learning-center');
    cy.contains('ACCESS Resource Advisor')
      .should('have.attr', 'href', 'https://ara.access-ci.org');
    cy.get('.btn.btn-primary.ms-0')
      .contains('Open a help ticket')
      .should('have.attr', 'href', '/help-ticket');
    cy.contains('GET STARTED WITH ACCESS')
      .should('have.attr', 'href', 'https://access-ci.org/about/get-started/');
    cy.contains('VIDEO LEARNING CENTER')
      .should('have.attr', 'href', 'https://support.access-ci.org/video-learning-center');
    cy.contains('ACCESS RESOURCE ADVISOR')
      .should('have.attr', 'href', 'https://ara.access-ci.org');
    cy.get('.btn.btn-primary.mt-8')
      .contains('Visit documentation')
      .should('have.attr', 'href'); // TODO , 'https://access-ci.atlassian.net/wiki/spaces/ACCESSdocumentation/overview');
    cy.get('.btn.btn-primary')
      .contains('VISIT ASK.CI')
      .should('have.attr', 'href', 'https://ask.cyberinfrastructure.org');

    // popular tags
    cy.get('.block-top-tags-from-askci')
      .contains('Popular tags on Ask.CI');

    cy.get('.block-top-tags-from-askci')
      .find('a')
      .each(($a) => cy.wrap($a)
        .should('have.attr', 'href')
        .and('contain', 'https://ask.cyberinfrastructure.org/tag'));

    // popular 3 ci-links so popular links list is full
    cy.loginAs('administrator@amptesting.com', 'b8QW]X9h7#5n');
    create_dummy_ci_link();
    create_dummy_ci_link();
    create_dummy_ci_link();
    cy.drupalLogout();
    cy.visit("/knowledge-base");

    cy.get('.btn.btn-primary')
      .contains('FIND RESOURCES')
      .should('have.attr', 'href', '/knowledge-base/resources');

    cy.get('.field--type-text-with-summary')
      .contains('Popular Resources');

    // verify count of ask-ci links is 10
    cy.get('.block-top-tags-from-askci > .flex-wrap')
      .find('a')
      .should('have.length', 10)
      .each(($a) => cy.wrap($a)
        .should('have.attr', 'href')
        .and('contain', '/tag'));

    // verify count of ci-links is 3
    cy.get('.view-resources.view-id-resources')
      .find('a')
      .should('have.length', 3)
      .each(($a) => cy.wrap($a)
        .should('have.attr', 'href')
        .and('contain', '/knowledge-base/resources'));

  });
});


// helper function to create a KB Resource that can be added to the AG
function create_dummy_ci_link() {
  cy.visit('/form/resource');
  cy.get('#edit-approved').check();
  cy.get('#edit-title').type('dummy-ci-link-for-testing-knowledge-base', { delay: 0 });
  cy.get('#edit-category').select('Learning');
  cy.get('#edit-skill-level-304').check();  // beginner level
  cy.get('.form-item-description-html-value .ck-content').then(el => {
    const editor = el[0].ckeditorInstance
    editor.setData("Dummy description for ci-link 'dummy-ci-link-for-testing-knowledge-base'")
  });
  // tag "ACCESS-account" is selected
  cy.get('.tags').contains('ACCESS-account').click();
  cy.get('.form-item-domain').find('.select2-search__field').type('ACCESS{enter}', { delay: 0 });
  cy.get('#edit-submit').click();
}



