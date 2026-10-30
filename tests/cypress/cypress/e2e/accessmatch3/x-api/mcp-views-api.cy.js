/**
 * MCP views are served by jsonapi_views at /jsonapi/views/<view>/<display>.
 * They are not JSON:API resources, so D8-2831 allowlist mode must leave them
 * working. The views run anonymously here (no X-Acting-User header), as the
 * original specs did; rows are checked only when the result is non-empty.
 */
describe("Test MCP Views JSON:API Endpoints", () => {

  const views = [
    { path: '/jsonapi/views/mcp_my_announcements/page_1', label: 'title' },
    { path: '/jsonapi/views/mcp_my_affinity_groups/page_1', label: 'title' },
    // The mcp_my_events display of event_instance_mine (path mcp/my-events).
    { path: '/jsonapi/views/event_instance_mine/mcp_my_events', label: 'title' },
  ];

  views.forEach(({ path, label }) => {
    it(`GET ${path} returns valid JSON:API response`, () => {
      cy.request(path).then((response) => {
        expect(response.status).to.eq(200);
        expect(response.headers).to.have.property('content-type').that.includes('application/vnd.api+json');
        expect(response.body).to.have.property('data');
        expect(response.body.data).to.be.an('array');
      });
    });

    it(`GET ${path} rows have type, id and ${label}`, () => {
      cy.request(path).then((response) => {
        expect(response.status).to.eq(200);
        if (response.body.data.length === 0) {
          cy.log('No rows returned for anonymous user');
          return;
        }
        const row = response.body.data[0];
        expect(row).to.have.property('type');
        expect(row).to.have.property('id');
        expect(row.attributes).to.have.property(label);
      });
    });
  });

});
