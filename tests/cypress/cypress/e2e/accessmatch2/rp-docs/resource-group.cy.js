describe("Resource Group — listing page", () => {

  it("shows resource groups on the /documentation/resources listing", () => {
    cy.visit("/documentation/resources");
    cy.get(".view-rp-documentation-index .views-row").should("have.length.greaterThan", 0);
  });

  it("shows resource groups alongside individual resources", () => {
    cy.visit("/documentation/resources");
    cy.contains("Test Resource Group");
  });

  it("renders the group title as a plain h2 (no longer a link)", () => {
    cy.visit("/documentation/resources");
    cy.contains("h2", "Test Resource Group");
    cy.get("#group-test-resource-group h2").should("exist");
    // The h2 itself is not a link.
    cy.get("#group-test-resource-group h2 a").should("not.exist");
  });

  it("does not render the org name under the group title", () => {
    cy.visit("/documentation/resources");
    // field_access_org_name is excluded from the view.
    cy.get("#group-test-resource-group").contains("Test Org").should("not.exist");
  });

  it("inline resources are rendered as a list (no table headings)", () => {
    cy.visit("/documentation/resources");
    // The listing now uses <ul class="rp-resource-list"> grid items, not a <table>.
    cy.get("#group-test-resource-group .rp-resource-group-list table")
      .should("not.exist");
    cy.get("#group-test-resource-group .rp-resource-group-list .rp-resource-list-item")
      .should("have.length.greaterThan", 0);
  });

  it("inline resource links point to individual RP doc pages", () => {
    cy.visit("/documentation/resources");
    cy.get(".rp-resource-group-list")
      .contains("a", "Alpha")
      .should("have.attr", "href")
      .and("include", "/documentation/resources/alpha");
  });

  it("resource group rows have anchor IDs", () => {
    cy.visit("/documentation/resources");
    cy.get("#group-test-resource-group").should("exist");
  });

  it("inline list shows summary text", () => {
    cy.visit("/documentation/resources");
    cy.get("#group-test-resource-group .rp-resource-group-list")
      .should("contain", "GPU-accelerated supercomputer for HPC, AI, and machine learning workloads.");
  });

  it("page title reads 'Resource Documentation'", () => {
    cy.visit("/documentation/resources");
    cy.contains("Resource Documentation");
  });

  it("renders the ARA CTA in the listing header", () => {
    cy.visit("/documentation/resources");
    // The ARA CTA moved from the right sidebar into the full-width view header.
    cy.get(".rp-ara-cta").within(() => {
      cy.contains("Find the best resource for your project");
      cy.contains("Try the ACCESS Resource Advisor")
        .should("have.attr", "href")
        .and("include", "ara.access-ci.org");
    });
  });

});

describe("Resource Group — individual (ungrouped) resource rendering", () => {

  it("ungrouped resources render as a single-item list below their h2 title", () => {
    cy.visit("/documentation/resources");
    // Gamma is ungrouped with field_rp_listing checked, so it should show.
    cy.get(".rp-individual-resource-row").should("have.length.greaterThan", 0);
    // Listing view h2 uses short_name ("Gamma") rather than full title.
    cy.contains("h2", "Gamma");
    cy.get(".rp-individual-resource-list .rp-resource-list-item").should("exist");
  });

});

describe("Resource Group — detail page", () => {

  beforeEach(() => {
    cy.visit("/documentation/resources/test-resource-group");
  });

  it("renders the page title", () => {
    cy.contains("Test Resource Group");
  });

  it("renders the group description", () => {
    cy.contains("A test resource group for CI testing");
  });

  it("renders the resources table with headers", () => {
    cy.get(".rp-resource-group-list").should("exist");
    cy.contains("h2", "Resources in this group");
    cy.get(".rp-resource-group-list table thead").within(() => {
      cy.contains("th", "Resource");
      cy.contains("th", "Description");
    });
  });

  it("lists linked resources in the table using the short name", () => {
    cy.get(".rp-resource-group-list table tbody tr").should("have.length.greaterThan", 0);
    cy.get(".rp-resource-group-list table tbody").contains("Alpha");
    // Full title should no longer appear in the row (short_name supersedes it).
    cy.get(".rp-resource-group-list table tbody").should("not.contain", "Test Resource Alpha");
  });

  it("resource links point to individual RP doc pages", () => {
    cy.get(".rp-resource-group-list table tbody")
      .contains("a", "Alpha")
      .should("have.attr", "href")
      .and("include", "/documentation/resources/alpha");
  });

});

