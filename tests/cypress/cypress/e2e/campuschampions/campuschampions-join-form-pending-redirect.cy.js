/**
 * Join Campus Champions Form - pending-submission redirect (D8-2789)
 *
 * A non-admin who already has a pending (status "new") Join Campus Champions
 * submission is redirected from the blank form to edit that submission, so a
 * second application cannot stack while one is under review. An approved
 * champion (no pending submission) may open the blank form to start a fresh
 * application, for example to change organizations.
 *
 * Enforced by JoinFormRedirectSubscriber on the entity.webform.canonical route.
 *
 * The test seeds a user and a submission via drush, then drives the browser as
 * that user.
 */
describe('Join Campus Champions Form - pending redirect', () => {
  const USERNAME = 'cypress-pending-redirect';
  const PASSWORD = 'Test-Pending-123!';
  const EMAIL = 'cypress-pending-redirect@no-reply.com';

  // Seed the user and a pending submission owned by them; capture the sid.
  before(() => {
    cy.exec(`ddev drush user:cancel --delete-content -y ${USERNAME}`, { failOnNonZeroExit: false });
    cy.exec(
      `ddev drush user:create ${USERNAME} --mail="${EMAIL}" --password="${PASSWORD}"`,
      { failOnNonZeroExit: false }
    );
    // Create a status=new submission owned by the new user. Print the sid on a
    // line prefixed SID: so the test can read it back.
    cy.exec(
      `ddev drush php:eval "` +
        `\\$uids = \\Drupal::entityQuery('user')->accessCheck(FALSE)->condition('name','${USERNAME}')->execute(); ` +
        `\\$uid = \\$uids ? (int) reset(\\$uids) : 0; ` +
        `\\$s = \\Drupal\\webform\\Entity\\WebformSubmission::create(['webform_id'=>'join_campus_champions','uid'=>\\$uid,'data'=>['status'=>'new','user_first_name'=>'Pending','user_last_name'=>'Redirect']]); ` +
        `\\$s->save(); ` +
        `print 'SID:' . \\$s->id();"`
    ).then((res) => {
      const match = res.stdout.match(/SID:(\d+)/);
      expect(match, 'seeded submission sid').to.not.be.null;
      Cypress.env('pendingSid', match[1]);
    });
  });

  after(() => {
    cy.exec(`ddev drush user:cancel --delete-content -y ${USERNAME}`, { failOnNonZeroExit: false });
  });

  it('redirects a user with a pending submission to edit it', () => {
    cy.loginUser(USERNAME, PASSWORD);
    cy.visit('/form/join-campus-champions');
    // Landed on the submission edit page, not the blank add form.
    cy.location('pathname').should((path) => {
      expect(path).to.match(
        new RegExp(`/webform/join_campus_champions/submissions/${Cypress.env('pendingSid')}/edit`)
      );
    });
  });

  it('does not redirect once the submission is no longer pending', () => {
    // Flip the seeded submission to approved: no pending application remains, so
    // the user may open the blank form to start a fresh one.
    cy.exec(
      `ddev drush php:eval "` +
        `\\$s=\\Drupal\\webform\\Entity\\WebformSubmission::load(${'' + Cypress.env('pendingSid')}); ` +
        `if(\\$s){\\$s->setElementData('status','approved'); \\$s->save();}"`
    );
    cy.loginUser(USERNAME, PASSWORD);
    cy.visit('/form/join-campus-champions');
    // Stayed on the blank add form (no redirect to the submission edit page).
    cy.location('pathname').should('include', '/form/join-campus-champions');
    cy.contains('Join Campus Champions');
  });
});
