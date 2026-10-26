/*
    Legacy KB Resources URLs redirect to /knowledge-base/resources.

    The listing paths are 301 redirects created by access_cilink update 10001
    (/ci-links, /resources, /knowledge-base/ci-links). The per-resource path
    /knowledge-base/ci-links/{sid} is a route of its own whose controller
    (CiLinkController::redirectToResource) sends a 302 to
    /knowledge-base/resources/{sid}.

    The resource used for the per-resource redirect is created here, so the
    spec does not rely on what other specs or the database left behind.
*/

import { ADMIN, createKbResource, deleteSubmissions } from "../../../support/kb-resources";

const RESOURCES = "/knowledge-base/resources";

describe("KB Resources legacy redirects", () => {
  const sid = {};

  before(() => {
    cy.loginAs(...ADMIN);
    createKbResource({
      title: `kbredirect-${Date.now()}`,
      tags: ["xalt"],
    }).then((id) => {
      sid.value = id;
    });
    cy.drupalLogout();
  });

  after(() => deleteSubmissions([sid.value]));

  ["/ci-links", "/resources", "/knowledge-base/ci-links"].forEach((path) => {
    it(`${path} permanently redirects to the resources listing`, () => {
      cy.request({ url: path, followRedirect: false }).then((response) => {
        expect(response.status).to.eq(301);
        expect(response.headers.location).to.match(/\/knowledge-base\/resources$/);
      });
    });

    it(`${path} ends on the resources listing for a browser`, () => {
      cy.visit(path);
      cy.location("pathname").should("eq", RESOURCES);
      cy.get(".view-search-ci-links").should("exist");
    });
  });

  it("/knowledge-base/ci-links/{sid} redirects to /knowledge-base/resources/{sid}", () => {
    cy.request({
      url: `/knowledge-base/ci-links/${sid.value}`,
      followRedirect: false,
    }).then((response) => {
      expect(response.status).to.eq(302);
      expect(response.headers.location).to.match(
        new RegExp(`/knowledge-base/resources/${sid.value}$`)
      );
    });
  });

  it("the per-resource redirect lands on that resource", () => {
    cy.visit(`/knowledge-base/ci-links/${sid.value}`);
    cy.location("pathname").should("eq", `${RESOURCES}/${sid.value}`);
    cy.get("h1").should("contain", "kbredirect-");
  });
});
