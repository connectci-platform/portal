describe("Classroom Stories buttons on the classrooms landing page", () => {
  beforeEach(() => {
    cy.visit('/appverse/classroom');
  });

  it("Top button is 'Contribute a story' and emails the OOD contact", () => {
    // Scoped to the slideshow view's header (spamspan rewrites the link client-side).
    cy.get('.ag-slideshow.oocs header').within(() => {
      cy.contains('a', 'Contribute a story')
        .should('have.attr', 'href', 'mailto:ktomko@osc.edu?subject=OOD%20Classroom%20Story');
      // "Read the stories" must no longer be in the header.
      cy.contains('Read the stories').should('not.exist');
    });
  });

  it("Bottom 'Read the stories' button links to the stories page", () => {
    cy.get('.ag-slideshow.oocs footer').within(() => {
      cy.contains('a', 'Read the stories')
        .should('have.attr', 'href')
        .and('match', /\/appverse\/classroom\/stories$/);
    });
    // Only one "Read the stories" button in the whole section.
    cy.get('.ag-slideshow.oocs a:contains("Read the stories")').should('have.length', 1);
  });
});