describe("Resource Group — ARA banner on listing page", () => {

  beforeEach(() => {
    cy.clearLocalStorage();
  });

  it("shows ARA banner on targeted group with ara_context and ara_group params", () => {
    cy.visit("/documentation/resources?ara_context=Recommended+for+Python,+Earth+Sciences&ara_group=test-resource-group");
    cy.get("#group-test-resource-group .ara-group-banner").should("be.visible");
    cy.get("#group-test-resource-group .ara-banner-text").should("contain", "Python");
  });

  it("does not add a ring border around the targeted group (banner is sufficient)", () => {
    cy.visit("/documentation/resources?ara_context=Recommended+for+Python&ara_group=test-resource-group");
    cy.get("#group-test-resource-group").should("not.have.class", "ring-2");
  });

  it("passes ara_context through to resource links", () => {
    cy.visit("/documentation/resources?ara_context=Recommended+for+Python&ara_group=test-resource-group");
    cy.get("#group-test-resource-group .rp-resource-list-link").first()
      .should("have.attr", "href")
      .and("include", "ara_context=");
  });

  it("persists banner on page reload via localStorage", () => {
    cy.visit("/documentation/resources?ara_context=Recommended+for+Python&ara_group=test-resource-group");
    cy.get("#group-test-resource-group .ara-group-banner").should("be.visible");

    cy.visit("/documentation/resources");
    cy.get("#group-test-resource-group .ara-group-banner").should("be.visible");
    cy.get("#group-test-resource-group .ara-banner-text").should("contain", "Python");
  });

  it("dismiss button clears banner for that group only", () => {
    cy.visit("/documentation/resources?ara_context=Recommended+for+Python&ara_group=test-resource-group");
    cy.get("#group-test-resource-group .ara-group-banner").should("be.visible");

    cy.get("#group-test-resource-group .ara-dismiss").click();
    cy.get("#group-test-resource-group .ara-group-banner").should("not.be.visible");

    // Should not reappear on reload
    cy.visit("/documentation/resources");
    cy.get("#group-test-resource-group .ara-group-banner").should("not.be.visible");
  });

  it("no banner without ara_context param or localStorage", () => {
    cy.visit("/documentation/resources");
    cy.get(".ara-group-banner").should("not.be.visible");
  });

  it("supports multiple groups with shared context via comma-separated ara_group", () => {
    cy.visit("/documentation/resources?ara_context=Recommended+for+Python&ara_group=test-resource-group,nonexistent-group");
    cy.get("#group-test-resource-group .ara-group-banner").should("be.visible");
  });

  it("supports ara_recs format for multiple groups with different contexts", () => {
    cy.visit("/documentation/resources?ara_recs=test-resource-group:Recommended+for+Python");
    cy.get("#group-test-resource-group .ara-group-banner").should("be.visible");
    cy.get("#group-test-resource-group .ara-banner-text").should("contain", "Python");
  });

});

describe("Resource Group — ARA banner on detail page", () => {

  beforeEach(() => {
    cy.clearLocalStorage();
  });

  it("shows ARA banner when arriving with ara_context param", () => {
    cy.visit("/documentation/resources/test-resource-group?ara_context=Recommended+for+Python,+Earth+Sciences");
    cy.get("#ara-recommendation-banner").should("be.visible");
    cy.get("#ara-recommendation-text").should("contain", "Python");
  });

  it("passes ara_context through to resource links on detail page", () => {
    cy.visit("/documentation/resources/test-resource-group?ara_context=Recommended+for+Python");
    cy.get(".rp-resource-list-link").first()
      .should("have.attr", "href")
      .and("include", "ara_context=");
  });

  it("persists banner on detail page reload via localStorage", () => {
    cy.visit("/documentation/resources/test-resource-group?ara_context=Recommended+for+Python");
    cy.get("#ara-recommendation-banner").should("be.visible");

    cy.visit("/documentation/resources/test-resource-group");
    cy.get("#ara-recommendation-banner").should("be.visible");
    cy.get("#ara-recommendation-text").should("contain", "Python");
  });

  it("dismiss button clears banner and localStorage on detail page", () => {
    cy.visit("/documentation/resources/test-resource-group?ara_context=Recommended+for+Python");
    cy.get("#ara-recommendation-banner").should("be.visible");

    cy.get("#ara-dismiss").click();
    cy.get("#ara-recommendation-banner").should("not.be.visible");

    cy.visit("/documentation/resources/test-resource-group");
    cy.get("#ara-recommendation-banner").should("not.be.visible");
  });

  it("no banner without ara_context param or localStorage", () => {
    cy.visit("/documentation/resources/test-resource-group");
    cy.get("#ara-recommendation-banner").should("not.be.visible");
  });

});

describe("Resource Group — admin action buttons", () => {

  it("anonymous users do not see admin buttons", () => {
    cy.visit("/documentation/resources");
    cy.get(".rp-admin-actions").should("not.exist");
  });

  it("rp_documentation_manager sees Manage Resources and Manage Resource Groups buttons", () => {
    cy.exec('ddev drush user:role:add rp_documentation_manager "authenticated_test_user"', { failOnNonZeroExit: false });
    cy.loginAs("authenticated@amptesting.com", "6%l7iF}6(4tI");
    cy.visit("/documentation/resources");
    cy.get(".rp-admin-actions").should("exist");
    cy.get(".rp-admin-actions").contains("a", "Manage Resources")
      .should("have.attr", "href", "/rp-resources/manage");
    cy.get(".rp-admin-actions").contains("a", "Manage Resource Groups")
      .should("have.attr", "href", "/rp-groups/manage");
    // The "+ Add Resource Group" button moved from the listing page to the
    // Groups admin page (in the view header).
    cy.visit("/rp-groups/manage");
    cy.contains("a", "+ Add Resource Group")
      .should("have.attr", "href", "/node/add/resource_group");
    cy.exec('ddev drush user:role:remove rp_documentation_manager "authenticated_test_user"', { failOnNonZeroExit: false });
  });

});

describe("Resource Group — field_rp_listing filter", () => {

  it("resources with field_rp_listing unchecked do not appear as standalone rows", () => {
    cy.visit("/documentation/resources");
    // Test Resource Beta has field_rp_listing unchecked — it should not appear
    // as its own row. After the short_name change, an h2 would read "Beta" —
    // assert both the full title and the short name are absent.
    cy.get(".view-rp-documentation-index > .view-content.rp-listing-main > .views-row").each(($row) => {
      cy.wrap($row).find("h2").should("not.contain", "Test Resource Beta");
      cy.wrap($row).find("h2").invoke("text").then((text) => {
        expect(text.trim()).to.not.eq("Beta");
      });
    });
  });

});
