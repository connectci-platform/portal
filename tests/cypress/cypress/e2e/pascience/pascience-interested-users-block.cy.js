/**
 * Test PA Science "Interested People" block on project pages
 *
 * This test verifies that the InterestedUsersBlock:
 * - Shows interested users to the project owner and PA Science managers
 * - Is hidden from anonymous users and non-owner authenticated users
 * - Only appears when the project status is "Recruiting"
 * - Each user name links to their community persona page
 *
 * Note: These tests should be run with CYPRESS_BASE_URL=https://pasciencedmz.ddev.site
 */

describe("PA Science - Interested People Block", () => {

  let projectUrl;

  before(() => {
    // Ensure user 2000 has pascience_manager role
    cy.drush('user:role:add', ['pascience_manager'], { mail: 'user+2000@localhost.localdomain' });

    // Create a recruiting project as admin (who will be the owner)
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit('/form/project');

    cy.get('input[name="approved_milestones"]').check();
    cy.get('input[name="approved"]').check();
    cy.get('input[name="project_title"]').type('Interested Block Test Project');
    cy.get('input[name="tags[682]"]').check();
    cy.get('select[name="status"]').select('Recruiting');
    cy.get('input[name="project_leader[first]"]').type('Block');
    cy.get('input[name="project_leader[last]"]').type('Tester');
    cy.get('input[name="email"]').clear();
    cy.get('input[name="email"]').type('blocktester@test.com');
    cy.get('textarea[name="project_description"]').type('Test project for interested users block');

    cy.get('input#edit-actions-01-submit').click();
    cy.url().should('include', '/project/');
    cy.contains('Interested Block Test Project');

    // Save the project URL for later tests
    cy.url().then((url) => {
      projectUrl = url;
    });

    // Now login as authenticated user and express interest
    cy.loginUser("authenticated@amptesting.com", "6%l7iF}6(4tI");
    cy.visit('/projects');
    cy.get('input[name="search"]').type('Interested Block Test Project');
    cy.wait(2000);
    cy.contains('Interested Block Test Project').click();
    cy.contains("I'm interested").click();
    cy.contains('interested', { timeout: 10000 }).should('be.visible');
  });

  it("Block is visible to the project owner with interested user listed", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit(projectUrl);

    cy.contains('Interested People').should('be.visible');
    cy.get('.card').contains('Interested People')
      .parent()
      .within(() => {
        // The authenticated user's name should appear
        cy.get('a[href*="/community-persona/"]').should('have.length.gte', 1);
      });
  });

  it("Each interested user name links to their community persona page", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit(projectUrl);

    cy.get('.card').contains('Interested People')
      .parent()
      .find('a[href*="/community-persona/"]')
      .first()
      .should('have.attr', 'href')
      .and('match', /\/community-persona\/\d+/);
  });

  it("Block is visible to pascience_manager users", () => {
    // Login as manager (user 2000) via drush one-time link
    cy.drush('user-login', [], { uid: 2000, uri: Cypress.config('baseUrl') })
      .its('stdout')
      .then((loginUrl) => {
        cy.visit(loginUrl);
      });

    cy.visit(projectUrl);
    cy.contains('Interested People').should('be.visible');
  });

  it("Block is NOT visible to anonymous users", () => {
    cy.drupalLogout();
    cy.visit(projectUrl);
    cy.contains('Interested People').should('not.exist');
  });

  it("Block is NOT visible to a non-owner, non-manager authenticated user", () => {
    cy.loginUser("authenticated@amptesting.com", "6%l7iF}6(4tI");
    cy.visit(projectUrl);
    cy.contains('Interested People').should('not.exist');
  });

  it("Block is NOT visible when project status is not Recruiting", () => {
    // Change project status to In Progress
    cy.url().then(() => {
      const sid = projectUrl.match(/\/project\/(\d+)/)[1];

      cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
      cy.visit(`/webform/project/submissions/${sid}/edit`);
      cy.get('select[name="status"]').select('In Progress');
      cy.get('input#edit-actions-01-submit').click();

      cy.visit(projectUrl);
      cy.contains('Interested People').should('not.exist');

      // Restore Recruiting status for cleanup test
      cy.visit(`/webform/project/submissions/${sid}/edit`);
      cy.get('select[name="status"]').select('Recruiting');
      cy.get('input#edit-actions-01-submit').click();
    });
  });

  after(() => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit('/admin/structure/webform/manage/project/results/submissions');

    cy.get('body').then($body => {
      if ($body.text().includes('Interested Block Test Project')) {
        cy.contains('Interested Block Test Project').closest('tr').find('a').contains('Delete').click({ force: true });
        cy.get('.ui-dialog-buttonpane button.button--primary').contains('Delete').click();
        cy.wait(1000);
      }
    });
  });
});
