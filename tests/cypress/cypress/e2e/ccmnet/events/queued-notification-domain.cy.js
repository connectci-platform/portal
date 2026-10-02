/*
  D8-2835: queued event registration notices (cancel / reinstate / reschedule
  of an event occurrence) must SEND with the EVENT's own domain's mail
  identity (From / Reply-To), not whatever domain happens to be active when
  cron drains the queue.

  Notices are queued at enqueue time into
  recurring_events_registration_email_notifications_queue_worker and sent
  when the worker drains it. access_misc_mailer_build()
  (docroot/modules/custom/access/modules/access_misc/access_misc.module,
  ~line 53) sets From/Reply-To from the ACTIVE DOMAIN at SEND time — for
  ccmnet_org: From "CCMNet <info@mg.ccmnet.org>", Reply-To
  "CCMNet <info@ccmnet.org>".

  Sibling ticket D8-2811 (see ../accessmatch3/events/events-notification-domain.cy.js
  and EventDomainContext) already fixed the SUBJECT/BODY/LINKS baked into the
  queue item at ENQUEUE time. This ticket is the other half: when
  `drush cron` (or a bare `drush queue:run`) drains the queue, there is no
  HTTP request, so the domain negotiator falls back to the site's DEFAULT
  domain (necyberteam.org / nectd8_wpi_edu — see
  docroot/sites/default/config/default/domain.record.nectd8_wpi_edu.yml,
  is_default: true) instead of the ccmnet event's domain, and the notice goes
  out with the default From/Reply-To instead of ccmnet's. The fix stamps the
  event's domain id onto the queue item at enqueue time and switches the
  active domain while the worker SENDS it.

  This spec lives in the ccmnet folder so `vendor/bin/robo cypress ccmnet`
  points Cypress's baseUrl at https://ccmnet.ddev.site — creating the event
  while browsing that host is what assigns its domain_access field to
  ccmnet_org (domain_access defaults new content to the active domain), with
  no manual domain switching required. The queue is then drained with a
  PLAIN `drush queue:run ...` — no --uri — which is exactly the "drush cron,
  no domain context" reproduction from the ticket: that command runs in the
  site's default domain, never ccmnet, so a From/Reply-To assertion failing
  pre-fix and passing post-fix is the acceptance check.

  Prerequisite: the birth-publish contrib patch must be applied so a
  Published series births a Published, registerable instance (see
  accessmatch3/events/events-notifications.cy.js).

  NOTE: like the sibling notification specs, these tests assert over the
  whole Mailpit store (clearMailpit in beforeEach/between steps), which is
  only sound with retries disabled — the project sets no `retries` (default
  0); keep it that way.
*/

const DRAIN = 'ddev drush queue:run recurring_events_registration_email_notifications_queue_worker';
const ADMIN = ['administrator@amptesting.com', 'b8QW]X9h7#5n'];
const WALNUT = ['walnut@pie.org', 'Walnut'];
const SERIES_TITLE = 'cypress-ccmnet-queued-notification-domain-test-event';

// The mail identity access_misc_mailer_build() sets for domain_id ccmnet_org.
const CCMNET_FROM = 'info@mg.ccmnet.org';
const CCMNET_REPLY_TO = 'info@ccmnet.org';

