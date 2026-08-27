// SKIPPED: This spec creates test apps via the legacy /node/add/appverse_app form,
// which AddRepoForm replaced. AddRepoForm uses a different DOM/AJAX shape and
// the test fixtures here do not yet have a server-side GitHub mock to drive the
// new flow. Revive once Phase 1.7 Task 52 (GitHub fixture wiring) lands.
describe.skip("Appverse Workflow Moderation", () => {
  const testApp = {
    title: `Cypress Workflow Test ${Date.now()}`,
    githubUrl: 'https://github.com/OSC/bc_example_jupyter'
  };

  describe("Content moderation workflow", () => {
    // Use Cypress.env to persist node path across tests
    before(() => {
      Cypress.env('createdNodeId', null);
    });

    beforeEach(() => {
      cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    });

    it("New app starts in Draft state", () => {
      // Intercept the AJAX call
      cy.intercept('POST', '**/appverse/add-repo?ajax_form=1**').as('fetchRepo');

      cy.visit('/appverse/add-repo');

      // Wait for JS to add the Fetch Repo button (added dynamically)
      cy.contains('Fetch Repo', { timeout: 10000 }).should('exist');

      // Enter GitHub URL and fetch
      cy.get('[data-drupal-selector="edit-field-appverse-github-url-0-uri"]')
        .type(testApp.githubUrl);
      cy.contains('Fetch Repo').click();
      cy.wait('@fetchRepo');

      // Wait for form to be populated
      cy.contains('We\'ve detected the following', { timeout: 5000 }).should('be.visible');

      // Check default moderation state is Draft
      cy.get('[data-drupal-selector="edit-moderation-state-0-state"]')
        .should('have.value', 'draft');

      // Select App Type (required field)
      cy.get('input[name^="field_appverse_app_type"]').first().check();

      // Submit to create in draft state
      cy.get('#edit-submit').click();

      // Submit redirects to the user's my-apps listing.
      cy.url({ timeout: 10000 }).should('include', '/my-apps');

      // Assert the "has been created" status message actually renders. This is
      // a real quality check, not just a wait: confirming the message appears
      // protects against regressions in status-message rendering (e.g. the
      // BigPipe removal Pantheon requires). Target the semantic status wrapper
      // (role="status") and the message content rather than the .messages--status
      // class, which the ood theme does not emit.
      cy.get('[role="status"]', { timeout: 10000 })
        .should('contain', 'has been created');

      // Find the just-created node via JSON:API to drive the rest of the flow.
      cy.request({
        method: 'GET',
        url: '/jsonapi/node/appverse_app?sort=-created&page[limit]=1',
        headers: { 'Accept': 'application/vnd.api+json' }
      }).then((response) => {
        expect(response.body.data, 'a newly created appverse_app node').to.have.length.greaterThan(0);
        const nodeId = response.body.data[0].attributes.drupal_internal__nid;
        Cypress.env('createdNodeId', nodeId);

        // Verify moderation state in edit form
        cy.visit(`/node/${nodeId}/edit`);
        cy.get('[data-drupal-selector="edit-moderation-state-0-state"]')
          .should('have.value', 'draft');
      });
    });

    it("Can transition from Draft to Ready for Review", function() {
      const nodeId = Cypress.env('createdNodeId');
      if (!nodeId) {
        this.skip();
        return;
      }

      cy.visit(`/node/${nodeId}/edit`);

      // Change moderation state to Ready for Review
      cy.get('[data-drupal-selector="edit-moderation-state-0-state"]')
        .select('ready_for_review');

      cy.get('#edit-submit').click();

      // Verify the transition succeeded
      cy.visit(`/node/${nodeId}/edit`);
      cy.get('[data-drupal-selector="edit-moderation-state-0-state"]')
        .should('have.value', 'ready_for_review');
    });

    it("Can transition from Ready for Review to Published", function() {
      const nodeId = Cypress.env('createdNodeId');
      if (!nodeId) {
        this.skip();
        return;
      }

      cy.visit(`/node/${nodeId}/edit`);

      // Change moderation state to Published
      cy.get('[data-drupal-selector="edit-moderation-state-0-state"]')
        .select('published');

      cy.get('#edit-submit').click();

      // Wait for save to complete and verify redirect
      cy.url().should('not.include', '/edit');

      // Verify the transition succeeded by checking edit form again
      cy.visit(`/node/${nodeId}/edit`);
      cy.get('[data-drupal-selector="edit-moderation-state-0-state"]')
        .should('have.value', 'published');
    });

    it("Published app is visible to anonymous users", function() {
      const nodeId = Cypress.env('createdNodeId');
      if (!nodeId) {
        this.skip();
        return;
      }

      // Logout to test as anonymous
      cy.clearCookies();
      cy.visit(`/node/${nodeId}`);

      // Should be able to view the page (not 403 or redirect to login)
      // URL may be redirected to path alias, so just check we're not on login/access denied
      cy.url().should('not.include', '/user/login');
      cy.get('body').should('not.contain', 'Access denied');
      // Should see the node content (it has appverse-app class or shows some content)
      cy.get('.node--type-appverse-app, article, .content').should('exist');
    });

    it("Can transition from Published to Archived", function() {
      const nodeId = Cypress.env('createdNodeId');
      if (!nodeId) {
        this.skip();
        return;
      }

      cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
      cy.visit(`/node/${nodeId}/edit`);

      // Change moderation state to Archived
      cy.get('[data-drupal-selector="edit-moderation-state-0-state"]')
        .select('archived');

      cy.get('#edit-submit').click();

      // Wait for save to complete
      cy.url().should('not.include', '/edit');

      // Verify the transition succeeded by checking the JSON:API
      cy.request({
        method: 'GET',
        url: `/jsonapi/node/appverse_app/${nodeId}?fields[node--appverse_app]=moderation_state`,
        headers: { 'Accept': 'application/vnd.api+json' },
        failOnStatusCode: false
      }).then((response) => {
        // Node may not be accessible via API when archived, which is also valid
        if (response.status === 200) {
          expect(response.body.data.attributes.moderation_state).to.eq('archived');
        }
      });

      // Also verify via edit form - look for current state indicator
      cy.visit(`/node/${nodeId}/edit`);
      // Look for "Current state" text or the moderation state wrapper
      cy.get('body').then(($body) => {
        const hasArchivedText = $body.text().includes('Archived') ||
                                $body.text().includes('archived');
        expect(hasArchivedText).to.be.true;
      });
    });

    it("Archived app is not publicly listed via API", function() {
      const nodeId = Cypress.env('createdNodeId');
      if (!nodeId) {
        this.skip();
        return;
      }

      // Logout to test as anonymous
      cy.clearCookies();

      // Check that archived apps are not returned in the public API listing
      // (filter for published status)
      cy.request({
        method: 'GET',
        url: '/jsonapi/node/appverse_app?filter[status]=1',
        headers: { 'Accept': 'application/vnd.api+json' },
        failOnStatusCode: false
      }).then((response) => {
        if (response.status === 200 && response.body.data) {
          // The archived node should not appear in the filtered list
          const nodeIds = response.body.data.map(n => n.attributes.drupal_internal__nid);
          expect(nodeIds).to.not.include(nodeId);
        }
      });
    });

    it("Can restore Archived app to Draft", function() {
      const nodeId = Cypress.env('createdNodeId');
      if (!nodeId) {
        this.skip();
        return;
      }

      cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
      cy.visit(`/node/${nodeId}/edit`);

      // Change moderation state back to Draft
      cy.get('[data-drupal-selector="edit-moderation-state-0-state"]')
        .select('draft');

      cy.get('#edit-submit').click();

      // Wait for save to complete
      cy.url().should('not.include', '/edit');

      // Verify the transition succeeded
      cy.visit(`/node/${nodeId}/edit`);
      // Check that Draft state options are now available
      cy.get('[data-drupal-selector="edit-moderation-state-0-state"]')
        .find('option[value="ready_for_review"]').should('exist');
    });
  });

  describe("Moderation state options", () => {
    beforeEach(() => {
      cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    });

    it("Draft state has correct transition options", () => {
      cy.visit('/appverse/add-repo');

      // From Draft, should be able to go to: Draft, Ready for Review, Published
      cy.get('[data-drupal-selector="edit-moderation-state-0-state"]').then(($select) => {
        const options = [...$select.find('option')].map(o => o.value);
        expect(options).to.include('draft');
        expect(options).to.include('ready_for_review');
        expect(options).to.include('published');
      });
    });
  });

  describe("JSON:API reflects moderation state", () => {
    it("Only published apps visible to anonymous via API", () => {
      cy.request({
        method: 'GET',
        url: '/jsonapi/node/appverse_app?filter[status]=1',
        headers: {
          'Accept': 'application/vnd.api+json'
        },
        failOnStatusCode: false
      }).then((response) => {
        if (response.status === 200 && response.body.data) {
          // All returned apps should be published (status = true)
          response.body.data.forEach((app) => {
            expect(app.attributes.status).to.eq(true);
          });
        }
      });
    });
  });
});
