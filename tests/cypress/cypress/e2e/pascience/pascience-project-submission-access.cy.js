/**
 * D8-3101: webform submission view_any on the `project` webform is limited to
 * admin roles. Approved projects stay public on /project/<sid>; unapproved
 * projects are not visible to anonymous users, and nobody but admins reaches
 * the webform submission URLs.
 *
 * Projects are created through /form/project as administrator (same selectors
 * as pascience-projects-submit-admin.cy.js), so run with
 * CYPRESS_BASE_URL=https://pasciencedmz.ddev.site. Created projects are removed
 * in after() with drush.
 */
import { ADMIN, deleteSubmissions } from "../../support/kb-resources";

const stamp = Date.now();
const APPROVED_TITLE = `D8-3101 approved project ${stamp}`;
const UNAPPROVED_TITLE = `D8-3101 unapproved project ${stamp}`;

const expectDenied = (url) => {
  cy.request({ url, failOnStatusCode: false, followRedirect: false }).then((res) => {
    const location = res.headers.location || "";
    const denied =
      res.status === 403 ||
      (res.status >= 300 && res.status < 400 && /\/user(\/login|\?|$)|\/f64816be/.test(location));
    expect(denied, `${url} denied (status ${res.status}, location "${location}")`).to.eq(true);
  });
};

const createProject = (title, approved) => {
  cy.visit("/form/project");
  cy.get("#edit-approved-milestones").check();
  if (approved) {
    cy.get("#edit-approved").check();
  }
  cy.get("#edit-project-title").type(title);
  cy.get("#edit-region-933").check(); // PA Science program
  cy.get('select[name="status"]').select("In Progress");
  cy.get("#edit-project-leader-first").type("Harry");
  cy.get("#edit-project-leader-last").type("Potter");
  cy.get("#edit-project-description").type(`Description for ${title}`);
  cy.get("#edit-actions-01-submit").click();
  return cy
    .location("pathname")
    .should("match", /\/project\/\d+$/)
    .then((path) => Number(path.split("/").pop()));
};

describe("D8-3101: project webform submission access", () => {
  let approvedSid;
  let unapprovedSid;

  before(() => {
    cy.loginUser(...ADMIN);
    createProject(APPROVED_TITLE, true).then((sid) => {
      approvedSid = sid;
    });
    createProject(UNAPPROVED_TITLE, false).then((sid) => {
      unapprovedSid = sid;
    });
  });

  after(() => {
    cy.loginUser(...ADMIN);
    deleteSubmissions([approvedSid, unapprovedSid]);
  });

  context("logged out", () => {
    beforeEach(() => {
      cy.clearCookies();
    });

    it("denies the webform results listing", () => {
      expectDenied("/admin/structure/webform/manage/project/results/submissions");
    });

    it("denies the webform submission page of an unapproved project", () => {
      expectDenied(`/webform/project/submissions/${unapprovedSid}`);
    });

    it("shows an approved project on its public page", () => {
      cy.visit(`/project/${approvedSid}`);
      cy.contains(APPROVED_TITLE);
    });

    it("does not show an unapproved project on its public page", () => {
      cy.request({
        url: `/project/${unapprovedSid}`,
        failOnStatusCode: false,
      }).then((res) => {
        expect(res.body).not.to.contain(UNAPPROVED_TITLE);
      });
    });
  });
});
