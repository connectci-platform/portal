/*
  Test creating a tools_case_study node and verifying it appears
  on the /tools/researcher-stories view.
*/

const TITLE = 'cypress-tools-case-study-' + Date.now();

describe('Tools Case Study - create and verify on researcher stories view', () => {
  it('creates a tools_case_study node and it appears on /tools/researcher-stories', () => {
    cy.loginAs('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/node/add/tools_case_study');

    cy.get('h1').contains('Create');

    // Title
    cy.get('#edit-title-0-value').type(TITLE, { delay: 0 });

    // Institution
    cy.get('#edit-field-tcs-institution-0-value').type('Cypress University', { delay: 0 });

    // Research Topic
    cy.get('#edit-field-tcs-research-topic-0-value').type('Automated Testing Research', { delay: 0 });

    // Role
    cy.get('#edit-field-tcs-role-0-value').type('Principal Investigator', { delay: 0 });

    // Main Image
    cy.get('#edit-field-tcs-main-image-0-upload')
      .selectFile('cypress/files/story-feature.jpg');
    cy.get('.js-form-item-field-tcs-main-image-0-alt input').type('DNA Strands');

    // Wait for file upload
    cy.contains(/story-feature.*\.jpg/, { timeout: 10000 }).should('be.visible');

    // Allocation Type (required radio buttons)
    cy.get('#edit-field-tcs-allocation-type-explore').check();

    // Researcher Name (entity_reference to user)
    cy.get('#edit-field-tcs-researcher-name-0-target-id')
      .type('administrator_test_user')
      .wait(500)
      .type('{downarrow}{enter}');

    // Project Summary
    cy.get('.field--name-body .ck-content').then(el => {
      const editor = el[0].ckeditorInstance
      editor.setData('Lorem ipsum dolor sit amet, consectetur adipiscing elit. Duis eu elit lobortis, tincidunt ex id, sagittis tortor. Proin mollis, tortor aliquam porttitor faucibus, lorem arcu pellentesque augue, convallis rhoncus urna arcu at arcu. Integer vestibulum placerat accumsan. Curabitur fringilla tincidunt mauris quis ultricies. Morbi elit risus, tincidunt in elit non, tempus ornare turpis. Nullam orci augue, molestie et velit non, mattis porta massa. Mauris placerat turpis erat, quis tristique nisl fermentum commodo. Phasellus molestie posuere lacus sit amet blandit. Proin tincidunt consectetur quam. Aliquam sed fermentum mauris. Nullam quis sapien quis mi suscipit mattis. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Suspendisse maximus lorem ac rhoncus viverra. Suspendisse a neque id ipsum sollicitudin semper sed a ante. Ut accumsan faucibus sodales. Fusce pellentesque tempor bibendum. Pellentesque vestibulum justo et felis viverra porttitor. Nam a scelerisque nisi. Sed vel sagittis ex. Proin mollis eget purus non sagittis. Nulla nec accumsan sapien. Maecenas pharetra, mi sit amet facilisis auctor, elit augue dignissim ante, nec feugiat est magna nec sem. Integer augue nisi, rutrum eget odio et, pellentesque scelerisque sapien. Etiam ac purus ut tellus venenatis rutrum id at ligula. Morbi placerat, urna vitae luctus porttitor, nibh purus maximus velit, eu bibendum libero turpis in turpis.')
    })

    // Software
    cy.get('#edit-field-tcs-software-0-uri').type('https://claude.ai', { delay: 0 });
    cy.get('#edit-field-tcs-software-0-title').type('Claude', { delay: 0 });

    // Access Resource (entity_reference to CiDeR resource). Wait for the
    // autocomplete suggestion and click it; submit-time validation only
    // accepts an exact title match, so typed text alone is not enough.
    cy.get('#edit-field-tcs-access-resource-0-target-id')
      .type('UD DARWIN Storage');
    cy.get('.ui-autocomplete:visible li', { timeout: 10000 })
      .contains('UD DARWIN Storage')
      .click();

    // Field of science
    cy.get('#edit-field-tcs-field-of-science-0-value').type('200', { delay: 0 });

    // Time to science
    cy.get('#edit-field-tcs-time-to-science-0-value').type('54', { delay: 0 });

    // Service units
    cy.get('#edit-field-tcs-service-units-0-value').type('60', { delay: 0 });

    // Total GPU hours
    cy.get('#edit-field-tcs-total-gpu-hours-0-value').type('1020', { delay: 0 });

    // Total CPU hours
    cy.get('#edit-field-tcs-total-cpu-hours-0-value').type('2460', { delay: 0 });

    // # of jobs completed
    cy.get('#edit-field-tcs-jobs-completed-0-value').type('1000', { delay: 0 });

    // # of sessions completed (cloud resources such as Jetstream2)
    cy.get('#edit-field-tcs-sessions-completed-0-value').type('250', { delay: 0 });

    // Publish
    cy.get('#edit-status-value').check();

    cy.get('form.node-tools-case-study-form #edit-submit').scrollIntoView().click();

    // Should land on node page
    cy.get('h1').contains(TITLE);

    // Jobs and Sessions both display, with Sessions directly after Jobs
    cy.get('.field--name-field-tcs-jobs-completed')
      .should('contain', '# of Jobs Completed')
      .and('contain', '1000');
    cy.get('.field--name-field-tcs-sessions-completed')
      .should('contain', '# of Sessions Completed')
      .and('contain', '250');
    cy.get('.field--name-field-tcs-jobs-completed, .field--name-field-tcs-sessions-completed')
      .should('have.length', 2)
      .first()
      .should('have.class', 'field--name-field-tcs-jobs-completed');

    // Jetstream2-style story: clear Jobs, only Sessions should display
    cy.window().then((win) => {
      cy.visit('/' + win.drupalSettings.path.currentPath + '/edit');
    });
    cy.get('#edit-field-tcs-jobs-completed-0-value').clear();
    cy.get('form.node-tools-case-study-edit-form #edit-submit').scrollIntoView().click();
    cy.get('h1').contains(TITLE);
    cy.get('.field--name-field-tcs-sessions-completed').should('contain', '250');
    cy.get('.field--name-field-tcs-jobs-completed').should('not.exist');

    // Verify node appears on researcher stories view
    cy.visit('/tools/researcher-stories');
    cy.contains(TITLE);

  });
});
