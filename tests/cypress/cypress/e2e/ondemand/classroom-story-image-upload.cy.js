/**
 * D8-2878: Classroom Story inline images whose filename contains a space.
 *
 * CKEditor 5 saves the uploaded image as
 * `src="/sites/default/files/inline-images/classroom%20story%20image.png"`.
 * The rel_to_abs filter used to re-encode that path to `...%2520...`, which
 * 404s. This uploads an image with a space in its name through the CKEditor 5
 * image upload button and confirms the rendered src stays single-encoded and
 * actually loads.
 */
describe("Classroom Story inline image upload", () => {
  const adminUser = {
    email: 'administrator@amptesting.com',
    password: 'b8QW]X9h7#5n',
  };

  const fixture = 'cypress/fixtures/classroom story image.png';
  const storyTitle = `Cypress Classroom Story Image ${Date.now()}`;

  let adminName;

  before(() => {
    // The required "Name" field is a user autocomplete; reference the admin.
    cy.exec(
      `ddev drush eval "\\$uids = \\Drupal::entityQuery('user')->condition('mail', '${adminUser.email}')->accessCheck(FALSE)->execute(); echo \\Drupal\\user\\Entity\\User::load(reset(\\$uids))->getAccountName();"`,
      { failOnNonZeroExit: false, timeout: 60000 }
    ).then((result) => {
      adminName = result.stdout.trim();
    });
  });

  it("Renders an uploaded image with a space in its filename", () => {
    cy.loginUser(adminUser.email, adminUser.password);
    cy.visit('/node/add/open_ondemand_classroom_stories');

    cy.get('#edit-title-0-value').type(storyTitle);
    cy.get('[data-drupal-selector="edit-field-oocs-name-0-target-id"]')
      .then(($input) => {
        expect(adminName, 'admin account name').to.not.be.empty;
        cy.wrap($input).type(adminName);
      });

    // Upload through the CKEditor 5 image upload button in the body field.
    cy.get('.form-item-body-0-value .ck-content').should('exist');
    cy.get('.form-item-body-0-value .ck-toolbar input[type="file"]')
      .first()
      .selectFile(fixture, { force: true });

    // Wait until the upload finishes and CKEditor has the server file URL.
    cy.get('.form-item-body-0-value .ck-content img', { timeout: 20000 })
      .should('have.attr', 'src')
      .and('include', 'inline-images/classroom%20story%20image');

    // CKEditor opens the alternative text balloon after an upload, and it
    // keeps focus until confirmed, so fill it in before saving the node.
    cy.get('.ck-balloon-panel_visible .ck-input-text')
      .type('Classroom story test image{enter}');
    cy.get('.form-item-body-0-value .ck-content img')
      .should('have.attr', 'alt', 'Classroom story test image');

    cy.get('#edit-submit').click();
    cy.url({ timeout: 30000 }).should('not.include', '/node/add');
    cy.contains(storyTitle).should('exist');

    // The rendered body image keeps a single level of encoding and loads.
    cy.get('img[src*="inline-images/classroom"]')
      .should('have.length.at.least', 1)
      .first()
      .then(($img) => {
        const src = $img.attr('src');
        expect(src).to.include('classroom%20story%20image');
        expect(src).to.not.include('%2520');
        cy.request(src).its('status').should('eq', 200);
      });

    cy.get('img[src*="inline-images/classroom"]')
      .first()
      .should(($img) => {
        expect($img[0].complete, 'image complete').to.be.true;
        expect($img[0].naturalWidth, 'image naturalWidth').to.be.greaterThan(0);
      });
  });

  after(() => {
    // Remove the story and the uploaded file entity.
    cy.exec(
      `ddev drush eval "` +
      `\\$storage = \\Drupal::entityTypeManager()->getStorage('node'); ` +
      `\\$nids = \\$storage->getQuery()->condition('title', '${storyTitle}')->accessCheck(FALSE)->execute(); ` +
      `\\$storage->delete(\\$storage->loadMultiple(\\$nids)); ` +
      `\\$fstorage = \\Drupal::entityTypeManager()->getStorage('file'); ` +
      `\\$fids = \\$fstorage->getQuery()->condition('filename', 'classroom story image%', 'LIKE')->accessCheck(FALSE)->execute(); ` +
      `\\$fstorage->delete(\\$fstorage->loadMultiple(\\$fids)); ` +
      `echo 'cleaned';"`,
      { failOnNonZeroExit: false, timeout: 60000 }
    );
  });
});
