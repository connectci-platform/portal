/*
  Test the "Add new instance" route on an event series. This route uses
  form_id `eventinstance_default_form` (not *_add_form), and creates the
  instance via EventSeriesController::addInstance() which bypasses
  EventCreationService — so without our fixes, the form fails for non-admins
  with "Domain Access field is required" and inherited fields (title, body)
  come back empty after save.

  Covers:
  - access_events.module form_alter hides domain_access for non-admins on the
    addInstance route (form_id was missing from the alter's id list).
  - access_events_entity_presave() wires field_inheritance state on instance
    create (recurring_events bypasses this on the addInstance path).
    Upstream issue: https://www.drupal.org/project/recurring_events/issues/3357979
  - description inheritance type is `fallback` so an instance body overrides
    the series body without duplicating it.
*/

import { eventDate } from '../../../support/event-dates';

describe('Add new instance to an event series', () => {
  const seriesTitle = `cypress-add-instance-${Date.now()}`;
  const seriesBody = 'Series-level body content for inheritance testing.';
  let seriesId;

  before(() => {
    // The non-admin user (walnut) creates the series so they own it and have
    // edit/add-instance access to it. This mirrors Marco's production scenario:
    // a non-admin event creator adding instances to their own series.
    cy.loginAs('walnut@pie.org', 'Walnut');
    cy.visit('/events/add');
    cy.get('h1').contains('Create Community Event');

    cy.get('#edit-title-0-value').type(seriesTitle, { delay: 0 });
    cy.get('.field--name-body .ck-content').then((el) => {
      el[0].ckeditorInstance.setData(seriesBody);
    });
    cy.get('#edit-summary-text').type('Summary for instance test.', { delay: 0 });

    // Single custom date so we get exactly one auto-created instance.
    cy.get('#edit-recur-type-custom').click();
    cy.get('#edit-custom-date-0-value-date').type(eventDate(90).iso, { delay: 0 });
    cy.get('#edit-custom-date-0-end-value-date').type(eventDate(90).iso, { delay: 0 });
    cy.get('#edit-custom-date-0-value-time').type('14:00:00', { delay: 0 });
    cy.get('#edit-custom-date-0-end-value-time').type('15:00:00', { delay: 0 });

    cy.get('#edit-field-location-0-value').type('Zoom', { delay: 0 });
    cy.get('#edit-field-contact-0-value').type('Test Contact', { delay: 0 });
    cy.get('#edit-moderation-state-0-state').select('Published');
    cy.get('#edit-field-event-type-training').click();
    cy.get('#edit-field-affiliation-community').click();
    cy.get('#edit-field-skill-level-beginner').click();

    cy.get('#edit-submit').click();

    // Series form redirects to /events/series/{id}.
    cy.url().should('match', /\/events\/series\/\d+/);
    cy.url().then((url) => {
      const match = url.match(/\/events\/series\/(\d+)/);
      seriesId = match[1];
    });
  });

  it('non-admin can add an instance and inherited fields populate', () => {
    cy.loginAs('walnut@pie.org', 'Walnut');

    cy.visit(`/events/series/${seriesId}/add`);

    // Domain Access widget must be hidden for non-admins on this route. If the
    // form_alter regression returns, this assertion catches it before save.
    cy.get('input[name^="domain_access"]').should('not.exist');

    cy.get('#edit-date-0-value-date').clear().type(eventDate(121).iso, { delay: 0 });
    cy.get('#edit-date-0-end-value-date').clear().type(eventDate(121).iso, { delay: 0 });
    cy.get('#edit-date-0-value-time').clear().type('14:00:00', { delay: 0 });
    cy.get('#edit-date-0-end-value-time').clear().type('15:00:00', { delay: 0 });

    cy.get('#edit-submit').click();

    // No "Domain Access field is required" — save succeeds.
    cy.contains('Domain Access field is required').should('not.exist');

    // Rendered instance page shows inherited title and body from the series.
    cy.get('h1').should('contain', seriesTitle);
    cy.get('.region-content').should('contain', seriesBody);
  });

  it('instance body overrides series body (fallback, not append)', () => {
    cy.loginAs('walnut@pie.org', 'Walnut');

    cy.visit(`/events/series/${seriesId}/add`);

    cy.get('#edit-date-0-value-date').clear().type(eventDate(149).iso, { delay: 0 });
    cy.get('#edit-date-0-end-value-date').clear().type(eventDate(149).iso, { delay: 0 });
    cy.get('#edit-date-0-value-time').clear().type('14:00:00', { delay: 0 });
    cy.get('#edit-date-0-end-value-time').clear().type('15:00:00', { delay: 0 });

    const instanceBody = 'Per-instance override body content.';
    cy.get('.field--name-body .ck-content').then((el) => {
      el[0].ckeditorInstance.setData(instanceBody);
    });

    cy.get('#edit-submit').click();

    // Rendered page shows the instance body and NOT the series body. If the
    // inheritance type drifts back to `append`, both would render.
    cy.get('.region-content').should('contain', instanceBody);
    cy.get('.region-content').should('not.contain', seriesBody);
  });
});
