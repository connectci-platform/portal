/*
   Verify the community-outreach tag page.
*/
describe("Verify the the community-outreach tag page", () => {
  it("Tests the tags page", () => {

    cy.visit("/tags/community-outreach");

    checkTitleAndBreadcrumbs();
    checkSectionAffinityGroups();
    checkSectionAnnouncements('.view-id-tagged_news_block', '/announcements/');
    checkSectionEvents('.view-id-recurring_events_event_instances', '/events/');
    checkSectionInterestedSkilled('.view-people-with-expertise-tags', 'expertise');
    checkSectionInterestedSkilled('.view-people-with-interest-tags', 'interest');
    checkSectionCILinks();

    /////////////////////////////////////////////////////////////////////////

    function checkSectionAnnouncements(blockclass, href_type) {
      cy.get(blockclass)
        .find('tbody')
        .find('tr')
        .each((row) => {
          cy.wrap(row).find('a').should('have.attr', 'href')
            .and('contain', href_type);
          cy.wrap(row).find('.view-field-published-date-table-column')
            .should('not.be.empty');
        });
    }

    /////////////////////////////////////////////////////////////////////////

    function checkSectionEvents(blockclass, href_type) {

      cy.get('body').then(($body) => {
        if (!$body.text().includes('No events or trainings are currently scheduled.')) {
          cy.get(blockclass)
            .find('tbody')
            .find('tr')
            .each((row) => {
              cy.wrap(row).find('a').should('have.attr', 'href')
                .and('contain', href_type);
              cy.wrap(row).find('.view-field-published-date-table-column')
                .should('not.be.empty');
            });
        }
      })
    }

    /////////////////////////////////////////////////////////////////////////

    function checkSectionInterestedSkilled(blockclass, skillOrInterest) {

      cy.get(blockclass)
        .find('.col-md-4.views-row')
        .should('have.length', 3)
        .each((block) => {

          // look for h3 for user's name with link to community persona
          cy.wrap(block)
            .find('h3').find('a').should('have.attr', 'href')
            .and('contain', '/community-persona/');

          // check there's an image for the user
          cy.wrap(block)
            .find('.ms-3') // class="ms-3 minw-35 max-w-[150px]"
            .find('img').verifyImage();

          // check there is at least one square tags
          cy.wrap(block)
            .find('.square-tags')
            .find('.view-content > div').should('have.length.gt', 0)
            .each((e) => cy.wrap(e)
              .find('a').should('have.attr', 'href'));
          // sometimes contains terms not tags - but can't get regex to work
          // .and('contain', /tags|terms/));
        });

      // check more button
      cy.wait(1000);
      cy.get(blockclass)
        .contains('more people')
        .should('have.attr', 'href', '/tags/community-outreach/people-with-' + skillOrInterest);
    }


    /////////////////////////////////////////////////////////////////////////

    function checkSectionCILinks() {

      cy.loginAs('administrator@amptesting.com', 'b8QW]X9h7#5n');
      create_dummy_ci_link();
      create_dummy_ci_link();
      create_dummy_ci_link();
      create_dummy_ci_link();
      cy.drupalLogout();
      cy.visit("/tags/community-outreach");

      // verify count of ci-links is 3
      // verify each link looks good

      cy.get('.view-resources')
        .find('tbody')
        .find('tr').should('have.length', 3)
        .each((row, rowIndex) => {
          cy.wrap(row).find('td').each((cell, cellIndex) => {

            // cy.task('log', 'row: ' + rowIndex + ' cell: ' + cellIndex + ' text: ' + cell.text());

            switch (cellIndex) {
              case 0:
                cy.wrap(cell)
                  .contains('dummy-ci-link-for-testing-community-outreach-tag')
                  .should('have.attr', 'href')
                  .and('contain', '/knowledge-base/resources');
                break;
              case 1:
                cy.wrap(cell)
                  .contains('Learning')
                break;
              case 2:
                cy.wrap(cell)
                  .contains('community-outreach')
                  .should('have.attr', 'href', "/tags/community-outreach")
                break;
              case 3:
                cy.wrap(cell)
                  .contains('Beginner')
                break;
            }
          })
        });

      // check more button
      cy.get('.view-resources')
        .contains('All Resources with this tag')
        .should('have.attr', 'href', '/tags/340/resources');
    }

    /////////////////////////////////////////////////////////////////////////

    function createEvents() {
      // login user with the "authenticated" role
      cy.loginAs("administrator@amptesting.com", "b8QW]X9h7#5n");
      cy.visit("/events/add");

      //Page Title
      cy.get("h1").contains("Create Community Event");

      //User filling out form title
      cy.get("#edit-title-0-value").type("example-event", { delay: 0 });

      //Date and Time of Event
      cy.get("#edit-recur-type-custom").click();
      cy.get("#custom-date-values > thead > tr > .field-label").contains(
        "Custom Date(s) and Time(s)"
      );
      cy.get("#edit-custom-date-0-value-date").type("2026-12-12", { delay: 0 });
      cy.get("#edit-custom-date-0-end-value-date").type("2027-12-12", { delay: 0 });
      cy.get("#edit-custom-date-0-value-time").type("04:30:00", { delay: 0 });
      cy.get("#edit-custom-date-0-end-value-time").type("04:30:00", { delay: 0 });

      //Event Location
      cy.get("#edit-field-location-0-value").type("Zoom", { delay: 0 });

      //Event Contact
      cy.get("#edit-field-contact-0-value").type("Pecan Pie", { delay: 0 });

      //Registration Link
      cy.get("#edit-field-registration-0-uri").type("https://example.com", { delay: 0 });

      //Event Tag
      cy.get("#edit-field-tags-0-target-id").type("login (682)", { delay: 0 });

      //Save As Selection
      cy.get("#edit-moderation-state-0-state").select("Published");

      //Event Type
      cy.get("#edit-field-event-type-training").click();

      //Event Affiliation
      cy.get("#edit-field-affiliation-community").click();

      //Event Skill Level
      cy.get("#edit-field-skill-level-advanced").click();

      //Form Submit Button and confirmation
      cy.get("#edit-submit").click();
      cy.contains("Successfully saved the example-event event series");
    }


    /////////////////////////////////////////////////////////////////////////

    function checkTitleAndBreadcrumbs() {
      cy.get(".page-title").contains("community-outreach");

      // check breadcrumbs
      const crumbs = [
        ['Support', '/'],
        ['Tags', '/tags'],
        ['community-outreach', null]
      ];
      cy.checkBreadcrumbs(crumbs);
    }

    /////////////////////////////////////////////////////////////////////////

    function checkSectionAffinityGroups() {
      cy.get('.view-affinity-groups-with-tag.view-display-id-block_2')
        .find('tbody')
        .find('tr')
        .each((row, rowIndex) => {

          // each row uses the same href in mulitple columns, so get the href from the first column.
          cy.wrap(row).find('td').eq(0).find('a').invoke('attr', 'href')
            .then(($href) => {
              // cy.log('href = ' + $href);

              cy.wrap(row).find('td').each((cell, cellIndex) => {

                // cy.task('log', 'row: ' + rowIndex + ' cell: ' + cellIndex + ' text: ' + cell.text());

                cy.wrap(cell).as('cell')
                switch (cellIndex) {
                  case 0:
                    cy.get('@cell').find('a').should('have.attr', 'href', $href);
                    cy.get('@cell').find('img').verifyImage();
                    break;
                  case 1:
                    cy.get('@cell').find('a').should('have.attr', 'href', $href);
                    break;
                  case 2:
                    cy.get('@cell').should('not.be.empty');
                    break;
                  case 3:
                    // tags - there should be at least one tag, and not more than 3
                    cy.get('@cell').find('a').should('have.length.gt', 0);
                    cy.get('@cell').find('a').should('have.length.lt', 4);
                    cy.get('@cell').find('a').each((a) =>
                      cy.wrap(a).should('have.attr', 'href')
                        .and('contain', '/tags/'));
                    break;
                  case 4:
                    cy.get('@cell').find('a').contains('Login to join');
                    cy.get('@cell').find('a').should('have.attr', 'href')
                      .and('contain', '/user/login?destination=' + $href);
                    break;
                }
              });

            });
        });

      cy.get('.view-affinity-groups-with-tag.view-display-id-block_2')
        .contains('All Affinity Groups with this tag')
        .should('have.attr', 'href', "/tags/340/affinity-groups");
    }

    /////////////////////////////////////////////////////////////////////////

    // helper function to create a KB Resource
    function create_dummy_ci_link() {
      cy.visit('/form/resource');
      cy.get('#edit-approved').check();
      cy.get('#edit-title').type('dummy-ci-link-for-testing-community-outreach-tag', { delay: 0 });
      cy.get('#edit-category').select('Learning');
      cy.get('#edit-skill-level-304').check();  // beginner level
      cy.get('.form-item-description-html-value .ck-content').then(el => {
        const editor = el[0].ckeditorInstance
        editor.setData("Dummy description for ci-link 'dummy-ci-link-for-testing-community-outreach-tag'")
      });
      cy.get('.tags').contains('community-outreach').click();
      cy.get('.form-item-domain').find('input').type('ACCESS{enter}', { delay: 0 });
      cy.get('#edit-submit').click();
    }
  });
});