describe('Queued event notices send with the ccmnet domain identity (D8-2835)', () => {
  let seriesId;
  let instanceId;

  before(() => {
    // Guard the premise of the whole spec: the plain (no --uri) drush context
    // this spec drains the queue from is genuinely NOT ccmnet. If the site's
    // default domain ever changes to ccmnet_org, DRAIN below would no longer
    // reproduce the bug and every From/Reply-To assertion would pass
    // trivially either way.
    cy.exec(
      'ddev drush php:eval "echo \\Drupal::service(\'domain.negotiator\')->getActiveDomain()?->id();"',
      { failOnNonZeroExit: false, timeout: 60000 },
    ).its('stdout').should('not.contain', 'ccmnet_org');

    // Create a published, registration-enabled event series via the operator
    // UI, while browsing ccmnet.ddev.site — it inherits domain_access from
    // the active domain (ccmnet_org).
    cy.loginUser(...ADMIN);
    cy.visit('/events/add');
    cy.get('#edit-title-0-value').type(SERIES_TITLE, { delay: 0, force: true });
    // Body is a required CKEditor field — set via the editor instance (a plain
    // .type() does not work on CKEditor); an empty body blocks form submit.
    cy.get('.field--name-body .ck-content').then((el) => {
      el[0].ckeditorInstance.setData('CCMNet queued notification domain coverage body.');
    });
    cy.get('#edit-summary-text').type('CCMNet queued notification domain coverage.', { delay: 0, force: true });
    // Select the custom recurrence radio FIRST — the custom-date fields are hidden
    // behind a #states visibility condition on recur_type=custom until this is clicked.
    cy.get('#edit-recur-type-custom').click({ force: true });
    cy.get('#edit-custom-date-0-value-date').type('2027-11-01', { delay: 0, force: true });
    cy.get('#edit-custom-date-0-end-value-date').type('2027-11-01', { delay: 0, force: true });
    cy.get('#edit-custom-date-0-value-time').type('10:00:00', { delay: 0, force: true });
    cy.get('#edit-custom-date-0-end-value-time').type('11:00:00', { delay: 0, force: true });
    cy.get('#edit-field-location-0-value').type('Zoom', { delay: 0, force: true });
    cy.get('#edit-field-contact-0-value').type('Pecan Pie', { delay: 0, force: true });
    cy.get('input[data-drupal-selector="edit-event-registration-0-registration"]').check({ force: true });
    cy.get('#edit-event-registration-0-capacity').type('50', { delay: 0, force: true });
    cy.get('#edit-moderation-state-0-state').select('Published', { force: true });
    cy.get('#edit-field-event-type-training').click({ force: true });
    cy.get('#edit-field-affiliation-community').click({ force: true });
    cy.get('#edit-field-skill-level-advanced').click({ force: true });
    cy.get('form:not(#search-block-form) #edit-submit').click({ force: true });
    cy.url().then((url) => {
      const m = url.match(/\/events\/series\/(\d+)/);
      expect(m, 'series id in URL').to.not.be.null;
      seriesId = m[1];
    });
  });

  beforeEach(() => {
    cy.clearMailpit();
    // Fresh occurrence per test so a cancel/reinstate in one test cannot
    // supersede or collide with another's queued notice.
    cy.loginUser(...ADMIN);
    cy.visit(`/events/series/${seriesId}/add`);
    cy.get('#edit-date-0-value-date').clear({ force: true }).type('2027-11-15', { delay: 0, force: true });
    cy.get('#edit-date-0-end-value-date').clear({ force: true }).type('2027-11-15', { delay: 0, force: true });
    cy.get('#edit-date-0-value-time').clear({ force: true }).type('10:00:00', { delay: 0, force: true });
    cy.get('#edit-date-0-end-value-time').clear({ force: true }).type('11:00:00', { delay: 0, force: true });
    cy.get('form:not(#search-block-form) #edit-submit').click({ force: true });
    // Capture the new instance id, then do all instanceId-dependent steps
    // INSIDE the .then — a template literal like `/events/${instanceId}` is
    // evaluated when the command is enqueued, before this .then runs.
    cy.url().then((url) => {
      const m = url.match(/\/events\/(\d+)(?:$|\?)/);
      expect(m, 'instance id in URL').to.not.be.null;
      instanceId = m[1];
      // The Add-Instance form is the HAND-AUTHORED path, so the occurrence is
      // born DRAFT (by design) — invisible to walnut → 403 on register.
      // Publish it as admin first.
      cy.visit(`/events/${instanceId}/edit`);
      cy.get('#edit-moderation-state-0-state').select('Published', { force: true });
      cy.get('form:not(#search-block-form) #edit-submit').click({ force: true });
      // Guard the premise of the whole spec: the occurrence really is on
      // ccmnet_org. If domain assignment ever changes (e.g. the /events/add
      // form stops defaulting domain_access to the active domain), this
      // fails loudly here instead of the From/Reply-To assertions below
      // turning into a silent tautology.
      cy.exec(
        'ddev drush php:eval "' +
          `\\$i = \\Drupal::entityTypeManager()->getStorage('eventinstance')->load(${instanceId}); ` +
          'echo \\Drupal::service(\'access_events.domain_context\')->resolveHostname(\\$i);"',
        { timeout: 120000 },
      ).its('stdout').should('contain', 'ccmnet.org');
      // Register walnut on the now-published occurrence. The "Register"
      // link normally lives in the event_instance_sidebar block, but that
      // block is only placed in the aspTheme/ood themes
      // (block.block.asptheme_eventinstancesidebar.yml /
      // block.block.ood_eventinstancesidebar.yml) — ccmnet_org runs the
      // "nect" theme (domain_theme_switch.settings.yml:
      // ccmnet_org_site: nect), which has no such block, so there is no
      // UI path to register on a ccmnet event page. The registration UI
      // itself is not what D8-2835 is about, so create the Registrant
      // directly, the same way sibling specs manipulate event/registrant
      // data straight through Drupal (e.g. the past-date registrant setup
      // in events-notifications.cy.js).
      cy.exec(
        'ddev drush php:eval "' +
          `\\$i = \\Drupal::entityTypeManager()->getStorage('eventinstance')->load(${instanceId}); ` +
          `\\$u = \\Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['mail' => '${WALNUT[0]}']); ` +
          '\\$u = reset(\\$u); ' +
          '\\$r = \\Drupal::entityTypeManager()->getStorage(\'registrant\')->create([' +
          "'type' => 'default', " +
          "'eventinstance_id' => \\$i->id(), " +
          "'eventseries_id' => \\$i->getEventSeries()->id(), " +
          `'email' => '${WALNUT[0]}', ` +
          "'user_id' => \\$u->id(), " +
          "'field_first_name' => 'Walnut', " +
          "'field_last_name' => 'Pie', " +
          "'waitlist' => 0, " +
          "]); " +
          '\\$r->save(); ' +
          'echo \\$r->id();"',
        { timeout: 120000 },
      ).its('stdout').should('match', /^\d+$/);
    });
  });

  after(() => {
    // Registrants MUST be deleted FIRST — EventDeleteGuard refuses to delete a
    // series that still has registrations (by design: cancel-not-delete).
    cy.exec(
      'ddev drush php:eval "' +
        `\\$series = \\Drupal::entityTypeManager()->getStorage('eventseries')->loadByProperties(['title' => '${SERIES_TITLE}']); ` +
        '\\$rs = \\Drupal::entityTypeManager()->getStorage(\'registrant\'); ' +
        'foreach (\\$series as \\$s) { ' +
        'foreach (\\$s->get(\'event_instances\')->referencedEntities() as \\$inst) { ' +
        'foreach (\\$rs->loadByProperties([\'eventinstance_id\' => \\$inst->id()]) as \\$r) { \\$r->delete(); } } ' +
        '\\$s->delete(); }"',
      { failOnNonZeroExit: false, timeout: 120000 },
    );
    cy.exec('ddev drush search-api:index events --batch-size=50', { failOnNonZeroExit: false, timeout: 120000 });
  });

  it('sends the Event Cancelled notice with the ccmnet From/Reply-To when drained outside ccmnet', () => {
    cy.loginUser(...ADMIN);
    cy.visit(`/events/${instanceId}/edit`);
    cy.get('#edit-moderation-state-0-state').select('Archived', { force: true });
    cy.get('form:not(#search-block-form) #edit-submit').click({ force: true });
    // Drain from the DEFAULT domain context (no --uri) — the drush-cron
    // reproduction from the ticket. See the before() guard above confirming
    // this context is genuinely not ccmnet.
    cy.exec(DRAIN, { failOnNonZeroExit: false, timeout: 120000 });
    cy.waitForEmail({ to: WALNUT[0], subject: 'Event Cancelled:' }).then((message) => {
      cy.assertEmailContent(message, {
        subject: 'Event Cancelled:',
        from: CCMNET_FROM,
        replyTo: CCMNET_REPLY_TO,
        to: WALNUT[0],
        bodyContains: 'has been cancelled',
      });
    });
  });

  it('sends the Event Reinstated notice with the ccmnet From/Reply-To when drained outside ccmnet', () => {
    // Archive first (this queues a cancel notice; drain+clear so it does not
    // pollute the reinstate assertion below).
    cy.loginUser(...ADMIN);
    cy.visit(`/events/${instanceId}/edit`);
    cy.get('#edit-moderation-state-0-state').select('Archived', { force: true });
    cy.get('form:not(#search-block-form) #edit-submit').click({ force: true });
    cy.exec(DRAIN, { failOnNonZeroExit: false, timeout: 120000 });
    cy.clearMailpit();
    // Now reinstate: archived -> published.
    cy.visit(`/events/${instanceId}/edit`);
    cy.get('#edit-moderation-state-0-state').select('Published', { force: true });
    cy.get('form:not(#search-block-form) #edit-submit').click({ force: true });
    cy.exec(DRAIN, { failOnNonZeroExit: false, timeout: 120000 });
    cy.waitForEmail({ to: WALNUT[0], subject: 'Event Reinstated:' }).then((message) => {
      cy.assertEmailContent(message, {
        subject: 'Event Reinstated:',
        from: CCMNET_FROM,
        replyTo: CCMNET_REPLY_TO,
        to: WALNUT[0],
        bodyContains: 'is back on',
      });
    });
  });
});
