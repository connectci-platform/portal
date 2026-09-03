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
 * Fixtures (amp_dev.install): "Test Resource Group" with members Alpha
 * (explicit values) and Beta (inherits). Node IDs differ per environment, so
 * everything anchors on the pathauto aliases and resolves nids at runtime.
 */
describe("RP docs — inheritable boolean (account/MFA) control", { retries: { runMode: 2, openMode: 0 } }, () => {
  const BETA_ALIAS = "/documentation/resources/beta";
  const GROUP_ALIAS = "/documentation/resources/test-resource-group";

  // Resolved in before(); alias + /edit does not route, so edit visits need
  // the real /node/{nid}/edit path.
  let betaNid;

  // The badge selects live inside the collapsed "Login" details group; open it
  // (idempotently, via the attribute) before interacting with them.
  const openLoginGroup = () =>
    cy.get("details#edit-group-login-access").invoke("attr", "open", "open");

  // Run a drush php snippet against the node behind an alias. Loud by default
  // so a broken fixture fails the run at setup with a clear message.
  const drushOnAlias = (alias, php, opts = {}) =>
    cy.exec(
      `ddev drush php:eval '$p=\\Drupal::service("path_alias.manager")->getPathByAlias("${alias}");` +
        `$n=\\Drupal::entityTypeManager()->getStorage("node")->load((int) substr($p, 6));` +
        `if (!$n) { echo "NO NODE for ${alias}"; exit(1); }${php}'`,
      { timeout: 120000, ...opts }
    );

  const setBool = (alias, field, value, opts = {}) =>
    drushOnAlias(
      alias,
      value === ""
        ? `$n->set("${field}", NULL);$n->save();`
        : `$n->set("${field}", ${value});$n->save();`,
      opts
    );

  before(() => {
    cy.exec('ddev drush user:role:add rp_documentation_manager "authenticated_test_user"', { failOnNonZeroExit: false });
    cy.exec(
      `ddev drush php:eval 'echo (int) substr(\\Drupal::service("path_alias.manager")->getPathByAlias("${BETA_ALIAS}"), 6);'`
    ).then((r) => {
      betaNid = r.stdout.trim().replace(/\D/g, "");
      expect(betaNid, `nid resolved from ${BETA_ALIAS}`).to.not.equal("0");
    });
    // Group requires an account; Beta left empty so it inherits.
    setBool(GROUP_ALIAS, "field_rp_account_required", "1");
    setBool(BETA_ALIAS, "field_rp_account_required", "");
    cy.exec("ddev drush cr", { failOnNonZeroExit: false, timeout: 180000 });
    // Warm the rebuilt caches so the first cy.visit is not a cold full
    // bootstrap that can blow the page-load budget on a fresh stack.
    cy.request({ url: BETA_ALIAS, timeout: 120000 });
  });

  after(() => {
    // Restore fixture state (group empty, Beta empty); quiet so teardown noise
    // never masks a test result.
    setBool(GROUP_ALIAS, "field_rp_account_required", "", { failOnNonZeroExit: false });
    setBool(BETA_ALIAS, "field_rp_account_required", "", { failOnNonZeroExit: false });
    cy.exec('ddev drush user:role:remove rp_documentation_manager "authenticated_test_user"', { failOnNonZeroExit: false });
    cy.exec("ddev drush cr", { failOnNonZeroExit: false, timeout: 180000 });
  });

  it("renders the inherited badge on a resource that inherits account_required", () => {
    // Beta inherits the group's TRUE, so the page shows the account badge even
    // though Beta's own field is empty.
    cy.visit(BETA_ALIAS);
    cy.contains("RP account needed");
  });

  it("shows a three-state select defaulting to Inherit with the resolved value", () => {
    cy.loginAs("authenticated@amptesting.com", "6%l7iF}6(4tI");
    cy.visit(`/node/${betaNid}/edit`);

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
    cy.visit(`/node/${betaNid}/edit`);

    // Explicitly choose No, save.
    openLoginGroup();
    cy.get("select[name='operations_cider_bool[field_rp_account_required]']").select("0");
    cy.get("#edit-submit").click();

    // The rendered page no longer shows the account badge — the explicit No
    // overrode the group's Yes.
    cy.visit(BETA_ALIAS);
    cy.contains("RP account needed").should("not.exist");

    // Reset Beta back to inherit for the next test / teardown.
    setBool(BETA_ALIAS, "field_rp_account_required", "", { failOnNonZeroExit: false });
    cy.exec("ddev drush cr", { failOnNonZeroExit: false, timeout: 180000 });
  });

  it("saving while left on Inherit keeps the resource inheriting (no silent flip)", () => {
    // The regression this whole change fixes: opening and saving an inheriting
    // resource must not turn the empty field into an explicit value.
    cy.loginAs("authenticated@amptesting.com", "6%l7iF}6(4tI");
    cy.visit(`/node/${betaNid}/edit`);
    cy.get("select[name='operations_cider_bool[field_rp_account_required]']")
      .find("option:selected")
      .invoke("val")
      .should("eq", ""); // still on Inherit
    cy.get("#edit-submit").click();

    // Still inheriting -> badge still present from the group's Yes.
    cy.visit(BETA_ALIAS);
    cy.contains("RP account needed");
    // The badge alone cannot distinguish inherited-Yes from a wrongly
    // materialized explicit Yes — assert the stored field is genuinely empty.
    drushOnAlias(
      BETA_ALIAS,
      'echo $n->get("field_rp_account_required")->isEmpty() ? "EMPTY" : "MATERIALIZED";'
    ).then((r) => {
      expect(r.stdout).to.contain("EMPTY");
    });
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
    drushOnAlias(BETA_ALIAS, '$n->set("field_rp_account_setup_url", []);$n->save();');
    drushOnAlias(GROUP_ALIAS, '$n->set("field_rp_account_setup_url", []);$n->save();');
    cy.exec("ddev drush cr", { failOnNonZeroExit: false, timeout: 180000 });
    cy.visit(`/node/${betaNid}/edit`);
    openLoginGroup();
    cy.get("select[name='operations_cider_bool[field_rp_account_required]']").select("1");
    cy.get("#edit-submit").click();
    cy.contains("is required when RP Account Required is checked");

    // Reset Beta back to inherit for teardown.
    setBool(BETA_ALIAS, "field_rp_account_required", "", { failOnNonZeroExit: false });
    cy.exec("ddev drush cr", { failOnNonZeroExit: false, timeout: 180000 });
  });

  it("does NOT false-error when the setup URL is inherited from the group", () => {
    // Regression: account_setup_url is itself inheritable. A resource that
    // inherits account_required=Yes AND inherits its setup URL from the group
    // has an empty OWN setup_url but a valid effective one, so the "URL
    // required" rule must NOT fire — the resource must save.
    cy.loginAs("authenticated@amptesting.com", "6%l7iF}6(4tI");
    // Group provides the setup URL; Beta's own is empty (inherits it).
    drushOnAlias(GROUP_ALIAS, '$n->set("field_rp_account_setup_url", ["uri" => "https://group.example/setup"]);$n->save();');
    drushOnAlias(BETA_ALIAS, '$n->set("field_rp_account_setup_url", []);$n->save();');
    cy.exec("ddev drush cr", { failOnNonZeroExit: false, timeout: 180000 });

    cy.visit(`/node/${betaNid}/edit`);
    // Leave account on Inherit (currently Yes) and save.
    cy.get("#edit-submit").click();
    // Positive proof the save LANDED (otherwise the negative checks below
    // would also pass while validation silently blocked the save).
    cy.contains("has been updated");
    cy.contains("is required when RP Account Required is checked").should("not.exist");

    // Regression: the save must NOT materialize the inherited URL onto the
    // resource (that would silently convert inheritance into an explicit copy).
    drushOnAlias(
      BETA_ALIAS,
      'echo $n->get("field_rp_account_setup_url")->isEmpty() ? "EMPTY" : "MATERIALIZED";'
    ).then((r) => {
      expect(r.stdout).to.contain("EMPTY");
    });

    // Teardown: clear the group's setup URL again.
    drushOnAlias(GROUP_ALIAS, '$n->set("field_rp_account_setup_url", []);$n->save();', { failOnNonZeroExit: false });
    cy.exec("ddev drush cr", { failOnNonZeroExit: false, timeout: 180000 });
  });
});
