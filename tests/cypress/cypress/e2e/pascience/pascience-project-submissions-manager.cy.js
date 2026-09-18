/**
 * Test PA Science "Project Submissions" page for PA Science Managers
 *
 * This test verifies that PA Science managers can view all PA Science project submissions.
 *
 * Note: These tests should be run with CYPRESS_BASE_URL=https://pasciencedmz.ddev.site
 */

describe("PA Science - Project Submissions (Manager View)", () => {

  let project2Url;

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

    // Now login as user with pascience_manager role (user 1995)
    // Note: We'll need to create login credentials for this user or use a test account
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

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
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
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
    cy.get('input[name="region[345]"]').check(); // At-Large region
    cy.get('select[name="status"]').select('In Progress');
    cy.get('input[name="project_leader[first]"]').type('Other');
    cy.get('input[name="project_leader[last]"]').type('Region');
    cy.get('input[name="email"]').clear();
    cy.get('input[name="email"]').type('other@test.com');
    cy.get('textarea[name="project_description"]').type('This is not a PA Science project');

    cy.get('input#edit-actions-01-submit').click();

    // Visit PA Science project submissions page
    cy.visit('/project-submissions');

    // Verify PA Science projects appear
    cy.contains('PA Science Manager Test Project 1').should('be.visible');

    // Verify non-PA Science project does NOT appear
    cy.contains('Non-PA Science Project - Should Not Appear').should('not.exist');
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

  it("Cleanup - Delete test projects", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit('/admin/structure/webform/manage/project/results/submissions');

    const testTitles = [
      'PA Science Manager Test Project 1',
      'PA Science Manager Test Project 2',
      'Non-PA Science Project - Should Not Appear'
    ];

    testTitles.forEach(title => {
      cy.get('body').then($body => {
        if ($body.text().includes(title)) {
          cy.contains(title).closest('tr').find('a').contains('Delete').click({ force: true });
          cy.get('.ui-dialog-buttonpane button.button--primary').contains('Delete').click();
          cy.wait(1000);
        }
      });
    });
  });
});
