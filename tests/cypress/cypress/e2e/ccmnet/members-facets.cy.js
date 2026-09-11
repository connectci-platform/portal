//
// Regression test: keyword search plus facets on /members (ccmnet).
//
// Bug: a facet click fires two facet-block refreshes, one from
// updateFacetsView() and one from the beforeSend() override riding along with
// the Views AJAX request. Both build their replace selectors from the block
// wrapper ids, and Drupal appends a random --<hash> suffix to those ids on
// every AJAX re-render, so the second response is left with selectors that
// match nothing. Core's insert command then resolves an empty wrapper and
// detaches behaviors from the whole document, emptying
// Drupal.views.instances with no replacement to re-attach it.
//
// The next facet click threw "Cannot read properties of undefined (reading
// 'settings')" in updateFacetsView(). The checkbox widget disables every
// checkbox in the block before it triggers and only a successful refresh
// re-enables them, so the facet stayed unchecked and permanently disabled.
//
// This drives the repro sequence and asserts the checkboxes come out of it
// checked and interactive, with both selections in the URL. Cypress also fails
// the test on the application's uncaught TypeError, so the throw itself is
// covered even before the assertions run.
//
describe('Anonymous user searches and facets the CCMnet members view', () => {
  it('should keep both facet checkboxes checked and enabled after a keyword search plus two facet clicks', () => {
    cy.visit('/members');

    // Keyword search first, since the bug only reproduces on a facet click
    // that follows a prior Views AJAX rebuild (the search). Use the stable
    // data-drupal-selector, not the id, since the exposed form's id gains a
    // Drupal form-build counter suffix after any AJAX rebuild. cy.searchAndWait
    // waits for the request carrying the typed value and then for the facet
    // blocks that refresh behind it.
    const SEARCH_INPUT = '[data-drupal-selector="edit-search-api-fulltext"]:visible';
    cy.searchAndWait(SEARCH_INPUT, 'Andrew');

    // Each facet is rendered twice in the sidebar (a desktop block and a
    // collapsed mobile copy), both producing a checkbox with the SAME id, so
    // a bare id selector is ambiguous and can silently act on the hidden
    // mobile checkbox. Scope every facet interaction with :visible. Never
    // select the facet BLOCK by id — the block wrapper gains a random
    // `--<hash>` suffix each time the facets AJAX re-renders it.
    const PYTHON_FACET = '#user-skills-members-python:visible';
    const GIT_FACET = '#user-skills-members-git:visible';

    // cy.clickFacetAndWait waits for the widget's `facets_filter` binding
    // before clicking and for both the view and the facet blocks to settle
    // after, so the second click acts on a block carrying hrefs that already
    // include the first selection. A fixed timer here lost to the two-to-five
    // second round trips under CI load, and the second click then replaced the
    // first selection instead of adding to it.
    cy.clickFacetAndWait(PYTHON_FACET);
    cy.url().should('include', 'user_skills_members%3Apython');

    // Second facet click — this is the one that reproduced the bug. If the
    // first click's stale replace selector wiped Drupal.views.instances, this
    // click throws instead of refreshing, and the widget stays disabled.
    cy.clickFacetAndWait(GIT_FACET);

    // The heart of the regression test: both checkboxes must still be checked
    // AND not disabled. The original symptom was every facet checkbox left
    // disabled and unchecked after the second click, because the block was
    // never replaced and nothing re-enabled it. A checkbox that silently
    // reverted to unchecked, or that stayed disabled, means the regression is
    // back.
    cy.get(PYTHON_FACET).should('be.checked').and('not.be.disabled');
    cy.get(GIT_FACET).should('be.checked').and('not.be.disabled');

    // Confirm both facet selections made it into the URL as separate f[]
    // params (in either order — facets does not guarantee click order is
    // preserved in the querystring).
    cy.url().then((url) => {
      expect(url).to.include('f%5B0%5D');
      expect(url).to.include('f%5B1%5D');
      const hasPython = url.includes('user_skills_members%3Apython');
      const hasGit = url.includes('user_skills_members%3Agit');
      expect(hasPython, 'URL contains the python facet param').to.be.true;
      expect(hasGit, 'URL contains the git facet param').to.be.true;
    });
  });
});
