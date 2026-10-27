/**
 * Test PA Science "Project Submissions" page for PA Science Managers
 *
 * This test verifies that PA Science managers can view all PA Science project submissions.
 *
 * Note: These tests should be run with CYPRESS_BASE_URL=https://pasciencedmz.ddev.site
 */

describe("PA Science - Project Submissions (Manager View)", () => {

  let project2Url;
  let nonPaSid;

  // A real pascience_manager account, created by amp_dev's install hook
  // alongside the other shared test users. It holds only that role, which is
  // the point: administrator is allowed everything, so logging in as an admin
  // would pass the delete test without the role granting anything and make the
  // denial test assert a 403 that never comes.
  const loginAsManager = () => {
    cy.loginUser('pascience_manager@amptesting.com', 'Vn4^kT7qM2d#');
  };

  it("PA Science Manager can view all PA Science project submissions", () => {
    // Create a PA Science project as regular user
    cy.loginUser("authenticated@amptesting.com", "6%l7iF}6(4tI");
    cy.visit('/form/project');

    cy.get('input[name="project_title"]').type('PA Science Manager Test Project 1');
    // Region is auto-selected based on domain
    cy.get('input[name="project_leader[first]"]').type('User');
    cy.get('input[name="project_leader[last]"]').type('One');
    cy.get('input[name="email"]').clear();
    cy.get('input[name="email"]').type('user1@test.com');
    cy.get('textarea[name="project_description"]').type('First test project for manager view');

    cy.get('input#edit-actions-01-submit').click();
    cy.url().should('include', '/project/');

    // Create another PA Science project as different user
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit('/form/project');

    cy.get('input[name="approved_milestones"]').check();
    cy.get('input[name="approved"]').check();
    cy.get('input[name="project_title"]').type('PA Science Manager Test Project 2');
    // Region is auto-selected based on domain
    cy.get('select[name="status"]').select('Recruiting');
    cy.get('input[name="project_leader[first]"]').type('User');
    cy.get('input[name="project_leader[last]"]').type('Two');
    cy.get('input[name="email"]').clear();
    cy.get('input[name="email"]').type('user2@test.com');
    cy.get('textarea[name="project_description"]').type('Second test project for manager view');

    cy.get('input#edit-actions-01-submit').click();
    cy.url().should('include', '/project/');
    cy.url().then((url) => {
      project2Url = url;
    });

    // Assert as the manager, not as the admin who created the project above:
    // administrator can see every program's submissions, so this test would
    // pass without the pascience_manager role granting anything.
    loginAsManager();

    // Visit the project submissions page
    cy.visit('/project-submissions');

    // Verify the page loads
    cy.contains('Project Submissions');

    // Verify both PA Science projects appear
    cy.contains('PA Science Manager Test Project 1').should('be.visible');
    cy.contains('PA Science Manager Test Project 2').should('be.visible');

    // Verify PA Science region is shown
    cy.contains('PA Science').should('be.visible');
  });

  it("Project submissions page shows project details", () => {
    // The manager's own view of the queue, not an admin's.
    loginAsManager();
    cy.visit('/project-submissions');

    // Verify we can see submission details
    cy.contains('PA Science Manager Test Project 1').should('be.visible');

    // Check for common fields that should be displayed
    cy.get('body').then($body => {
      // Look for project leader names or other identifying information
      const hasUserOne = $body.text().includes('User') || $body.text().includes('One');
      const hasUserTwo = $body.text().includes('Two');
      expect(hasUserOne || hasUserTwo).to.be.true;
    });
  });

  it("Non-PA Science projects should not appear for PA Science managers", () => {
    // Create a project in a different region as admin
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit('/form/project');

    cy.get('input[name="approved_milestones"]').check();
    cy.get('input[name="approved"]').check();
    cy.get('input[name="project_title"]').type('Non-PA Science Project - Should Not Appear');
    // The form pre-checks the PA Science program on this domain; drop it so
    // the project belongs to At-Large only.
    cy.get('input[name="region[933]"]').uncheck();
    cy.get('input[name="region[345]"]').check(); // At-Large region
    cy.get('select[name="status"]').select('In Progress');
    cy.get('input[name="project_leader[first]"]').type('Other');
    cy.get('input[name="project_leader[last]"]').type('Region');
    cy.get('input[name="email"]').clear();
    cy.get('input[name="email"]').type('other@test.com');
    cy.get('textarea[name="project_description"]').type('This is not a PA Science project');

    cy.get('input#edit-actions-01-submit').click();
    cy.url().should('match', /\/project\/\d+/).then((url) => {
      nonPaSid = url.match(/\/project\/(\d+)/)[1];
    });

    // The project above is created as administrator, who sees every program's
    // submissions. Switch to the manager before asserting, or the listing
    // renders for an admin and the assertion below is about the wrong user.
    loginAsManager();
    cy.visit('/project-submissions');

    // Verify PA Science projects appear
    cy.contains('PA Science Manager Test Project 1').should('be.visible');

    // The page stacks one section per program (At-Large, CAREERS, Kentucky,
    // ..., then PA Science), so a page-wide assertion finds the At-Large
    // project in its own section and fails for the wrong reason. What matters
    // here is that it is absent from the PA Science queue, which is the table
    // carrying the manager's Delete links.
    cy.contains('table', 'Delete').within(() => {
      cy.contains('PA Science Manager Test Project 1').should('exist');
      cy.contains('Non-PA Science Project - Should Not Appear').should('not.exist');
    });
  });

  it("Declined project is hidden from the default queue, and visible only when the filter doesn't exclude it", () => {
    // Set Test Project 2 (created above) to Declined.
    const sid = project2Url.match(/\/project\/(\d+)/)[1];

    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit(`/webform/project/submissions/${sid}/edit`);
    cy.get('select[name="status"]').select('Declined');
    cy.get('input#edit-actions-01-submit').click();

    // The `page_pa_science_submissions` display's exposed filter
    // (webform_submission_value_1, operator `not in`) currently defaults to
    // excluding Declined (not Halted, as an earlier draft of the queue
    // config had it — see views.view.project_submissions.yml, filter
    // webform_submission_value_1: value: {Declined: Declined}). So the
    // default queue now hides declined rows entirely.
    cy.visit('/project-submissions');
    cy.contains('PA Science Manager Test Project 2').should('not.exist');

    // Drive the exposed filter by URL, not the widget: expose.identifier is
    // webform_submission_value_1, multiple: true, remember: false, and the
    // widget is bef_select2 so cy.select() does not work on it (the exposed
    // label is now "Status hidden", naming the inversion). Mind the
    // inversion — the operator is `not in`, so the values in the URL are the
    // ones EXCLUDED, not selected. Excluding Halted instead of Declined
    // proves the row was filtered rather than gone: Declined is no longer in
    // the exclusion list, so it reappears, still labeled Declined.
    cy.visit('/project-submissions?webform_submission_value_1[]=Halted');
    cy.contains('PA Science Manager Test Project 2')
      .should('be.visible')
      .closest('tr')
      .contains('Declined')
      .should('be.visible');
  });

  it("Clearing the status filter and applying keeps Declined shown instead of re-applying the default", () => {
    // D8-2880: an empty multi-select sends no query parameter, so "Apply with
    // nothing selected" used to look like a first load and the default
    // (exclude Declined) came back. The form now adds ps=1 on every Apply.
    // Test Project 2 was set to Declined by the previous test.
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit('/project-submissions');

    // First load: Declined is excluded by default and hidden from the queue.
    cy.get('select[name="webform_submission_value_1[]"]')
      .invoke('val')
      .should('deep.equal', ['Declined']);
    cy.contains('PA Science Manager Test Project 2').should('not.exist');

    // Deselect Declined (the widget is select2, so drive the underlying
    // select) and apply.
    cy.get('select[name="webform_submission_value_1[]"]')
      .invoke('val', [])
      .trigger('change', { force: true });
    cy.get('form.views-exposed-form')
      .find('input[type="submit"], button[type="submit"]')
      .first()
      .click();

    cy.url().should('include', 'ps=1');
    cy.get('select[name="webform_submission_value_1[]"]')
      .invoke('val')
      .should((val) => expect(val || []).to.not.include('Declined'));
    cy.contains('PA Science Manager Test Project 2')
      .should('be.visible')
      .closest('tr')
      .contains('Declined')
      .should('be.visible');

    // The marker survives a sort link, so the choice persists.
    cy.get('table thead th a').first().click();
    cy.url().should('include', 'ps=1');
    cy.get('select[name="webform_submission_value_1[]"]')
      .invoke('val')
      .should((val) => expect(val || []).to.not.include('Declined'));
    cy.contains('PA Science Manager Test Project 2').should('be.visible');
  });

  it("PA Science manager can delete a PA Science project from the listing", () => {
    const title = 'PA Science Manager Delete Test Project';

    // Create the project to be deleted.
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit('/form/project');
    cy.get('input[name="approved_milestones"]').check();
    cy.get('input[name="approved"]').check();
    cy.get('input[name="project_title"]').type(title);
    cy.get('select[name="status"]').select('Recruiting');
    cy.get('input[name="project_leader[first]"]').type('Delete');
    cy.get('input[name="project_leader[last]"]').type('Me');
    cy.get('input[name="email"]').clear();
    cy.get('input[name="email"]').type('deleteme@test.com');
    cy.get('textarea[name="project_description"]').type('Project that will be deleted');
    cy.get('input#edit-actions-01-submit').click();
    cy.url().should('include', '/project/');

    loginAsManager();
    cy.visit('/project-submissions');
    cy.contains(title)
      .closest('tr')
      .find('a')
      .contains('Delete')
      .click();

    // Assert we actually left the listing before asserting on page content.
    // The listing shows the project title and a "Declined" status filter, so
    // the three content assertions below also pass on the listing itself — if
    // the Delete link failed to navigate they would not catch it, and the
    // submit would then resolve against the listing's own hidden exposed-filter
    // button instead of this form's.
    cy.location('pathname').should('include', '/delete');

    // Confirmation page names the project and warns about attached files.
    cy.contains(title).should('be.visible');
    cy.contains(/permanently deleted/i).should('be.visible');
    cy.contains(/Declined/).should('be.visible');

    // Scoped to the confirm form: a bare #edit-submit also matches the
    // listing's exposed-filter Search button.
    cy.get('form').contains('input[type="submit"], button[type="submit"]', /^Delete$/)
      .click();

    cy.location('pathname').should('eq', '/project-submissions');

    // Re-request the listing rather than asserting on the response the delete
    // redirected to. The view itself no longer returns the row, so a stale
    // render here is the redirect's cached copy, not a failed delete.
    cy.reload();
    cy.contains(title).should('not.exist');
  });

  it("PA Science manager is denied direct delete access to a non-PA Science project", function () {
    expect(nonPaSid, 'sid of the non-PA Science project').to.be.a('string');

    loginAsManager();
    cy.request({
      url: `/webform/project/submissions/${nonPaSid}/delete`,
      failOnStatusCode: false,
    }).its('status').should('eq', 403);
    cy.request({
      url: `/admin/structure/webform/manage/project/submission/${nonPaSid}/delete`,
      failOnStatusCode: false,
    }).its('status').should('eq', 403);
  });

  it("Cleanup - Delete test projects", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit('/admin/structure/webform/manage/project/results/submissions');

    const testTitles = [
      'PA Science Manager Test Project 1',
      'PA Science Manager Test Project 2',
      'PA Science Manager Delete Test Project',
      'Non-PA Science Project - Should Not Appear'
    ];

    testTitles.forEach(title => {
      // Each delete redirects away from the listing, so come back before
      // looking for the next title.
      cy.visit('/admin/structure/webform/manage/project/results/submissions');
      cy.get('body').then($body => {
        if ($body.text().includes(title)) {
          // Follow the row's delete route directly rather than clicking the
          // link. The link requests _wrapper_format=drupal_modal, and whether
          // a dialog actually opens or the browser just loads the confirm page
          // varies; cleanup should not depend on which happened.
          cy.contains(title)
            .closest('tr')
            .find('a')
            .contains('Delete')
            .invoke('attr', 'href')
            .then((href) => {
              cy.visit(href.replace(/[?&]_wrapper_format=drupal_modal/, ''));
            });
          cy.get('form').contains('input[type="submit"], button[type="submit"]', /^Delete$/)
            .click();
        }
      });
    });
  });
});
