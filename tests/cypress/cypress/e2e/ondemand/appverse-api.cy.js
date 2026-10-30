describe("Appverse JSON:API", () => {

  describe("Appverse App API", () => {
    it("Returns valid JSON:API response", () => {
      cy.request({
        method: 'GET',
        url: '/jsonapi/node/appverse_app',
        headers: {
          'Accept': 'application/vnd.api+json'
        },
        failOnStatusCode: false
      }).then((response) => {
        // Skip if endpoint not available
        if (response.status === 404) {
          cy.log('JSON:API endpoint for appverse_app not available');
          return;
        }

        expect(response.status).to.eq(200);
        expect(response.headers['content-type']).to.include('application/vnd.api+json');
        expect(response.body).to.have.property('data');
        expect(response.body).to.have.property('links');
      });
    });

    it("Returns expected attributes for appverse_app", () => {
      cy.request({
        method: 'GET',
        url: '/jsonapi/node/appverse_app',
        headers: {
          'Accept': 'application/vnd.api+json'
        },
        failOnStatusCode: false
      }).then((response) => {
        if (response.status === 404 || !response.body.data || response.body.data.length === 0) {
          cy.log('No appverse_app data available');
          return;
        }

        const app = response.body.data[0];

        // Verify type
        expect(app.type).to.eq('node--appverse_app');

        // Verify required attributes exist
        const expectedAttributes = [
          'drupal_internal__nid',
          'drupal_internal__vid',
          'title',
          'status',
          'created',
          'changed',
          'moderation_state',
          'body',
          'field_appverse_github_url',
          'field_appverse_readme',
          'field_appverse_lastupdated',
          'field_appverse_stars',
          'flag_count'
        ];

        expectedAttributes.forEach((attr) => {
          expect(app.attributes).to.have.property(attr);
        });
      });
    });

    it("Returns expected relationships for appverse_app", () => {
      cy.request({
        method: 'GET',
        url: '/jsonapi/node/appverse_app',
        headers: {
          'Accept': 'application/vnd.api+json'
        },
        failOnStatusCode: false
      }).then((response) => {
        if (response.status === 404 || !response.body.data || response.body.data.length === 0) {
          cy.log('No appverse_app data available');
          return;
        }

        const app = response.body.data[0];

        // Verify expected relationships exist
        const expectedRelationships = [
          'node_type',
          'uid',
          'field_appverse_app_type',
          'field_appverse_organization',
          'field_appverse_software_implemen',
          'field_license'
        ];

        expectedRelationships.forEach((rel) => {
          expect(app.relationships).to.have.property(rel);
        });
      });
    });

    it("drupal_internal__nid is a number", () => {
      cy.request({
        method: 'GET',
        url: '/jsonapi/node/appverse_app',
        headers: {
          'Accept': 'application/vnd.api+json'
        },
        failOnStatusCode: false
      }).then((response) => {
        if (response.status === 404 || !response.body.data || response.body.data.length === 0) {
          cy.log('No appverse_app data available');
          return;
        }

        const app = response.body.data[0];
        expect(app.attributes.drupal_internal__nid).to.be.a('number');
        expect(app.attributes.drupal_internal__nid).to.be.greaterThan(0);
      });
    });

    it("Supports pagination", () => {
      cy.request({
        method: 'GET',
        url: '/jsonapi/node/appverse_app?page[limit]=2',
        headers: {
          'Accept': 'application/vnd.api+json'
        },
        failOnStatusCode: false
      }).then((response) => {
        if (response.status === 404) {
          cy.log('JSON:API endpoint for appverse_app not available');
          return;
        }

        expect(response.status).to.eq(200);
        expect(response.body.data.length).to.be.at.most(2);

        // Should have pagination links if more data exists
        expect(response.body.links).to.have.property('self');
      });
    });

    it("Supports filtering by status", () => {
      cy.request({
        method: 'GET',
        url: '/jsonapi/node/appverse_app?filter[status]=1',
        headers: {
          'Accept': 'application/vnd.api+json'
        },
        failOnStatusCode: false
      }).then((response) => {
        if (response.status === 404) {
          cy.log('JSON:API endpoint for appverse_app not available');
          return;
        }

        expect(response.status).to.eq(200);

        // All returned items should be published
        response.body.data.forEach((app) => {
          expect(app.attributes.status).to.eq(true);
        });
      });
    });

    it("Can include related software entity", () => {
      cy.request({
        method: 'GET',
        url: '/jsonapi/node/appverse_app?include=field_appverse_software_implemen',
        headers: {
          'Accept': 'application/vnd.api+json'
        },
        failOnStatusCode: false
      }).then((response) => {
        if (response.status === 404) {
          cy.log('JSON:API endpoint for appverse_app not available');
          return;
        }

        expect(response.status).to.eq(200);

        // If includes are present, they should be in the included array
        if (response.body.included && response.body.included.length > 0) {
          const softwareInclude = response.body.included.find(
            (item) => item.type === 'node--appverse_software'
          );
          // Software includes should have expected fields if present
          if (softwareInclude) {
            expect(softwareInclude.attributes).to.have.property('title');
          }
        }
      });
    });
  });

  describe("Appverse Software API", () => {
    it("Returns valid JSON:API response", () => {
      cy.request({
        method: 'GET',
        url: '/jsonapi/node/appverse_software',
        headers: {
          'Accept': 'application/vnd.api+json'
        },
        failOnStatusCode: false
      }).then((response) => {
        // Skip if endpoint not available
        if (response.status === 404) {
          cy.log('JSON:API endpoint for appverse_software not available');
          return;
        }

        expect(response.status).to.eq(200);
        expect(response.headers['content-type']).to.include('application/vnd.api+json');
        expect(response.body).to.have.property('data');
      });
    });

    it("Returns expected attributes for appverse_software", () => {
      cy.request({
        method: 'GET',
        url: '/jsonapi/node/appverse_software',
        headers: {
          'Accept': 'application/vnd.api+json'
        },
        failOnStatusCode: false
      }).then((response) => {
        if (response.status === 404 || !response.body.data || response.body.data.length === 0) {
          cy.log('No appverse_software data available');
          return;
        }

        const software = response.body.data[0];

        // Verify type
        expect(software.type).to.eq('node--appverse_software');

        // Verify required attributes exist
        const expectedAttributes = [
          'drupal_internal__nid',
          'title',
          'status',
          'body'
        ];

        expectedAttributes.forEach((attr) => {
          expect(software.attributes).to.have.property(attr);
        });
      });
    });
  });

  describe("Appverse allowlist (D8-2831)", () => {
    // The call the appverse frontend makes for the list/detail views.
    const frontendUrl = '/jsonapi/node/appverse_app?filter[status]=1'
      + '&include=field_appverse_app_type,field_add_implementation_tags,field_appverse_organization,field_license'
      + '&fields[node--appverse_app]=title,body,field_appverse_github_url,field_appverse_readme,field_appverse_lastupdated,field_appverse_stars,flag_count,drupal_internal__nid,path,field_appverse_software_implemen,field_appverse_repo,field_appverse_app_type,field_add_implementation_tags,field_appverse_organization,field_appverse_maintainer_name,field_license'
      + '&fields[taxonomy_term--appverse_app_type]=name'
      + '&fields[taxonomy_term--appverse_organization]=name'
      + '&fields[taxonomy_term--appverse_license]=name';

    // Relationship field => resource type that must appear in `included`.
    const includeMap = {
      field_appverse_app_type: 'taxonomy_term--appverse_app_type',
      field_add_implementation_tags: 'taxonomy_term--appverse_implementation_tags',
      field_appverse_organization: 'taxonomy_term--appverse_organization',
      field_license: 'taxonomy_term--appverse_license',
    };

    const hasRelated = (app, field) => {
      const data = app.relationships && app.relationships[field] && app.relationships[field].data;
      return Array.isArray(data) ? data.length > 0 : !!data;
    };

    beforeEach(() => {
      cy.clearCookies();
    });

    it("frontend list call includes related taxonomy terms", () => {
      cy.request({
        url: frontendUrl,
        headers: { 'Accept': 'application/vnd.api+json' },
        failOnStatusCode: false,
      }).then((response) => {
        expect(response.status).to.eq(200);
        expect(response.body.data).to.be.an('array');
        const included = response.body.included || [];

        // A missing allowlist entry silently drops included data, so assert
        // each type whenever some app actually references it.
        Object.entries(includeMap).forEach(([field, type]) => {
          if (response.body.data.some((app) => hasRelated(app, field))) {
            expect(
              included.some((item) => item.type === type),
              `included contains ${type}`
            ).to.eq(true);
          } else {
            cy.log(`No app has ${field} populated; skipping ${type}`);
          }
        });
      });
    });

    it("appverse_app items expose the taxonomy relationships", () => {
      cy.request({
        url: '/jsonapi/node/appverse_app',
        headers: { 'Accept': 'application/vnd.api+json' },
        failOnStatusCode: false,
      }).then((response) => {
        expect(response.status).to.eq(200);
        if (response.body.data.length === 0) {
          cy.log('No appverse_app data available');
          return;
        }
        Object.keys(includeMap).forEach((field) => {
          expect(response.body.data[0].relationships).to.have.property(field);
        });
      });
    });

    it("GET /jsonapi/flagging/appverse_apps returns a data array", () => {
      cy.request({
        url: '/jsonapi/flagging/appverse_apps',
        headers: { 'Accept': 'application/vnd.api+json' },
        failOnStatusCode: false,
      }).then((response) => {
        expect(response.status).to.eq(200);
        expect(response.body).to.have.property('data');
        expect(response.body.data).to.be.an('array');
      });
    });
  });

  describe("Session Token", () => {
    it("Returns a valid CSRF token", () => {
      cy.request('/session/token').then((response) => {
        expect(response.status).to.eq(200);
        expect(response.body).to.be.a('string');
        expect(response.body.length).to.be.greaterThan(20);
        // Token should be URL-safe base64-like characters
        expect(response.body).to.match(/^[A-Za-z0-9_-]+$/);
      });
    });
  });
});
