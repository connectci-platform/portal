/*  
    This test is specifically focused on the Tools overview page tested for an unauthenticated user.
    This test checks for major functions like:
    Page Title, 
    Page Intro,
    and testing one tool 
    
*/

describe("Unauthenticated user tests the Tools overview Page", () => {
  it("Should test Tools overview page for unauthenticated user", () => {
    cy.visit("/tools/overview");

    //Page Title And Intro Paragraph
    cy.get(".page-title").contains("Tools");
    cy.get(".clearfix > .prose").contains(
      "Try these cutting-edge tools designed to streamline your time to science."
    );

    //Testing OnDeman Tool Row
    cy.get(":nth-child(3) > p").contains(
      "Access remote computing resources from any device."
    );
    cy.get(".clearfix > .grid > :nth-child(4)").contains(
      "Remote access web portal"
    );

    //Testing Find Out More btn for OnDemand Row
    cy.get(":nth-child(3) > div > .btn").contains("FIND OUT MORE").click();
    cy.contains("ACCESS OnDemand");
  });
});
