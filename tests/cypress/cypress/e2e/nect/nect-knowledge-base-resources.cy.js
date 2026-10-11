/*
    KB Resources page (/knowledge-base/resources) on NECT: smoke test of the
    facets, using the data the site already has.

    Anonymous visitors get the Topics facet and a login prompt; the other
    facets (Content Type, Skill Level, ...) are for authenticated users.
*/

import { AUTHENTICATED, checkFirstTagFacet } from "../../support/kb-resources";

describe("NECT KB Resources page", () => {
  it("shows the Topics facet and filters by its first item for an anonymous visitor", () => {
    cy.visit("/knowledge-base/resources");
    cy.get('ul[data-drupal-facet-alias="tags"]:visible').should("exist");
    cy.contains("Login to use more filters");
    cy.get('ul[data-drupal-facet-alias="content-type"]').should("not.exist");

    checkFirstTagFacet();
  });

  it("shows the Topics, Content Type and Skill Level facets for an authenticated user", () => {
    cy.loginUser(...AUTHENTICATED);
    cy.visit("/knowledge-base/resources");
    cy.get('ul[data-drupal-facet-alias="tags"]:visible').should("exist");
    cy.get('ul[data-drupal-facet-alias="content-type"]:visible').should("exist");
    cy.get('ul[data-drupal-facet-alias="skill-level"]:visible').should("exist");

    checkFirstTagFacet();
  });
});
