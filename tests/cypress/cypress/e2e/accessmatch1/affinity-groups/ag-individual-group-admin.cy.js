/**
 * Admin user tests the Individual Affinity Groups page.
 *
 * Edits the AG to add a ci-link & cider resource, and verifies those appear.
 * Verifies the see & contact members buttons appear.
 * Tests adding announcements via entity reference field.
 */
describe("Admin user tests the Individual Affinity Groups", () => {
  it("Should test the Individual Affinity Groups page", () => {

    cy.loginAs('administrator@amptesting.com', 'b8QW]X9h7#5n');

    // Clear search api index
    cy.visit("/admin/config/search/search-api/index/ci_links")
    cy.get("#edit-clear").click()
    cy.get("#edit-submit").click()

    // first create a dummy ci-links so can reference one of them in the AG.
    create_dummy_ci_link();

    // now edit the AG
    cy.visit("/affinity-groups/access-support");

    cy.get('.page-title').contains('ACCESS Support');

    // click the Edit tab in the tabs available to admins
    cy.get('.tabs')
      .contains("Edit")
      .should('have.attr', 'href', '/node/327/edit')
      .click();

    // Add a ci-link - this involves typing the title into the field, which
    // gets populated with options that have (nn) suffixes, then selecting the
    // first option the dropdown shows. See selectAutocompleteOption below for
    // why we wait on the menu and assert the resolved value.
    const ciLink0 = '#edit-field-resources-entity-reference-0-target-id';
    selectAutocompleteOption(ciLink0, 'access-support-ci-link-for-testing');

    if (!Cypress.$('[data-drupal-selector="edit-field-resources-entity-reference-1-target-id"]').length) {
      cy.get('#edit-field-resources-entity-reference-add-more').click();
      // The "Add another item" button triggers a Drupal AJAX rebuild; wait for
      // it to finish before interacting with the field it adds.
      cy.waitForDrupalSettle();
    }

    const ciLink1 = '[data-drupal-selector="edit-field-resources-entity-reference-1-target-id"]';
    selectAutocompleteOption(ciLink1, 'access-support-ci-link-for-testing');

    // Add a cider resource
    cy.get('#edit-field-cider-resources-0-target-id').clear();
    cy.get('#edit-field-cider-resources-0-target-id').type('UD DARWIN Storage (DARWIN Storage) (366)');

    // Set the slug to something unique
    cy.get('#edit-field-group-slug-0-value').clear().type('access-support');
    cy.get('#edit-field-group-id-0-value').clear().type('test.group.id');

    // submit changes
    cy.get('#edit-submit').click();

    // verify the added ci-link appears
    cy.get('.block-resources-for-affinity-group')
      .contains('Resources');

    cy.get('#ci-links')
      .contains('access-support-ci-link-for-testing')
      .should('have.attr', 'href')
      .and('contain', '/resources');

    // verify the added cider resource appears
    cy.get('.node--type-access-active-resources-from-cid')
      .contains('UD DARWIN Storage (DARWIN Storage)');

    // verify email AG button has correct href
    cy.get('.block-access-affinitygroup')
      .contains('Email Affinity Group')
      .should('have.attr', 'href')
      .and('contain', '/form/affinity-group-contact?nid=327');

    // verify View Memebers button is good - another test will verify the page
    cy.get('.view-id-affinity_group')
      .contains('View Members')
      .should('have.attr', 'href')
      .and('contain', '/affinity-groups/618/users/ACCESS Support');
  });
});

/**
 * Select an option from a Drupal entity-reference autocomplete field, reliably.
 *
 * The flaky part this guards against: cy.typeAutocomplete waits for the
 * autocomplete AJAX *response*, but jQuery UI renders the suggestion menu a tick
 * later. Sending {downarrow}{enter} immediately can land before the menu exists,
 * so nothing is selected. The field keeps the raw typed text (no "(nn)" id),
 * Drupal silently drops the unresolved reference on submit, and the resources
 * block then renders empty - making the test fail far away at the block
 * assertion instead of here.
 *
 * So we: wait for the menu to actually render, key-select the first option, then
 * assert the field resolved to a real entity (its value gains the "(nn)" id
 * suffix). If selection ever fails again it fails right here, at the cause.
 */
