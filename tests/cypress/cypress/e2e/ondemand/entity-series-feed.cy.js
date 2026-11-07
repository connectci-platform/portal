/**
 * D8-2831: the entity_series_json_feed view (published Open OnDemand event
 * series, consumed by an external Drupal site) is a jsonapi_views route, not a
 * JSON:API resource, so it must keep working in allowlist mode while private
 * eventseries fields stay out of the output.
 */
describe("Entity series JSON feed", () => {

  const feedPath = '/jsonapi/views/entity_series_json_feed/page_1';

  const sensitiveFields = [
    'field_post_survey_email_text',
    'field_pre_survey_email_text',
    'field_screen_survey_email_text',
    'field_registration',
    'field_choose_where_to_share_this',
    'domain_access',
    'field_contact',
    'uid',
    // Declared cardinality 1 by recurring_events but holds every instance,
    // which trips a core JSON:API assertion; no feed consumer follows it.
    'event_instances',
  ];

  beforeEach(() => {
    cy.clearCookies();
  });

  it("returns a valid JSON:API response", () => {
    cy.request(feedPath).then((response) => {
      expect(response.status).to.eq(200);
      expect(response.headers['content-type']).to.include('application/vnd.api+json');
      expect(response.body).to.have.property('data');
      expect(response.body.data).to.be.an('array');
    });
  });

  it("rows have a title", () => {
    cy.request(feedPath).then((response) => {
      if (response.body.data.length === 0) {
        cy.log('No published event series available');
        return;
      }
      response.body.data.forEach((row) => {
        expect(row.attributes).to.have.property('title');
      });
    });
  });

  it("does not expose sensitive fields", () => {
    cy.request(feedPath).then((response) => {
      response.body.data.forEach((row) => {
        sensitiveFields.forEach((field) => {
          expect(row.attributes || {}, `attributes of ${row.id}`).to.not.have.property(field);
          expect(row.relationships || {}, `relationships of ${row.id}`).to.not.have.property(field);
        });
      });
    });
  });

});
