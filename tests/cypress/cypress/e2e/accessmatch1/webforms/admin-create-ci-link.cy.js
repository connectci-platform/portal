//
// As an admin user, create a test KB Resource called "cypress-ci-link-for-testing"
//
describe("Admin user uses form to create a KB Resource", () => {
  it("should create a KB Resource", () => {
    // login user with the "administrator" role
    cy.loginAs("administrator@amptesting.com", "b8QW]X9h7#5n");
    cy.visit("/form/resource");
    cy.contains(" Resource ");
    // cy.task("log", "logged in as Apple Pie, on add ci-link form");

    // cy.contains('Edit Affinity Group ACCESS Support');
    cy.get("#edit-approved").check();
    cy.get("#edit-title").type("cypress-ci-link-for-testing");
    cy.get("#edit-category").select("Learning");
    cy.get("#edit-skill-level-304").check(); // beginner level
    cy.get("#edit-skill-level-305").check(); // intermediate level
    cy.get('.form-item-description-html-value .ck-content').then(el => {
      const editor = el[0].ckeditorInstance
      editor.setData("Dummy description for ci-link 'cypress-ci-link-for-testing'")
    });

    // add title & url for a link
    cy.get("#edit-link-to-resource-items-0-title").type(
      "title for ci-link link 0"
    );
    cy.get("#edit-link-to-resource-items-0-url").type(
      "http://example-0.com"
    );

    // add 2 addition link options
    cy.get("#edit-link-to-resource-add-more-items")
      .type("{selectall}{backspace}")
      .type("2");
    cy.get("#edit-link-to-resource-add-submit").click();

    // // add the 2 links
    cy.get('input[id^="edit-link-to-resource-items-1-title"]').type(
      "title for ci-link link 1"
    );
    cy.get('input[name="link_to_resource[items][1][url]"]').type(
      "http://example-1.com"
    );
    cy.get('input[name="link_to_resource[items][2][title]"').type(
      "title for ci-link link 2"
    );
    cy.get('input[name="link_to_resource[items][2][url]"]').type(
      "http://example-2.com"
    );

    // tag "ACCESS-account" is selected
    cy.get('span[data-tid="733"]').click();

    // tag "finite-element-analysis" is selected
    cy.get('span[data-tid="588"]').click();

    cy.get('.form-item-domain').find('.select2-search__field').type('ACCESS{enter}');

    // submit
    cy.get("#edit-submit").click();

    // cy.visit('/webform/ci-link/submissions/1/edit');

    // verify submission looks good
    cy.contains("cypress-ci-link-for-testing");
    cy.contains("Dummy description for ci-link 'cypress-ci-link-for-testing'");
    cy.get('.item-list').contains("title for ci-link link 0").should(
      "have.attr",
      "href",
      "http://example-0.com"
    );
    cy.get('.item-list').contains("title for ci-link link 1").should(
      "have.attr",
      "href",
      "http://example-1.com"
    );
    cy.get('.item-list').contains("title for ci-link link 2").should(
      "have.attr",
      "href",
      "http://example-2.com"
    );
    cy.get('.form-item-tags').contains("ACCESS-account")
      .should("have.attr", "href")
      .and("contains", "/tags/access-account");
    cy.get('.form-item-tags').contains("finite-element-analysis")
      .should("have.attr", "href")
      .and("contains", "/tags/finite-element-analysis");
    cy.get('.form-item-skill-level').contains("Beginner")
      .should("have.attr", "href")
      .and("contains", "/skill-level/beginner");
    cy.get('.form-item-skill-level').contains("Intermediate")
      .should("have.attr", "href")
      .and("contains", "/skill-level/intermediate");
  });
});
