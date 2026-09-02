/*
   Verify a tag's page's list of engagements.
   Create 4 engagements, verify they appear.

*/
describe("Verify a tag's page list of engagements", () => {
  it("Tests the tags page", () => {


    // add some engagements with this tag
    cy.loginAs('administrator@amptesting.com', 'b8QW]X9h7#5n');
    addEngagements(1);
    addEngagements(2);
    addEngagements(3);
    addEngagements(4);
    cy.drupalLogout();

    // verify each engagement looks good

    cy.visit("/tags/community-outreach");

    cy.get('.view-match-engagement-view')
      .find('.views-row').should('have.length', 3)
      .each((row, rowIndex) => {

        cy.get('.view-match-engagement-view')
          .find('.card-title')
          .contains('dummy engagement');
        cy.get('.view-match-engagement-view')
          .contains('In Progress');
      });


    // check more button
    cy.get('.view-match-engagement-view')
      .contains('All Engagements with this tag')
      .should('have.attr', 'href', '/tag/340/engagements');

    /////////////////////////////////////////////////////////////////////////

    function addEngagements(num) {

      // create an engagement
      cy.visit("/node/add/match_engagement?type=plus");

      // Select the required "I went to ACCESS/NAIRR Office Hours" checkbox
      cy.get("#edit-field-match-office-hours-value").check();
      
      cy.get("#edit-title-0-value").type("dummy engagement for tag community-outreach number " + num);
      cy.get('.field--name-body .ck-content').then(el => {
        const editor = el[0].ckeditorInstance
        editor.setData('dummy engagement description')
      })

      // open tags section
      cy.get(".tags").click();
      // select community-outreach tag
      cy.get(".tags-select").contains("community-outreach").click();
      // Send for Review
      cy.get('#edit-moderation-state-0-state').select('Submitted');
      cy.get('#edit-submit').click();
      // The node save redirects and re-renders; under CI load this can exceed
      // the default 4s, so give the confirmation message room before asserting.
      cy.contains('Thank you for sending your project for review.', { timeout: 20000 });

      // update to In Progress
      cy.get('.tabs')
        .contains('Edit')
        .click();
      cy.get('#edit-moderation-state-0-state').select('In Progress');
      cy.get('#edit-submit').click();
      cy.contains('has been updated.', { timeout: 20000 });
    }

  });
});
