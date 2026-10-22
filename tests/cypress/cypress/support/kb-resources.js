/**
 * Helpers for specs that exercise the KB Resources page.
 *
 * A resource is a `resource` webform submission. The ci_links search index has
 * `index_directly` on, so a submission is searchable as soon as the form is
 * saved: there is no cron or reindex step to wait for. A flag change is the
 * exception: it only marks the item for reindexing, which the same option then
 * processes at the end of the request.
 */

export const ADMIN = ["administrator@amptesting.com", "b8QW]X9h7#5n"];
export const AUTHENTICATED = ["authenticated@amptesting.com", "6%l7iF}6(4tI"];

// Webform skill level option values.
export const SKILL = { beginner: 304, intermediate: 305, advanced: 306 };

/**
 * Creates a KB resource through /form/resource. Log in as an administrator
 * first: only administrators and KB project managers may set "approved".
 *
 * @param {object} options
 * @param {string} options.title
 *   Resource title.
 * @param {string} [options.category]
 *   Label of the category option (e.g. "Learning", "Code").
 * @param {number[]} [options.skillLevels]
 *   Skill level option values (see SKILL).
 * @param {string[]} [options.tags]
 *   Tag spans to click, by the id suffix of `#tag-<slug>`. The form accepts
 *   at most six tags.
 * @param {string} [options.domain]
 *   Text typed into the domain select2, whose first match is chosen. "ACCESS"
 *   picks "ACCESS CSSN", which is the domain of accessmatch.ddev.site.
 * @param {boolean} [options.approved]
 *   Tick the "approved" box. Defaults to true.
 *
 * @returns {Cypress.Chainable<number>}
 *   Yields the new submission id, read from the confirmation URL
 *   (/webform/resource/submissions/<sid>).
 */
export function createKbResource({
  title,
  category = "Learning",
  skillLevels = [SKILL.beginner],
  tags = [],
  domain = "ACCESS",
  approved = true,
}) {
  cy.visit("/form/resource");
  if (approved) {
    cy.get("#edit-approved").check();
  }
  cy.get("#edit-title").type(title);
  cy.get("#edit-category").select(category);
  skillLevels.forEach((level) => {
    cy.get(`#edit-skill-level-${level}`).check();
  });
  cy.get(".form-item-description-html-value .ck-content").then((el) => {
    el[0].ckeditorInstance.setData(`Description for ${title}`);
  });
  tags.forEach((slug) => {
    cy.get(`#tag-${slug}`).click();
  });
  cy.get(".form-item-domain")
    .find(".select2-search__field")
    .type(`${domain}{enter}`);
  // Scoped to the webform: on themes with a header search form, that form
  // takes #edit-submit and the webform's button gets a suffixed id.
  cy.get('form.webform-submission-resource-form [data-drupal-selector="edit-submit"]').click();

  return cy
    .location("pathname")
    .should("match", /\/webform\/resource\/submissions\/\d+$/)
    .then((path) => Number(path.split("/").pop()));
}

// A listed resource. The markup comes from the access_cilink module, so it is
// the same on every theme.
export const RESOURCE_ITEMS = ".details-wrap";

/**
 * Checks the first visible item of the Topics facet on /knowledge-base/resources
 * and asserts the filter took: the URL gains f[0]=tags:..., the list does not
 * grow and shows as many items as the facet counted (up to the page size).
 * Uses whatever the current page lists, so visit a listing narrowed to
 * resources the spec created (e.g. ?search_api_fulltext=<id>) first.
 */
export function checkFirstTagFacet() {
  cy.get(RESOURCE_ITEMS).should("have.length.greaterThan", 0);
  cy.get(RESOURCE_ITEMS).its("length").then((before) => {
    cy.get('ul[data-drupal-facet-alias="tags"]:visible li.facet-item:not(.honey):visible')
      .first()
      .then(($item) => {
        const id = $item.find("input[type=checkbox]").attr("id");
        const counted = Number($item.find(".facet-item__count").text().replace(/\D/g, ""));
        expect(id, "facet checkbox id").to.be.a("string").and.not.be.empty;
        expect(counted, "facet item count").to.be.greaterThan(0);

        cy.clickFacetAndWait(`#${id}:visible`);
        cy.location("search").should((search) => {
          expect(decodeURIComponent(search)).to.match(/f\[0\]=tags:/);
        });
        cy.get(RESOURCE_ITEMS)
          .should("have.length.at.most", before)
          .and("have.length", Math.min(counted, before));
      });
  });
}
