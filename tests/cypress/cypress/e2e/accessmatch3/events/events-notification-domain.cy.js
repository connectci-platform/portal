/*
  D8-2811: event notification emails must build their links from the EVENT's own
  domain_access domain, not from whatever request happened to enqueue them.

  Contrib renders subject and body — and with them every absolute URL — at
  ENQUEUE time (NotificationService::addEmailNotificationToQueue() bakes them
  into the queue item), so the domain has to be right at enqueue, not when cron
  drains the queue. CancellationNotifier now wraps the enqueue loop in
  EventDomainContext::forEntity(), which points BOTH the domain negotiator and
  the router request context at the event's domain.

  Only the "Event Rescheduled" (instance_modification_notification) template
  carries an absolute link — [registrant:delete_url] — so it is the notice these
  tests assert on. Cancelled and Reinstated bodies are link-free.

  The spec runs against accessmatch.ddev.site, which is a domain_alias PATTERN
  for the amp_cyberinfrastructure_org domain record whose canonical hostname is
  support.access-ci.org. That split is what makes this testable end to end: the
  request host and the event domain's host are genuinely different strings, so a
  link built from the request (the bug) and a link built from the event's domain
  (the fix) can never be confused for one another.

  Prerequisite: the birth-publish contrib patch must be applied so a Published
  series births a Published, registerable instance.
*/

const DRAIN = 'ddev drush queue:run recurring_events_registration_email_notifications_queue_worker';
const ADMIN = ['administrator@amptesting.com', 'b8QW]X9h7#5n'];
const WALNUT = ['walnut@pie.org', 'Walnut'];
const SERIES_TITLE = 'cypress-notification-domain-test-event';

// The canonical hostname of the domain record these events belong to
// (domain.record.amp_cyberinfrastructure_org). Every link in a notification
// about them must be on this host.
const EVENT_HOST = 'support.access-ci.org';
// The host the browser is on while it triggers the reschedule — an ALIAS of the
// same domain record, and the host the pre-fix code baked into the links.
const REQUEST_HOST = 'accessmatch.ddev.site';
// A third, unrelated domain used to enqueue from "somewhere else" entirely.
const OTHER_URI = 'https://ccmnet.ddev.site';
const OTHER_HOST = 'ccmnet.org';

// Reschedules the occurrence from PHP, with no HTTP request in play at all —
// the drush-CLI path the ticket reproduced the bug on. Returns a shell command.
// $uri is optional: passing one gives the CLI request context that domain's
// host, standing in for "enqueued from a request on some other domain".
const rescheduleViaDrush = (instanceId, date, uri = null) =>
  `ddev drush ${uri ? `--uri=${uri} ` : ''}php:eval "` +
  `\\$i = \\Drupal::entityTypeManager()->getStorage('eventinstance')->load(${instanceId}); ` +
  `\\$i->set('date', ['value' => '${date}T14:00:00', 'end_value' => '${date}T15:00:00']); ` +
  `\\$i->set('moderation_state', 'published'); ` +
  `\\$i->setNewRevision(TRUE); ` +
  `\\$i->save();"`;

// Asserts the rescheduled notice's cancel-registration link is on the event's
// own domain and on no other host. The negative half matters as much as the
// positive one: a body that merely CONTAINS the right host could still carry a
// second, wrong-host link, which is exactly what the bug shipped.
const assertLinksOnEventDomain = (message, forbiddenHosts) =>
  cy.getMailpitMessage(message.ID).then((full) => {
    const body = (full.Text || '').replace(/\s+/g, ' ');
    expect(body, 'cancel link on the event domain').to.contain(`https://${EVENT_HOST}/`);
    forbiddenHosts.forEach((host) => {
      expect(body, `no link on ${host}`).to.not.contain(host);
    });
  });

