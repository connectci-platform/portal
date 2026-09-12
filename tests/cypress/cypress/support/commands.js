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
// `cy.wait(1000)` timers — fast enough on a quiet machine, racy under CI load,
// where a Views round trip plus the facets block refresh that follows it runs
// two to five seconds. These helpers wait for the request that actually
// carries the interaction and then for the page to go idle, so tests stay
// fast on a fast machine and reliable on a slow one.
//
// Pick the right one for the situation:
// - typeAutocomplete:    entity-reference / taxonomy autocomplete fields
// - searchAndWait:       exposed search-api filter inputs
// - clearSearchAndWait:  clearing one of those inputs
// - clickFacetAndWait:   facet checkboxes, facet links and facet reset links
// - expandFacetSoftLimit: a facet's "Show more" link, by facet URL alias
// - waitForAjaxIdle:     "wait until nothing is in flight" (the primitive)
// - expectAjax/waitForAjax: escape hatch for everything else (named alias)
// - waitForDrupalSettle: "wait for any in-flight AJAX throbber to disappear"
//
// Never target an exposed filter or a facet block by id. Both the exposed
// form's duplicate-id counter (`--2`) and the block wrapper's AJAX suffix
// (`--<hash>`) change once a view re-renders over AJAX. Use
// `[data-drupal-selector="edit-search-api-fulltext"]` for the search input and
// `.block-facet-block<facet-id>:visible` for a facet block. Facet items are
// placed twice (a desktop block and a collapsed mobile copy) and both copies
// carry the same item id, so scope every facet item with `:visible`.
// -----------------------------------------------------------------------------

/**
 * Wait until no jQuery-driven AJAX request is in flight and no Drupal AJAX
 * throbber is left on the page.
 *
 * Views AJAX, the facets block refresh and the facets summary refresh all run
 * through Drupal.ajax, which runs through jQuery.ajax, so `jQuery.active` is
 * an exact count of the requests still outstanding. Polling it is what
 * replaces the fixed timers: a facet click fires two requests in sequence, and
 * the second one only starts once the first response has been inserted.
 *
 * @param {object} [options]
 * @param {number} [options.grace]
 *   Milliseconds to wait before the first check, for a debounced auto-submit
 *   that has not fired yet. The exposed filters on these views are debounced
 *   up to 800ms.
 * @param {number} [options.settle]
 *   Milliseconds the page must stay idle before this resolves. This is what
 *   catches the follow-up request that starts after the first one lands.
 * @param {number} [options.timeout]
 *   Milliseconds to keep polling before failing.
 *
 * @example
 *   cy.get('#some-facet:visible').click();
 *   cy.waitForAjaxIdle();
 */
Cypress.Commands.add("waitForAjaxIdle", (options = {}) => {
  const { grace = 0, settle = 750, timeout = 30000 } = options;

  return cy.window({ log: false }).then({ timeout: grace + timeout + 5000 }, (win) => {
    const busy = () => {
      const active = win.jQuery ? win.jQuery.active : 0;
      return active > 0 || win.document.querySelector('.ajax-progress') !== null;
    };
    const deadline = Date.now() + grace + timeout;

    // idleSince is null until we see a quiet poll, then holds the timestamp of
    // that poll so we can require `settle` ms of continuous quiet.
    const poll = (idleSince) => {
      if (busy()) {
        if (Date.now() > deadline) {
          throw new Error(`waitForAjaxIdle: AJAX still in flight after ${timeout}ms`);
        }
        return Cypress.Promise.delay(100).then(() => poll(null));
      }
      if (idleSince !== null && Date.now() - idleSince >= settle) {
        return null;
      }
      if (Date.now() > deadline) {
        throw new Error(`waitForAjaxIdle: AJAX did not settle within ${timeout}ms`);
      }
      return Cypress.Promise.delay(100).then(() => poll(idleSince === null ? Date.now() : idleSince));
    };

    return Cypress.Promise.delay(grace).then(() => poll(null));
  });
});

/**
 * Wait for a Views AJAX request that actually carries the given exposed-filter
 * value, skipping any request already queued when the intercept was set up.
 *
 * `cy.wait('@alias')` resolves on the first request to match the alias, which
 * under load is routinely the previous interaction's request or a debounce
 * fragment ("jul" on the way to "julie"). Waiting for the request whose
 * payload holds the value we typed makes the wait mean what the spec meant.
 *
 * @param {string} alias
 *   Intercept alias, without the leading "@".
 * @param {string} name
 *   Exposed filter parameter name, e.g. "search_api_fulltext".
 * @param {string} value
 *   The value the request must carry. Pass '' for a cleared field.
 */
Cypress.Commands.add("waitForExposedFilterRequest", (alias, name, value, options = {}) => {
  const { attempts = 12, timeout = 20000 } = options;
  const pattern = new RegExp(`${Cypress._.escapeRegExp(name)}=${Cypress._.escapeRegExp(value)}(&|$)`);

  const next = (left) =>
    cy.wait(`@${alias}`, { timeout }).then((interception) => {
      const request = interception.request;
      // The value can arrive in the query string (Views AJAX, and the `?q=`
      // copy of a facet href) or in the POST body, so search both.
      const parts = [request.url || ''];
      if (typeof request.body === 'string') {
        parts.push(request.body);
      }
      else if (request.body) {
        parts.push(JSON.stringify(request.body));
      }
      let haystack = parts.join('&').replace(/\+/g, ' ');
      try {
        haystack = decodeURIComponent(haystack);
      }
      catch (e) {
        // Leave the raw string in place: a value that cannot be decoded is
        // still worth matching against.
      }
      if (pattern.test(haystack)) {
        return null;
      }
      if (left <= 1) {
        throw new Error(`waitForExposedFilterRequest: no Views AJAX request carried ${name}="${value}"`);
      }
      return next(left - 1);
    });

  return next(attempts);
});

