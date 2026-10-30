/*
    Checks the "Researcher stories" band on the ACCESS Support homepage
    (/support-home): the heading, the story cards and the Read all stories
    link. Card count comes from the homepage_stories view display.
*/
describe("Support homepage researcher stories", () => {
  it("Should show story cards with a Read all stories link", () => {
    cy.visit("/support-home");

    cy.get(".homepage-stories").within(() => {
      cy.contains("h2", "Researcher stories").should("be.visible");

      cy.get(".homepage-story").should("have.length.within", 1, 3);
      cy.get(".homepage-story")
        .first()
        .within(() => {
          cy.get(".homepage-story__tag").should("exist");
          cy.get(".homepage-story__title").should("not.be.empty");
          cy.get(".homepage-story__name").should("not.be.empty");
        });
      cy.get(".homepage-story")
        .first()
        .should("match", "a")
        .and("have.attr", "href")
        .and("not.be.empty");
      cy.get(".homepage-story").first().find("a").should("have.length", 0);

      cy.contains("a", "Read all stories").should(
        "have.attr",
        "href",
        "/tools/researcher-stories",
      );
    });
  });
});
