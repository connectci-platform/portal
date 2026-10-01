/**
 * Test PA Science "My Project Submissions" page
 *
 * This test verifies that authenticated users can view their own project submissions.
 *
 * Note: These tests should be run with CYPRESS_BASE_URL=https://pasciencedmz.ddev.site
 */

describe("PA Science - My Project Submissions", () => {

  let myProjectUrl;

  it("Authenticated user can view their project submissions", () => {
    // Create a project as authenticated user
    cy.loginUser("authenticated@amptesting.com", "6%l7iF}6(4tI");
    cy.visit('/form/project');

    cy.get('input[name="project_title"]').type('My Test Project for Submissions View');
    // Region is auto-selected based on domain
    cy.get('input[name="project_leader[first]"]').type('Test');
    cy.get('input[name="project_leader[last]"]').type('User');
    cy.get('input[name="email"]').clear();
    cy.get('input[name="email"]').type('authenticated@amptesting.com');
    cy.get('textarea[name="project_description"]').type('This is my test project');

    cy.get('input#edit-actions-01-submit').click();

    // Verify project was created
    cy.url().should('include', '/project/');
    cy.contains('My Test Project for Submissions View');
    cy.url().then((url) => {
      myProjectUrl = url;
    });

    // Visit My Project Submissions page
    cy.visit('/user/project-submissions');

    // Verify the page loads
    cy.contains('My Project Submissions');

    // Verify our project appears in the list
    cy.contains('My Test Project for Submissions View').should('be.visible');

    // Verify we can see project details
    cy.contains('PA Science').should('be.visible');
  });

  it("User can only see their own project submissions", () => {
    // Create a project as admin
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit('/form/project');

    cy.get('input[name="approved_milestones"]').check();
    cy.get('input[name="approved"]').check();
    cy.get('input[name="project_title"]').type('Admin Project - Should Not Appear');
    // Region is auto-selected based on domain
    cy.get('select[name="status"]').select('In Progress');
    cy.get('input[name="project_leader[first]"]').type('Admin');
    cy.get('input[name="project_leader[last]"]').type('User');
    cy.get('input[name="email"]').clear();
    cy.get('input[name="email"]').type('admin@test.com');
    cy.get('textarea[name="project_description"]').type('Admin test project');

    cy.get('input#edit-actions-01-submit').click();

    // Now login as authenticated user
    cy.loginUser("authenticated@amptesting.com", "6%l7iF}6(4tI");
    cy.visit('/user/project-submissions');

    // Verify we see our own project
    cy.contains('My Test Project for Submissions View').should('be.visible');

    // Verify we DON'T see the admin's project
    cy.contains('Admin Project - Should Not Appear').should('not.exist');
  });

  it("Submitter sees Declined status on their own submission", () => {
    // Submitters can edit their own project's status (known gap: `status`
    // carries no #access_*_roles, so the select renders for the owner).
    const sid = myProjectUrl.match(/\/project\/(\d+)/)[1];

    cy.loginUser("authenticated@amptesting.com", "6%l7iF}6(4tI");
    cy.visit(`/webform/project/submissions/${sid}/edit`);
    // Status has no #states gate, so it is visible on a submission before
    // approved_milestones is checked.
    cy.get('select[name="status"]').should('be.visible').select('Declined');
    cy.get('input#edit-actions-01-submit').click();

    cy.visit('/user/project-submissions');
    cy.contains('My Test Project for Submissions View')
      .should('be.visible')
      .closest('tr')
      .contains('Declined')
      .should('be.visible');

    // Absent from the public /projects listing — but that's because this
    // project was never approved (approved = 0), not because of its
    // Declined status. front_projects gates solely on approved = 1.
    cy.visit('/projects');
    cy.contains('My Test Project for Submissions View').should('not.exist');
  });

  it("Cleanup - Delete test projects", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit('/admin/structure/webform/manage/project/results/submissions');

    const testTitles = [
      'My Test Project for Submissions View',
      'Admin Project - Should Not Appear'
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
