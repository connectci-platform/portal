/**
 * Helpers for specs that exercise the KB Resources page.
 *
 * A resource is a `resource` webform submission. The ci_links search index has
 * `index_directly` on, so a submission is searchable as soon as the form is
 * saved: there is no cron or reindex step to wait for. A flag change is
 * indexed immediately too, in the flag request itself and after the flag
 * counts are updated, so the page the flag link redirects to is current.
 */

export const ADMIN = ["administrator@amptesting.com", "b8QW]X9h7#5n"];
export const AUTHENTICATED = ["authenticated@amptesting.com", "6%l7iF}6(4tI"];

// Webform skill level option values.
export const SKILL = { beginner: 304, intermediate: 305, advanced: 306 };

/**
 * Chooses an Affinity Group in the select2 "associate with an Affinity Group"
 * control of the resource form. Its select2:select handler copies the choice
 * into the hidden `affinity_group` element that the form submits.
 *
 * @param {string} title
 *   Title of the Affinity Group (the option label).
 */
export function selectAffinityGroup(title) {
  cy.get(".js-form-item-field-ag-nodes-select .select2-selection").click();
  cy.get(".select2-container--open .select2-search__field").type(title);
  cy.get(".select2-results__option--highlighted").contains(title).click();
}

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
 *   picks "ACCESS CSSN", which is the domain of accessmatch.ddev.site. Pass
 *   null to leave it alone: the domain select is only shown to administrators,
 *   so a non-admin submitter gets the current domain.
 * @param {boolean} [options.approved]
 *   Tick the "approved" box. Defaults to true.
 * @param {string} [options.affinityGroup]
 *   Title of the Affinity Group to choose in the "associate with an Affinity
 *   Group" select (a select2 listing the AGs of the current domain). Chosen
 *   before "approved" is ticked, because ticking it disables the select.
 * @param {boolean} [options.private]
 *   Tick the "private" box, which keeps the resource off the public KB
 *   Resources page. The box is only visible once an Affinity Group is chosen
 *   (its #states hide it while the AG is empty), so this requires
 *   `affinityGroup`. Defaults to false. Do not combine it with `approved`:
 *   ticking "approved" disables the AG elements, so the form saves neither the
 *   Affinity Group nor the private flag. To get an approved private resource,
 *   create it unapproved and approve it afterwards (see approveSubmission()).
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
  affinityGroup = undefined,
  private: isPrivate = false,
}) {
  if (isPrivate && !affinityGroup) {
    throw new Error("createKbResource: `private` requires `affinityGroup`");
  }
  cy.visit("/form/resource");
  if (affinityGroup) {
    selectAffinityGroup(affinityGroup);
  }
  if (isPrivate) {
    cy.get("#edit-private").check();
  }
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
  if (domain) {
    cy.get(".form-item-domain")
      .find(".select2-search__field")
      .type(`${domain}{enter}`);
  }
  // Scoped to the webform: on themes with a header search form, that form
  // takes #edit-submit and the webform's button gets a suffixed id.
  cy.get('form.webform-submission-resource-form [data-drupal-selector="edit-submit"]').click();

  return cy
    .location("pathname")
    .should("match", /\/webform\/resource\/submissions\/\d+$/)
    .then((path) => Number(path.split("/").pop()));
}

/**
 * Marks an existing resource submission as approved with drush, leaving its
 * Affinity Group and private flag as they are. The edit form cannot do this:
 * ticking "approved" there disables the AG elements, which are then not saved.
 * Saving through the entity API reindexes the resource immediately.
 *
 * @param {number} sid
 *   Submission id returned by createKbResource().
 *
 * @returns {Cypress.Chainable}
 */
export function approveSubmission(sid) {
  const id = Number(sid);
  if (!Number.isInteger(id) || id <= 0) {
    throw new Error(`approveSubmission: invalid submission id ${sid}`);
  }
  const php =
    '$sub = \\Drupal\\webform\\Entity\\WebformSubmission::load(' + id + ');' +
    '$sub->setElementData("approved", 1); $sub->save(); print "done";';
  return cy.drush("php:eval", [`'${php}'`]).then((result) => {
    if (result.exitCode !== 0 || result.stdout.trim() !== "done") {
      throw new Error(`approveSubmission failed: ${JSON.stringify(result)}`);
    }
  });
}

/**
 * Deletes the given webform submissions with drush, so a spec removes the
 * resources it created. Pass only submission ids captured at creation. Ids are
 * validated as positive integers before they reach the PHP snippet; undefined
 * ids (a failed before() hook) are skipped.
 *
 * @param {Array<number|undefined>} sids
 *   Submission ids returned by createKbResource().
 *
 * @returns {Cypress.Chainable}
 */
export function deleteSubmissions(sids) {
  const ids = (sids || []).filter((sid) => sid !== undefined && sid !== null).map(Number);
  ids.forEach((id) => {
    if (!Number.isInteger(id) || id <= 0) {
      throw new Error(`deleteSubmissions: invalid submission id ${id}`);
    }
  });
  if (!ids.length) {
    return cy.wrap(null);
  }
  const php =
    'foreach (' + JSON.stringify(ids) + ' as $sid) {' +
    '$sub = \\Drupal\\webform\\Entity\\WebformSubmission::load($sid);' +
    'if ($sub) { $sub->delete(); }' +
    '}' +
    'print "done";';
  return cy.drush("php:eval", [`'${php}'`]).then((result) => {
    if (result.exitCode !== 0 || result.stdout.trim() !== "done") {
      throw new Error(`deleteSubmissions failed: ${JSON.stringify(result)}`);
    }
  });
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
