describe("Admin Notes on Mentorships", () => {

  it("Admin can add notes that are not visible to mentorship creator", () => {
    // First create a test mentorship to work with
    cy.loginWith("walnut@pie.org", "Walnut")
    cy.visit("/node/add/mentorship_engagement")

    // Verify that regular users cannot see the admin notes field when creating
    cy.get('#edit-field-admin-notes-0-value').should('not.exist');

    // Create a simple mentorship for testing
    cy.get("#edit-field-me-looking-for-mentor").check()
    cy.get("#edit-title-0-value").type("Admin Notes Test Mentorship")
    cy.get("#edit-body-0-summary").type("Test mentorship for admin notes")
    
    cy.get('.form-item-body-0-value .ck-content').then(el => {
      const editor = el[0].ckeditorInstance
      editor.setData('This is a test mentorship for testing admin-only notes functionality.')
    })

    cy.get("details.tags summary").click()
    cy.get("#tag-ai").click()
    cy.get('#edit-field-me-state').select('Recruiting')
    cy.get(".node-mentorship-engagement-form #edit-submit").click()

    // Admin approves the mentorship
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit("/admin/content?type=mentorship_engagement");
    cy.get('.view-content').contains('Admin Notes Test Mentorship').closest('tr').find('.edit a').click();
    cy.get('#edit-field-ccmnet-approved-value').scrollIntoView().check({force: true});
    cy.get('.node-mentorship-engagement-edit-form #edit-submit').click();

    // Now test the admin notes functionality
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    
    // Go directly to the mentorships admin page filtered by content type
    cy.visit("/admin/content?type=mentorship_engagement");
    
    // Find and edit the mentorship (Admin Notes Test Mentorship)
    cy.get('.view-content').contains('Admin Notes Test Mentorship').closest('tr').find('.edit a').click();
    
    // Verify we're on the correct edit page
    cy.url().should('include', '/edit');
    cy.get('body').should('contain', 'Admin Notes Test Mentorship');
    
    // Add admin notes to the field
    cy.get('#edit-field-admin-notes-0-value').should('exist').clear().type('This is an admin-only note that should not be visible to the mentorship creator');
    
    // Save the mentorship - use more generic form selector
    cy.get('.node-mentorship-engagement-edit-form #edit-submit').click();
    
    // Now login as the mentorship creator and verify they cannot see the admin note
    cy.loginWith("walnut@pie.org", "Walnut");
    cy.visit("/mentorships");
    
    // Navigate to the mentorship
    cy.contains('Admin Notes Test Mentorship').click();
    
    // Wait for the page to fully load
    cy.url().should('include', '/mentorships/');
    
    // Verify the admin note is not visible on the mentorship page
    cy.get('body').should('not.contain', 'This is an admin-only note that should not be visible to the mentorship creator');
    
    // Also check if there's an edit link for the creator and verify they don't see admin fields
    cy.get('body').should('exist'); // Ensure page is loaded
    cy.get('body').then($body => {
      if ($body.find('.top-bar .top-bar__actions a.toolbar-button--primary:contains("Edit")').length > 0) {
        cy.clickTopBarEdit();
        
        // Verify admin notes field is not visible to the creator
        cy.get('#edit-field-admin-notes-0-value').should('not.exist');
        
        // Verify the note content is not visible in any form fields
        cy.get('body').should('not.contain', 'This is an admin-only note');
      }
    });
  });


  afterEach(() => {
    // Cleanup - delete the test mentorship
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit("/admin/content?type=mentorship_engagement");
    
    cy.get('body').then($body => {
      if ($body.find('.view-content:contains("Admin Notes Test Mentorship")').length > 0) {
        cy.get('.view-content').contains('Admin Notes Test Mentorship').closest('tr').find('.dropbutton-toggle button').click();
        cy.get('.view-content').contains('Admin Notes Test Mentorship').closest('tr').find('.delete a').click();
        cy.get('.ui-dialog-buttonset .button--primary').contains('Delete').click();
      }
    });
  });
});