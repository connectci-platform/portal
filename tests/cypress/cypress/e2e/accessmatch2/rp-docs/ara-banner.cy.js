describe("ARA Recommendation Banner", () => {

  beforeEach(() => {
    cy.clearLocalStorage();
  });

  it("shows banner when arriving with ara_context param", () => {
    cy.visit("/documentation/resources/alpha?ara_context=Recommended+for+QGIS,+Python,+Earth+Sciences");
    cy.get("#ara-recommendation-banner").should("be.visible");
    cy.contains("The ACCESS Resource Advisor recommends this resource");
    cy.get("#ara-recommendation-text").should("contain", "QGIS");
  });

  it("persists banner on page reload (localStorage)", () => {
    cy.visit("/documentation/resources/alpha?ara_context=Recommended+for+QGIS");
    cy.get("#ara-recommendation-banner").should("be.visible");

    // Revisit without param — banner should still show from localStorage.
    cy.visit("/documentation/resources/alpha");
    cy.get("#ara-recommendation-banner").should("be.visible");
    cy.get("#ara-recommendation-text").should("contain", "QGIS");
  });

  it("dismiss button clears banner and localStorage", () => {
    cy.visit("/documentation/resources/alpha?ara_context=Recommended+for+QGIS");
    cy.get("#ara-recommendation-banner").should("be.visible");

    cy.get("#ara-dismiss").click();
    cy.get("#ara-recommendation-banner").should("not.be.visible");

    // Reload — banner should stay dismissed.
    cy.visit("/documentation/resources/alpha");
    cy.get("#ara-recommendation-banner").should("not.be.visible");
  });

  it("no banner without ara_context param or localStorage", () => {
    cy.visit("/documentation/resources/alpha");
    cy.get("#ara-recommendation-banner").should("not.be.visible");
  });

});

/*
  Structured ARA recommendations (?ara_ref=<opaque id>).

  The page fetches the full recommendation set from the ARA endpoint
  configured in drupalSettings.aspTheme.ara.endpoint and renders this
  resource's entry, keyed by drupalSettings.aspTheme.ara.resourceKey (the
  node's field_access_global_resource_id — see aspTheme.theme). We don't
  know that fixture value ahead of time, so every test here visits the page
  once first to read it off the window, then builds the stubbed payload
  around it and intercepts the ARA endpoint before revisiting with
  ?ara_ref=.
*/
describe("ARA Recommendation Banner — structured (ara_ref)", () => {

  const RP_PATH = "/documentation/resources/alpha";
  const ARA_ENDPOINT_GLOB = "**/api/recommendations/**";

  beforeEach(() => {
    cy.clearLocalStorage();
  });

  function visitAndGetResourceKey() {
    cy.visit(RP_PATH);
    return cy.window()
      .its("drupalSettings.aspTheme.ara.resourceKey")
      .should("be.a", "string");
  }

  it("valid ara_ref renders the structured banner with description and reasons", () => {
    visitAndGetResourceKey().then((resourceKey) => {
      cy.intercept("GET", ARA_ENDPOINT_GLOB, {
        statusCode: 200,
        body: {
          resources: {
            [resourceKey]: {
              description: "Great fit for your GPU-accelerated workload.",
              reasons: [
                { type: "hardware", label: "Has A100 GPUs" },
                { type: "software", label: "TensorFlow preinstalled" },
              ],
            },
          },
        },
      }).as("araFetch");

      cy.visit(`${RP_PATH}?ara_ref=rec-valid-123`);
      cy.wait("@araFetch");

      cy.get("#ara-recommendation-banner").should("be.visible");
      cy.get("#ara-recommendation-text")
        .should("contain", "Great fit for your GPU-accelerated workload.");
      cy.get("#ara-recommendation-reasons").should("exist");
      cy.get("#ara-recommendation-reasons").should("contain", "Has A100 GPUs");
      cy.get("#ara-recommendation-reasons").should("contain", "TensorFlow preinstalled");
    });
  });

  it("unknown ref (404) shows no banner and logs no console errors", () => {
    visitAndGetResourceKey().then(() => {
      cy.intercept("GET", ARA_ENDPOINT_GLOB, {
        statusCode: 404,
        body: "Not Found",
      }).as("araFetch404");

      cy.visit(`${RP_PATH}?ara_ref=rec-unknown`, {
        onBeforeLoad(win) {
          cy.stub(win.console, "error").as("consoleError");
        },
      });
      cy.wait("@araFetch404");

      cy.get("#ara-recommendation-banner").should("not.be.visible");
      cy.get("@consoleError").should("not.have.been.called");
    });
  });

  it("structured banner persists across a reload with no query param, from cache", () => {
    visitAndGetResourceKey().then((resourceKey) => {
      cy.intercept("GET", ARA_ENDPOINT_GLOB, {
        statusCode: 200,
        body: {
          resources: {
            [resourceKey]: {
              description: "Recommended for large-memory jobs.",
              reasons: [{ type: "history", label: "You used this before" }],
            },
          },
        },
      }).as("araFetch");

      cy.visit(`${RP_PATH}?ara_ref=rec-persist-1`);
      cy.wait("@araFetch");
      cy.get("#ara-recommendation-banner").should("be.visible");

      // Revisit with no ara_ref and no intercept armed — must render from
      // the cached ara_recommendations entry, not a fresh fetch.
      cy.visit(RP_PATH);
      cy.get("#ara-recommendation-banner").should("be.visible");
      cy.get("#ara-recommendation-text")
        .should("contain", "Recommended for large-memory jobs.");
      cy.get("#ara-recommendation-reasons").should("contain", "You used this before");
    });
  });

  it("expired payload (expires_at in the past) shows no banner after reload", () => {
    visitAndGetResourceKey().then((resourceKey) => {
      cy.intercept("GET", ARA_ENDPOINT_GLOB, {
        statusCode: 200,
        body: {
          expires_at: "2000-01-01T00:00:00Z",
          resources: {
            [resourceKey]: {
              description: "This should never be visible.",
              reasons: [],
            },
          },
        },
      }).as("araFetchExpired");

      cy.visit(`${RP_PATH}?ara_ref=rec-expired`);
      cy.wait("@araFetchExpired");

      cy.visit(RP_PATH);
      cy.get("#ara-recommendation-banner").should("not.be.visible");
    });
  });

  it("renders a description and reason label containing markup as literal text", () => {
    visitAndGetResourceKey().then((resourceKey) => {
      const payload = '<img src=x onerror=alert(1)>';
      cy.intercept("GET", ARA_ENDPOINT_GLOB, {
        statusCode: 200,
        body: {
          resources: {
            [resourceKey]: {
              description: `Malicious description ${payload}`,
              reasons: [{ type: "software", label: `Malicious reason ${payload}` }],
            },
          },
        },
      }).as("araFetchXss");

      cy.visit(`${RP_PATH}?ara_ref=rec-xss`);
      cy.wait("@araFetchXss");

      cy.get("#ara-recommendation-banner").should("be.visible");
      cy.get("#ara-recommendation-text").should("contain.text", payload);
      cy.get("#ara-recommendation-reasons").should("contain.text", payload);

      cy.get("body").then(() => {
        expect(Cypress.$("#ara-recommendation-text img").length).to.eq(0);
        expect(Cypress.$("#ara-recommendation-reasons img").length).to.eq(0);
      });
    });
  });

});
