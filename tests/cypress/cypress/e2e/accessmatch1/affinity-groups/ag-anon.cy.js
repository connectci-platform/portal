//
// Anonymous user visits the affinity-group page
//
describe('Anonymous user visit the affinity-group page', () => {
  it('should find expected stuff', () => {

    cy.visit('/affinity-groups');

    // Verify the request a group button is present and has the correct href
    cy.contains('Request a group')
      .invoke('attr', 'href')
      .should('eq', '/form/affinity-group-request');

    // Verify ACCESS Support Affinity Group.  First get the table row
    // containing the img alt text as an alias:
    cy.get('[alt="Support ACCESS Affinity Group logo"]')
      .parents('tr')
      .as('af-row');

    // 1. verify the image has the correct href
    cy.get('@af-row')
      .find('img')
      .parent()
      .invoke('attr', 'href')
      .should('contain', '/affinity-groups/access-support');

    // 2. verify the text has the correct href
    cy.get('@af-row')
      .contains('ACCESS Support')
      .invoke('attr', 'href')
      .should('contain', '/affinity-groups/access-support');

    // 3. Description: verify text in description
    cy.get('@af-row')
      .contains('Become an ACCESS Support insider by joining our affinity group.');

    // 4. Verify it has the expected tag
    cy.get('@af-row')
      .get('.square-tags')
      .contains('ACCESS-website')
      .invoke('attr', 'href')
      .should('contain', '/tags/access-website');

    // 5. verify the join button for anonymous
    cy.get('@af-row')
      .get('[href="/user/login?destination=/affinity-groups"]')
      .invoke('attr', 'title')
      .should('eq', 'Login to join');

    // Check the ai filter. The facet is placed twice in sidebar_first: a
    // desktop block and a mobile block (collapsed / display:none at desktop
    // width). Both render a checkbox with the SAME id (#affinity-search-tags-271),
    // so a bare `cy.get('#affinity-search-tags-271')` is ambiguous and can act
    // on the hidden mobile checkbox, whose click never refreshes the results.
    // Scope with `:visible` to reach the desktop checkbox — this is robust
    // across the facet AJAX re-render, which appends a random `--<hash>` suffix
    // to the block wrapper id (so an exact `#block-asptheme-affinitysearchtags`
    // selector stops matching after the first interaction).
    //
    // Facets disables the checkboxes while its AJAX request is in flight, so
    // wait for them to be enabled before interacting, and assert on the results
    // table (which retries) rather than using fixed waits. Assertions are scoped
    // to the results table so the site name ("ACCESS Support") in the page
    // title/chrome does not produce a false match, and we confirm an ai-tagged
    // group is present before asserting the non-ai group is gone (so the facet
    // AJAX has settled).
    cy.get('a.facets-soft-limit-link:visible').contains('Show more').click();
    cy.get('#affinity-search-tags-271:visible')
      .should('not.be.disabled').check();
    cy.get('.view-affinity-group-search table.views-table').within(() => {
      cy.contains('Anvil');
      cy.contains('ACCESS Support').should('not.exist');
    });
    cy.get('#affinity-search-tags-271:visible')
      .should('not.be.disabled').uncheck();
    // Full list must come back before the search box is used.
    cy.get('.view-affinity-group-search table.views-table').contains('ACCESS Support');
    // Wait for the facet AJAX to FULLY settle before touching the search box.
    // Facets disables its checkboxes (input disabled + a .facets-disabled class
    // on the .facets-widget-checkbox wrapper) for the duration of the Views
    // AJAX that re-renders both the results and the exposed form; both clearing
    // is the signal that the re-render is done. Without this, the search input
    // can still be mid-detach when we reach it, and cy.type() fails
    // intermittently with "requires a valid typeable element".
    cy.get('#affinity-search-tags-271:visible').should('not.be.disabled');
    cy.get('.facets-widget-checkbox:visible').should('not.have.class', 'facets-disabled');

    // Use Search Box — type and trigger BEF auto-submit via input event.
    // The auto-submit is debounced, so an intermediate keystroke (e.g. the
    // partial query "ACCESS") fires an AJAX search that matches many groups,
    // including ACCESS Support and Anvil. Assert on the SETTLED result first —
    // the sole "ACCESS RP Integration" row inside the results container, which
    // retries until the final full-string search lands — before checking that
    // the partial-match groups are gone. Otherwise we race the debounce and
    // assert against a partial-query result set.
    // Target the input by its stable data-drupal-selector, NOT the id: the id
    // carries a Drupal form-build counter suffix (--2) that increments when the
    // exposed form is rebuilt by the facet AJAX above, so a hardcoded
    // #edit-search-api-fulltext--2 stops matching after those interactions.
    //
    // The facet uncheck fires a Views AJAX that re-renders the exposed form,
    // detaching and replacing the search input. Chaining .clear().type()
    // straight off a single cy.get() races that re-render: Cypress can resolve
    // the old (about-to-detach) element and then fail with "requires a valid
    // typeable element". So re-query the input right before each interaction
    // (each cy.get retries until it resolves the current, attached input) and
    // assert it is not detached before typing.
    const SEARCH_INPUT = '[data-drupal-selector="edit-search-api-fulltext"]';
    cy.get(SEARCH_INPUT).should('be.visible').and('not.be.disabled');
    cy.get(SEARCH_INPUT).clear();
    cy.get(SEARCH_INPUT)
      // 30ms/keystroke: Cypress 15's faster default typing let the debounced
      // BEF auto-submit fire on a partial query; this keeps keystrokes paced so
      // the submit settles on the full string (the settled-state asserts below
      // are the real guard, this just reduces intermediate churn).
      .type('ACCESS RP Integration', { delay: 30 })
      .trigger('keyup');
    // Wait for the results table to settle to the single full-string match,
    // then confirm the partial-match groups are gone. Scoped to the results
    // table so the site name ("ACCESS Support") in the page chrome/title does
    // not produce a false match.
    cy.get('.view-affinity-group-search table.views-table tbody tr')
      .should('have.length', 1)
      .and('contain', 'ACCESS RP Integration');
    cy.get('.view-affinity-group-search table.views-table').within(() => {
      cy.contains('Anvil').should('not.exist');
      cy.contains('ACCESS Support').should('not.exist');
    });
  });
})