function selectAutocompleteOption(selector, text) {
  cy.get(selector).clear();
  cy.typeAutocomplete(selector, text);
  // Block until jQuery UI has rendered at least one suggestion.
  cy.get('.ui-autocomplete:visible li.ui-menu-item').should('have.length.greaterThan', 0);
  cy.get(selector).type('{downarrow}{enter}');
  // Confirm a real reference was selected before relying on it downstream.
  cy.get(selector).invoke('val').should('match', /\(\d+\)\s*$/);
}

// helper function to create a ci-link that can be added to the AG
function create_dummy_ci_link() {
  cy.visit('/form/resource');
  cy.get('#edit-approved').check();
  cy.get('#edit-title').type('access-support-ci-link-for-testing');
  cy.get('#edit-category').select('learning');
  cy.get('#edit-skill-level-304').check();  // beginner level
  cy.get('.form-item-description-html-value .ck-content').then(el => {
    const editor = el[0].ckeditorInstance
    editor.setData("Dummy description for ci-link 'access-support-ci-link-for-testing'")
  });
  // tag "ACCESS-account" is selected
  cy.get('span[data-tid="733"]').click();
  cy.get('.form-item-domain').find('input').type('ACCESS{enter}');
  cy.get('#edit-submit').click();
}

/**
 * Test adding announcements to affinity group via entity reference field.
 */
describe("Admin can add announcements to Affinity Group via entity reference", () => {
  const testAnnouncementTitle = 'AG Entity Reference Test Announcement';

  it("Should create an announcement and add it to the AG via entity reference", () => {
    cy.loginAs('administrator@amptesting.com', 'b8QW]X9h7#5n');

    // First create an announcement
    cy.visit("/node/add/access_news");
    cy.get("#edit-title-0-value").type(testAnnouncementTitle);
    // Select a tag (required field)
    cy.get('#tag-ai').click();
    cy.get('#edit-body-0-summary').type('Test summary for entity reference test');
    cy.get('.ck-editor__editable').type('This announcement tests the entity reference field on affinity groups.');
    cy.get("#edit-field-affiliation").select("Community");
    // Don't check "on the Announcements page" - we only want this to show via entity reference
    cy.get('input[name="field_choose_where_to_share_this[on_the_announcements_page]"]').uncheck();
    cy.get('[name="moderation_state[0][state]"]').select("Published");
    cy.get("#edit-submit").click();
    cy.contains(`Announcement ${testAnnouncementTitle} has been created.`);

    // Now edit the AG to add this announcement via entity reference
    cy.visit("/node/327/edit");

    // Find the field_affinity_announcements field and add the announcement.
    // selectAutocompleteOption waits for the suggestion menu and asserts the
    // reference resolved, so a missed selection fails here rather than later.
    const announcementField = '[data-drupal-selector="edit-field-affinity-announcements-0-target-id"]';
    selectAutocompleteOption(announcementField, testAnnouncementTitle);

    cy.get('#edit-submit').click();
    cy.contains('has been updated');

    // Verify the announcement appears in the News block on the AG page
    cy.visit("/affinity-groups/access-support");
    cy.get('.block-access-affinitygroup.block-affinity-bottom-left')
      .contains('News');
    cy.get('.block-access-affinitygroup.block-affinity-bottom-left')
      .contains(testAnnouncementTitle);
  });

  it("Should clean up the test announcement from AG", () => {
    cy.loginAs('administrator@amptesting.com', 'b8QW]X9h7#5n');

    // Remove the announcement from the AG
    cy.visit("/node/327/edit");
    cy.get('[data-drupal-selector="edit-field-affinity-announcements-0-target-id"]').clear();
    cy.get('#edit-submit').click();

    // Verify announcement no longer appears on AG page
    cy.visit("/affinity-groups/access-support");
    cy.get('.block-access-affinitygroup.block-affinity-bottom-left')
      .should('not.contain', testAnnouncementTitle);
  });
});


