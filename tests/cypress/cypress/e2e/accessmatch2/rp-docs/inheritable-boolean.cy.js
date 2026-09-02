/**
 * Three-state inheritable-boolean control on RP resource docs.
 *
 * The account/MFA badge booleans inherit from the parent resource group. The
 * resource edit form shows a select (Inherit / Yes / No) instead of a checkbox
 * so an editor can see the inherited value and explicitly override it, and
 * saving an inheriting resource must NOT silently flip it. Kernel tests cover
 * the submit mapping in isolation; this covers the on-page behaviour the node
 * form pulls in (the real widget render + the rendered badge).
 *
 * Fixtures (amp_dev.install): "Test Resource Group" (12312) with members
 * Alpha (12309, explicit values) and Beta (12310, inherits). The group's
 * account_required is empty by default; this spec sets it and restores it.
 */
describe("RP docs — inheritable boolean (account/MFA) control", () => {
  const GROUP_NID = 12312;
  const BETA_NID = 12310;
  const BETA_PATH = "/documentation/resources/beta";
  const BETA_EDIT = `/node/${BETA_NID}/edit`;

  // The badge selects live inside the collapsed "Login" details group; open it
  // (idempotently, via the attribute) before interacting with them.
  const openLoginGroup = () =>
    cy.get("details#edit-group-login-access").invoke("attr", "open", "open");

  const setBool = (nid, field, value) =>
    // value: "1", "0", or "" (empty = inherit)
    cy.exec(
      value === ""
        ? `ddev drush php:eval '$n=\\Drupal::entityTypeManager()->getStorage("node")->load(${nid});$n->set("${field}",NULL);$n->save();'`
        : `ddev drush php:eval '$n=\\Drupal::entityTypeManager()->getStorage("node")->load(${nid});$n->set("${field}",${value});$n->save();'`,
      { failOnNonZeroExit: false }
    );

  before(() => {
    cy.exec('ddev drush user:role:add rp_documentation_manager "authenticated_test_user"', { failOnNonZeroExit: false });
    // Group requires an account; Beta left empty so it inherits.
    setBool(GROUP_NID, "field_rp_account_required", "1");
    setBool(BETA_NID, "field_rp_account_required", "");
    cy.exec("ddev drush cr", { failOnNonZeroExit: false });
  });

  after(() => {
    // Restore fixture state (group empty, Beta empty).
    setBool(GROUP_NID, "field_rp_account_required", "");
    setBool(BETA_NID, "field_rp_account_required", "");
    cy.exec('ddev drush user:role:remove rp_documentation_manager "authenticated_test_user"', { failOnNonZeroExit: false });
    cy.exec("ddev drush cr", { failOnNonZeroExit: false });
  });

  it("renders the inherited badge on a resource that inherits account_required", () => {
    // Beta inherits the group's TRUE, so the page shows the account badge even
    // though Beta's own field is empty.
    cy.visit(BETA_PATH);
    cy.contains("RP account needed");
  });

  it("shows a three-state select defaulting to Inherit with the resolved value", () => {
    cy.loginAs("authenticated@amptesting.com", "6%l7iF}6(4tI");
    cy.visit(BETA_EDIT);

    // The field is a <select>, not a checkbox, and its inherit option names the
    // currently-inherited value.
    cy.get("select[name='operations_cider_bool[field_rp_account_required]']")
      .as("accountSelect")
      .should("exist");
    cy.get("@accountSelect")
      .find("option:selected")
      .invoke("text")
      .should("match", /Inherit from resource group \(currently: Yes\)/i);
  });

  it("lets a resource override the inherited value to No, hiding the badge", () => {
    cy.loginAs("authenticated@amptesting.com", "6%l7iF}6(4tI");
    cy.visit(BETA_EDIT);

    // Explicitly choose No, save.
    openLoginGroup();
    cy.get("select[name='operations_cider_bool[field_rp_account_required]']").select("0");
    cy.get("#edit-submit").click();

    // The rendered page no longer shows the account badge — the explicit No
    // overrode the group's Yes.
    cy.visit(BETA_PATH);
    cy.contains("RP account needed").should("not.exist");

    // Reset Beta back to inherit for the next test / teardown.
    setBool(BETA_NID, "field_rp_account_required", "");
    cy.exec("ddev drush cr", { failOnNonZeroExit: false });
  });

  it("saving while left on Inherit keeps the resource inheriting (no silent flip)", () => {
    // The regression this whole change fixes: opening and saving an inheriting
    // resource must not turn the empty field into an explicit value.
    cy.loginAs("authenticated@amptesting.com", "6%l7iF}6(4tI");
    cy.visit(BETA_EDIT);
    cy.get("select[name='operations_cider_bool[field_rp_account_required]']")
      .find("option:selected")
      .invoke("val")
      .should("eq", ""); // still on Inherit
    cy.get("#edit-submit").click();

    // Still inheriting -> badge still present from the group's Yes.
    cy.visit(BETA_PATH);
    cy.contains("RP account needed");
  });

  it("keeps the account-setup-url validation working through the select", () => {
    // The select moved the field's form value out of its default path; a
    // validate handler mirrors it back so access_misc's "setup URL required
    // when account required" rule still fires. Choose Yes with no setup URL and
    // expect the validation error (not a successful save).
    cy.loginAs("authenticated@amptesting.com", "6%l7iF}6(4tI");
    // No setup URL anywhere — not on Beta AND not on the group (which Beta would
    // otherwise inherit, correctly suppressing the error). Only then is the
    // "URL required" rule genuinely expected to fire.
    cy.exec(
      `ddev drush php:eval '$b=\\Drupal::entityTypeManager()->getStorage("node")->load(${BETA_NID});$b->set("field_rp_account_setup_url",[]);$b->save();$g=\\Drupal::entityTypeManager()->getStorage("node")->load(${GROUP_NID});$g->set("field_rp_account_setup_url",[]);$g->save();'`,
      { failOnNonZeroExit: false }
    );
    cy.exec("ddev drush cr", { failOnNonZeroExit: false });
    cy.visit(BETA_EDIT);
    openLoginGroup();
    cy.get("select[name='operations_cider_bool[field_rp_account_required]']").select("1");
    cy.get("#edit-submit").click();
    cy.contains("is required when RP Account Required is checked");

    // Reset Beta back to inherit for teardown.
    setBool(BETA_NID, "field_rp_account_required", "");
    cy.exec("ddev drush cr", { failOnNonZeroExit: false });
  });

  it("does NOT false-error when the setup URL is inherited from the group", () => {
    // Regression: account_setup_url is itself inheritable. A resource that
    // inherits account_required=Yes AND inherits its setup URL from the group
    // has an empty OWN setup_url but a valid effective one, so the "URL
    // required" rule must NOT fire — the resource must save.
    cy.loginAs("authenticated@amptesting.com", "6%l7iF}6(4tI");
    // Group provides the setup URL; Beta's own is empty (inherits it).
    cy.exec(
      `ddev drush php:eval '$g=\\Drupal::entityTypeManager()->getStorage("node")->load(${GROUP_NID});$g->set("field_rp_account_setup_url",["uri"=>"https://group.example/setup"]);$g->save();$b=\\Drupal::entityTypeManager()->getStorage("node")->load(${BETA_NID});$b->set("field_rp_account_setup_url",[]);$b->save();'`,
      { failOnNonZeroExit: false }
    );
    cy.exec("ddev drush cr", { failOnNonZeroExit: false });

    cy.visit(BETA_EDIT);
    // Leave account on Inherit (currently Yes) and save.
    cy.get("#edit-submit").click();
    // No validation error, and we land on the saved node (not back on the form).
    cy.contains("is required when RP Account Required is checked").should("not.exist");

    // Teardown: clear the group's setup URL again.
    cy.exec(
      `ddev drush php:eval '$g=\\Drupal::entityTypeManager()->getStorage("node")->load(${GROUP_NID});$g->set("field_rp_account_setup_url",[]);$g->save();'`,
      { failOnNonZeroExit: false }
    );
    cy.exec("ddev drush cr", { failOnNonZeroExit: false });
  });
});
