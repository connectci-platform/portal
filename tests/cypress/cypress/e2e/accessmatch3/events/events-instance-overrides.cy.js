/*
  Test that per-instance override fields work for previously-inherited fields.
  Each override field defaults to inheriting from the series, but can be set
  on the instance to override.

  Fields covered: registration (URL), virtual_meeting_link, location, speakers,
  pre_survey_url, post_survey_url, pre_survey_email_text, post_survey_email_text,
  event_summary, screen_survey_url, screen_survey_email_text.

  Strategy: create a series as the test user (so they own and can edit
  instances), then add an instance with overrides for representative fields
  across the type families (link, text, text-long) and verify both override
  and fallback semantics.

  Each test seeds its assertion strings with a unique suffix so chrome,
  breadcrumbs, or admin UI text can't collide with the override content.
*/

import { eventDate } from '../../../support/event-dates';

const stamp = Date.now();
const seriesTitle       = `cypress-overrides-${stamp}`;
const seriesLocation    = `series-loc-${stamp}`;
const seriesRegistration = `https://example.com/series-registration-${stamp}`;
const seriesVirtualLink = `https://example.com/series-virtual-${stamp}`;

describe('Per-instance override fields', () => {
  let seriesId;

  before(() => {
    cy.loginAs('walnut@pie.org', 'Walnut');
    cy.visit('/events/add');
    cy.get('h1').contains('Create Community Event');

    cy.get('#edit-title-0-value').type(seriesTitle, { delay: 0 });
    cy.get('.field--name-body .ck-content').then((el) => {
      el[0].ckeditorInstance.setData('Series body content.');
    });
    cy.get('#edit-summary-text').type('Series summary.', { delay: 0 });

    cy.get('#edit-recur-type-custom').click();
    cy.get('#edit-custom-date-0-value-date').type(eventDate(180).iso, { delay: 0 });
    cy.get('#edit-custom-date-0-end-value-date').type(eventDate(180).iso, { delay: 0 });
    cy.get('#edit-custom-date-0-value-time').type('14:00:00', { delay: 0 });
    cy.get('#edit-custom-date-0-end-value-time').type('15:00:00', { delay: 0 });

    cy.get('#edit-field-location-0-value').type(seriesLocation, { delay: 0 });
    cy.get('#edit-field-contact-0-value').type('Test Contact', { delay: 0 });
    cy.get('#edit-field-registration-0-uri').type(seriesRegistration, { delay: 0 });
    cy.get('#edit-field-event-virtual-meeting-link-0-uri').type(seriesVirtualLink, { delay: 0 });
    cy.get('#edit-moderation-state-0-state').select('Published');
    cy.get('#edit-field-event-type-training').click();
    cy.get('#edit-field-affiliation-community').click();
    cy.get('#edit-field-skill-level-beginner').click();

    cy.get('#edit-submit').click();
    cy.url().should('match', /\/events\/series\/\d+/);
    cy.url().then((url) => {
      seriesId = url.match(/\/events\/series\/(\d+)/)[1];
    });
  });

  it('instance with no overrides inherits all fields from the series', () => {
    cy.loginAs('walnut@pie.org', 'Walnut');
    cy.visit(`/events/series/${seriesId}/add`);

    cy.get('#edit-date-0-value-date').clear().type(eventDate(211).iso, { delay: 0 });
    cy.get('#edit-date-0-end-value-date').clear().type(eventDate(211).iso, { delay: 0 });
    cy.get('#edit-date-0-value-time').clear().type('14:00:00', { delay: 0 });
    cy.get('#edit-date-0-end-value-time').clear().type('15:00:00', { delay: 0 });

    cy.get('#edit-submit').click();

    // Rendered page shows the series-level values via inheritance.
    cy.get('main').should('contain', seriesLocation);
    cy.get('main a[href="' + seriesRegistration + '"]').should('exist');
    cy.get('main a[href="' + seriesVirtualLink + '"]').should('exist');
  });

  it('instance overrides registration URL while location still inherits', () => {
    const overrideRegistration = `https://example.com/instance-reg-${stamp}-a`;

    cy.loginAs('walnut@pie.org', 'Walnut');
    cy.visit(`/events/series/${seriesId}/add`);

    cy.get('#edit-date-0-value-date').clear().type(eventDate(241).iso, { delay: 0 });
    cy.get('#edit-date-0-end-value-date').clear().type(eventDate(241).iso, { delay: 0 });
    cy.get('#edit-date-0-value-time').clear().type('14:00:00', { delay: 0 });
    cy.get('#edit-date-0-end-value-time').clear().type('15:00:00', { delay: 0 });

    // Override the registration URL only.
    cy.get('#edit-field-registration-0-uri').clear().type(overrideRegistration, { delay: 0 });

    cy.get('#edit-submit').click();

    // Registration is the override; location still inherits from series.
    cy.get('main a[href="' + overrideRegistration + '"]').should('exist');
    cy.get('main a[href="' + seriesRegistration + '"]').should('not.exist');
    cy.get('main').should('contain', seriesLocation);
  });

  it('instance overrides location while registration still inherits', () => {
    const overrideLocation = `instance-loc-${stamp}-b`;

    cy.loginAs('walnut@pie.org', 'Walnut');
    cy.visit(`/events/series/${seriesId}/add`);

    cy.get('#edit-date-0-value-date').clear().type(eventDate(272).iso, { delay: 0 });
    cy.get('#edit-date-0-end-value-date').clear().type(eventDate(272).iso, { delay: 0 });
    cy.get('#edit-date-0-value-time').clear().type('14:00:00', { delay: 0 });
    cy.get('#edit-date-0-end-value-time').clear().type('15:00:00', { delay: 0 });

    // The override fields land lower in the form than the inherited base
    // location field. Use the field name selector to disambiguate.
    cy.get('input[name="field_location[0][value]"]').clear().type(overrideLocation, { delay: 0 });

    cy.get('#edit-submit').click();

    // Location is the override; registration still inherits.
    cy.get('main').should('contain', overrideLocation);
    cy.get('main').should('not.contain', seriesLocation);
    cy.get('main a[href="' + seriesRegistration + '"]').should('exist');
  });

  it('instance overrides virtual meeting link while other link fields inherit', () => {
    const overrideVirtual = `https://example.com/instance-vlink-${stamp}-c`;

    cy.loginAs('walnut@pie.org', 'Walnut');
    cy.visit(`/events/series/${seriesId}/add`);

    cy.get('#edit-date-0-value-date').clear().type(eventDate(303).iso, { delay: 0 });
    cy.get('#edit-date-0-end-value-date').clear().type(eventDate(303).iso, { delay: 0 });
    cy.get('#edit-date-0-value-time').clear().type('14:00:00', { delay: 0 });
    cy.get('#edit-date-0-end-value-time').clear().type('15:00:00', { delay: 0 });

    cy.get('#edit-field-event-virtual-meeting-link-0-uri').clear().type(overrideVirtual, { delay: 0 });

    cy.get('#edit-submit').click();

    // Virtual link override appears; other link fields still come from series.
    cy.get('main a[href="' + overrideVirtual + '"]').should('exist');
    cy.get('main a[href="' + seriesVirtualLink + '"]').should('not.exist');
    cy.get('main a[href="' + seriesRegistration + '"]').should('exist');
  });
});
