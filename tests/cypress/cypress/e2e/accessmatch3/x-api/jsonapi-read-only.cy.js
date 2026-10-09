/**
 * D8-2832: JSON:API is read-only.
 *
 * Nothing on the site writes through JSON:API; the MCP server and appverse
 * write only to custom routes (/api/*, /appverse/*). These specs assert core's
 * read-only mode rejects JSON:API writes with 405, reads still work, and
 * custom write routes are not caught by it.
 */
describe("Test JSON:API is read-only", () => {

  const collection = '/jsonapi/node/affinity_group';
  const jsonApiHeaders = {
    'Content-Type': 'application/vnd.api+json',
    'Accept': 'application/vnd.api+json',
  };
  const body = {
    data: {
      type: 'node--affinity_group',
      attributes: { title: 'D8-2832 read-only probe' },
    },
  };

  context("anonymous", () => {
    beforeEach(() => {
      cy.clearCookies();
    });

    it("POST /jsonapi/node/affinity_group returns 405", () => {
      cy.request({
        method: 'POST',
        url: collection,
        headers: jsonApiHeaders,
        body,
        failOnStatusCode: false,
      }).then((response) => {
        expect(response.status).to.eq(405);
        expect(JSON.stringify(response.body)).to.include('only read operations');
      });
    });

    it("GET /jsonapi/node/affinity_group still returns 200 with data", () => {
      cy.request({ url: collection, failOnStatusCode: false }).then((response) => {
        expect(response.status).to.eq(200);
        expect(response.body).to.have.property('data');
        expect(response.body.data).to.be.an('array');
      });
    });

    it("POST /api/suggest-tags is not blocked by read-only mode", () => {
      cy.request({
        method: 'POST',
        url: '/api/suggest-tags',
        body: {},
        failOnStatusCode: false,
      }).then((response) => {
        expect(response.status).to.not.eq(405);
      });
    });
  });

  context("administrator", () => {
    beforeEach(() => {
      cy.loginAs('administrator@amptesting.com', 'b8QW]X9h7#5n');
    });

    it("POST, PATCH and DELETE on affinity_group return 405 and change nothing", () => {
      cy.request({
        method: 'POST',
        url: collection,
        headers: jsonApiHeaders,
        body,
        failOnStatusCode: false,
      }).then((response) => {
        expect(response.status).to.eq(405);
      });

      cy.request({ url: `${collection}?page[limit]=1`, failOnStatusCode: false }).then((response) => {
        expect(response.status).to.eq(200);
        expect(response.body.data).to.have.length.greaterThan(0);
        const uuid = response.body.data[0].id;
        const title = response.body.data[0].attributes.title;
        const url = `${collection}/${uuid}`;

        cy.request({
          method: 'PATCH',
          url,
          headers: jsonApiHeaders,
          body: {
            data: {
              type: 'node--affinity_group',
              id: uuid,
              attributes: { title: 'D8-2832 changed' },
            },
          },
          failOnStatusCode: false,
        }).then((patchResponse) => {
          expect(patchResponse.status).to.eq(405);
        });

        cy.request({
          method: 'DELETE',
          url,
          headers: jsonApiHeaders,
          failOnStatusCode: false,
        }).then((deleteResponse) => {
          expect(deleteResponse.status).to.eq(405);
        });

        cy.request({ url, failOnStatusCode: false }).then((getResponse) => {
          expect(getResponse.status).to.eq(200);
          expect(getResponse.body.data.id).to.eq(uuid);
          expect(getResponse.body.data.attributes.title).to.eq(title);
        });
      });
    });

    it("GET /jsonapi/views/mcp_my_affinity_groups/page_1 still returns 200", () => {
      cy.request({
        url: '/jsonapi/views/mcp_my_affinity_groups/page_1',
        failOnStatusCode: false,
      }).then((response) => {
        expect(response.status).to.eq(200);
        expect(response.body).to.have.property('data');
        expect(response.body.data).to.be.an('array');
      });
    });
  });

});
