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
  Structured ARA recommendations (?ara_data=<base64url JSON>).

  The fixtures are the two example links the ARA team sent on D8-2762
  (2026-09-10 and 2026-09-11), kept verbatim. Both are for Delta GPU, so the
  matching-resource tests decode them, swap in the alpha test resource's
  global resource id and re-encode; the verbatim payloads double as the
  "different resource" case. Both arrive without base64 padding.
*/
describe("ARA Recommendation Banner — ara_data", () => {

  const RP_PATH = "/documentation/resources/alpha";
  const ALPHA_GLOBAL_ID = "alpha.test.access-ci.org";

  // Example link 1 (2026-09-10): has `blurb` but no `rp_docs_description`.
  const EXAMPLE_1 = {
    araContext: "Recommended for 256.0 GB Memory AMD MI210 GPU",
    araData: "eyJnbG9iYWxfcmVzb3VyY2VfaWQiOiJkZWx0YS1ncHUubmNzYS5hY2Nlc3MtY2kub3JnIiwibmFtZSI6IkRlbHRhIEdQVSIsInNjb3JlIjoyMywicmVhc29ucyI6WyIyNTYuMCBHQiBNZW1vcnkiLCJBTUQgTUkyMTAiLCJHUFUiXSwidG9vbHRpcCI6IiIsImJsdXJiIjoiRGVsdGEgR1BVIGlzIGEgZ29vZCBmaXQgZm9yIHlvdSBiZWNhdXNlIGl0IG1hdGNoZXMgeW91ciBleGFjdCByZXF1aXJlbWVudHMgd2l0aCBpdHMgMjU2LjAgR0IgTWVtb3J5IGFuZCBBTUQgTUkyMTAgR1BVLiBXaXRoIGl0cyBtaXhlZCBhcmNoaXRlY3R1cmUgb2YgQU1EIE1JMTAwL01JMjEwIG5vZGVzLCBEZWx0YSBHUFUncyBBTUQgTUkyMTAgR1BVIHNwZWNpZmljYWxseSBhbGlnbnMgd2l0aCB5b3VyIHNwZWNpZmllZCBuZWVkIGZvciBhbiBBTUQtYmFzZWQgR1BVLiBBZGRpdGlvbmFsbHksIHRoZSBzeXN0ZW0gb2ZmZXJzIGEgcmFuZ2Ugb2Ygc29mdHdhcmUgb3B0aW1pemVkIGZvciBBSSBhbmQgbWFjaGluZSBsZWFybmluZyB0cmFpbmluZyBhbmQgaW5mZXJlbmNlLCBtYWtpbmcgaXQgd2VsbC1zdWl0ZWQgdG8gc3VwcG9ydCB5b3VyIGNvbXB1dGF0aW9uYWwgbmVlZHMuIn0",
  };

  // Example link 2 (2026-09-11): the current contract, with `rp_docs_description`.
  const EXAMPLE_2 = {
    araContext: "Recommended for NVIDIA H200 141 GB 256.0 GB Memory aocc-mixed GPU Biological Sciences",
    araData: "eyJnbG9iYWxfcmVzb3VyY2VfaWQiOiJkZWx0YS1ncHUubmNzYS5hY2Nlc3MtY2kub3JnIiwibmFtZSI6IkRlbHRhIEdQVSIsInNjb3JlIjoyMzQsInJlYXNvbnMiOlsiTlZJRElBIEgyMDAgMTQxIEdCIiwiMjU2LjAgR0IgTWVtb3J5IiwiYW9jYy1taXhlZCIsIkdQVSIsIkJpb2xvZ2ljYWwgU2NpZW5jZXMiXSwidG9vbHRpcCI6IiIsImJsdXJiIjoiRGVsdGEgR1BVIGlzIGEgZ29vZCBmaXQgZm9yIHlvdSBiZWNhdXNlIGl0IHByb3ZpZGVzIHRoZSBzcGVjaWZpYyBzb2Z0d2FyZSB0aGF0IHlvdXIgcmVzZWFyY2ggcmVxdWlyZXMuIFlvdSBuZWVkIHRvIHJ1biAnYW9jYy1taXhlZCcsIHdoaWNoIGlzIGF2YWlsYWJsZSBvbiB0aGlzIHBsYXRmb3JtLCBhbmQgYWxzbyBoYXZlIGFjY2VzcyB0byBvdGhlciByZWxldmFudCBwYWNrYWdlcyBzdWNoIGFzICdhYmluaXQnIGFuZCAnYWR2aXNvcicuIEZ1cnRoZXJtb3JlLCBEZWx0YSBHUFUgZmVhdHVyZXMgYSBkaXZlcnNlIHJhbmdlIG9mIGhpZ2gtcGVyZm9ybWFuY2UgR1BVcywgaW5jbHVkaW5nIHRoZSBOVklESUEgSDIwMCB3aXRoIDE0MSBHQiBvZiBSQU0sIHdoaWNoIG1hdGNoZXMgeW91ciByZXF1aXJlbWVudCBmb3IgYXQgbGVhc3Qgb25lIEdQVSB3aXRoIHN1YnN0YW50aWFsIG1lbW9yeS4gVGhpcyBtZWV0cyB5b3VyIHNwZWNpZmllZCBuZWVkIGZvciAnTlZJRElBLUgyMDBfMTQxX29wdGlvbicsIGFuZCBhbHNvIGFsaWducyB3ZWxsIHdpdGggdGhlIGxhcmdlIGFtb3VudCBvZiBtZW1vcnkgeW91IHJlcXVlc3RlZCAoNjQtNTEyKS4gQWRkaXRpb25hbGx5LCBEZWx0YSBHUFUgaXMgcGFydGljdWxhcmx5IHN1aXRlZCB0byBiaW9sb2dpY2FsIHNjaWVuY2VzIHJlc2VhcmNoLCB3aGljaCBpcyB5b3VyIGZpZWxkIG9mIGZvY3VzLiIsInJwX2RvY3NfZGVzY3JpcHRpb24iOiJZb3UgbmVlZCBoaWdoLXBlcmZvcm1hbmNlIG1lbW9yeSBhbmQgbG9uZy10ZXJtIHN0b3JhZ2UgdG8gc3VwcG9ydCB5b3VyIHJlc2VhcmNoIGluIEJpb2xvZ2ljYWwgU2NpZW5jZXMsIGFuZCBEZWx0YSBHUFUncyAyNTYgR0IgTWVtb3J5IGFuZCAxLjUgVEIgbG9jYWwgc3RvcmFnZSBwZXIgbm9kZSB3aWxsIG1lZXQgdGhvc2UgbmVlZHMuIn0",
  };

  function decode(araData) {
    return JSON.parse(Cypress.Buffer.from(araData.replace(/-/g, "+").replace(/_/g, "/"), "base64").toString("utf8"));
  }

  // base64url without padding, the way the ARA sends it.
  function encode(payload) {
    return Cypress.Buffer.from(JSON.stringify(payload), "utf8")
      .toString("base64")
      .replace(/\+/g, "-")
      .replace(/\//g, "_")
      .replace(/=+$/, "");
  }

  function forAlpha(example) {
    return { ...decode(example.araData), global_resource_id: ALPHA_GLOBAL_ID };
  }

  function visitWith(araContext, araData, options = {}) {
    const query = new URLSearchParams({ ara_context: araContext, ara_data: araData });
    cy.visit(`${RP_PATH}?${query}`, options);
  }

  function visitSpyingConsole(araContext, araData) {
    visitWith(araContext, araData, {
      onBeforeLoad(win) {
        cy.spy(win.console, "error").as("consoleError");
      },
    });
  }

  // The QA Bot in @access-ci/ui logs "QA Bot: No valid API key provided" on
  // every page load in CI, so only errors from anything else count.
  function expectNoBannerConsoleErrors() {
    cy.get("@consoleError").then((spy) => {
      const own = spy.getCalls().filter((call) => !String(call.args[0]).startsWith("QA Bot"));
      expect(own, "console.error calls").to.be.empty;
    });
  }

  beforeEach(() => {
    cy.clearLocalStorage();
  });

  it("shows rp_docs_description and the reasons for a matching ara_data", () => {
    const payload = forAlpha(EXAMPLE_2);
    visitWith(EXAMPLE_2.araContext, encode(payload));

    cy.get("#ara-recommendation-banner").should("be.visible");
    cy.get("#ara-recommendation-text").should("have.text", payload.rp_docs_description);
    cy.get("#ara-recommendation-text").should("not.contain", payload.blurb);
    cy.get("#ara-recommendation-reasons li").should("have.length", 5);
    payload.reasons.forEach((reason, i) => {
      cy.get("#ara-recommendation-reasons li").eq(i).should("have.text", reason);
    });
  });

  it("uses ara_context as the body when ara_data has no rp_docs_description", () => {
    const payload = forAlpha(EXAMPLE_1);
    visitWith(EXAMPLE_1.araContext, encode(payload));

    cy.get("#ara-recommendation-banner").should("be.visible");
    cy.get("#ara-recommendation-text").should("have.text", EXAMPLE_1.araContext);
    cy.get("#ara-recommendation-reasons li").should("have.length", 3);
    cy.get("#ara-recommendation-reasons").should("contain", "AMD MI210");
  });

  it("falls back to ara_context when ara_data is for a different resource", () => {
    // Verbatim example link: its payload is for delta-gpu, not alpha.
    visitWith(EXAMPLE_2.araContext, EXAMPLE_2.araData);

    cy.get("#ara-recommendation-banner").should("be.visible");
    cy.get("#ara-recommendation-text").should("have.text", EXAMPLE_2.araContext);
    cy.get("#ara-recommendation-reasons").should("not.exist");
  });

  it("falls back to ara_context with no console errors when ara_data is not base64", () => {
    visitSpyingConsole(EXAMPLE_2.araContext, "%%%not-base64%%%");

    cy.get("#ara-recommendation-text").should("have.text", EXAMPLE_2.araContext);
    cy.get("#ara-recommendation-reasons").should("not.exist");
    expectNoBannerConsoleErrors();
  });

  it("falls back to ara_context with no console errors when ara_data is not JSON", () => {
    // Valid base64url of truncated JSON.
    const notJson = Cypress.Buffer.from('{"global_resource_id": "alpha', "utf8")
      .toString("base64")
      .replace(/=+$/, "");
    visitSpyingConsole(EXAMPLE_2.araContext, notJson);

    cy.get("#ara-recommendation-text").should("have.text", EXAMPLE_2.araContext);
    cy.get("#ara-recommendation-reasons").should("not.exist");
    expectNoBannerConsoleErrors();
  });

  it("renders payload values containing HTML as literal text", () => {
    const markup = "<img src=x onerror=alert(1)>";
    const payload = {
      ...forAlpha(EXAMPLE_2),
      rp_docs_description: `Description ${markup}`,
      reasons: [`Reason ${markup}`],
    };
    visitWith(EXAMPLE_2.araContext, encode(payload));

    cy.get("#ara-recommendation-text").should("have.text", `Description ${markup}`);
    cy.get("#ara-recommendation-reasons li").should("have.text", `Reason ${markup}`);
    cy.get("#ara-recommendation-banner img").should("not.exist");
  });

  it("keeps the ara_data banner on reload and clears it on Dismiss", () => {
    const payload = forAlpha(EXAMPLE_2);
    visitWith(EXAMPLE_2.araContext, encode(payload));
    cy.get("#ara-recommendation-banner").should("be.visible");

    cy.visit(RP_PATH);
    cy.get("#ara-recommendation-text").should("have.text", payload.rp_docs_description);
    cy.get("#ara-recommendation-reasons li").should("have.length", 5);

    cy.get("#ara-dismiss").click();
    cy.get("#ara-recommendation-banner").should("not.be.visible");

    cy.visit(RP_PATH);
    cy.get("#ara-recommendation-banner").should("not.be.visible");
  });

});
