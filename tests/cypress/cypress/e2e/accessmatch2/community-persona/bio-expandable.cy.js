/*
  Expandable bio on the community persona (D8-2796).
  Pecan Pie (uid 201) is seeded with a long multi-block bio -> her public
  profile clamps the bio with a More/Less toggle. The admin test user is
  seeded with a long bio -> the own-persona page clamps too. The old
  #bio-summary / #full-bio markup is gone.

  NB: Pecan's username is not set in the fixture (amp_dev.install:230 is
  commented out), so we never loginAs Pecan — only her public /community-
  persona/201 page, which needs no login. The own-persona case logs in as
  the admin fixture that every other spec in this suite uses.

  NB2: expandable_text clips overflow via inline max-height + overflow:hidden
  on .expandable-text__content (set/cleared by expandable-text.js), not via
  display/visibility on the clipped paragraph itself. A clipped paragraph can
  still report nonzero offsetHeight to jQuery's :visible check, so
  .should('not.be.visible')/.should('be.visible') on the marker text is
  unreliable. Assert the DOM state the JS actually toggles instead: the
  is-collapsed class + the inline max-height style on .expandable-text__content
  (see Task 9's resource-page.cy.js expandable-intro spec for the same pattern).
*/

const PECAN_UID = 201;
const ADMIN_USER = "administrator@amptesting.com";
const ADMIN_PASS = "b8QW]X9h7#5n";

describe("Community persona — expandable bio (public profile)", () => {

  it("clamps Pecan's long bio with a working toggle", () => {
    cy.visit(`/community-persona/${PECAN_UID}`);
    cy.get("#community-persona .expandable-text").should("exist");
    cy.get("#community-persona .expandable-text.is-collapsed").should("exist");
    cy.get("#community-persona .expandable-text__toggle")
      .should("have.attr", "aria-expanded", "false")
      .and("contain.text", "More");

    // Marker paragraph is present but clipped while collapsed — assert the
    // DOM state the JS toggles (is-collapsed + inline max-height), not
    // jQuery :visible on the clipped element itself.
    cy.contains("#community-persona .expandable-text p", "mentor students").should("exist");
    cy.get("#community-persona .expandable-text__content")
      .should("have.attr", "style")
      .and("match", /max-height/);

    cy.get("#community-persona .expandable-text__toggle").click();

    cy.get("#community-persona .expandable-text").should("not.have.class", "is-collapsed");
    cy.get("#community-persona .expandable-text__toggle")
      .should("have.attr", "aria-expanded", "true")
      .and("contain.text", "Less");
    cy.get("#community-persona .expandable-text__content")
      .invoke("attr", "style")
      .should("satisfy", (style) => !style || !/max-height/.test(style));
    cy.contains("#community-persona .expandable-text p", "mentor students").should("be.visible");

    // Old hand-rolled markup is gone.
    cy.get("#community-persona #bio-summary").should("not.exist");
    cy.get("#community-persona #full-bio").should("not.exist");
  });

});

describe("Community persona — expandable bio (own persona)", () => {

  it("clamps the admin's long bio on their own persona page", () => {
    cy.loginAs(ADMIN_USER, ADMIN_PASS);
    cy.visit("/community-persona");
    cy.get("#community-persona .expandable-text").should("exist");
    cy.get("#community-persona .expandable-text.is-collapsed").should("exist");
    cy.get("#community-persona .expandable-text__toggle")
      .should("have.attr", "aria-expanded", "false")
      .and("contain.text", "More");

    cy.contains("#community-persona .expandable-text p", "office hours").should("exist");
    cy.get("#community-persona .expandable-text__content")
      .should("have.attr", "style")
      .and("match", /max-height/);

    cy.get("#community-persona .expandable-text__toggle").click();

    cy.get("#community-persona .expandable-text").should("not.have.class", "is-collapsed");
    cy.get("#community-persona .expandable-text__toggle")
      .should("have.attr", "aria-expanded", "true")
      .and("contain.text", "Less");
    cy.get("#community-persona .expandable-text__content")
      .invoke("attr", "style")
      .should("satisfy", (style) => !style || !/max-height/.test(style));
    cy.contains("#community-persona .expandable-text p", "office hours").should("be.visible");

    // Old hand-rolled markup is gone.
    cy.get("#community-persona #bio-summary").should("not.exist");
    cy.get("#community-persona #full-bio").should("not.exist");
  });

});
