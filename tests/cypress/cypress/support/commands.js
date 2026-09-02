/**
 * Verify all images load.
 */
Cypress.Commands.add("verifyImages", () => {
  cy.get("img").each(($img) => cy.wrap($img).verifyImage());
});

/**
 * Verify that an img element loads.
 *
 * Note that this is a "child" custom command, so it must be called
 * with parent cypress command that yields an image element, e.g.:
 *    cy.get('.field--name-field-image')
 *       .get('img')
 *       .verifyImage();
 */
Cypress.Commands.add("verifyImage", { prevSubject: true }, ($img) => {
  const tagName = $img[0]?.tagName?.toLowerCase();

  if (tagName === 'svg') {
    // Inline SVG
    expect($img[0]).to.exist;
    return cy.wrap($img);
  }

  const url = $img[0]?.src || $img[0]?.srcset;

  if (!url) {
    throw new Error(`Found an image with no src or srcset`);
  }

  if (url.startsWith('data:image')) {
    // Data URI: check it exists and isn't empty
    expect(url.length).to.be.greaterThan(10); // crude check
    return cy.wrap($img);
  }

  // Regular image URL. The first hit to an image-style derivative triggers
  // on-the-fly generation, which can briefly return 503 under CI load before
  // the derivative is ready. Poll with backoff (not failing on non-2xx) until
  // it builds, rather than a single immediate retry that races the generation.
  const attempts = 4;
  const verifyImageUrl = (remaining, delay) => {
    return cy.request({ url, failOnStatusCode: false }).then((response) => {
      if (response.status === 200 && response.body.length > 0) {
        return cy.wrap($img);
      }
      if (remaining <= 1) {
        // Out of retries — assert so the failure message is meaningful.
        expect(response.status, `image ${url} status`).to.eq(200);
        expect(response.body.length, `image ${url} body length`).to.be.greaterThan(0);
        return cy.wrap($img);
      }
      cy.task("log", `Image not ready (status ${response.status}), retrying: ${url}`);
      return cy.wait(delay).then(() => verifyImageUrl(remaining - 1, delay * 2));
    });
  };
  return verifyImageUrl(attempts, 500);
});

/**
 * Verify breadcrumbs.
 *
 * var crumbs - an array of arrays, each inner array holds a [crumb, href] where
 *    the crumb is the text of the breadcrumb and the href is the expected url or
 *    null if no url is expected.
 *
 * Example:
 *  const crumbs = [
 *     ['Support', '/'],
 *     ['Affinity Groups', '/affinity-groups'],
 *     ['ACCESS Support', null]
 *   ];
 *   cy.checkBreadcrumbs(crumbs);
 *
 * This looks at the element with the 'breadcrumb' class, and confirms all the
 * crumbs are present and have the expected hrefs.
 *
 */
Cypress.Commands.add("checkBreadcrumbs", (crumbs) => {
  var crumb, href;
  for ([crumb, href] of crumbs) {
    crumb = crumb.charAt(0).toUpperCase() + crumb.slice(1)
    if (href) {
      cy.get(".breadcrumb")
        .contains(crumb)
        .should("have.attr", "href")
        .and("contain", href);
    } else {
      cy.get(".breadcrumb").contains(crumb).should("not.have.attr", "href");
    }
  }
});

/**
 * Logs out the user.
 */
Cypress.Commands.add('drupalLogout', () => {
  cy.visit('/user/logout', { failOnStatusCode: false });

  // Deal with logout confirmation form (Drupal 10 requires confirmation).
  cy.get('body').then(($body) => {
    if ($body.find('#user-logout-confirm #edit-submit').length) {
      cy.get('#user-logout-confirm #edit-submit').click();
    }
  });

  // Guarantee the session is gone even if the confirmation flow raced or the
  // page was already anonymous. Dropping the session cookie makes the browser
  // anonymous so the login form is rendered on the next visit, instead of the
  // current user's profile page.
  cy.clearCookies();
});

/**
 * Logs out, visits a login route, and submits the login form.
 *
 * Self-heals against a stale session: if a leftover session redirects the
 * login route to the user profile page (where #edit-name is absent), it clears
 * cookies and reloads so the anonymous login form renders before typing.
 *
 * @param {string} loginPath
 *   The path of the login form to visit.
 * @param {string} username
 *   The username with which to log in.
 * @param {string} password
 *   The password for the user's account.
 */
