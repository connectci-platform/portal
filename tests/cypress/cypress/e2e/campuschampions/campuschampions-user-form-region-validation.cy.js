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
 * - administrator@amptesting.com edits authenticated_test_user (uid 68170)
 */
describe('User form - Campus Champions program membership guard', () => {
  const TEST_UID = 68170;
  const CC_OPTION = '572';

  // Seed the identity fields this user is missing so the form can POST and
  // reach the server-side validate handler (an empty required First/Last name
  // otherwise blocks submission client-side, never running our validation).
  before(() => {
    cy.exec(
      `ddev drush php:eval "\\$u=\\Drupal\\user\\Entity\\User::load(${TEST_UID}); if(\\$u){\\$u->set('field_user_first_name','Region'); \\$u->set('field_user_last_name','Tester'); \\$u->save();}"`,
      { failOnNonZeroExit: false }
    );
  });

  // Fill the remaining required fields on the user form, overriding is_cc per
  // test. Assumes First/Last name are already seeded (see before()).
  const prepareForm = ({ isCc }) => {
    cy.visit(`/user/${TEST_UID}/edit`);
    if (isCc) {
      cy.get('#edit-field-is-cc-value').check({ force: true });
    }
    else {
      cy.get('#edit-field-is-cc-value').uncheck({ force: true });
    }
    // Select Campus Champions in the multi-select Programs list.
    cy.get('select[name="field_region[]"]').select(CC_OPTION);
    // field_access_organization is a required entity autocomplete on this domain.
    cy.get('input[name="field_access_organization[0][target_id]"]')
      .clear()
      .type('Arkansas for Medical');
    cy.get('.ui-autocomplete .ui-menu-item', { timeout: 10000 })
      .contains('University of Arkansas for Medical Sciences').click();
  };

  beforeEach(() => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
  });

  // Leave the user without the Campus Champions program after each test so the
  // fixture stays clean regardless of which assertions ran.
  afterEach(() => {
    cy.exec(
      `ddev drush php:eval "\\$u=\\Drupal\\user\\Entity\\User::load(${TEST_UID}); if(\\$u){\\$u->set('field_is_cc',0); \\$u->set('field_region',[]); \\$u->save();}"`,
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
