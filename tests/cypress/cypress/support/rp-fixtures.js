/**
 * Helpers for specs that need Resource Provider fixtures created on demand.
 */

/**
 * Creates (or refreshes) Test Resource Delta and Test Inheritance Group by
 * calling the amp_dev install helper through drush, so the spec does not
 * depend on the nightly DB artifact. The helper is safe to re-run.
 *
 * Delta owns only a storage row; the group supplies file transfer and
 * datasets that Delta inherits.
 *
 * @returns {Cypress.Chainable}
 */
export function seedInheritanceFixture() {
  // The whole string is single-quoted for the shell, so the PHP uses double
  // quotes only.
  const php =
    'require_once DRUPAL_ROOT . "/" . \\Drupal::service("extension.list.module")->getPath("amp_dev") . "/amp_dev.install";' +
    'amp_dev_install_create_rp_inheritance_fixture();' +
    'print "done";';
  return cy.drush("php:eval", [`'${php}'`]).then((result) => {
    if (result.exitCode !== 0 || !result.stdout.trim().endsWith("done")) {
      throw new Error(`seedInheritanceFixture failed: ${JSON.stringify(result)}`);
    }
  });
}