// NOTE: these tests assert over the whole Mailpit store, relying on
// clearMailpit having emptied it. Sound only with retries disabled — a retried
// test would re-run beforeEach and re-drain. The project sets no `retries`
// (default 0); keep it that way.
describe('Event notification emails carry the event domain (D8-2811)', () => {
  let seriesId;
  let instanceId;

  before(() => {
    // Create a published, registration-enabled event series via the operator UI.
    // It inherits domain_access from the active domain — accessmatch.ddev.site,
    // i.e. the amp record whose canonical host is EVENT_HOST.
    cy.loginAs(...ADMIN);
    cy.visit('/events/add');
    cy.get('#edit-title-0-value').type(SERIES_TITLE, { delay: 0 });
    // Body is a required CKEditor field — set via the editor instance (a plain
    // .type() does not work on CKEditor); an empty body blocks form submit.
    cy.get('.field--name-body .ck-content').then((el) => {
      el[0].ckeditorInstance.setData('Domain-context notification coverage body.');
    });
    cy.get('#edit-summary-text').type('Domain-context notification coverage.', { delay: 0 });
    // Select the custom recurrence radio FIRST — the custom-date fields are hidden
    // behind a #states visibility condition on recur_type=custom until this is clicked.
    cy.get('#edit-recur-type-custom').click();
    cy.get('#edit-custom-date-0-value-date').type('2027-11-01', { delay: 0 });
    cy.get('#edit-custom-date-0-end-value-date').type('2027-11-01', { delay: 0 });
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
    cy.url().then((url) => {
      const m = url.match(/\/events\/series\/(\d+)/);
      expect(m, 'series id in URL').to.not.be.null;
      seriesId = m[1];
    });
  });

  beforeEach(() => {
    cy.clearMailpit();
    // Fresh occurrence per test so a reschedule in one test cannot supersede or
    // collide with another's queued notice.
    cy.loginAs(...ADMIN);
    cy.visit(`/events/series/${seriesId}/add`);
    cy.get('#edit-date-0-value-date').clear().type('2027-11-15', { delay: 0 });
    cy.get('#edit-date-0-end-value-date').clear().type('2027-11-15', { delay: 0 });
    cy.get('#edit-date-0-value-time').clear().type('10:00:00', { delay: 0 });
    cy.get('#edit-date-0-end-value-time').clear().type('11:00:00', { delay: 0 });
    cy.get('#edit-submit').click();
    // Capture the new instance id, then do all instanceId-dependent steps INSIDE
    // the .then — a template literal like `/events/${instanceId}` is evaluated
    // when the command is enqueued, before this .then runs.
    cy.url().then((url) => {
      const m = url.match(/\/events\/(\d+)(?:$|\?)/);
      expect(m, 'instance id in URL').to.not.be.null;
      instanceId = m[1];
      // The Add-Instance form is the HAND-AUTHORED path, so the occurrence is
      // born DRAFT (by design) — invisible to walnut → 403 on register. Publish
      // it as admin first.
      cy.visit(`/events/${instanceId}/edit`);
      cy.get('#edit-moderation-state-0-state').select('Published');
      cy.get('#edit-submit').click();
      // Guard the premise of the whole spec: the occurrence really is on the
      // domain whose canonical host these tests expect. If domain assignment
      // ever changes, this fails loudly here instead of turning the host
      // assertions below into a silent tautology.
      cy.exec(
        `ddev drush php:eval "` +
          `\\$i = \\Drupal::entityTypeManager()->getStorage('eventinstance')->load(${instanceId}); ` +
          `echo \\Drupal::service('access_events.domain_context')->resolveHostname(\\$i);"`,
        { timeout: 120000 },
      ).its('stdout').should('contain', EVENT_HOST);
      // Register walnut on the now-published occurrence. Capacity is 50, so this
      // is a direct (non-waitlist) registration.
      cy.loginAs(...WALNUT);
      cy.visit(`/events/${instanceId}`);
      cy.get('#block-asptheme-eventinstancesidebar').contains('Register').click();
      cy.contains('Please confirm your registration below');
      cy.get('#edit-submit').click();
      cy.contains('You will receive an email after your registration is approved');
    });
  });

  after(() => {
    // Registrants MUST be deleted FIRST — EventDeleteGuard refuses to delete a
    // series that still has registrations (by design: cancel-not-delete).
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

  it('builds the cancel link on the event domain when rescheduled from the browser', () => {
    // The operator is on accessmatch.ddev.site — an alias of the event's own
    // domain record, but NOT its canonical hostname. Pre-fix the link was built
    // from this request host; it must now come from the domain record.
    cy.loginAs(...ADMIN);
    cy.visit(`/events/${instanceId}/edit`);
    cy.get('#edit-date-0-value-date').clear().type('2027-12-08', { delay: 0 });
    cy.get('#edit-date-0-end-value-date').clear().type('2027-12-08', { delay: 0 });
    cy.get('#edit-moderation-state-0-state').select('Published');
    cy.get('#edit-submit').click();
    cy.exec(DRAIN, { failOnNonZeroExit: false, timeout: 120000 });
    cy.waitForEmail({ to: WALNUT[0], subject: 'Event Rescheduled:' }).then((message) => {
      cy.assertEmailContent(message, {
        subject: 'Event Rescheduled:',
        bodyContains: 'you can cancel your registration here:',
      });
      assertLinksOnEventDomain(message, [REQUEST_HOST]);
    });
  });

  it('builds the cancel link on the event domain when enqueued from the CLI', () => {
    // The ticket's literal reproduction: a drush run has no HTTP request, so the
    // request context falls back to the install's own base URL
    // (cyberteam-drupal.ddev.site here) and the pre-fix links pointed there.
    cy.exec(rescheduleViaDrush(instanceId, '2027-12-09'), { timeout: 120000 });
    cy.exec(DRAIN, { failOnNonZeroExit: false, timeout: 120000 });
    cy.waitForEmail({ to: WALNUT[0], subject: 'Event Rescheduled:' }).then((message) => {
      assertLinksOnEventDomain(message, [REQUEST_HOST, 'cyberteam-drupal.ddev.site']);
    });
  });

  it('ignores the enqueueing request domain when it is a different site', () => {
    // The production shape of the bug: a notice for an event on domain Y
    // enqueued from a request on domain X carried X's host. --uri gives the CLI
    // request context ccmnet's host, so X and Y are genuinely different domain
    // RECORDS here, not just different aliases of one.
    cy.exec(rescheduleViaDrush(instanceId, '2027-12-10', OTHER_URI), { timeout: 120000 });
    cy.exec(DRAIN, { failOnNonZeroExit: false, timeout: 120000 });
    cy.waitForEmail({ to: WALNUT[0], subject: 'Event Rescheduled:' }).then((message) => {
      assertLinksOnEventDomain(message, [OTHER_HOST, 'ccmnet.ddev.site', REQUEST_HOST]);
    });
  });
});
