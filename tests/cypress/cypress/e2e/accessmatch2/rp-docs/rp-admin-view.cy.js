describe("RP Resources Admin View", () => {

  const managePath = "/rp-resources/manage";

  describe("Anonymous access", () => {
    it("denies anonymous users access to the manage view", () => {
      cy.request({ url: managePath, failOnStatusCode: false }).then((response) => {
        expect(response.status).to.be.oneOf([403, 200]);
        if (response.status === 200) {
          // If 200, must be the login redirect page, not the actual view.
          expect(response.body).to.not.include('RP Resources');
        }
      });
    });
  });

  describe("kb_pm role (no longer has RP access)", () => {
    before(() => {
      // Ensure rp_documentation_manager is removed in case a prior test left it behind
      cy.exec('ddev drush user:role:remove rp_documentation_manager "authenticated_test_user"', { failOnNonZeroExit: false });
      cy.exec('ddev drush user:role:add kb_pm "authenticated_test_user"', { failOnNonZeroExit: false });
    });

    after(() => {
      cy.exec('ddev drush user:role:remove kb_pm "authenticated_test_user"', { failOnNonZeroExit: false });
    });

    beforeEach(() => {
      cy.loginAs("authenticated@amptesting.com", "6%l7iF}6(4tI");
    });

    it("cannot access the manage view", () => {
      cy.request({ url: managePath, failOnStatusCode: false }).then((response) => {
        expect(response.status).to.eq(403);
      });
    });
  });

  describe("rp_documentation_manager role", () => {
    before(() => {
      cy.exec('ddev drush user:role:add rp_documentation_manager "authenticated_test_user"', { failOnNonZeroExit: false });
    });

    after(() => {
      cy.exec('ddev drush user:role:remove rp_documentation_manager "authenticated_test_user"', { failOnNonZeroExit: false });
    });

    beforeEach(() => {
      cy.loginAs("authenticated@amptesting.com", "6%l7iF}6(4tI");
    });

    it("can access the manage view", () => {
      cy.visit(managePath);
      cy.url().should("include", managePath);
      cy.contains("RP Resources");
    });

    it("shows table with expected columns", () => {
      cy.visit(managePath);
      cy.get("table thead").within(() => {
        cy.contains("th", "Resource");
        cy.contains("th", "Organization");
        cy.contains("th", "Updated");
        cy.contains("th", "Operations");
      });
    });

    it("displays CIDER resource rows", () => {
      cy.visit(managePath);
      cy.get("table tbody tr").should("have.length.greaterThan", 0);
    });

    it("has exposed filters for Resource and Organization", () => {
      cy.visit(managePath);
      cy.get("#edit-title").should("exist");
      cy.get("#edit-org").should("exist");
    });

    it("filters by resource name", () => {
      cy.visit(managePath);
      // The exposed "Resource" filter (URL identifier "title") does a CONTAINS
      // match on field_cider_short_name, not the raw node title — so filter on
      // the short name "Alpha", which is also the displayed Resource cell value.
      cy.get("#edit-title").type("Alpha");
      cy.get("#edit-submit-rp-resources-admin").click();
      // The exposed filter submits as a GET reload. Wait for the URL to reflect
      // the applied filter AND for the filtered row to actually render before
      // asserting — a bare "contains" can fire against the pre-reload unfiltered
      // table still on screen. Scoping the wait to the row containing "Alpha"
      // makes the retry wait for the real post-filter render.
      cy.url().should("include", "title=Alpha");
      cy.get("table tbody").contains("tr", "Alpha");
    });

    it("filters by organization name", () => {
      cy.visit(managePath);
      cy.get("#edit-org").type("Test University");
      cy.get("#edit-submit-rp-resources-admin").click();
      cy.get("table tbody tr").should("have.length.greaterThan", 0);
      cy.get("table tbody").contains("Test University");
    });

    it("has edit link in operations column", () => {
      cy.visit(managePath);
      cy.get("table tbody tr").first().within(() => {
        cy.contains("Edit");
      });
    });

    it("can navigate to edit form for a resource", () => {
      cy.visit(managePath);
      cy.get("table tbody tr").first().within(() => {
        cy.contains("Edit").click();
      });
      cy.url().should("include", "/edit");
      cy.get("#edit-submit").should("exist");
    });
  });

});

describe("RP Documentation Index — public", () => {

  it("loads the /documentation/resources listing page", () => {
    cy.visit("/documentation/resources");
    cy.contains("Each ACCESS resource has unique configurations");
  });

  it("lists resources that have descriptions", () => {
    cy.visit("/documentation/resources");
    cy.get(".view-rp-documentation-index .views-row").should("have.length.greaterThan", 0);
  });

});

describe("RP Groups Admin View", () => {

  const managePath = "/rp-groups/manage";

  describe("Anonymous access", () => {
    it("denies anonymous users access to the groups manage view", () => {
      cy.request({ url: managePath, failOnStatusCode: false }).then((response) => {
        expect(response.status).to.be.oneOf([403, 200]);
        if (response.status === 200) {
          expect(response.body).to.not.include('Resource Groups');
        }
      });
    });
  });

  describe("rp_documentation_manager role", () => {
    before(() => {
      cy.exec('ddev drush user:role:add rp_documentation_manager "authenticated_test_user"', { failOnNonZeroExit: false });
    });

    after(() => {
      cy.exec('ddev drush user:role:remove rp_documentation_manager "authenticated_test_user"', { failOnNonZeroExit: false });
    });

    beforeEach(() => {
      cy.loginAs("authenticated@amptesting.com", "6%l7iF}6(4tI");
    });

    it("can access the groups manage view", () => {
      cy.visit(managePath);
      cy.url().should("include", managePath);
      cy.contains("Resource Groups");
    });

    it("shows the Add Resource Group button in the view header", () => {
      cy.visit(managePath);
      cy.contains("a", "+ Add Resource Group")
        .should("have.attr", "href", "/node/add/resource_group");
    });

    it("shows table with expected columns", () => {
      cy.visit(managePath);
      cy.get("table thead").within(() => {
        cy.contains("th", "Group");
        cy.contains("th", "Member resources");
        cy.contains("th", "On listing");
        cy.contains("th", "Updated");
        cy.contains("th", "Operations");
      });
    });

    it("lists the Test Resource Group row", () => {
      cy.visit(managePath);
      cy.get("table tbody").contains("Test Resource Group");
    });

    it("filters by group name", () => {
      cy.visit(managePath);
      cy.get("#edit-title").type("Test Resource Group");
      cy.get("#edit-submit-rp-groups-admin").click();
      cy.get("table tbody").contains("Test Resource Group");
    });

    it("has edit link in operations column", () => {
      cy.visit(managePath);
      cy.get("table tbody tr").first().within(() => {
        cy.contains("Edit");
      });
    });
  });

});