const submitLoginForm = (loginPath, username, password) => {
  cy.drupalLogout();
  cy.visit(loginPath);

  // If a stale session redirected us away from the login form, force the
  // anonymous state and reload before interacting with the form.
  cy.get('body').then(($body) => {
    if (!$body.find('#edit-name').length) {
      cy.clearCookies();
      cy.visit(loginPath);
    }
  });

  cy.get('#edit-name').should('be.visible').type(username);
  cy.get('#edit-pass').type(password, {
    log: false,
  });
  cy.get('.form-submit').contains('Log in').click();
};

/**
 * Basic user login command. Requires valid username and password.
 *
 * @param {string} username
 *   The username with which to log in.
 * @param {string} password
 *   The password for the user's account.
 */
Cypress.Commands.add("loginAs", (username, password) => {
  submitLoginForm("/f64816be-34ca-4d5b-975a-687cb374ddf7", username, password);
});

/**
 * Basic user login command. Requires valid username and password.
 *
 * @param {string} username
 *   The username with which to log in.
 * @param {string} password
 *   The password for the user's account.
 */
Cypress.Commands.add("loginUser", (username, password) => {
  submitLoginForm("/user", username, password);
});

/**
 * User login command for default /user/login route.
 *
 * Requires valid username and password.
 *
 * @param {string} username
 *   The username with which to log in.
 * @param {string} password
 *   The password for the user's account.
 */
Cypress.Commands.add("loginWith", (username, password) => {
  submitLoginForm("/user/login", username, password);
});

/**
 * Custom command to verify the existence of a button within the "region-cta" block
 */
Cypress.Commands.add("verifyCallToActionBlock", (url, text, href) => {
  // Visit the specified URL
  cy.visit(url);

  // cta section is tested, contained text within button, Button destination

  cy.get("#cta")
    .should("exist")
    .contains(text)
    .should("have.attr", "href")
    .and("contain", href);
});

/**
 * Deletes last node created.
 */
Cypress.Commands.add("deleteLastNode", () => {
  cy.visit("/admin/content");
  cy.get("tbody > :nth-child(1) > .views-field-title > a").click();
  cy.get("#block-dingo-local-tasks > ul > :nth-child(3) > a").click({
    force: true,
  });
  cy.get('[value="Delete"]').click();
});

/**
 * Logs a user in by their uid via drush uli.
 */
Cypress.Commands.add("drushUli", () => {
  cy.task("log", "in drushUli");

  cy.drush("uli", ["--uri=" + Cypress.config("baseUrl")], {}).then((result) => {
    cy.task("log", "result = " + JSON.stringify(result));
    if (result.code !== 0) {
      throw new Error(result.stderr);
    } else {
      cy.task("log", 'drushUli trying to visit "' + result.stdout + '"');
      cy.visit(result.stdout);
    }
  });
});

/**
 * Defines a cypress command that executes drush commands.
 *
 * Note that our definition of the drush command depends on our environment
 * variable 'drushCommand'. Define this in cypress.json or cypress.env.json
 * based on your local dev setup.
 *
 * We're passing the object containing 'failOnNonZeroExit' to Cypress so that
 * our Cypress tests don't crash if the drush command returns an error (e.g.
 * if we try to delete a user account that does not exist.)
 */
Cypress.Commands.add("drush", (command, args = [], options = {}) => {
  cy.task("log", 'in drush, command = "' + command + '"');
  const ee = `drush ${command} ${stringifyArguments(args)} ${stringifyOptions(
    options
  )} -y`;
  cy.task("log", "in drush, about to exec this:  " + ee);
  return cy.exec(ee, { failOnNonZeroExit: false });
});

/**
 * Returns a series of arguments, separated by spaces.
 *
 * @param {*} args
 * @returns
 */
function stringifyArguments(args) {
  return args.join(" ");
}

/**
 * Returns a string from an array of options.
 *
 * @param {array} options
 * @returns
 */
