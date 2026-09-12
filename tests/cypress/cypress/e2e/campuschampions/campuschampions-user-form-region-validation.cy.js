/**
 * User form - Campus Champions program requires membership
 *
 * Selecting "Campus Champions" in a user's Programs (field_region) without the
 * is_cc flag set is blocked with a message pointing at the join form. Enforced
 * by the user form's validate handler (campuschampions form_alter attaches it
 * to #validate).
 *
 * Asserts the validation wiring stays intact — the handler is referenced by
 * string name in #validate, so a rename that misses a reference silently
 * disables this check.
 *
 * Edits an existing regular user as an administrator:
 * - administrator@amptesting.com edits authenticated_test_user
 */
describe('User form - Campus Champions program membership guard', () => {
  const CC_OPTION = '572';

  // Resolve the fixture user's uid by name — it is assigned at fixture-build
  // time and is not stable across DB builds, so it must not be hardcoded.
  before(() => {
    cy.exec(
      `ddev drush php:eval "\\$u = user_load_by_name('authenticated_test_user'); print \\$u ? \\$u->id() : 0;"`
    ).then((res) => {
      const uid = parseInt(res.stdout.trim(), 10);
      expect(uid, 'authenticated_test_user uid').to.be.greaterThan(0);
      Cypress.env('testUid', uid);
    });
  });

  // Toggle is_cc and select the Campus Champions program, then submit. The
  // authenticated_test_user fixture already has a name and organization (set in
  // amp_dev), so the form's required identity/org fields are satisfied without
  // this spec mutating the shared user.
  const prepareForm = ({ isCc }) => {
    cy.visit(`/user/${Cypress.env('testUid')}/edit`);
    if (isCc) {
      cy.get('#edit-field-is-cc-value').check({ force: true });
    }
    else {
      cy.get('#edit-field-is-cc-value').uncheck({ force: true });
    }
    // Select Campus Champions in the multi-select Programs list.
    cy.get('select[name="field_region[]"]').select(CC_OPTION);
  };

  beforeEach(() => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
  });

  // Leave the user without the Campus Champions program after each test so the
  // fixture stays clean regardless of which assertions ran.
  afterEach(() => {
    cy.exec(
      `ddev drush php:eval "\\$u=user_load_by_name('authenticated_test_user'); if(\\$u){\\$u->set('field_is_cc',0); \\$u->set('field_region',[]); \\$u->save();}"`,
      { failOnNonZeroExit: false }
    );
  });

  it('blocks selecting the Campus Champions program when is_cc is not set', () => {
    prepareForm({ isCc: false });
    cy.get('#edit-submit').click();

    cy.contains('Please join the Campus Champions by submitting', { timeout: 30000 })
      .should('be.visible');
  });

  it('allows the Campus Champions program when is_cc is set', () => {
    prepareForm({ isCc: true });
    cy.get('#edit-submit').click();

    // Saved: the guard message is absent and Drupal shows the saved notice.
    cy.contains('Please join the Campus Champions by submitting').should('not.exist');
    cy.contains('The changes have been saved', { timeout: 30000 }).should('exist');
  });
});
