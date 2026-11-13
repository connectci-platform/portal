/**
 * D8-3101: webform submission view_any on the `resource` webform is limited to
 * admin roles. Public (approved) resources stay visible through the KB
 * listing and detail pages; pending resources are not reachable through the
 * webform URLs by anonymous or ordinary authenticated users.
 *
 * Fixtures are created through the UI as administrator (createKbResource) and
 * removed in after() with deleteSubmissions, so no sid is hardcoded.
 */
import {
  ADMIN,
  AUTHENTICATED,
  createKbResource,
  deleteSubmissions,
  RESOURCE_ITEMS,
} from "../../../support/kb-resources";

const stamp = Date.now();
const APPROVED_TITLE = `D8-3101 approved resource ${stamp}`;
const PENDING_TITLE = `D8-3101 pending resource ${stamp}`;

// Access denied is a 403, or a redirect to the login page for anonymous users.
const expectDenied = (url) => {
  cy.request({ url, failOnStatusCode: false, followRedirect: false }).then((res) => {
    const location = res.headers.location || "";
    const denied =
      res.status === 403 ||
      (res.status >= 300 && res.status < 400 && /\/user(\/login|\?|$)|\/f64816be/.test(location));
    expect(denied, `${url} denied (status ${res.status}, location "${location}")`).to.eq(true);
  });
};

describe("D8-3101: resource webform submission access", () => {
  let approvedSid;
  let pendingSid;

  before(() => {
    cy.loginAs(...ADMIN);
    createKbResource({ title: APPROVED_TITLE, tags: ["xalt"], approved: true }).then((sid) => {
      approvedSid = sid;
    });
    createKbResource({ title: PENDING_TITLE, tags: ["xalt"], approved: false }).then((sid) => {
      pendingSid = sid;
    });
  });

  after(() => {
    cy.loginAs(...ADMIN);
    deleteSubmissions([approvedSid, pendingSid]);
  });

  context("logged out", () => {
    beforeEach(() => {
      cy.clearCookies();
    });

    it("denies the webform results listing", () => {
      expectDenied("/admin/structure/webform/manage/resource/results/submissions");
    });

    it("denies the webform submission page of a pending resource", () => {
      expectDenied(`/webform/resource/submissions/${pendingSid}`);
    });

    it("shows an approved public resource on its detail page", () => {
      cy.visit(`/knowledge-base/resources/${approvedSid}`);
      cy.contains(APPROVED_TITLE);
    });

    it("does not show a pending resource on its detail page", () => {
      cy.request({
        url: `/knowledge-base/resources/${pendingSid}`,
        failOnStatusCode: false,
      }).then((res) => {
        expect(res.body).not.to.contain(PENDING_TITLE);
      });
    });

    it("lists resource cards on the knowledge base listing", () => {
      cy.visit("/knowledge-base/resources");
      cy.get(RESOURCE_ITEMS).should("have.length.greaterThan", 0);
      cy.get("body").should("not.contain", PENDING_TITLE);
    });
  });

  context("authenticated (non-admin) user", () => {
    it("is denied the pending resource's webform submission page", () => {
      cy.loginAs(...AUTHENTICATED);
      cy.request({
        url: `/webform/resource/submissions/${pendingSid}`,
        failOnStatusCode: false,
      })
        .its("status")
        .should("eq", 403);
    });

    it("is denied the webform results listing", () => {
      cy.loginAs(...AUTHENTICATED);
      cy.request({
        url: "/admin/structure/webform/manage/resource/results/submissions",
        failOnStatusCode: false,
      })
        .its("status")
        .should("eq", 403);
    });
  });
});