function stringifyOptions(options) {
  return Object.keys(options)
    .map((option) => {
      let output = `--${option}`;

      if (options[option] === true) {
        return output;
      }

      if (options[option] === false) {
        return "";
      }

      if (typeof options[option] === "string") {
        output += `="${options[option]}"`;
      } else {
        output += `=${options[option]}`;
      }

      return output;
    })
    .join(" ");
}

// -----------------------------------------------------------------------------
// AJAX synchronization helpers
//
// Drupal-driven AJAX (Views, facets, autocomplete, multi-value form widgets)
// has no synchronous "done" signal we can await. Tests historically used fixed
// `cy.wait(1000)` timers — fast enough on a quiet machine, racy under CI load.
// These helpers wait for the actual response or DOM signal, so tests stay
// fast on a fast machine and reliable on a slow one.
//
// Pick the right one for the situation:
// - typeAutocomplete:    entity-reference / taxonomy autocomplete fields
// - searchAndWait:       exposed search-api filter inputs
// - expectAjax/waitForAjax: escape hatch for everything else (named alias)
// - waitForDrupalSettle: "wait for any in-flight AJAX throbber to disappear"
//
// NOT for facets: the facets module binds `change.facets` during
// Drupal.attachBehaviors, so a click can land before binding and fire no AJAX
// at all. Both AJAX-intercept and URL-change waits proved unreliable. Use
// cy.wait(1000) after .check()/.uncheck() in facet specs until we find a
// deterministic ready-signal for the widget binding.
// -----------------------------------------------------------------------------

/**
 * Type into a Drupal entity-reference autocomplete field and wait for the
 * autocomplete AJAX response.
 *
 * @example
 *   cy.typeAutocomplete('#edit-field-access-organization-0-target-id', 'NCSA');
 *   // Then check whether the dropdown appeared:
 *   cy.get('body').then(($body) => {
 *     if ($body.find('.ui-autocomplete:visible').length > 0) { ... }
 *   });
 */
Cypress.Commands.add("typeAutocomplete", (selector, value) => {
  const alias = `autocompleteAjax_${Cypress._.uniqueId()}`;
  cy.intercept('GET', '**/entity_reference_autocomplete/**').as(alias);
  cy.get(selector).type(value, { delay: 0 });
  cy.wait(`@${alias}`);
});

/**
 * Type into an exposed search-api filter and wait for the Views AJAX response.
 *
 * @example
 *   cy.searchAndWait('#edit-search-api-fulltext', 'AI');
 */
Cypress.Commands.add("searchAndWait", (selector, query) => {
  const alias = `viewsSearchAjax_${Cypress._.uniqueId()}`;
  cy.intercept('GET', '**/views/ajax**').as(alias);
  cy.get(selector).type(query, { delay: 0 });
  cy.wait(`@${alias}`);
});

/**
 * Set up an aliased AJAX intercept BEFORE the action that triggers it.
 * Pair with cy.waitForAjax(alias) AFTER the action.
 *
 * @example
 *   cy.expectAjax('myAjax', '**\/system/ajax');
 *   cy.get('#some-button').click();
 *   cy.waitForAjax('myAjax');
 */
Cypress.Commands.add("expectAjax", (alias, urlPattern) => {
  cy.intercept(urlPattern).as(alias);
});

/**
 * Wait for a previously-aliased AJAX request to complete.
 * Pair with cy.expectAjax(alias, urlPattern) BEFORE the action.
 */
Cypress.Commands.add("waitForAjax", (alias) => {
  cy.wait('@' + alias);
});

/**
 * Wait for any in-flight Drupal AJAX throbber to disappear. Use this when
 * you don't know exactly what URL the action triggers but know there's a
 * Drupal AJAX rebuild in progress (e.g. multi-value field add/remove).
 *
 * Returns immediately if no throbber is currently visible.
 *
 * @example
 *   cy.get('#edit-field-foo-add-more').click();
 *   cy.waitForDrupalSettle();
 */
Cypress.Commands.add("waitForDrupalSettle", () => {
  cy.get('body').then(($body) => {
    if ($body.find('.ajax-progress').length > 0) {
      cy.get('.ajax-progress', { timeout: 10000 }).should('not.exist');
    }
  });
});
