/*
  Cypress coverage for the three event-crud registrant notification emails:
  cancelled / rescheduled / reinstated. Each test creates its OWN occurrence
  (beforeEach) so the tests do not collide on moderation_state. Notifications
  are queued and drained via `drush queue:run`, then asserted in Mailpit.
  Prerequisite: the birth-publish contrib patch must be applied so a Published
  series births a Published, registerable instance.
*/

const DRAIN = 'ddev drush queue:run recurring_events_registration_email_notifications_queue_worker';
const ADMIN = ['administrator@amptesting.com', 'b8QW]X9h7#5n'];
const WALNUT = ['walnut@pie.org', 'Walnut'];
const SERIES_TITLE = 'cypress-notification-test-event';

// NOTE: the supersede and past-gating tests assert exact email COUNTS / absence
// over the whole Mailpit store (there is no per-test namespacing), relying on
// clearMailpit having emptied it. This is only sound with retries disabled — a
// retried happy-path test would re-run beforeEach and re-drain, breaking the
// count logic. The project sets no `retries` (default 0); keep it that way.
describe('Event registrant notification emails', () => {
  let seriesId;
  let instanceId;

  before(() => {
    // Create a published, registration-enabled event series via the operator UI.
    cy.loginAs(...ADMIN);
    cy.visit('/events/add');
    cy.get('#edit-title-0-value').type(SERIES_TITLE, { delay: 0 });
    // Body is a required CKEditor field — set via the editor instance (a plain
    // .type() does not work on CKEditor); an empty body blocks form submit.
    cy.get('.field--name-body .ck-content').then((el) => {
      el[0].ckeditorInstance.setData('Notification coverage event body.');
    });
    cy.get('#edit-summary-text').type('Notification coverage event.', { delay: 0 });
    // Select the custom recurrence radio FIRST — the custom-date fields are hidden
    // behind a #states visibility condition on recur_type=custom until this is clicked.
    cy.get('#edit-recur-type-custom').click();
    cy.get('#edit-custom-date-0-value-date').type('2027-09-01', { delay: 0 });
    cy.get('#edit-custom-date-0-end-value-date').type('2027-09-01', { delay: 0 });
    cy.get('#edit-custom-date-0-value-time').type('10:00:00', { delay: 0 });
    cy.get('#edit-custom-date-0-end-value-time').type('11:00:00', { delay: 0 });
    cy.get('#edit-field-location-0-value').type('Zoom', { delay: 0 });
    cy.get('#edit-field-contact-0-value').type('Pecan Pie', { delay: 0 });
    cy.get('input[data-drupal-selector="edit-event-registration-0-registration"]').check();
    cy.get('#edit-event-registration-0-capacity').type('50', { delay: 0 });
    cy.get('#edit-moderation-state-0-state').select('Published');
    cy.get('#edit-field-event-type-training').click();
    cy.get('#edit-field-affiliation-community').click();
    cy.get('#edit-field-skill-level-advanced').click();
    cy.get('#edit-submit').click();
    // Capture the series id from the resulting URL (/events/series/{id}).
    cy.url().then((url) => {
      const m = url.match(/\/events\/series\/(\d+)/);
      expect(m, 'series id in URL').to.not.be.null;
      seriesId = m[1];
    });
    // No reindex needed: walnut registers by DIRECT instance URL (below), not via
    // the /events fulltext search, so the search index is irrelevant to this spec.
  });

  beforeEach(() => {
    cy.clearMailpit();
    // Fresh occurrence on the series so each test starts from a clean published instance.
    cy.loginAs(...ADMIN);
    cy.visit(`/events/series/${seriesId}/add`);
    cy.get('#edit-date-0-value-date').clear().type('2027-09-15', { delay: 0 });
    cy.get('#edit-date-0-end-value-date').clear().type('2027-09-15', { delay: 0 });
    cy.get('#edit-date-0-value-time').clear().type('10:00:00', { delay: 0 });
    cy.get('#edit-date-0-end-value-time').clear().type('11:00:00', { delay: 0 });
    cy.get('#edit-submit').click();
    // Capture the new instance id from the resulting URL (/events/{id}), then do
    // all instanceId-dependent steps INSIDE the .then — a template literal like
    // `/events/${instanceId}` is evaluated when the command is enqueued, which is
    // before this .then runs, so referencing instanceId outside the .then reads
    // undefined. Register walnut within the same .then chain.
    cy.url().then((url) => {
      const m = url.match(/\/events\/(\d+)(?:$|\?)/);
      expect(m, 'instance id in URL').to.not.be.null;
      instanceId = m[1];
      // The Add-Instance form is the HAND-AUTHORED path, so the occurrence is born
      // DRAFT (by design) — invisible to walnut (a non-admin) → 403 on register.
      // Publish it as admin first so it's a live, registerable occurrence.
      cy.visit(`/events/${instanceId}/edit`);
      cy.get('#edit-moderation-state-0-state').select('Published');
      cy.get('#edit-submit').click();
      // Register walnut on this now-published occurrence via the operator UI.
      // Capacity is 50, so this is a direct (non-waitlist) registration — the
      // success message is the custom approval string, NOT the waitlist one.
      cy.loginAs(...WALNUT);
      cy.visit(`/events/${instanceId}`);
      cy.get('#block-asptheme-eventinstancesidebar').contains('Register').click();
      cy.contains('Please confirm your registration below');
      cy.get('#edit-submit').click();
      cy.contains('You will receive an email after your registration is approved');
    });
  });

  after(() => {
    // Idempotency + no cross-spec pollution: delete every series this spec created
    // (matched by title, so prior orphaned runs are also swept), so re-runs don't
    // accumulate same-titled series and the 2020-dated occurrence does not linger in
    // the shared accessmatch3 CI run's /events index. Registrants MUST be deleted
    // FIRST — EventDeleteGuard refuses to delete a series that still has registrations
    // (by design: cancel-not-delete). So this deletes registrant rows on each
    // occurrence, THEN the series.
    cy.exec(
      `ddev drush php:eval "` +
        `\\$series = \\Drupal::entityTypeManager()->getStorage('eventseries')->loadByProperties(['title' => '${SERIES_TITLE}']); ` +
        `\\$rs = \\Drupal::entityTypeManager()->getStorage('registrant'); ` +
        `foreach (\\$series as \\$s) { ` +
        `foreach (\\$s->get('event_instances')->referencedEntities() as \\$inst) { ` +
        `foreach (\\$rs->loadByProperties(['eventinstance_id' => \\$inst->id()]) as \\$r) { \\$r->delete(); } } ` +
        `\\$s->delete(); }"`,
      { failOnNonZeroExit: false, timeout: 120000 },
    );
    cy.exec('ddev drush search-api:index events --batch-size=50', { failOnNonZeroExit: false, timeout: 120000 });
  });

  it('sends an Event Cancelled email when an occurrence is archived', () => {
    cy.loginAs(...ADMIN);
    cy.visit(`/events/${instanceId}/edit`);
    cy.get('#edit-moderation-state-0-state').select('Archived');
    cy.get('#edit-submit').click();
    cy.exec(DRAIN, { failOnNonZeroExit: false, timeout: 120000 });
    cy.waitForEmail({ to: WALNUT[0], subject: 'Event Cancelled:' }).then((message) => {
      cy.assertEmailContent(message, {
        subject: 'Event Cancelled:',
        bodyContains: ['has been cancelled', 'your registration has been kept'],
      });
    });
  });

  it('sends an Event Rescheduled email when a published occurrence date changes', () => {
    cy.loginAs(...ADMIN);
    cy.visit(`/events/${instanceId}/edit`);
    // Change the date; keep moderation state on Published (the publish self-transition).
    cy.get('#edit-date-0-value-date').clear().type('2027-10-20', { delay: 0 });
    cy.get('#edit-date-0-end-value-date').clear().type('2027-10-20', { delay: 0 });
    cy.get('#edit-moderation-state-0-state').select('Published');
    cy.get('#edit-submit').click();
    cy.exec(DRAIN, { failOnNonZeroExit: false, timeout: 120000 });
    cy.waitForEmail({ to: WALNUT[0], subject: 'Event Rescheduled:' }).then((message) => {
      cy.assertEmailContent(message, {
        subject: 'Event Rescheduled:',
        // The delete-url link text is unique to the reschedule email.
        bodyContains: ['has been rescheduled to', 'you can cancel your registration here:'],
      });
    });
  });

  it('sends an Event Reinstated email when an archived occurrence is republished', () => {
    // Archive first (this queues a cancel notice; drain+clear so it does not pollute the assert).
    cy.loginAs(...ADMIN);
    cy.visit(`/events/${instanceId}/edit`);
    cy.get('#edit-moderation-state-0-state').select('Archived');
    cy.get('#edit-submit').click();
    cy.exec(DRAIN, { failOnNonZeroExit: false, timeout: 120000 });
    cy.clearMailpit();
    // Now reinstate: archived -> published.
    cy.visit(`/events/${instanceId}/edit`);
    cy.get('#edit-moderation-state-0-state').select('Published');
    cy.get('#edit-submit').click();
    cy.exec(DRAIN, { failOnNonZeroExit: false, timeout: 120000 });
    cy.waitForEmail({ to: WALNUT[0], subject: 'Event Reinstated:' }).then((message) => {
      cy.assertEmailContent(message, {
        subject: 'Event Reinstated:',
        bodyContains: ['is back on', 'scheduled for'],
      });
    });
  });

  it('supersedes a stale reinstate notice: double-reinstate sends exactly one Event Reinstated', () => {
    cy.loginAs(...ADMIN);
    const setState = (state) => {
      cy.visit(`/events/${instanceId}/edit`);
      cy.get('#edit-moderation-state-0-state').select(state);
      cy.get('#edit-submit').click();
    };
    // NO drain between these — the supersede sweep only removes UNCLAIMED (undrained) items.
    setState('Archived'); // cancel #1 queued
    setState('Published'); // reinstate #1 queued
    setState('Archived'); // cancel #2 queued
    setState('Published'); // reinstate #2 queued; sweep removes the unclaimed reinstate #1
    // One drain at the end.
    cy.exec(DRAIN, { failOnNonZeroExit: false, timeout: 120000 });
    // Exactly one Event Reinstated email (the first reinstate notice was superseded).
    cy.searchMailpitMessages({ to: WALNUT[0], subject: 'Event Reinstated:' }).then((messages) => {
      expect(messages.length, 'exactly one Event Reinstated email').to.equal(1);
    });
    // Pair-check: cancels are NEVER superseded, so both cancel notices survive.
    // Asserting two Event Cancelled emails guards against a false "1 reinstate"
    // that is actually "0 reinstate + 1 stray" — it proves the queue drained the
    // full expected sequence, not a coincidental single match.
    cy.searchMailpitMessages({ to: WALNUT[0], subject: 'Event Cancelled:' }).then((messages) => {
      expect(messages.length, 'two Event Cancelled emails (never superseded)').to.equal(2);
    });
  });

  it('does not email when a verifiably-past occurrence is cancelled', () => {
    // Set the occurrence end to the past via SQL BEFORE loading the edit form
    // (loading the form first would rewrite the future date on submit).
    const past = '2020-01-01';
    cy.exec(`ddev drush sqlq "UPDATE eventinstance_field_data SET date__value = '${past}T10:00:00', date__end_value = '${past}T11:00:00' WHERE id = ${instanceId}"`, { failOnNonZeroExit: false, timeout: 120000 });
    cy.exec(`ddev drush sqlq "UPDATE eventinstance_field_revision SET date__value = '${past}T10:00:00', date__end_value = '${past}T11:00:00' WHERE id = ${instanceId}"`, { failOnNonZeroExit: false, timeout: 120000 });
    cy.exec('ddev drush cr', { failOnNonZeroExit: false, timeout: 120000 });
    cy.clearMailpit();
    // Cancel it — the archive still happens, but the past gate enqueues 0 notices.
    cy.loginAs(...ADMIN);
    cy.visit(`/events/${instanceId}/edit`);
    cy.get('#edit-moderation-state-0-state').select('Archived');
    cy.get('#edit-submit').click();
    cy.exec(DRAIN, { failOnNonZeroExit: false, timeout: 120000 });
    // Assert ABSENCE — searchMailpitMessages, NOT waitForEmail (which throws on timeout).
    cy.searchMailpitMessages({ to: WALNUT[0], subject: 'Event Cancelled:' }).then((messages) => {
      expect(messages.length, 'no Event Cancelled email for a past occurrence').to.equal(0);
    });
  });
});