/**
 * Click a facet item (checkbox, link or reset link) and wait for the view and
 * the facet blocks to finish refreshing.
 *
 * Two things make a bare `.click()` unreliable. The facets checkbox widget
 * binds `change.facets` during Drupal.attachBehaviors, so a click that lands
 * before the binding fires no AJAX at all; and the widget disables every
 * checkbox in the block for the duration of its own request, so a click on a
 * block whose refresh has not landed either does nothing or acts on a stale
 * href that drops the selection already made.
 *
 * @example
 *   cy.clickFacetAndWait('#user-skills-members-python:visible');
 */
Cypress.Commands.add("clickFacetAndWait", (selector, options = {}) => {
  const { settle = 750 } = options;

  cy.waitForFacetBinding(selector);
  cy.get(selector).should('not.be.disabled');
  cy.get(selector).click();
  cy.waitForAjaxIdle({ settle });
  // Facets disables every checkbox in a block for the duration of its own
  // request and only a successful refresh re-enables them, so a widget left in
  // the disabled state means the refresh never landed. Assert on the widget
  // rather than on the item just clicked: a reset link takes itself off the
  // page, and an item can be re-rendered outside a soft limit.
  cy.get('.js-facets-widget.facets-disabled').should('not.exist');
});

/**
 * Click a facet's "Show more" link so items past its soft limit become
 * clickable.
 *
 * Addressed by the facet's URL alias, not by a block class: the block classes
 * differ per theme (the nect theme emits none of the `block-facet-block<id>`
 * classes that the asp theme does), and soft-limit.js rewrites
 * `data-drupal-facet-id` to `<id>-0`, `<id>-1` when a facet is placed more than
 * once. The link itself is inserted as a sibling of the list, which is why this
 * goes through the list's parent.
 *
 * @example
 *   cy.expandFacetSoftLimit('organization_cyberteam_people');
 */
Cypress.Commands.add("expandFacetSoftLimit", (facetAlias) => {
  cy.get(`ul[data-drupal-facet-alias="${facetAlias}"]`)
    .parent()
    .find('a.facets-soft-limit-link:visible')
    .first()
    .click();
});

/**
 * Wait until the facets widget holding `selector` has its `facets_filter`
 * handler bound, which is the point from which a click actually triggers AJAX.
 *
 * Facet items that sit outside a widget (a plain reset link) have nothing to
 * bind, so for those this only waits for the element itself.
 */
Cypress.Commands.add("waitForFacetBinding", (selector) => {
  cy.get(selector).should('exist');
  cy.window({ log: false }).should((win) => {
    const $item = win.jQuery(selector);
    expect($item.length, `facet item ${selector} present`).to.be.greaterThan(0);

    const $widget = $item.closest('.js-facets-widget');
    if ($widget.length === 0) {
      return;
    }
    const events = win.jQuery._data($widget[0], 'events') || {};
    expect(
      events.facets_filter,
      `facets_filter handler bound on the widget holding ${selector}`
    ).to.not.be.undefined;
  });
});

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
 * Type into an exposed search-api filter and wait for the view to hold the
 * results for that exact query.
 *
 * Waits for the Views AJAX request that carries the typed value, not merely
 * the first request to go by, then waits for the facet blocks that refresh
 * behind it. The filter parameter name is read off the input, so this works
 * for any exposed filter, not just search_api_fulltext.
 *
 * @example
 *   cy.searchAndWait('[data-drupal-selector="edit-search-api-fulltext"]', 'AI');
 */
Cypress.Commands.add("searchAndWait", (selector, query) => {
  const alias = `viewsSearchAjax_${Cypress._.uniqueId()}`;
  cy.intercept('**/views/ajax**').as(alias);
  cy.get(selector).then(($input) => {
    const name = $input.attr('name') || 'search_api_fulltext';
    cy.wrap($input, { log: false }).type(query, { delay: 0 });
    cy.waitForExposedFilterRequest(alias, name, query);
  });
  cy.waitForAjaxIdle();
});

/**
 * Clear an exposed search-api filter and wait for the Views AJAX response.
 *
 * BEF auto-submits on an empty field regardless of the configured minimum
 * length, so clearing triggers a refresh back to the unfiltered result set.
 *
 * @example
 *   cy.clearSearchAndWait('[data-drupal-selector="edit-search-api-fulltext"]');
 */
Cypress.Commands.add("clearSearchAndWait", (selector) => {
  const alias = `viewsClearAjax_${Cypress._.uniqueId()}`;
  cy.intercept('**/views/ajax**').as(alias);
  cy.get(selector).then(($input) => {
    const name = $input.attr('name') || 'search_api_fulltext';
    // Nothing to clear means nothing auto-submits, so there is no request to
    // wait for.
    if (!$input.val()) {
      return;
    }
    cy.wrap($input, { log: false }).clear();
    cy.waitForExposedFilterRequest(alias, name, '');
  });
  cy.waitForAjaxIdle();
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
