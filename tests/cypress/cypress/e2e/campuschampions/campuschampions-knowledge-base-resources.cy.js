/*
    KB Resources page (/knowledge-base/resources) on Campus Champions: smoke test of the
    facets.

    The spec creates its own resources (tag xalt, region "Campus Champions"), titled with a
    per-run id, so it does not depend on what the database dump holds. The
    listing is narrowed to them with the fulltext filter, which reads a hyphen
    as minus, so it searches the bare id.

    Anonymous visitors get the Topics facet and a login prompt; the other
    facets (Content Type, Skill Level, ...) are for authenticated users.
*/

import { ADMIN, AUTHENTICATED, checkFirstTagFacet, createKbResource, deleteSubmissions } from "../../support/kb-resources";

const RUN = Date.now().toString();
const RUN_ONLY = `/knowledge-base/resources?search_api_fulltext=${RUN}`;

const created = [];

describe("Campus Champions KB Resources page", () => {
  before(() => {
    // Typing "Campus" in the domain select2 matches only the "Campus Champions" region term.
    cy.loginUser(...ADMIN);
    ["one", "two"].forEach((n) => {
      createKbResource({ title: `kbsmoke-${RUN}-${n}`, tags: ["xalt"], domain: "Campus" }).then((id) => { created.push(id); });
    });
    cy.drupalLogout();
  });

  after(() => deleteSubmissions(created));

  it("shows the Topics facet and filters by its first item for an anonymous visitor", () => {
    cy.visit(RUN_ONLY);
    cy.get('ul[data-drupal-facet-alias="tags"]:visible').should("exist");
    cy.contains("Login to use more filters");
    cy.get('ul[data-drupal-facet-alias="content-type"]').should("not.exist");

    checkFirstTagFacet();
  });

  it("shows the Topics, Content Type and Skill Level facets for an authenticated user", () => {
    cy.loginUser(...AUTHENTICATED);
    cy.visit(RUN_ONLY);
    cy.get('ul[data-drupal-facet-alias="tags"]:visible').should("exist");
    cy.get('ul[data-drupal-facet-alias="content-type"]:visible').should("exist");
    cy.get('ul[data-drupal-facet-alias="skill-level"]:visible').should("exist");

    checkFirstTagFacet();
  });
});
