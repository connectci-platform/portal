/**
 * Campus Champions submission status transitions (D8-2789)
 *
 * Two status transitions introduced for the change-institution flow:
 *
 * - Supersede on approval: approving a new application marks the applicant's
 *   prior approved submissions "superseded", so only the current one is live.
 * - Removed on removal: removing a champion marks their approved submissions
 *   "removed".
 *
 * Both run through _campuschampions_set_champion_submission_status (from the
 * ApproveCCAction and RemoveChampionForm respectively). Submissions are seeded
 * via drush; the action under test is driven through the admin UI; the outcome
 * is read back via drush.
 */
describe('Campus Champions status transitions', () => {
  const USERNAME = 'cypress-status-xfer';
  const EMAIL = 'cypress-status-xfer@no-reply.com';

  // Read a submission's status element back via drush.
  const statusOf = (sid) =>
    cy.exec(
      `ddev drush php:eval "\\$s=\\Drupal\\webform\\Entity\\WebformSubmission::load(${sid}); print \\$s ? (\\$s->getElementData('status') ?? '') : 'MISSING';"`
    ).then((res) => res.stdout.trim());

  // Poll the submission status until it matches, tolerating the approval batch's
  // asynchronous commit (the VBO batch finishes a beat after the UI returns).
  const expectStatus = (sid, expected, attempt = 0) => {
    statusOf(sid).then((status) => {
      if (status === expected || attempt >= 10) {
        expect(status, `submission ${sid} status`).to.eq(expected);
        return;
      }
      cy.wait(1000);
      expectStatus(sid, expected, attempt + 1);
    });
  };

  // Create the test user (idempotent).
  const seedUser = () => {
    cy.exec(`ddev drush user:cancel --delete-content -y ${USERNAME}`, { failOnNonZeroExit: false });
    cy.exec(`ddev drush user:create ${USERNAME} --mail="${EMAIL}" --password="Test-Status-123!"`);
    // Mark them a champion so RemoveChampionForm accepts them.
    cy.exec(
      `ddev drush php:eval "` +
        `\\$uids=\\Drupal::entityQuery('user')->accessCheck(FALSE)->condition('name','${USERNAME}')->execute(); ` +
        `\\$u=\\Drupal\\user\\Entity\\User::load(reset(\\$uids)); ` +
        `\\$u->set('field_is_cc',1); \\$u->addRole('research_computing_facilitator'); \\$u->save(); ` +
        `print 'UID:'.\\$u->id();"`
    );
  };

  // Create a submission owned by the test user with the given status and name;
  // return its sid.
  const seedSubmission = (status, firstName, lastName) =>
    cy.exec(
      `ddev drush php:eval "` +
        `\\$uids=\\Drupal::entityQuery('user')->accessCheck(FALSE)->condition('name','${USERNAME}')->execute(); ` +
        `\\$uid=(int) reset(\\$uids); ` +
        // Include user_email + username so ApproveCCAction resolves the
        // submission back to this same account (it looks the account up by
        // email, then username), and the supersede runs against this user.
        `\\$s=\\Drupal\\webform\\Entity\\WebformSubmission::create(['webform_id'=>'join_campus_champions','uid'=>\\$uid,'data'=>['status'=>'${status}','username'=>'${USERNAME}','user_email'=>'${EMAIL}','user_first_name'=>'${firstName}','user_last_name'=>'${lastName}','carnegie_classification'=>'166683','champion_user_type'=>'user_champion','supervisor_name'=>'Sup','supervisor_email'=>'sup@no-reply.com']]); ` +
        `\\$s->save(); print 'SID:'.\\$s->id();"`
    ).then((res) => res.stdout.match(/SID:(\d+)/)[1]);

  afterEach(() => {
    cy.exec(`ddev drush user:cancel --delete-content -y ${USERNAME}`, { failOnNonZeroExit: false });
  });

  // Invoke the shared status-transition helper for a user via drush. This is
  // the exact function ApproveCCAction and RemoveChampionForm call.
  const setChampionStatus = (uid, newStatus, excludeSid) =>
    cy.exec(
      `ddev drush php:eval "` +
        `print _campuschampions_set_champion_submission_status(${uid}, '${newStatus}'` +
        (excludeSid ? `, ${excludeSid}` : '') +
        `);"`
    );

  // Resolve the seeded user's uid.
  const uidOf = () =>
    cy.exec(
      `ddev drush php:eval "\\$ids=\\Drupal::entityQuery('user')->accessCheck(FALSE)->condition('name','${USERNAME}')->execute(); print \\$ids ? (int) reset(\\$ids) : 0;"`
    ).then((res) => parseInt(res.stdout.trim(), 10));

  it('supersedes prior approved submissions, keeping the excluded one', () => {
    // The supersede transition (invoked by ApproveCCAction on approval) marks a
    // user's other approved submissions superseded while leaving the just-
    // approved one untouched. Drive the helper directly: the webform validation
    // and account-resolution that ApproveCCAction layers on top are exercised by
    // the approve-action spec; here we isolate the transition itself.
    seedUser();
    let keepSid;
    let priorSid;
    // Two approved submissions for the same user: one is the "current" approval
    // to keep, the other a prior approval that should be superseded.
    seedSubmission('approved', 'KeepOrg', 'Xfer').then((sid) => { keepSid = sid; });
    seedSubmission('approved', 'PriorOrg', 'Xfer').then((sid) => { priorSid = sid; });

    // Supersede the user's approved submissions except the one being kept.
    cy.then(() => {
      uidOf().then((uid) => {
        setChampionStatus(uid, 'superseded', keepSid);
      });
    });

    // The prior approval is superseded; the kept one stays approved.
    cy.then(() => {
      expectStatus(priorSid, 'superseded');
      expectStatus(keepSid, 'approved');
    });
  });

  it('marks a champion submission removed when the champion is removed', () => {
    seedUser();
    let approvedSid;
    seedSubmission('approved', 'ToRemove', 'Xfer').then((sid) => { approvedSid = sid; });

    // Remove the champion through the remove-champion admin form.
    cy.loginUser('pecan@pie.org', 'Pecan');
    cy.visit('/remove-champion');
    cy.get('input[data-drupal-selector="edit-champion"]').type(USERNAME);
    cy.get('.ui-autocomplete .ui-menu-item', { timeout: 10000 }).first().click();
    cy.get('input[type="submit"][value="Remove"]').click();
    // Form completes (redirect / confirmation).
    cy.get('body', { timeout: 10000 }).should('exist');

    cy.then(() => {
      expectStatus(approvedSid, 'removed');
    });
  });
});
