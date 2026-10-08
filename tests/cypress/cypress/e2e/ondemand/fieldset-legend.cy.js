// A fieldset takes its accessible name from its <legend>. The theme used to
// render the title as <p class="fieldset-legend">, leaving fieldsets unnamed.
describe("fieldset titles render as legends", () => {
  beforeEach(() => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit('/events/add');
  });

  it("Event Type fieldset has a legend as a direct child", () => {
    cy.get('fieldset[data-drupal-selector="edit-field-event-type"] > legend.fieldset-legend')
      .should('contain.text', 'Event Type');
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
