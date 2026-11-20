/*
 * Cross-domain announcement exclusion test
 * This test verifies that announcements created on one domain do not appear on other domains
 * Runs from Campus Champions domain context and uses cy.origin() for ACCESS
 */

describe("Cross-Domain Announcement Exclusion Test", () => {
  
  it("Should verify announcements are properly isolated between domains", () => {
    const accessTitle = 'ACCESS-ONLY Test ' + Date.now();
    const ccTitle = 'CC-ONLY Test ' + Date.now();
    
    // Part 1: Create announcement on Campus Champions domain (current domain)
    cy.visit('/user/logout', { failOnStatusCode: false });
    cy.visit('/user');
    cy.get("#edit-name").type('administrator@amptesting.com');
    cy.get("#edit-pass").type('b8QW]X9h7#5n', { log: false });
    cy.get(".form-submit").contains("Log in").click();
    
    // Create Campus Champions announcement
    cy.visit('/node/add/access_news');
    cy.get('#edit-title-0-value').type(ccTitle);
    cy.get('#tag-ai').click();
    cy.get('#edit-body-0-summary').type('Summary for Campus Champions announcement');
    cy.get('.ck-editor__editable').type('This should only appear on Campus Champions domain.');
    cy.get('[name="moderation_state[0][state]"]').select('Published');
    cy.get('.node-access-news-form #edit-submit').click();
    cy.contains(`Announcement ${ccTitle} has been created.`);
    
    // Verify CC announcement appears on Campus Champions
    cy.visit('/announcements');
    cy.get('body').should('contain', ccTitle);
    cy.log(`✓ Campus Champions announcement "${ccTitle}" visible on Campus Champions domain`);
    
    // Part 2: Create announcement on ACCESS domain, verify isolation, and cleanup
    // All ACCESS operations are done in a single cy.origin() block to avoid spec bridge timeout issues
    cy.origin('https://accessmatch.ddev.site', { args: { accessTitle, ccTitle } }, ({ accessTitle, ccTitle }) => {
      // Handle uncaught exceptions within this origin
      Cypress.on('uncaught:exception', (err, runnable) => {
        if (err.message.includes('attachShadow') ||
            err.message.includes('Cannot read properties of null') ||
            err.message.includes('jQuery is not defined')) {
          return false;
        }
        return true;
      });

      // Visit the ACCESS login page directly
      cy.visit('/f64816be-34ca-4d5b-975a-687cb374ddf7');
      cy.get("#edit-name").type('administrator@amptesting.com');
      cy.get("#edit-pass").type('b8QW]X9h7#5n', { log: false });
      cy.get(".form-submit").contains("Log in").click();

      // Navigate to create announcement - use direct navigation since menu might not be available after login
      cy.visit('/node/add/access_news');

      // Create ACCESS announcement
      cy.get('#edit-title-0-value').type(accessTitle);
      cy.get('#edit-field-affiliation').select('ACCESS Collaboration');
      cy.get('#tag-ai').click();
      cy.get('#edit-body-0-summary').type('Summary for ACCESS announcement');
      cy.get('.ck-editor__editable').type('This should only appear on ACCESS domain.');
      cy.get('[name="moderation_state[0][state]"]').select('Published');
      cy.get('.node-access-news-form #edit-submit').click();
      cy.contains(`Announcement ${accessTitle} has been created.`);

      // Verify ACCESS announcement appears on ACCESS and CC announcement does NOT
      cy.get('a').contains('Announcements').click();
      cy.get('.layout__region--first').should('contain', accessTitle);
      cy.get('.layout__region--first').should('not.contain', ccTitle);
      cy.log(`✓ Verified ACCESS announcement visible and CC announcement NOT visible on ACCESS`);

      // Cleanup ACCESS announcement while still in this origin
      cy.contains(accessTitle).click();
      // Custom commands are not loaded inside cy.origin; the admin has the
      // Navigation top bar, so use its Edit button directly.
      cy.contains('.top-bar .top-bar__actions a.toolbar-button--primary', 'Edit').click();
      cy.get('#edit-delete').click();
      cy.get('.ui-dialog-buttonpane button.button--primary').click();
      cy.log(`✓ ACCESS announcement cleaned up`);
    });
    
    // Part 3: Back on Campus Champions - verify ACCESS announcement is NOT visible
    cy.visit('/announcements');
    cy.get('.layout__region--first').should('contain', ccTitle);
    cy.get('.layout__region--first').should('not.contain', accessTitle);
    cy.log(`✓ Verified ACCESS announcement is NOT visible on Campus Champions`);

    // Re-login on Campus Champions if needed (session may be lost after cy.origin)
    cy.visit('/user/login');
    cy.url().then((url) => {
      if (url.includes('/user/login')) {
        // Not logged in, need to login
        cy.get("#edit-name").type('administrator@amptesting.com');
        cy.get("#edit-pass").type('b8QW]X9h7#5n', { log: false });
        cy.get(".form-submit").contains("Log in").click();
      }
      // If redirected, user is already logged in
    });

    // Cleanup Campus Champions announcement (current domain)
    // Navigate to content admin and find the announcement there for more reliable deletion
    cy.visit('/admin/content');
    cy.get('input[name="title"]').type(ccTitle);
    cy.get('#edit-submit-content').click();
    cy.contains('a', ccTitle).parent().parent().find('input[type="checkbox"]').check();
    cy.get('#edit-action').select('node_delete_action');
    cy.get('.views-form #edit-submit').click();
    cy.get('#edit-submit').click(); // Confirm deletion (standalone confirm page)
    
    cy.log('✓ Cross-domain isolation test completed successfully');
  });
  
});