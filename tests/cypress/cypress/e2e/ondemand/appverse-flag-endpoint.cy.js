/**
 * Appverse "We use this App" flag endpoint.
 *
 * Covers the custom flag API:
 *   POST   /appverse/api/flag/{nid}  (X-CSRF-Token) → 200 {"flagged": true,  "changed": bool, "nid": N}
 *   DELETE /appverse/api/flag/{nid}  (X-CSRF-Token) → 200 {"flagged": false, "changed": bool, "nid": N}
 *   GET    /appverse/api/flags                      → 200 {"flagged": [{"uuid": U, "nid": N}]}
 *
 * Writes are idempotent; "changed" is true only if the request changed state.
 * Anonymous requests → 403, missing X-CSRF-Token → 403,
 * a node that is not an appverse_app → 404. The flagging--appverse_apps
 * JSON:API resource is disabled, so /jsonapi/flagging/appverse_apps → 404.
 *
 * This spec does NOT create apps. It flags/unflags an existing published
 * appverse_app fetched in before(). Every test that changes flag state ends
 * with the test user unflagged (see after()).
 *
 * Login helper and test user match the other ondemand appverse specs.
 */

const ADMIN_EMAIL = 'administrator@amptesting.com';
const ADMIN_PASS = 'b8QW]X9h7#5n';

const JSONAPI_HEADERS = { Accept: 'application/vnd.api+json' };

// Labels from ood-appverse/src/components/common/FlagButton.jsx (aria-label/title).
const LABEL_UNFLAGGED = 'We use this App';
const LABEL_FLAGGED = 'Remove from my apps';

