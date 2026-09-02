/*
 * Verify the /api-docs landing page and the Content and SDS API cards.
 * Also asserts that the served SDS spec is key-gated (X-API-Key) and points to
 * the ACCESS help ticket for key requests.
 *
 * Routes:
 *   /api-docs            — landing page listing all API cards (public)
 *   /api-docs/content    — ACCESS Content API swagger UI (public)
 *   /api-docs/sds        — ACCESS SDS API swagger UI (public)
 *   /openapi/access_sds?_format=json — raw SDS OpenAPI spec (requires
 *                          'access openapi api docs' permission; admin only)
 *
 * The first three tests use cy.request() anonymously (anonymous role has
 * 'access content').  The fourth test logs in as administrator via a
 * programmatic form POST so that we can assert the spec content without
 * triggering cy.visit() / cy.loginAs(), which crashes the renderer process
 * when the prior tests have used only cy.request().
 */

describe("API Docs landing + new cards", () => {
  it("landing page lists the Content, Resource Documentation, and SDS cards", () => {
    cy.request("/api-docs").then((r) => {
      expect(r.status).to.eq(200);
      expect(r.body).to.contain("ACCESS Content API");
      expect(r.body).to.contain("ACCESS Resource Documentation API");
      expect(r.body).to.contain("ACCESS SDS");
    });
  });

  it("Content API swagger sub-page loads", () => {
    cy.request("/api-docs/content").then((r) => expect(r.status).to.eq(200));
  });

  it("Resource Documentation API swagger sub-page loads", () => {
    cy.request("/api-docs/resources").then((r) => expect(r.status).to.eq(200));
  });

  it("SDS swagger sub-page loads", () => {
    cy.request("/api-docs/sds").then((r) => expect(r.status).to.eq(200));
  });

  it("served SDS spec is key-gated and points to the help ticket for access", () => {
    // Log in as administrator via programmatic form POST to avoid cy.visit()
    // (which crashes the Electron renderer when the prior tests used only
    // cy.request() and the browser context has not yet navigated anywhere).
    //
    // Step 1: GET the bypass-CILogon login page to obtain the form_build_id.
    cy.request("/f64816be-34ca-4d5b-975a-687cb374ddf7").then((loginPage) => {
      const html = loginPage.body;
      const match = html.match(/name="form_build_id"\s+value="([^"]+)"/);
      expect(match, "form_build_id should exist on login page").to.not.be.null;
      const formBuildId = match[1];

      // Step 2: POST login credentials.
      cy.request({
        method: "POST",
        url: "/f64816be-34ca-4d5b-975a-687cb374ddf7",
        form: true,
        body: {
          name: "administrator@amptesting.com",
          pass: "b8QW]X9h7#5n",
          form_build_id: formBuildId,
          form_id: "user_login_form",
          op: "Log in",
        },
        followRedirect: true,
      }).then(() => {
        // Step 3: Now fetch the spec — session cookie is shared by cy.request().
        // The openapi.download route is /openapi/{generator}?_format=json
        cy.request("/openapi/access_sds?_format=json").then((r) => {
          expect(r.status).to.eq(200);
          const spec =
            typeof r.body === "string" ? JSON.parse(r.body) : r.body;
          // The SDS card is key-gated: it documents an X-API-Key apiKey scheme
          // and directs key requests to the ACCESS help ticket. It deliberately
          // does NOT advertise the external host/operator, so we assert the
          // key-gating and the help-ticket guidance rather than provenance.
          expect(spec.info.description).to.contain("help-ticket");
          expect(spec.components.securitySchemes).to.have.property(
            "ApiKeyAuth"
          );
          expect(spec.components.securitySchemes.ApiKeyAuth.name).to.eq(
            "X-API-Key"
          );
          expect(spec.servers[0].url).to.eq("https://sds-ara-api.access-ci.org");
        });
      });
    });
  });
});
