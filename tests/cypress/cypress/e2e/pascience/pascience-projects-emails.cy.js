/**
 * Test PA Science project email notifications
 *
 * This test verifies that the correct emails are sent when:
 * - A new project is created (to PA Science managers)
 * - A project is marked as "Received" (to submission author)
 * - A project author notifies managers of an update (to PA Science managers)
 * - A project is approved and published (to submission author)
 * - Someone expresses interest in a recruiting project (to author and PA Science Manager)
 *
 * Note: These tests should be run with CYPRESS_BASE_URL=https://pasciencedmz.ddev.site
 *
 * Implementation details:
 * - Email policies: access_misc_project.project_created_pascience, project_received_pascience,
 *   project_updated_pascience, project_approved_pascience, project_flagged
 * - Emails sent to pascience_manager role and/or project submission author
 */

describe("PA Science Project Email Notifications", () => {

  before(() => {
    // Set up PA Science manager role for user 2000
    // This ensures consistent test behavior regardless of existing data
    //
    // Note: `drush user:role:add`'s positional argument is a comma-delimited
    // list of user *names*, not emails/uids - passing an email there silently
    // fails to match (drush reports "Unable to find a matching user" for any
    // lookup on an email containing "+"), so `cy.drush` here previously ran
    // with `failOnNonZeroExit: false` and never actually granted the role.
    // That left the pascience_manager role with no members, so the
    // 'Project Created'/'Project Updated'/interest-expressed emails - which
    // go to that role - were never sent, and cy.waitForEmail() timed out
    // waiting on Mailpit for mail that was never dispatched. Use --mail to
    // look the user up by email instead.
    cy.drush('user:role:add', ['pascience_manager'], { mail: 'user+2000@localhost.localdomain' });
  });

  beforeEach(() => {
    // Clear mailpit before each test to ensure clean state
    cy.clearMailpit();
  });

  it("Sends 'Project Created' email to PA Science managers when a new project is submitted", () => {
    // Login as authenticated user (not admin) to create project
    cy.loginUser("authenticated@amptesting.com", "6%l7iF}6(4tI");
    cy.visit('/form/project');

    // Fill out basic project form (only fields visible on create)
    cy.get('input[name="project_title"]').type('Test Project Creation Email');
    cy.get('input[name="project_leader[first]"]').type('Jane');
    cy.get('input[name="project_leader[last]"]').type('Doe');
    cy.get('input[name="email"]').clear();
    cy.get('input[name="email"]').type('jane.doe@test.com');
    cy.get('textarea[name="project_description"]').type('This is a test project to verify creation email.');

    cy.get('input#edit-actions-submit').click();

    // Verify project was created
    cy.url().should('include', '/project/');

    // Wait for email to be processed
    cy.wait(3000);

    // Check for PA Science Manager notification email
    cy.waitForEmail({
      to: 'user+2000@localhost.localdomain',
      subject: 'New Project Submission: Test Project Creation Email'
    }, 8000).then((message) => {
      cy.assertEmailContent(message, {
        subject: 'New Project Submission: Test Project Creation Email',
        to: 'user+2000@localhost.localdomain',
        htmlContains: ['A new project has been submitted to PA Science DMZ', 'jane.doe@test.com', 'Jane Doe']
      });
    });
  });

  it("Sends 'Project Received' email to author when admin marks project as received", () => {
    // First, create a project as authenticated user
    cy.loginUser("authenticated@amptesting.com", "6%l7iF}6(4tI");
    cy.visit('/form/project');

    cy.get('input[name="project_title"]').type('Test Project Received Email');
    cy.get('input[name="project_leader[first]"]').type('John');
    cy.get('input[name="project_leader[last]"]').type('Smith');
    cy.get('input[name="email"]').clear();
    cy.get('input[name="email"]').type('john.smith@test.com');
    cy.get('textarea[name="project_description"]').type('Test project for received email.');

    cy.get('input#edit-actions-submit').click();
    cy.url().should('include', '/project/');

    // Get the submission ID from URL
    cy.url().then((url) => {
      const submissionId = url.match(/\/project\/(\d+)/)[1];

      // Clear mailpit before admin action
      cy.clearMailpit();

      // Login as admin and mark as received
      cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
      cy.visit(`/webform/project/submissions/${submissionId}/edit`);

      // Check "Received" checkbox
      cy.get('input[name="approved_milestones"]').check();
      cy.get('input#edit-actions-01-submit').click();

      // Wait for email processing
      cy.wait(3000);

      // Check for author notification email
      cy.waitForEmail({
        to: 'authenticated@amptesting.com',
        subject: 'Project Submission Received: Test Project Received Email'
      }, 8000).then((message) => {
        cy.assertEmailContent(message, {
          subject: 'Project Submission Received: Test Project Received Email',
          to: 'authenticated@amptesting.com',
          htmlContains: ['Thank you for submitting your project to PA Science DMZ', 'Reference ID:', 'Notify PA Science DMZ Program Managers']
        });
      });
    });
  });

  it("Sends 'Project Updated' email to managers when author checks notification checkbox", () => {
    // First, create and receive a project
    cy.loginUser("authenticated@amptesting.com", "6%l7iF}6(4tI");
    cy.visit('/form/project');

    cy.get('input[name="project_title"]').type('Test Project Update Email');
    cy.get('input[name="project_leader[first]"]').type('Alice');
    cy.get('input[name="project_leader[last]"]').type('Johnson');
    cy.get('input[name="email"]').clear();
    cy.get('input[name="email"]').type('alice.johnson@test.com');
    cy.get('textarea[name="project_description"]').type('Test project for update email.');

    cy.get('input#edit-actions-submit').click();
    cy.url().should('include', '/project/');

    // Get submission ID and mark as received
    cy.url().then((url) => {
      const submissionId = url.match(/\/project\/(\d+)/)[1];

      cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
      cy.visit(`/webform/project/submissions/${submissionId}/edit`);
      cy.get('input[name="approved_milestones"]').check();
      cy.get('input#edit-actions-01-submit').click();

      // Clear mailpit before update action
      cy.clearMailpit();

      // Login back as author and update project with notification
      cy.loginUser("authenticated@amptesting.com", "6%l7iF}6(4tI");
      cy.visit(`/webform/project/submissions/${submissionId}/edit`);

      // Make a change and check the notification checkbox
      cy.get('textarea[name="project_description"]').clear();
      cy.get('textarea[name="project_description"]').type('Updated project description - ready for review.');
      cy.get('input[name="notify_managers_of_update"]').check();
      cy.get('input#edit-actions-01-submit').click();

      // Wait for email processing
      cy.wait(3000);

      // Check for manager notification email
      cy.waitForEmail({
        to: 'user+2000@localhost.localdomain',
        subject: 'Project Updated: Test Project Update Email'
      }, 8000).then((message) => {
        cy.assertEmailContent(message, {
          subject: 'Project Updated: Test Project Update Email',
          to: 'user+2000@localhost.localdomain',
          htmlContains: ['A project submission has been updated', 'alice.johnson@test.com', 'Alice Johnson']
        });
      });
    });
  });

  it("Sends 'Project Approved' email to author when admin approves and publishes project", () => {
    // First, create a project
    cy.loginUser("authenticated@amptesting.com", "6%l7iF}6(4tI");
    cy.visit('/form/project');

    cy.get('input[name="project_title"]').type('Test Project Approved Email');
    cy.get('input[name="project_leader[first]"]').type('Bob');
    cy.get('input[name="project_leader[last]"]').type('Williams');
    cy.get('input[name="email"]').clear();
    cy.get('input[name="email"]').type('bob.williams@test.com');
    cy.get('textarea[name="project_description"]').type('Test project for approval email.');

    cy.get('input#edit-actions-submit').click();
    cy.url().should('include', '/project/');

    // Get submission ID
    cy.url().then((url) => {
      const submissionId = url.match(/\/project\/(\d+)/)[1];

      // Clear mailpit before admin action
      cy.clearMailpit();

      // Login as admin and approve project
      cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
      cy.visit(`/webform/project/submissions/${submissionId}/edit`);

      // Check both "Received" and "Accept and Publish"
      cy.get('input[name="approved_milestones"]').check();
      cy.get('input[name="approved"]').check();
      cy.get('input#edit-actions-01-submit').click();

      // Wait for email processing
      cy.wait(3000);

      // Check for author notification email
      cy.waitForEmail({
        to: 'authenticated@amptesting.com',
        subject: 'Project Approved and Published: Test Project Approved Email'
      }, 8000).then((message) => {
        cy.assertEmailContent(message, {
          subject: 'Project Approved and Published: Test Project Approved Email',
          to: 'authenticated@amptesting.com',
          htmlContains: ['Great news! Your project has been approved', 'PA Science DMZ portal', 'View your published project']
        });
      });
    });
  });

  it("Sends emails when someone expresses interest in a recruiting project", () => {
    // First, create a recruiting project as admin
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit('/form/project');

    // Fill out recruiting project form
    cy.get('input[name="approved_milestones"]').check();
    cy.get('input[name="approved"]').check();
    cy.get('input[name="project_title"]').type('PA Science Recruiting Test');
    cy.get('input[name="tags[682]"]').check(); // Login tag (or any available tag)
    cy.get('select[name="status"]').select('Recruiting'); // Set to Recruiting status

    cy.get('input[name="project_leader[first]"]').type('Project');
    cy.get('input[name="project_leader[last]"]').type('Leader');
    cy.get('input[name="email"]').clear();
    cy.get('input[name="email"]').type('projectleader@test.com');
    cy.get('textarea[name="project_description"]').type('This is a recruiting project for PA Science');

    cy.get('input#edit-actions-01-submit').click();

    // Verify project was created with Recruiting status
    cy.url().should('include', '/project/');
    cy.contains('PA Science Recruiting Test');
    cy.contains('Recruiting');

    // Clear mailpit before someone expresses interest
    cy.clearMailpit();

    // Now login as different user and express interest
    cy.loginUser("authenticated@amptesting.com", "6%l7iF}6(4tI");
    cy.visit('/projects');

    // Search for the recruiting project
    cy.get('input[name="search"]').type('PA Science Recruiting Test');
    cy.wait(2000); // Wait for search results
    cy.contains('PA Science Recruiting Test').click();

    // Click "I'm interested" button (this triggers the flag event)
    cy.contains("I'm interested").click();

    // Wait for AJAX flag request to complete and button to update
    cy.contains('interested', { timeout: 10000 }).should('be.visible');

    // Give additional time for the flag event subscriber to process and send emails
    cy.wait(3000);

    // Debug: Log all emails that were sent
    cy.log('Checking all emails sent to Mailpit...');
    cy.getMailpitMessages().then((messages) => {
      cy.log(`Found ${messages ? messages.length : 0} total messages in Mailpit`);
      if (messages && messages.length > 0) {
        messages.forEach((msg, index) => {
          cy.log(`Email ${index + 1}: To: ${msg.To ? msg.To.map(t => t.Address).join(', ') : 'none'}, Subject: ${msg.Subject}`);
        });
      }
    });

    // Check for emails - subject format: "{{ title }} - interest shown on project"
    // Note: Emails go to the submission owner (administrator@amptesting.com), not the email field value

    // Check for project author notification email (submission owner)
    cy.waitForEmail({
      to: 'administrator@amptesting.com',
      subject: 'PA Science Recruiting Test - interest shown on project'
    }, 8000).then((message) => {
      cy.assertEmailContent(message, {
        subject: 'PA Science Recruiting Test - interest shown on project',
        to: 'administrator@amptesting.com',
        htmlContains: 'is interested in the project'
      });
    });

    // Check for PA Science Manager notification email
    cy.waitForEmail({
      to: 'user+2000@localhost.localdomain',
      subject: 'PA Science Recruiting Test - interest shown on project'
    }, 8000).then((message) => {
      cy.assertEmailContent(message, {
        subject: 'PA Science Recruiting Test - interest shown on project',
        to: 'user+2000@localhost.localdomain',
        htmlContains: 'is interested in the project'
      });
    });
  });

  it("Cleanup - Delete test projects", () => {
    // Login as admin to cleanup
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit('/admin/structure/webform/manage/project/results/submissions');

    // Delete test projects
    const testTitles = [
      'Test Project Creation Email',
      'Test Project Received Email',
      'Test Project Update Email',
      'Test Project Approved Email',
      'PA Science Recruiting Test',
      'PA Science Test Project' // From the other test file
    ];

    testTitles.forEach(title => {
      cy.get('body').then($body => {
        if ($body.text().includes(title)) {
          // Click the Delete link in the table
          cy.contains(title).closest('tr').find('a').contains('Delete').click({ force: true });
          // Click the Delete button in the jQuery UI dialog buttonpane
          cy.get('.ui-dialog-buttonpane button.button--primary').contains('Delete').click();
          cy.wait(1000); // Wait for deletion to complete
        }
      });
    });
  });
});
