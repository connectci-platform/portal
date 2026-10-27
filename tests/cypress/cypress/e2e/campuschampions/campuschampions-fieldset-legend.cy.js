// A fieldset takes its accessible name from its <legend>. The
// campus-champions-theme fieldset template used to render the title as
// <p class="fieldset-legend">, leaving fieldsets unnamed.
describe("fieldset titles render as legends", () => {
  beforeEach(() => {
    cy.visit('/form/join-campus-champions');
  });

  it("no fieldset title is rendered as a paragraph", () => {
    cy.get('fieldset').should('exist');
    cy.get('fieldset > p.fieldset-legend').should('not.exist');
  });

  it("every fieldset has exactly one legend", () => {
    cy.get('form fieldset').each(($f) => {
      expect($f.children('legend'), $f.attr('data-drupal-selector')).to.have.length(1);
    });
  });
});
