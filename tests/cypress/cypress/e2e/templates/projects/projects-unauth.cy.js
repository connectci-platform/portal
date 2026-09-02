describe("test projects/engagements page", () => {

  it("Unauthenticated user Test the Projects/Engagements Page", () => {
    cy.visit('/projects');
    cy.contains('Projects');
    cy.contains('Login to Add New Project');
    cy.contains('Title');
    cy.contains('Project Institution');
    cy.contains('Project Owner');
    cy.contains('Tags');
    cy.contains('Status');
    cy.contains('Project Leader');

    // This spec is a *reader*: the "login" tag link it ultimately clicks lives
    // in the Tags cell of the login-tagged project that projects-1-submit-admin
    // creates (projects are sanitized out of the DB snapshot, so that fixture is
    // the only project carrying the "login" tag). The flow exercises the search
    // exposed filter, returns to the full listing, then clicks the tag.

    // Search behaves as a Views AJAX exposed filter; exercise it to the
    // no-results state.
    cy.get('input[name="search"]').type('testy2002', { delay: 0 });
    cy.contains('There are no projects at this time. Please check back often as projects are added regularly.');

    // Better Exposed Filters re-renders the exposed form on each AJAX response.
    // If .clear() fires while the typed-search request is still in flight, the
    // response restores the old input value, leaving the view stuck on the
    // no-results state forever (verified: searchVal stays "testy2002", tbody
    // never repopulates). Retry the clear until the field actually stays empty.
    const clearSearch = (attempt = 0) => {
      cy.get('input[name="search"]').clear();
      cy.wait(500);
      cy.get('input[name="search"]').then(($input) => {
        if ($input.val() !== '' && attempt < 6) {
          clearSearch(attempt + 1);
        }
      });
    };
    clearSearch();
    cy.get('input[name="search"]').should('have.value', '');

    // Clearing rebuilds the results table via AJAX; the tbody is momentarily
    // empty mid-flight. Wait for it to repopulate before going for the tag link.
    cy.get('tbody tr', { timeout: 20000 }).should('have.length.greaterThan', 0);

    // Target the "login" tag link by href — the bare word "login" also appears
    // in "Login to Add New Project" and /user/login links — then confirm it
    // reaches the tag page.
    cy.get('a[href*="/tags/login"]').first().click();
    cy.url().should('include', '/tags/login');
  });

});
