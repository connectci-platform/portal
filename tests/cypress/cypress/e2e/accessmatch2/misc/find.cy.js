/*
    Test /find

    The page embeds the access-search widget (connectci/access-search), which
    replaced the Elastic Search UI app. The widget loads its own script and
    stylesheet from its deploy and renders into #access-search, so the markup
    here is the widget's `as-*` contract rather than Drupal's.

    Note: results come from an external search service, so retries are kept for
    intermittent failures.
 */
describe("Test of the /find page", { retries: { runMode: 2, openMode: 0 } }, () => {
  it("Should complete successfully", () => {
    cy.visit("/find", { timeout: 120000 });

    cy.get('#block-pagetitle').contains("Find Information about ACCESS");

    // check breadcrumbs
    const crumbs = [
      ['Support', '/'],
      ['Find Information About ACCESS', null]
    ];
    cy.checkBreadcrumbs(crumbs);

    // The widget is embedded, not server-rendered: wait for its script to
    // mount the search form before interacting with it.
    cy.get('#access-search', { timeout: 30000 })
      .should('have.attr', 'data-api-base');
    cy.get('#access-search .as-form', { timeout: 30000 }).should('exist');
    cy.get('#as-input').should('have.attr', 'placeholder', 'Search');

    cy.get('#as-input').type('test', { delay: 0 });
    cy.get('#access-search .as-form button[type="submit"]').click();

    // The count is the widget's own summary line ("N results for ..."). It
    // exists from mount and reads "Searching…" first, so assert on its text
    // with should() — which retries — rather than invoke('text'), which
    // snapshots once and would catch the in-flight state.
    cy.get('.as-count', { timeout: 30000 })
      .should('contain.text', 'result')
      .and(($el) => {
        expect($el.text()).to.match(/\d+\s+result/);
      });

    cy.get('.as-results .as-result', { timeout: 30000 })
      .should('have.length.greaterThan', 0);

    // Each result carries a deep link and a snippet, which is the whole point
    // of the widget over the previous listing-page scrape. a.as-title is the
    // link itself, not a wrapper around one.
    cy.get('.as-results .as-result').first().within(() => {
      cy.get('a.as-title')
        .should('not.have.text', '')
        .and('have.attr', 'href')
        .and('match', /^https?:\/\//);
      cy.get('.as-snippet').should('not.have.text', '');
    });
  });
});