describe('Appverse flag endpoint (/appverse/api/flag/{nid})', () => {
  // Populated in before() from an existing published appverse_app.
  let app = null;

  /**
   * Fetch a fresh CSRF token for the current session.
   * Must be called after logging in, since the token is session-bound.
   */
  const getCsrfToken = () =>
    cy.request('/session/token').then((response) => {
      expect(response.status).to.eq(200);
      return response.body;
    });

  /**
   * Read the current user's flagged apps and return whether the app is among them.
   */
  const isFlagged = () =>
    cy
      .request({
        method: 'GET',
        url: '/appverse/api/flags?_format=json',
        headers: { Accept: 'application/json' },
      })
      .then((response) => {
        expect(response.status).to.eq(200);
        expect(response.body.flagged).to.be.an('array');
        const entry = response.body.flagged.find((item) => item.nid === app.nid);
        if (entry) {
          expect(entry).to.deep.equal({ uuid: app.uuid, nid: app.nid });
        }
        return Boolean(entry);
      });

  before(() => {
    // Read-only lookup of an existing published app. No app is created here.
    cy.request({
      method: 'GET',
      url: '/jsonapi/node/appverse_app?filter[status]=1&page[limit]=1',
      headers: JSONAPI_HEADERS,
    }).then((response) => {
      expect(response.status).to.eq(200);
      expect(response.body.data, 'published appverse_app fixture').to.have.length.greaterThan(0);

      const node = response.body.data[0];
      app = {
        nid: node.attributes.drupal_internal__nid,
        uuid: node.id,
        path: node.attributes.path?.alias || `/node/${node.attributes.drupal_internal__nid}`,
      };

      expect(app.nid).to.be.a('number').and.be.greaterThan(0);
      expect(app.uuid).to.be.a('string').and.not.be.empty;
    });
  });

  describe('Anonymous user', () => {
    it('POST /appverse/api/flag/{nid} returns 403', () => {
      cy.request({
        method: 'POST',
        url: `/appverse/api/flag/${app.nid}?_format=json`,
        headers: { Accept: 'application/json' },
        failOnStatusCode: false,
      }).then((response) => {
        expect(response.status).to.eq(403);
      });
    });

    it('GET /appverse/api/flags returns 403', () => {
      cy.request({
        method: 'GET',
        url: '/appverse/api/flags?_format=json',
        headers: { Accept: 'application/json' },
        failOnStatusCode: false,
      }).then((response) => {
        expect(response.status).to.eq(403);
      });
    });
  });

  describe('Authenticated user', () => {
    beforeEach(() => {
      cy.loginUser(ADMIN_EMAIL, ADMIN_PASS);
    });

    it('JSON:API write to /jsonapi/flagging/appverse_apps returns 404 (resource disabled)', () => {
      // A valid CSRF token is sent so the 404 comes from the disabled JSON:API
      // resource, not from a CSRF rejection.
      getCsrfToken().then((token) => {
        cy.request({
          method: 'POST',
          url: '/jsonapi/flagging/appverse_apps',
          headers: {
            Accept: 'application/vnd.api+json',
            'Content-Type': 'application/vnd.api+json',
            'X-CSRF-Token': token,
          },
          body: {
            data: {
              type: 'flagging--appverse_apps',
              attributes: {},
              relationships: {
                flagged_entity: {
                  data: { type: 'node--appverse_app', id: app.uuid },
                },
              },
            },
          },
          failOnStatusCode: false,
        }).then((response) => {
          expect(response.status).to.eq(404);
        });
      });
    });

    it('POST /appverse/api/flag/{nid} without X-CSRF-Token returns 403', () => {
      cy.request({
        method: 'POST',
        url: `/appverse/api/flag/${app.nid}`,
        failOnStatusCode: false,
      }).then((response) => {
        expect(response.status).to.eq(403);
      });
    });

    it('round trip: DELETE, POST (twice), GET /appverse/api/flags shows flag, DELETE, GET shows no flag', () => {
      getCsrfToken().then((token) => {
        const flagHeaders = { 'X-CSRF-Token': token, Accept: 'application/json' };
        const flagUrl = `/appverse/api/flag/${app.nid}`;

        // Start from a known unflagged state. DELETE is idempotent.
        cy.request({ method: 'DELETE', url: flagUrl, headers: flagHeaders }).then((response) => {
          expect(response.status).to.eq(200);
          expect(response.body).to.deep.include({ flagged: false, nid: app.nid });
        });

        isFlagged().should('eq', false);

        // First flag.
        cy.request({ method: 'POST', url: flagUrl, headers: flagHeaders }).then((response) => {
          expect(response.status).to.eq(200);
          expect(response.body).to.deep.include({ flagged: true, changed: true, nid: app.nid });
        });

        // Second flag is a no-op and still succeeds (idempotent).
        cy.request({ method: 'POST', url: flagUrl, headers: flagHeaders }).then((response) => {
          expect(response.status).to.eq(200);
          expect(response.body).to.deep.include({ flagged: true, changed: false, nid: app.nid });
        });

        isFlagged().should('eq', true);

        // Unflag.
        cy.request({ method: 'DELETE', url: flagUrl, headers: flagHeaders }).then((response) => {
          expect(response.status).to.eq(200);
          expect(response.body).to.deep.include({ flagged: false, changed: true, nid: app.nid });
        });

        isFlagged().should('eq', false);
      });
    });
  });

  describe('Cleanup', () => {
    after(() => {
      // Leave the test user unflagged. Log in again because cookies do not
      // carry over from the last test under testIsolation.
      if (!app) {
        return;
      }
      cy.loginUser(ADMIN_EMAIL, ADMIN_PASS);
      getCsrfToken().then((token) => {
        cy.request({
          method: 'DELETE',
          url: `/appverse/api/flag/${app.nid}`,
          headers: { 'X-CSRF-Token': token, Accept: 'application/json' },
        }).then((response) => {
          expect(response.status).to.eq(200);
          expect(response.body).to.deep.include({ flagged: false, nid: app.nid });
        });
      });
    });
  });

  // TODO: enable once ood-appverse is rebuilt against /appverse/api/flag (D8-2920).
  // The live React bundle is loaded from jsDelivr and still writes through
  // JSON:API, so this UI flow cannot pass until the rebuilt bundle is deployed.
  it.skip('UI: "We use this App" flags, persists across reload, and unflags', () => {
    cy.loginUser(ADMIN_EMAIL, ADMIN_PASS);

    // Start unflagged so the first click is a flag.
    getCsrfToken().then((token) => {
      cy.request({
        method: 'DELETE',
        url: `/appverse/api/flag/${app.nid}`,
        headers: { 'X-CSRF-Token': token, Accept: 'application/json' },
      });
    });

    cy.intercept('POST', '**/appverse/api/flag/*').as('flagPost');
    cy.intercept('DELETE', '**/appverse/api/flag/*').as('flagDelete');

    cy.visit(app.path);

    const flagButton = (label) => cy.get(`button[aria-label="${label}"]`);

    // Flag.
    flagButton(LABEL_UNFLAGGED).should('be.visible').click();
    cy.wait('@flagPost').its('response.statusCode').should('eq', 200);
    flagButton(LABEL_FLAGGED).should('be.visible');

    // Flag persists after reload.
    cy.reload();
    flagButton(LABEL_FLAGGED).should('be.visible');

    // Unflag.
    flagButton(LABEL_FLAGGED).click();
    cy.wait('@flagDelete').its('response.statusCode').should('eq', 200);
    flagButton(LABEL_UNFLAGGED).should('be.visible');
  });
});
