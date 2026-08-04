/**
 * Campus Champions Applications Search Filter (D8-2789)
 *
 * The /cc-applications admin view has a single exposed "Search" box that
 * matches a term against the applicant first name, last name, or email at
 * once. This lets an admin find an applicant directly instead of paging or
 * using the duplicate-names toggle.
 *
 * The filter is a custom views handler (campuschampions_applicant_search)
 * that ORs the term across the configured submission elements in one subquery.
 *
 * Access requires: administrator OR campuschampionsadmin role.
 * Runs on the campus champions domain (CYPRESS_BASE_URL=campuschampions).
 *
 * Test users:
 * - pecan@pie.org / Pecan - has campuschampionsadmin role
 */
describe('Campus Champions Applications Search', () => {
  // Two distinct applicants so search can prove it narrows to one and excludes
  // the other. Names/emails are deliberately unusual to avoid collisions with
  // seeded fixture data ("User", "Test", etc.).
  const alpha = {
    username: 'cypress-search-zollarge',
    email: 'zolarge-search@no-reply.com',
    first: 'Zolarge',
    last: 'Quibblesnout',
  };
  const beta = {
    username: 'cypress-search-vexmoot',
    email: 'vexmoot-search@no-reply.com',
    first: 'Vexmoot',
    last: 'Thrangle',
  };

  // Submit the join form to create one application.
  const seedApplication = (person) => {
    cy.visit('/form/join-campus-champions');
    cy.get('#edit-letter-of-collaboration-upload')
      .selectFile('cypress/files/northern-lights.pdf');
    cy.contains('Remove', { timeout: 10000 });
    cy.get('input[name="username"]').type(person.username);
    cy.get('input[name="user_first_name"]').type(person.first);
    cy.get('input[name="user_last_name"]').type(person.last);
    cy.get('input[name="user_email"]').type(person.email);
    cy.get('#edit-champion-user-type-user-champion').check();
    cy.get('input[name="supervisor_name"]').type('Test Supervisor');
    cy.get('input[name="supervisor_email"]').type('supervisor@test.com');
    cy.get('input[name="field_access_organization"]').type('Arkansas for Medical');
    cy.get('.ui-autocomplete .ui-menu-item', { timeout: 10000 })
      .contains('University of Arkansas for Medical Sciences').click();
    cy.get('#webform-submission-join-campus-champions-add-form > #edit-actions > #edit-submit').click();
    cy.contains('Campus Champions Application Submitted', { timeout: 30000 });
  };

  before(() => {
    // Clean up any leftovers from a prior run, then seed both applicants.
    cy.exec(`ddev drush user:cancel --delete-content -y ${alpha.username}`, { failOnNonZeroExit: false });
    cy.exec(`ddev drush user:cancel --delete-content -y ${beta.username}`, { failOnNonZeroExit: false });
    seedApplication(alpha);
    seedApplication(beta);
  });

  after(() => {
    cy.exec(`ddev drush user:cancel --delete-content -y ${alpha.username}`, { failOnNonZeroExit: false });
    cy.exec(`ddev drush user:cancel --delete-content -y ${beta.username}`, { failOnNonZeroExit: false });
  });

  beforeEach(() => {
    cy.loginUser('pecan@pie.org', 'Pecan');
  });

  it('renders the search box in the exposed form', () => {
    cy.visit('/cc-applications');
    cy.get('input[name="search"]').should('exist').and('be.visible');
  });

  it('finds an applicant by last name and excludes non-matches', () => {
    cy.visit(`/cc-applications?search=${alpha.last}`);
    cy.get('tbody').should('contain', alpha.last);
    cy.get('tbody').should('not.contain', beta.last);
  });

  it('finds an applicant by first name', () => {
    cy.visit(`/cc-applications?search=${beta.first}`);
    cy.get('tbody').should('contain', beta.last);
    cy.get('tbody').should('not.contain', alpha.last);
  });

  it('finds an applicant by email fragment', () => {
    // Search a distinctive slice of the email local-part.
    cy.visit('/cc-applications?search=vexmoot-search');
    cy.get('tbody').should('contain', beta.last);
    cy.get('tbody').should('not.contain', alpha.last);
  });

  it('is case-insensitive', () => {
    cy.visit(`/cc-applications?search=${alpha.last.toLowerCase()}`);
    cy.get('tbody').should('contain', alpha.last);
  });

  it('returns no rows for a term that matches nobody', () => {
    cy.visit('/cc-applications?search=zzqx_nobody_9999');
    cy.get('tbody').should('not.contain', alpha.last);
    cy.get('tbody').should('not.contain', beta.last);
  });

  it('returns the full result set when the search box is empty', () => {
    // With no term, both seeded applicants are present (subject to the status
    // filter default, which includes "new" — freshly seeded apps are new).
    cy.visit('/cc-applications');
    cy.get('tbody').should('contain', alpha.last);
    cy.get('tbody').should('contain', beta.last);
  });
});
