/*
    Checks the "Training & events" band on the ACCESS Support homepage
    (/support-home): the See all links, the events list and the announcements
    list. Row counts come from the homepage_events and homepage_announcements
    view displays.
*/
describe("Support homepage events band", () => {
  it("Should show events and announcements with their See all links", () => {
    cy.visit("/support-home");

    cy.get(".homepage-events-band").within(() => {
      cy.contains("h2", "Training & events").should("be.visible");
      cy.contains("a", "See all events").should("have.attr", "href", "/events");

      cy.get(".homepage-ev").should("have.length.within", 1, 7);

      cy.contains("h2", "Announcements").should("be.visible");
      cy.contains("a", "See all announcements").should(
        "have.attr",
        "href",
        "/announcements",
      );

      cy.get("a.homepage-ann").should("have.length.within", 1, 3);
    });
  });
});
