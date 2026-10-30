/**
 * D8-2831: JSON:API runs in allowlist mode (jsonapi_extras default_disabled).
 *
 * Every resource not explicitly enabled 404s, so exposure can't come back via
 * permission drift or a newly added content type, vocabulary or media bundle.
 * These specs assert the closed surface, the few open resources, and that
 * sensitive affinity_group fields stay out of the output.
 */
describe("Test JSON:API allowlist mode", () => {

  // Not on the allowlist: must 404 for anonymous requests.
  const closedPaths = [
    '/jsonapi/node/page',
    '/jsonapi/node/access_active_resources_from_cid',
    '/jsonapi/node/mentorship_engagement',
    '/jsonapi/node/article',
    '/jsonapi/user/user',
    '/jsonapi/media/image',
  ];

  const sensitiveFields = [
    'field_ag_private_users',
    'field_coordinator',
    'field_ext_email_list',
    'field_list_id',
    'field_mailing_list',
    'field_domain_access',
  ];

  const jsonApi = (url) => cy.request({
    url,
    headers: { 'Accept': 'application/vnd.api+json' },
    failOnStatusCode: false,
  });

  beforeEach(() => {
    // Every assertion here is about the anonymous surface.
    cy.clearCookies();
  });

  context("resources outside the allowlist", () => {
    closedPaths.forEach((path) => {
      it(`GET ${path} returns 404`, () => {
        jsonApi(path).then((response) => {
          expect(response.status).to.eq(404);
          expect(response.body).to.not.have.property('data');
        });
      });
    });
  });

  context("allowlisted resources", () => {
    ['/jsonapi/node/affinity_group', '/jsonapi/taxonomy_term/tags'].forEach((path) => {
      it(`GET ${path} returns a JSON:API collection`, () => {
        jsonApi(path).then((response) => {
          expect(response.status).to.eq(200);
          expect(response.headers['content-type']).to.include('application/vnd.api+json');
          expect(response.body).to.have.property('data');
          expect(response.body.data).to.be.an('array');
        });
      });
    });

    it("affinity_group title CONTAINS filter finds a known group", () => {
      jsonApi('/jsonapi/node/affinity_group').then((response) => {
        expect(response.status).to.eq(200);
        if (response.body.data.length === 0) {
          cy.log('No affinity_group data available');
          return;
        }
        const title = response.body.data[0].attributes.title;
        const needle = title.substring(0, Math.min(title.length, 8));
        cy.request({
          url: '/jsonapi/node/affinity_group',
          qs: {
            'filter[title][operator]': 'CONTAINS',
            'filter[title][value]': needle,
          },
          headers: { 'Accept': 'application/vnd.api+json' },
          failOnStatusCode: false,
        }).then((filtered) => {
          expect(filtered.status).to.eq(200);
          expect(filtered.body.data).to.be.an('array').that.is.not.empty;
          const titles = filtered.body.data.map((item) => item.attributes.title);
          expect(titles).to.include(title);
        });
      });
    });

    it("taxonomy_term--tags terms expose attributes.name", () => {
      jsonApi('/jsonapi/taxonomy_term/tags').then((response) => {
        expect(response.status).to.eq(200);
        if (response.body.data.length === 0) {
          cy.log('No tags data available');
          return;
        }
        response.body.data.forEach((term) => {
          expect(term.type).to.eq('taxonomy_term--tags');
          expect(term.attributes).to.have.property('name');
        });
      });
    });

    it("affinity_group does not expose sensitive fields", () => {
      jsonApi('/jsonapi/node/affinity_group').then((response) => {
        expect(response.status).to.eq(200);
        response.body.data.forEach((item) => {
          sensitiveFields.forEach((field) => {
            expect(item.attributes, `attributes of ${item.id}`).to.not.have.property(field);
            expect(item.relationships || {}, `relationships of ${item.id}`).to.not.have.property(field);
          });
        });
      });
    });
  });

  context("/jsonapi index", () => {
    it("does not advertise closed resources", () => {
      jsonApi('/jsonapi').then((response) => {
        expect(response.status).to.eq(200);
        const links = response.body.links;
        [
          'node--page',
          'node--article',
          'user--user',
          'contact_form--contact_form',
          'webform--webform',
        ].forEach((key) => {
          expect(links, `index should not list ${key}`).to.not.have.property(key);
        });
      });
    });

    it("advertises allowlisted resources", () => {
      jsonApi('/jsonapi').then((response) => {
        expect(response.status).to.eq(200);
        const links = response.body.links;
        ['node--affinity_group', 'taxonomy_term--tags'].forEach((key) => {
          expect(links, `index should list ${key}`).to.have.property(key);
        });
      });
    });
  });

});
