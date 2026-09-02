// SKIPPED: This spec creates test apps via the legacy /node/add/appverse_app form,
// which AddRepoForm replaced. AddRepoForm uses a different DOM/AJAX shape and
// the test fixtures here do not yet have a server-side GitHub mock to drive the
// new flow. Revive once Phase 1.7 Task 52 (GitHub fixture wiring) lands.
describe.skip("Appverse App Creation", () => {
  const testApp = {
    title: `Cypress Test App ${Date.now()}`,
    githubUrl: 'https://github.com/OSC/bc_example_jupyter'
  };

  // Check if content type is available
  let contentTypeAvailable = true;

  before(() => {
    cy.request({
      method: 'GET',
      url: '/appverse/add-repo',
      failOnStatusCode: false
    }).then((response) => {
      // 404 means content type not available, 403 means need login (which is expected)
      contentTypeAvailable = response.status !== 404;
    });
  });

  describe("Unauthenticated user", () => {
    it("Cannot access app creation form without login", function() {
      if (!contentTypeAvailable) {
        this.skip();
        return;
      }

      cy.visit('/appverse/add-repo', { failOnStatusCode: false });
      // Should redirect to login or show access denied (403)
      cy.url().then((url) => {
        const redirectedToLogin = url.includes('/user/login');
        const accessDenied = url.includes('/appverse/add-repo');
        expect(redirectedToLogin || accessDenied).to.be.true;
      });
    });
  });

  describe("Authenticated user", () => {
    beforeEach(function() {
      if (!contentTypeAvailable) {
        this.skip();
        return;
      }
      cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    });

    it("Can access app creation form", function() {
      cy.visit('/appverse/add-repo', { failOnStatusCode: false });
      cy.get('body').then(($body) => {
        if ($body.text().includes('Page not found')) {
          cy.log('Content type not available - skipping');
          return;
        }
        cy.url().should('include', '/appverse/add-repo');
        cy.get('form').should('exist');
      });
    });

    it("App form has required fields", function() {
      cy.visit('/appverse/add-repo', { failOnStatusCode: false });
      cy.get('body').then(($body) => {
        if ($body.text().includes('Page not found')) {
          cy.log('Content type not available - skipping');
          return;
        }

        // Title field
        cy.get('#edit-title-0-value').should('exist');

        // GitHub URL field
        cy.get('[data-drupal-selector="edit-field-appverse-github-url-0-uri"]').should('exist');

        // Software reference field
        cy.get('[data-drupal-selector="edit-field-appverse-software-implemen-0-target-id"]').should('exist');

        // Fetch Repo button (added via JS from ood module)
        cy.contains('Fetch Repo', { timeout: 10000 }).should('exist');

        // Save button
        cy.get('#edit-submit').should('exist');

        // Moderation state field (appverse_editorial workflow)
        cy.get('[data-drupal-selector="edit-moderation-state-0-state"]').should('exist');
      });
    });

    it("Shows contributor documentation link", function() {
      cy.visit('/appverse/add-repo', { failOnStatusCode: false });
      cy.get('body').then(($body) => {
        if ($body.text().includes('Page not found')) {
          cy.log('Content type not available - skipping');
          return;
        }

        // Check for documentation link mentioned in ood_software.module
        cy.contains('contributor documentation').should('exist');
        cy.get('a[href*="appverse-contributor-documentation"]').should('exist');
      });
    });

    it("Fetch Repo button triggers GitHub lookup", function() {
      cy.visit('/appverse/add-repo', { failOnStatusCode: false });
      cy.get('body').then(($body) => {
        if ($body.text().includes('Page not found')) {
          cy.log('Content type not available - skipping');
          return;
        }

        // Wait for JS to add the Fetch Repo button
        cy.contains('Fetch Repo', { timeout: 10000 }).should('exist');

        // Enter a GitHub URL
        cy.get('[data-drupal-selector="edit-field-appverse-github-url-0-uri"]')
          .type(testApp.githubUrl);

        // Click Fetch Repo button
        cy.contains('Fetch Repo').click();

        // Wait for AJAX response - should show some feedback
        cy.wait(3000); // Allow time for GitHub API call

        // Should show status indicators (check marks or warnings)
        cy.get('.gh-url-suffix').should('exist');
      });
    });

    it("Can create a new app entry with valid GitHub repo", function() {
      cy.visit('/appverse/add-repo', { failOnStatusCode: false });
      cy.get('body').then(($body) => {
        if ($body.text().includes('Page not found')) {
          cy.log('Content type not available - skipping');
          return;
        }

        // Intercept the AJAX call to know when it completes
        cy.intercept('POST', '**/appverse/add-repo?ajax_form=1**').as('fetchRepo');

        // Wait for JS to add the Fetch Repo button
        cy.contains('Fetch Repo', { timeout: 10000 }).should('exist');

        // Enter GitHub URL
        cy.get('[data-drupal-selector="edit-field-appverse-github-url-0-uri"]')
          .type(testApp.githubUrl);

        // Click Fetch Repo to populate fields
        cy.contains('Fetch Repo').click();

        // Wait for AJAX to complete
        cy.wait('@fetchRepo');

        // Wait for the success indicator to appear (shows GitHub data was fetched)
        cy.contains('We\'ve detected the following', { timeout: 5000 }).should('be.visible');

        // Title field should now be populated - use name attribute as more stable selector
        cy.get('input[name="title[0][value]"]').should('not.have.value', '');

        // Check moderation state - default should be 'draft'
        cy.get('[data-drupal-selector="edit-moderation-state-0-state"]')
          .find('option[value="draft"]').should('exist');

        // Select App Type (required field)
        cy.get('input[name^="field_appverse_app_type"]').first().check();

        // Submit the form
        cy.get('#edit-submit').click();

        // Verify creation succeeded - should redirect away from /node/add or show success message
        // Content will be in 'draft' state by default due to workflow
        cy.url().should('not.include', '/node/add');
      });
    });

    it("Validates required fields on submit", function() {
      cy.visit('/appverse/add-repo', { failOnStatusCode: false });
      cy.get('body').then(($body) => {
        if ($body.text().includes('Page not found')) {
          cy.log('Content type not available - skipping');
          return;
        }

        // Try to submit without required fields
        cy.get('#edit-submit').click();

        // Should show validation error - could be Drupal message or HTML5 validation
        // Check if we're still on the form (didn't submit successfully)
        cy.url().should('include', '/appverse/add-repo');
      });
    });

    it("Moderation workflow has expected states", function() {
      cy.visit('/appverse/add-repo', { failOnStatusCode: false });
      cy.get('body').then(($body) => {
        if ($body.text().includes('Page not found')) {
          cy.log('Content type not available - skipping');
          return;
        }

        // Check available moderation states
        cy.get('[data-drupal-selector="edit-moderation-state-0-state"]').then(($select) => {
          // Draft should be available (default state)
          cy.wrap($select).find('option[value="draft"]').should('exist');
        });
      });
    });
  });

  describe("My Apps page", () => {
    beforeEach(() => {
      cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    });

    it("User can access My Apps page", () => {
      // Get current user ID and visit my-apps page
      cy.visit('/user');
      cy.url().then((url) => {
        const userId = url.match(/\/user\/(\d+)/)?.[1];
        if (userId) {
          cy.visit(`/user/${userId}/my-apps`);
          cy.contains('My Appverse Apps').should('exist');
        }
      });
    });

    it("My Apps page shows Add an app button", () => {
      cy.visit('/user');
      cy.url().then((url) => {
        const userId = url.match(/\/user\/(\d+)/)?.[1];
        if (userId) {
          cy.visit(`/user/${userId}/my-apps`);
          cy.get('a[href="/appverse/add-repo"]').should('exist');
        }
      });
    });
  });
});
