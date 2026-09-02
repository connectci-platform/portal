/*
  Mentorship Copy (D8-2188)

  TDD specs for the access_entity_copy module's "Copy" tab on mentorship
  engagement nodes. Walnut creates a source mentorship, then we exercise
  the copy flow and assert what carries over.

  One spec intentionally fails today and documents the remaining work:
    - visiting /node/add/mentorship_engagement without ?copy= shows a
      misleading "Not a valid mentorship id to copy" status message
*/

const SOURCE_TITLE = "Copy Test Source Mentorship";
const SOURCE_SUMMARY = "Source summary for copy test";
const SOURCE_BODY = "Source body content with <em>some inline markup</em>.";
const SOURCE_ATTRIBUTES = "Looking for someone with HPC experience";

describe("Mentorship Copy", () => {

  let sourceNid;

  before(() => {
    // Seed a source mentorship as walnut and capture its nid from the URL
    // after save so we don't have to find it by title in a listing (which
    // may require approval moderation).
    cy.loginWith("walnut@pie.org", "Walnut");
    cy.visit("/node/add/mentorship_engagement");
    cy.get("#edit-field-me-looking-for-mentor").check();
    cy.get("#edit-title-0-value").type(SOURCE_TITLE);
    cy.get("#edit-body-0-summary").type(SOURCE_SUMMARY);
    cy.get(".form-item-body-0-value .ck-content").then(el => {
      el[0].ckeditorInstance.setData(SOURCE_BODY);
    });
    cy.get(".form-item-field-me-preferred-attributes-0-value .ck-content").then(el => {
      el[0].ckeditorInstance.setData(SOURCE_ATTRIBUTES);
    });
    // Required fields beyond the ones we're testing the copy of.
    cy.get("details.tags summary").click();
    cy.get("#tag-ai").click();
    cy.get("#edit-field-me-state").select("Recruiting");
    // Select the (only) mentorship program so the copy test has something
    // non-trivial to assert against. Term 910 = Campus Champions AI Mentorship.
    cy.get('input[name="field_mentorship_program"][value="910"]').check();
    cy.get(".node-mentorship-engagement-form #edit-submit").click();
    // After save, pathauto redirects to /mentorships/{slug}. Pull the nid
    // from the body class instead of the URL.
    cy.get("body").invoke("attr", "class").then(classes => {
      const match = classes.match(/page-node-(\d+)/);
      sourceNid = match ? parseInt(match[1], 10) : null;
      expect(sourceNid).to.be.a("number");
    });
  });

  describe("Copy tab visibility", () => {

    it("shows Copy tab on a mentorship_engagement node for an authenticated user", () => {
      cy.loginWith("walnut@pie.org", "Walnut");
      cy.visit(`/node/${sourceNid}`);
      cy.contains(".primary-tabs .nav-link", "Copy").should("be.visible");
    });

  });

  describe("Copy flow", () => {

    beforeEach(() => {
      cy.loginWith("walnut@pie.org", "Walnut");
      cy.visit(`/node/${sourceNid}`);
    });

    it("clicking Copy lands on the add form with ?copy= in the URL", () => {
      cy.contains(".primary-tabs .nav-link", "Copy").click();
      cy.url().should("include", "/node/add/mentorship_engagement");
      cy.url().should("match", /[?&]copy=\d+/);
    });

    it("copies the source title into the new form", () => {
      cy.contains(".primary-tabs .nav-link", "Copy").click();
      cy.get("#edit-title-0-value").should("have.value", SOURCE_TITLE);
    });

    it("copies the source body summary into the new form", () => {
      cy.contains(".primary-tabs .nav-link", "Copy").click();
      cy.get("#edit-body-0-summary").should("have.value", SOURCE_SUMMARY);
    });

    it("copies the source body content into the new form", () => {
      cy.contains(".primary-tabs .nav-link", "Copy").click();
      cy.get(".form-item-body-0-value .ck-content").then(el => {
        const data = el[0].ckeditorInstance.getData();
        expect(data).to.include("Source body content");
      });
    });

    it("copies the source preferred attributes into the new form", () => {
      cy.contains(".primary-tabs .nav-link", "Copy").click();
      cy.get(".form-item-field-me-preferred-attributes-0-value .ck-content").then(el => {
        const data = el[0].ckeditorInstance.getData();
        expect(data).to.include("HPC experience");
      });
    });

    it("copies the source looking_for selection", () => {
      cy.contains(".primary-tabs .nav-link", "Copy").click();
      cy.get("#edit-field-me-looking-for-mentor").should("be.checked");
    });

    it("copies the source mentorship_program selection", () => {
      // The widget is options_buttons (radio buttons). Source has program
      // 910 (Campus Champions AI Mentorship) set in the before hook; copy
      // should land with that radio checked.
      cy.contains(".primary-tabs .nav-link", "Copy").click();
      cy.get('input[name="field_mentorship_program"][value="910"]')
        .should("be.checked");
    });

  });

  describe("Plain /node/add/mentorship_engagement (no ?copy)", () => {

    // Fails today: the hook_form_alter falls through to the error path
    // when the copy param is absent and shows the user a misleading message.
    it("does NOT show the 'Not a valid mentorship id to copy' message", () => {
      cy.loginWith("walnut@pie.org", "Walnut");
      cy.visit("/node/add/mentorship_engagement");
      cy.contains("Not a valid mentorship id to copy").should("not.exist");
    });

  });

});
