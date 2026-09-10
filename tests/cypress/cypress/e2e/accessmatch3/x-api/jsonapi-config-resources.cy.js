/**
 * D8-2805: config-entity JSON:API resources are disabled.
 *
 * contact_form and webform were returning recipient/handler email addresses
 * to anonymous requests. These specs assert the resources stay closed, so
 * re-enabling one shows up as a test failure rather than a silent leak.
 */
describe("Test JSON:API config entity resources are disabled", () => {

  // Disabled via jsonapi_extras in web/sites/default/config/default/.
  const disabledPaths = [
    '/jsonapi/contact_form/contact_form',
    '/jsonapi/webform/webform',
    '/jsonapi/search_page/search_page',
    '/jsonapi/key/key',
    '/jsonapi/block/block',
    '/jsonapi/node_type/node_type',
    '/jsonapi/media_type/media_type',
    '/jsonapi/menu/menu',
    '/jsonapi/date_format/date_format',
    '/jsonapi/paragraphs_type/paragraphs_type',
  ];

  beforeEach(() => {
    // Every assertion here is about the anonymous surface.
    cy.clearCookies();
  });

  it("GET /jsonapi/contact_form/contact_form returns 404 with no recipients", () => {
    cy.request({ url: '/jsonapi/contact_form/contact_form', failOnStatusCode: false }).then((response) => {
      expect(response.status).to.eq(404);
      expect(JSON.stringify(response.body)).to.not.include('recipients');
    });
  });

  it("GET /jsonapi/webform/webform returns 404 with no handler settings", () => {
    cy.request({ url: '/jsonapi/webform/webform', failOnStatusCode: false }).then((response) => {
      expect(response.status).to.eq(404);
      expect(JSON.stringify(response.body)).to.not.include('handlers');
    });
  });

  disabledPaths.forEach((path) => {
    it(`GET ${path} returns 404 to anonymous users`, () => {
      cy.request({ url: path, failOnStatusCode: false }).then((response) => {
        expect(response.status).to.eq(404);
        expect(response.body).to.not.have.property('data');
      });
    });
  });

  it("GET /jsonapi does not advertise the disabled resources", () => {
    cy.request('/jsonapi').then((response) => {
      expect(response.status).to.eq(200);
      const links = response.body.links;
      disabledPaths.forEach((path) => {
        const key = path.replace('/jsonapi/', '').replace('/', '--');
        expect(links, `index should not list ${key}`).to.not.have.property(key);
      });
    });
  });

});
