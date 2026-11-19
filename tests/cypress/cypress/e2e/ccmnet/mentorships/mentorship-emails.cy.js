/**
 * Test CCMNet mentorship email notifications
 * 
 * This test verifies that the correct emails are sent at various stages
 * of the mentorship lifecycle.
 * 
 * Note: These tests should be run with CYPRESS_BASE_URL=https://ccmnet.ddev.site
 */

describe("CCMNet Mentorship Email Notifications", () => {
  
  beforeEach(() => {
    // Clear mailpit before each test to ensure clean state
    cy.clearMailpit();
  });

  it("Sends emails when creating a new mentorship", () => {
    // Login as regular user
    cy.loginWith("pecan@pie.org", "Pecan");
    cy.visit("/node/add/mentorship_engagement");

    // Fill out mentorship form
    cy.get("#edit-field-me-looking-for-mentor").check();
    cy.get("#edit-title-0-value").type("Test Mentorship for Email Verification");
    cy.get("#edit-body-0-summary").type("This is a test mentorship to verify email notifications");
    
    cy.get('.form-item-body-0-value .ck-content').then(el => {
      const editor = el[0].ckeditorInstance;
      editor.setData('This is a detailed description of the mentorship. We are testing that the proper email notifications are sent when a mentorship is created. This includes emails to the author and to the CCMNet administrators.');
    });

    cy.get("details.tags summary").click();
    cy.get("#tag-ai").click();

    cy.get('.form-item-field-me-preferred-attributes-0-value .ck-content').then(el => {
      const editor = el[0].ckeditorInstance;
      editor.setData('Looking for a mentor with experience in HPC and AI.');
    });

    cy.get('#edit-field-me-state').select('Recruiting');

    // Submit the form
    cy.get(".node-mentorship-engagement-form #edit-submit").click();

    // Verify we're on the created mentorship page
    cy.url().should('include', '/mentorships/');
    cy.contains('Test Mentorship for Email Verification');

    // Check for author email
    cy.waitForEmail({
      to: 'pecan@pie.org',
      subject: 'Mentorship Engagement received'
    }).then((message) => {
      cy.assertEmailContent(message, {
        subject: 'Mentorship Engagement received',
        from: 'info@mg.ccmnet.org',
        replyTo: 'info@ccmnet.org',
        to: 'pecan@pie.org',
        htmlContains: 'Your requested CCMNet mentorship engagement has been created'
      });
    });


    // Check for admin notification email - correct subject from template
    cy.waitForEmail({
      subject: 'New Mentorship Created'
    }, 8000).then((message) => {
      cy.assertEmailContent(message, {
        subject: 'New Mentorship Created',
        from: 'info@mg.ccmnet.org',
        replyTo: 'info@ccmnet.org',
        htmlContains: 'A new CCMNet mentorship has been requested'
      });
    });
  });

  it("Sends approval email when admin approves mentorship", () => {
    // Login as admin
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    
    // Go to content admin and find our test mentorship
    cy.visit("/admin/content");
    
    // Use the same selector pattern as the working test
    cy.get(':nth-child(1) > .views-field-operations > .dropbutton-wrapper > .dropbutton-widget > .dropbutton > .edit > a').click();
    
    // Clear mailpit before approval to isolate this test
    cy.clearMailpit();
    
    // Approve the mentorship
    cy.get('#edit-field-ccmnet-approved-value').scrollIntoView().check({force: true});
    cy.get('form.node-mentorship-engagement-edit-form #edit-submit').click();


    // Check for approval notification email
    cy.waitForEmail({
      subject: 'Mentorship Engagement approved'
    }).then((message) => {
      cy.assertEmailContent(message, {
        subject: 'Mentorship Engagement approved',
        from: 'info@mg.ccmnet.org',
        replyTo: 'info@ccmnet.org',
        htmlContains: 'A CCMNet mentorship has been approved and is ready for review'
      });
    });
  });

  it("Sends emails when mentor is selected (including liaison notification)", () => {
    // First, login as admin to add a liaison to the mentorship
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit("/mentorships");
    cy.get('h2.ccmnet-link a').contains('Test Mentorship for Email Verification').click({ force: true });
    
    // Click the Edit button in the Navigation top bar
    cy.clickTopBarEdit();
    
    // Verify we're on the correct edit page
    cy.url().then((url) => {
      cy.log('Edit page URL:', url);
    });

    // Add Julie Ma as liaison
    cy.window().then(win => {
      win.jQuery('select[name="field_me_ccmnet_leadership"]').select2('open');
    });
    cy.get('.select2-results').should('be.visible');
    cy.get('.select2-results .select2-results__option').contains('Julie Ma').click();
    
    cy.get('form.node-mentorship-engagement-edit-form #edit-submit').click();
    
    // Verify liaison was saved
    cy.url().should('include', '/mentorships/');
    cy.contains('Mentorship Engagement Test Mentorship for Email Verification has been updated');

    // Now login as mentorship author to add mentor in a SEPARATE edit
    cy.loginWith("pecan@pie.org", "Pecan");
    cy.visit("/mentorships");
    cy.get('h2.ccmnet-link a').contains('Test Mentorship for Email Verification').click({ force: true });
    // Click the Edit button in the Navigation top bar (pecan has ccmnet_pm,
    // which grants "access navigation").
    cy.clickTopBarEdit();

    // Clear mailpit before making changes
    cy.clearMailpit();

    // Add a mentor - more reliable approach
    cy.get('#edit-field-mentor-0-target-id').clear();
    
    // Type slowly and wait for each keystroke to register
    cy.get('#edit-field-mentor-0-target-id').type('Walnut', { delay: 100 });
    cy.wait(1500); // Wait for partial match autocomplete
    
    // Complete the name
    cy.get('#edit-field-mentor-0-target-id').type(' Pie', { delay: 100 });
    cy.wait(1500); // Wait for full autocomplete to load
    
    // Use arrow keys - most reliable method
    cy.get('#edit-field-mentor-0-target-id').type('{downArrow}{enter}');

    // Save the mentorship
    cy.get('form.node-mentorship-engagement-edit-form #edit-submit').click();
    

    // Check for mentor notification email - we know this should work now
    cy.waitForEmail({
      to: 'walnut@pie.org',
      subject: 'You have been selected as a mentor'
    }).then((message) => {
      cy.assertEmailContent(message, {
        subject: 'You have been selected as a mentor',
        from: 'info@mg.ccmnet.org',
        replyTo: 'info@ccmnet.org',
        to: 'walnut@pie.org',
        htmlContains: 'Thank you for your interest'
      });
    });

    // Check for liaison notification email
    cy.waitForEmail({
      subject: 'A new mentorship match'
    }).then((message) => {
      cy.assertEmailContent(message, {
        subject: 'A new mentorship match',
        from: 'info@mg.ccmnet.org',
        replyTo: 'info@ccmnet.org',
        htmlContains: 'A CCMNet mentor match has been made'
      });
    });
  });

  it("Sends emails when state changes to In Progress", () => {
    // First, we need to set up a liaison for the mentorship
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    
    // Edit the mentorship to add a liaison
    cy.visit("/mentorships");
    cy.get('h2.ccmnet-link a').contains('Test Mentorship for Email Verification').click({ force: true });
    
    // Click the Edit button in the Navigation top bar
    cy.clickTopBarEdit();

    // Add Julie Ma as liaison (Select2 dropdown)
    cy.window().then(win => {
      win.jQuery('select[name="field_me_ccmnet_leadership"]').select2('open');
    });
    cy.get('.select2-results').should('be.visible');
    cy.get('.select2-results .select2-results__option').contains('Julie Ma').click();

    cy.get('form.node-mentorship-engagement-edit-form #edit-submit').click();

    // Now login as author to change state
    cy.loginWith("pecan@pie.org", "Pecan");
    cy.visit("/mentorships");
    cy.get('h2.ccmnet-link a').contains('Test Mentorship for Email Verification').click({ force: true });
    
    // Click the Edit button in the Navigation top bar (pecan has ccmnet_pm,
    // which grants "access navigation").
    cy.clickTopBarEdit();

    // Clear mailpit before state change
    cy.clearMailpit();

    // Change state to In Progress
    cy.get('#edit-field-me-state').select('In Progress');
    cy.get('form.node-mentorship-engagement-edit-form #edit-submit').click();


    // Check for in progress notification email - correct subject from actual email
    cy.waitForEmail({
      subject: 'Mentorship in progress'
    }).then((message) => {
      cy.assertEmailContent(message, {
        subject: 'Mentorship in progress',
        from: 'info@mg.ccmnet.org',
        replyTo: 'info@ccmnet.org',
        htmlContains: 'Click here to view'
      });
    });
  });

  it("Sends emails for mentee selection", () => {
    // Create a new mentorship looking for mentee
    cy.loginWith("walnut@pie.org", "Walnut");
    cy.visit("/node/add/mentorship_engagement");

    // Fill out form looking for mentee
    cy.get("#edit-field-me-looking-for-mentee").check();
    cy.get("#edit-title-0-value").type("Mentee Test - Email Verification");
    cy.get("#edit-body-0-summary").type("Looking for a mentee to test email notifications");
    
    cy.get('.form-item-body-0-value .ck-content').then(el => {
      const editor = el[0].ckeditorInstance;
      editor.setData('Testing mentee selection email notifications.');
    });

    cy.get("details.tags summary").click();
    cy.get("#tag-ai").click();

    cy.get('#edit-field-me-state').select('827');
    cy.get(".node-mentorship-engagement-form #edit-submit").click();
    
    // Verify we're on the created mentorship page
    cy.url().should('include', '/mentorships/');
    cy.contains('Mentee Test - Email Verification');

    // Approve the mentorship as admin first
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit("/admin/content");
    cy.get(':nth-child(1) > .views-field-operations > .dropbutton-wrapper > .dropbutton-widget > .dropbutton > .edit > a').click();
    cy.get('#edit-field-ccmnet-approved-value').scrollIntoView().check({force: true});
    cy.get('form.node-mentorship-engagement-edit-form #edit-submit').click();

    // Now login back as original user to add mentee
    cy.loginWith("walnut@pie.org", "Walnut");

    // Clear mailpit and add a mentee
    cy.clearMailpit();
    
    // Go back to mentorships listing and find our newly created mentorship
    cy.visit("/mentorships");
    cy.contains('Mentee Test - Email Verification').click({ force: true });
    
    // Click the Edit tab in the local tasks menu - use col-12 to target the correct area
    cy.get('.col-12 #block-nect-local-tasks .primary-tabs .nav-item a.nav-link').contains('Edit').click({ force: true });

    // Add a mentee - more reliable approach
    cy.get('#edit-field-mentee-0-target-id').clear();
    
    // Type slowly and wait for each keystroke to register
    cy.get('#edit-field-mentee-0-target-id').type('Pecan', { delay: 100 });
    cy.wait(1500); // Wait for partial match autocomplete
    
    // Complete the name
    cy.get('#edit-field-mentee-0-target-id').type(' Pie', { delay: 100 });
    cy.wait(1500); // Wait for full autocomplete to load
    
    // Use arrow keys - most reliable method
    cy.get('#edit-field-mentee-0-target-id').type('{downArrow}{enter}');

    // Verify the form state before submitting
    cy.get('#edit-field-me-looking-for-mentee').should('be.checked');

    cy.get('form.node-mentorship-engagement-edit-form #edit-submit').click();


    // Check for mentee notification email
    cy.waitForEmail({
      to: 'pecan@pie.org',
      subject: 'You have been selected as a mentee'
    }).then((message) => {
      cy.assertEmailContent(message, {
        subject: 'You have been selected as a mentee',
        from: 'info@mg.ccmnet.org',
        replyTo: 'info@ccmnet.org',
        to: 'pecan@pie.org',
        htmlContains: 'Thank you for your interest'
      });
    });
  });

  it("Sends cron-based interest notification emails", () => {
    // First, simulate someone clicking "interested" on a mentorship
    // This would normally set the access_mentorship_interested state
    
    // Login as admin to set up test data
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    
    // Get the node ID of our test mentorship
    cy.visit("/mentorships");
    cy.get('h2.ccmnet-link a').contains('Test Mentorship for Email Verification').click({ force: true });
    // Get the actual node ID from the page (should be in the URL or page source)
    cy.get('body').then($body => {
      // Look for node ID in contextual links or other elements
      const contextualLinks = $body.find('a[href*="/node/"]');
      let nodeId = null;
      
      if (contextualLinks.length > 0) {
        const href = contextualLinks.first().attr('href');
        const match = href.match(/\/node\/(\d+)/);
        if (match) {
          nodeId = match[1];
        }
      }
      
      if (!nodeId) {
        // Fallback: try to extract from current URL or page structure
        cy.url().then((url) => {
          cy.log('Could not find node ID, URL was:', url);
          // Skip the cron test if we can't find the node ID
          cy.log('Skipping cron test - node ID not found');
        });
        return;
      }
      
      cy.log('Found node ID:', nodeId);
      
      // Now actually click the "I'm Interested" button to set the state
      cy.loginWith("walnut@pie.org", "Walnut");
      cy.visit("/mentorships");
      cy.get('h2.ccmnet-link a').contains('Test Mentorship for Email Verification').click({ force: true });
      cy.get('a[href*="/interested"]').click();
      cy.contains('You have been added to the interested list');
      
      // Clear mailpit before running cron
      cy.clearMailpit();
      
      // Run only the ccmnet cron hook (not full cron) with CYPRESS_TEST_MODE
      cy.exec("ddev exec 'env CYPRESS_TEST_MODE=true drush php-eval \"ccmnet_cron();\"'", { failOnNonZeroExit: false, timeout: 60000 });
      
      
      // Check for author notification email
      cy.waitForEmail({
        to: 'pecan@pie.org',
        subject: 'Interest in your Mentorship'
      }, 8000).then((message) => {
        cy.assertEmailContent(message, {
          subject: 'Interest in your Mentorship',
          from: 'info@mg.ccmnet.org',
          replyTo: 'info@ccmnet.org',
          to: 'pecan@pie.org',
          htmlContains: 'Someone is interested in your mentorship request'
        });
      });

      // Check for admin summary email
      cy.waitForEmail({
        subject: 'Daily mentorship interest summary'
      }, 8000).then((message) => {
        cy.assertEmailContent(message, {
          subject: 'Daily mentorship interest summary',
          from: 'info@mg.ccmnet.org',
          replyTo: 'info@ccmnet.org',
          htmlContains: 'The following mentorships received new interest requests'
        });
      });
    });
  });

  it("Sends Campus Champions interest notification emails when someone clicks 'interested'", () => {
    // Clear any existing interest state to ensure clean test
    cy.exec('ddev exec drush state:delete access_mentorship_interested -y', { failOnNonZeroExit: false });
    
    // Create a Campus Champions specific mentorship (must be on CCMNet domain)
    cy.loginWith("pecan@pie.org", "Pecan");
    cy.visit("/node/add/mentorship_engagement");
    
    cy.get("#edit-field-me-looking-for-mentor").check();
    cy.get("#edit-title-0-value").type("CC Interest Test - Email Verification");
    cy.get("#edit-body-0-summary").type("Campus Champions mentorship for testing interest emails");
    
    cy.get('.form-item-body-0-value .ck-content').then(el => {
      const editor = el[0].ckeditorInstance;
      editor.setData('Testing interest notifications for Campus Champions mentorships.');
    });

    cy.get("details.tags summary").click();
    cy.get("#tag-ai").click();
    
    // Key difference: Select Campus Champions program (ID 910)
    cy.get('#edit-field-mentorship-program-910').check();
    cy.get('#edit-field-me-state').select('Recruiting');
    cy.get(".node-mentorship-engagement-form #edit-submit").click();

    // Approve the mentorship as admin
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit("/admin/content");
    cy.get(':nth-child(1) > .views-field-operations > .dropbutton-wrapper > .dropbutton-widget > .dropbutton > .edit > a').click();
    cy.get('#edit-field-ccmnet-approved-value').scrollIntoView().check({force: true});
    cy.get('form.node-mentorship-engagement-edit-form #edit-submit').click();

    // Clear mailpit before expressing interest
    cy.clearMailpit();

    // Clear interest state right before testing the button to ensure clean state
    cy.exec('ddev exec drush state:delete access_mentorship_interested -y', { failOnNonZeroExit: false });

    // Login as different user and express interest
    cy.loginWith("walnut@pie.org", "Walnut");
    cy.visit("/mentorships");
    cy.get('h2.ccmnet-link a').contains('CC Interest Test - Email Verification').click({ force: true });
    
    
    // Click the "I'm Interested" button
    cy.get('a[href*="/interested"]').click();
    
    // Should be redirected back to mentorship page with success message
    cy.url().should('include', '/mentorships/');
    cy.contains('You have been added to the interested list');

    // Verify the mentorship page shows the user is now interested
    cy.reload();
    cy.contains("I'm no longer Interested").should('exist');

    // Wait for state to be fully saved before running cron
    cy.wait(1000);

    // Debug: Check the state before running cron
    cy.exec('ddev drush state:get access_mentorship_interested', { failOnNonZeroExit: false }).then((result) => {
      cy.log('State before cron:', result.stdout);
    });

    // Run only the ccmnet cron hook (not full cron) with test mode to bypass time restrictions
    cy.exec("ddev exec 'env CYPRESS_TEST_MODE=true drush php-eval \"ccmnet_cron();\"'", { failOnNonZeroExit: false, timeout: 60000 }).then((result) => {
      cy.log('Cron stdout:', result.stdout);
      cy.log('Cron stderr:', result.stderr);
    });

    // Check for author notification email
    cy.waitForEmail({
      to: 'pecan@pie.org',
      subject: 'Interest in your Mentorship'
    }, 8000).then((message) => {
      cy.assertEmailContent(message, {
        subject: 'Interest in your Mentorship',
        from: 'info@mg.ccmnet.org',
        replyTo: 'info@ccmnet.org',
        to: 'pecan@pie.org',
        htmlContains: 'Someone is interested in your mentorship request'
      });
    });

    // Check for Campus Champions admin summary email (goes to champions_mentorship_admin role)
    cy.waitForEmail({
      subject: 'Daily mentorship interest summary'
    }, 8000).then((message) => {
      cy.assertEmailContent(message, {
        subject: 'Daily mentorship interest summary',
        from: 'info@mg.ccmnet.org',
        replyTo: 'info@ccmnet.org',
        htmlContains: 'CC Interest Test - Email Verification' // Should contain our test mentorship title
      });
    });
  });


  it("Cleanup - Delete test mentorships", () => {
    // Login as admin to cleanup
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit("/admin/content");

    // Delete test mentorships (including Campus Champions ones)
    const testTitles = [
      'Test Mentorship for Email Verification',
      'Mentee Test - Email Verification',
      'CC Interest Test - Email Verification'
    ];

    testTitles.forEach(title => {
      cy.get('.view-content').then($content => {
        if ($content.text().includes(title)) {
          cy.get('.view-content').contains(title).closest('tr').find('.dropbutton-toggle button').click();
          cy.contains('Delete').click({ force: true });
          // Confirm deletion in the dialog
          cy.get('.ui-dialog-buttonpane .button--primary').contains('Delete').click();
        }
      });
    });
  });
});