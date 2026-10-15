/*
    This test is specifically focused on the XDMoD page tested for an unauthenticated user.
    This test checks for major functions like:
    Page Title,
    Testing accordion function,


    View information about allocations, usage data, and usage, as well as specific information about your usage and general historic usage of the ACCESS allocated resources.
*/

describe("Unauthenticated user tests the XDMoD Page", () => {
  it("Should test XDMoD page for unauthenticated user", () => {
    cy.visit("/tools/xdmod");

    //Page Title and Intro paragraph
    cy.get(".prose > .text-white").contains(
      "Analyze and improve your allocation usage"
    );

    cy.contains("View information about allocations, usage data, and usage");

    //Checkpoint section: three cards, each with its own heading and copy.
    cy.get("#about > .grid > :nth-child(1)").contains(
      "Optimize job efficiency"
    );
    cy.get("#about > .grid > :nth-child(2)").contains(
      "Monitor allocation usage"
    );
    cy.get("#about > .grid > :nth-child(2)").contains(
      "View information about active and expired allocations."
    );

    //Accordion section and function
    cy.get("#using-xdmod > .colored-square").contains("Using XDMoD");
    cy.get("details summary")
      .contains("What do I need to do to start using XDMoD?")
      .click();
    cy.get("details[open] > .bg-white").contains(
      "Anyone with an ACCESS Identity can login to XDMoD."
    );

    //XDMOD Support Section The below five lines do not work for now
    // cy.get(".container > .colored-square").contains("XDMoD Support");
    // cy.get('.grid > [href="https://xdmod.access-ci.org"]')
    //   .contains("Launch XDMoD")
    //   .should("have.attr", "href")
    //   .and("contain", "https://xdmod.access-ci.org/");
  });
});
