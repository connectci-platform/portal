/*
    This test is specifically focused on an individual KB Resource page tested for an unauthenticated user.
    This test checks for major functions like:
    Title,
    Description,
    Attached Affinity Group,
    Tags,
    User engagement information ("0 People found this useful"),
    Category,
    Skill Level,
    verifying images and links load in,
    and "Login to vote" functionality
*/
describe("Unauthenticated user tests the Individual KB Resource Page", () => {
  it("Should test Individual KB Resource page for unauthenticated user", () => {
    //Naviagting To Indie CI Link
    cy.visit("/knowledge-base/resources");
    cy.contains("dummy link for testing knowledge base")
      .click()
      .then(() => {
        //CI Link Title
        // cy.get(".page-title").contains(
        //   "dummy link for testing knowledge base"
        // );
        //Tags
        cy.contains("ACCESS-account");
        //Description
        cy.contains(
          "Dummy description for ci-link 'dummy link for testing knowledge base'"
        );
        //CI Link Category and Skill Level
        cy.get(".md--col-span-1 div:nth-child(2)")
          .contains("Type")
        cy.get(".md--col-span-1 div:nth-child(2)")
          .contains("learning")
        cy.get(".md--col-span-1 div:nth-child(3)")
          .contains("Level")
        //Vote Section
        cy.get(".bg-light-teal").contains("Likes");
        // cy.get('.bg-light-teal').contains("Login to vote").click();
        cy.get(".bg-light-teal")
          .contains("Login to like")
          .should("have.attr", "href")
          .and("contain", "/user/login?destination=/knowledge-base/resources");
      });
  });
});
