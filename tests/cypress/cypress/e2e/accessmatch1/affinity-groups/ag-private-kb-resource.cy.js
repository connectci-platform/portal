/*
    KB resources on a private Affinity Group (D8-2415).

    A resource tagged with an Affinity Group (taxonomy term) is listed on the
    group's page in the "Knowledge Base Resources" block. Unapproved resources
    only appear on PRIVATE groups, and until a coordinator ticks "Resource
    allowed on Affinity Group" only administrators, AG leaders and match PMs
    see them (with an "Allow on this Affinity Group" link to the submission
    edit form).

    This spec creates its own group, coordinator and resource, all named with a
    per-run id, and removes them afterwards:

      group        private, domain ACCESS, member "Pecan Pi" (no admin, AG
                   leader or match PM role), a throwaway coordinator user
      resource     NOT approved, created by an authenticated user, tagged with
                   the group

    The group's affinity-group taxonomy term is created by the node presave
    hook (add_ag_taxonomy_term), not by the form.

    The tests depend on each other and run in order: the resource starts out
    not allowed on the group, and test "allows it" changes that.

    Not covered: the resource detail page for a member when the resource is
    only tagged with the group. KbResourceAccess only honors resources
    REFERENCED from the group's field_resources_entity_reference, so the
    member check references the resource there first.
*/

import {
  ADMIN,
  AUTHENTICATED,
  createKbResource,
  deleteSubmissions,
  selectAffinityGroup,
} from "../../../support/kb-resources";

const RUN = Date.now().toString();
const AG_TITLE = `kbpriv-ag-${RUN}`;
const AG_SLUG = `kbpriv-ag-${RUN}`;
const AG_GROUP_ID = `kbpriv.ag.${RUN}`;
const RESOURCE_TITLE = `kbpriv-resource-${RUN}`;

// Non-admin users log in through the hidden login path of loginAs(): /user and
// /user/login redirect to CILogon on this site.
// "Pecan Pi" (pecan@pie.org): a plain member, no admin / AG leader / match PM
// role. Entity autocomplete accepts "Label (uid)" typed in full; the uid is
// looked up by mail in before().
const PECAN = { login: "pecan@pie.org", password: "Pecan", label: "Pecan Pi" };
const COORDINATOR = { name: `kbpriv-coord-${RUN}`, password: `Coord-${RUN}-pw!` };

const ACCESS_DOMAIN_CHECKBOX = "#edit-field-domain-access-amp-cyberinfrastructure-org";
const RESOURCE_TABLE = ".block-resources-for-affinity-group #ci-links";
const SUBMISSION_EDIT = (id) => `/admin/structure/webform/manage/resource/submission/${id}/edit`;
const ALLOW_LINK = (id) => `a[href$="${SUBMISSION_EDIT(id)}"]`;

let sid;
let agPath;
let coordinatorUid;
let pecanUid;

/**
 * Runs a PHP snippet with drush and yields its stdout. Fails the test with the
 * result when drush exits non-zero (cy.drush does not).
 */
function phpEval(php) {
  return cy.drush("php:eval", [`'${php}'`]).then((result) => {
    if (result.exitCode !== 0) {
      throw new Error(`drush php:eval failed: ${JSON.stringify(result)}`);
    }
    return result.stdout.trim();
  });
}

/**
 * Logs in through the hidden login path and waits until the session is in
 * place, so a following cy.request() or visit() runs as this user.
 */
function loginAndWait(username, password) {
  cy.loginAs(username, password);
  // The login form redirects to its own path with this flag once it is done.
  cy.location("search").should("include", "check_logged_in");
}

/**
 * Asserts that an anonymous response is a denial. Access denied for an
 * anonymous visitor is 403, but this site turns it into a redirect to the
 * login page (the 307 of the redirect_403_to_login behavior, or CILogon), so
 * accept either. Never a 200 with the content.
 */
function expectAnonymousDenied(response) {
  if (response.status === 403) {
    return;
  }
  expect(response.status, "redirect status").to.be.oneOf([301, 302, 303, 307, 308]);
  expect(response.headers.location, "redirect target").to.match(/\/user\/login|cilogon\.org/);
}

/** The row of the AG page resource table for this run's resource. */
function resourceRow() {
  return cy.contains(`${RESOURCE_TABLE} tr`, RESOURCE_TITLE);
}

describe("KB resources on a private Affinity Group", () => {
  before(() => {
    // A throwaway coordinator. Saving the group gives its coordinators the
    // affinity_group_leader role, so use a fresh user rather than the shared
    // authenticated test user, whose roles other specs rely on.
    const php =
      '$u = \\Drupal\\user\\Entity\\User::create(["name" => "' + COORDINATOR.name + '",' +
      '"mail" => "' + COORDINATOR.name + '@amptesting.com", "pass" => "' + COORDINATOR.password + '", "status" => 1]);' +
      '$u->save(); print $u->id();';
    phpEval(php).then((uid) => {
      coordinatorUid = Number(uid);
      expect(coordinatorUid, "coordinator uid").to.be.greaterThan(0);
    });

    const pecanLookup =
      '$us = \\Drupal::entityTypeManager()->getStorage("user")->loadByProperties(["mail" => "' + PECAN.login + '"]);' +
      '$u = reset($us); print $u ? $u->id() : 0;';
    phpEval(pecanLookup).then((uid) => {
      pecanUid = Number(uid);
      expect(pecanUid, "Pecan uid").to.be.greaterThan(0);
    });

    // Admin creates the private group.
    loginAndWait(...ADMIN);
    cy.visit("/node/add/affinity_group");
    cy.get('input[name="title[0][value]"]').type(AG_TITLE, { delay: 0 });
    cy.get('input[name="field_group_slug[0][value]"]').type(AG_SLUG, { delay: 0 });
    cy.get('input[name="field_group_id[0][value]"]').type(AG_GROUP_ID, { delay: 0 });
    cy.get(ACCESS_DOMAIN_CHECKBOX).check();
    cy.get("#edit-field-ag-private-value").check();
    cy.get('input[name="field_ag_private_users[0][target_id]"]').then(($input) => {
      cy.wrap($input).type(`${PECAN.label} (${pecanUid})`, { delay: 0 });
    });
    cy.get('input[name="field_coordinator[0][target_id]"]').then(($input) => {
      cy.wrap($input).type(`${COORDINATOR.name} (${coordinatorUid})`, { delay: 0 });
    });
    cy.get('.node-affinity-group-form #edit-submit').click();

    // Look up the node path and the taxonomy term the presave hook created.
    const lookup =
      '$n = \\Drupal::entityTypeManager()->getStorage("node")->loadByProperties(["type" => "affinity_group", "title" => "' + AG_TITLE + '"]);' +
      '$n = reset($n); print $n->id() . "," . $n->get("field_affinity_group")->target_id;';
    phpEval(lookup).then((out) => {
      const [nid, tid] = out.split(",").map(Number);
      expect(nid, "group nid").to.be.greaterThan(0);
      expect(tid, "group term id").to.be.greaterThan(0);
      agPath = `/node/${nid}`;
    });

    // An authenticated, non-admin user submits an unapproved resource and
    // picks the group in the AG select.
    cy.loginAs(...AUTHENTICATED);
    createKbResource({ title: RESOURCE_TITLE, approved: false, domain: null, tags: ["xalt"], affinityGroup: AG_TITLE }).then((id) => {
      sid = id;
    });
    cy.drupalLogout();
  });

  after(() => {
    // Resources first, then the group (its delete hook looks at the node),
    // its auto-created term and the coordinator. Look the group up by title,
    // so a failed before() hook does not leave it behind.
    deleteSubmissions([sid]);
    const php =
      '$ns = \\Drupal::entityTypeManager()->getStorage("node")->loadByProperties(["type" => "affinity_group", "title" => "' + AG_TITLE + '"]);' +
      'foreach ($ns as $n) { $tid = $n->get("field_affinity_group")->target_id; $n->delete();' +
      '$t = \\Drupal\\taxonomy\\Entity\\Term::load($tid); if ($t && $t->getName() === "' + AG_TITLE + '") { $t->delete(); } }' +
      '$us = \\Drupal::entityTypeManager()->getStorage("user")->loadByProperties(["name" => "' + COORDINATOR.name + '"]);' +
      'foreach ($us as $u) { $u->delete(); }' +
      'print "done";';
    phpEval(php).should("eq", "done");
  });

  it("created the group with a taxonomy term and the resource tagged with it", () => {
    expect(sid, "resource sid").to.be.greaterThan(0);
    loginAndWait(...ADMIN);
    cy.visit(SUBMISSION_EDIT(sid));
    cy.get("#edit-title").should("have.value", RESOURCE_TITLE);
    // The hidden affinity_group element holds the term id; its label is the
    // group's title.
    cy.get("#edit-affinity-group option:selected").should("have.text", AG_TITLE);
  });

  it("shows an administrator the unapproved resource with an Allow link", () => {
    loginAndWait(...ADMIN);
    cy.visit(agPath);
    resourceRow().contains("a", RESOURCE_TITLE);
    resourceRow()
      .contains("a", "Allow on this Affinity Group")
      .should("have.attr", "href")
      .and("eq", SUBMISSION_EDIT(sid));
  });

  it("hides the not-yet-allowed resource from a member", () => {
    loginAndWait(PECAN.login, PECAN.password);
    cy.visit(agPath);
    // The member can view the private group itself.
    cy.contains(AG_TITLE);
    cy.contains(RESOURCE_TABLE + " tr", RESOURCE_TITLE).should("not.exist");
    cy.get("body").should("not.contain.text", RESOURCE_TITLE);
  });

  it("allows the resource on the group when an administrator ticks the box", () => {
    loginAndWait(...ADMIN);
    cy.visit(SUBMISSION_EDIT(sid));
    // The authenticated submitter cannot set a domain, which the form then
    // requires of whoever edits the resource.
    cy.get("#edit-domain").siblings(".select2").find(".select2-search__field").type("ACCESS{enter}");
    cy.get("#edit-resource-allowed-on-affinity-group").should("be.visible").check();
    cy.get('form.webform-submission-resource-form [data-drupal-selector="edit-submit"]').click();
    cy.visit(SUBMISSION_EDIT(sid));
    cy.get("#edit-resource-allowed-on-affinity-group").should("be.checked");

    // The administrator no longer gets the Allow link.
    cy.visit(agPath);
    resourceRow().contains("a", RESOURCE_TITLE);
    resourceRow().find(ALLOW_LINK(sid)).should("not.exist");
  });

  it("lists the allowed resource for a member, without the Allow link", () => {
    loginAndWait(PECAN.login, PECAN.password);
    cy.visit(agPath);
    resourceRow().contains("a", RESOURCE_TITLE);
    cy.get(RESOURCE_TABLE).contains("Allow on this Affinity Group").should("not.exist");
    cy.get(RESOURCE_TABLE).find(ALLOW_LINK(sid)).should("not.exist");
  });

  it("denies an anonymous visitor the resource detail page", () => {
    cy.drupalLogout();
    cy.request({ url: `/knowledge-base/resources/${sid}`, failOnStatusCode: false, followRedirect: false })
      .then(expectAnonymousDenied);
  });

  it("opens the detail page to a member once the group references the resource", () => {
    // The access rule only honors resources referenced from the group's
    // field_resources_entity_reference, not ones merely tagged with its term,
    // so reference it there.
    const php =
      '$n = \\Drupal::entityTypeManager()->getStorage("node")->loadByProperties(["type" => "affinity_group", "title" => "' + AG_TITLE + '"]);' +
      '$n = reset($n); $n->get("field_resources_entity_reference")->appendItem(' + sid + '); $n->save(); print "done";';
    phpEval(php).should("eq", "done");

    loginAndWait(PECAN.login, PECAN.password);
    cy.visit(`/knowledge-base/resources/${sid}`);
    cy.contains(RESOURCE_TITLE);
    cy.get("body").should("not.contain.text", "Access denied");

    // Still closed to the public.
    cy.drupalLogout();
    cy.request({ url: `/knowledge-base/resources/${sid}`, failOnStatusCode: false, followRedirect: false })
      .then(expectAnonymousDenied);
  });

  it("lets a coordinator open the submission edit form, but not a member", () => {
    loginAndWait(COORDINATOR.name, COORDINATOR.password);
    cy.request({ url: SUBMISSION_EDIT(sid), failOnStatusCode: false, followRedirect: false }).its("status").should("eq", 200);
    cy.visit(SUBMISSION_EDIT(sid));
    cy.get("#edit-title").should("have.value", RESOURCE_TITLE);

    loginAndWait(PECAN.login, PECAN.password);
    cy.request({ url: SUBMISSION_EDIT(sid), failOnStatusCode: false, followRedirect: false }).its("status").should("eq", 403);
    cy.drupalLogout();
    cy.request({ url: SUBMISSION_EDIT(sid), failOnStatusCode: false, followRedirect: false }).then(expectAnonymousDenied);
  });

  it("toggles the allowed box and the AG select with the approved box on the resource form", () => {
    loginAndWait(...ADMIN);
    cy.visit("/form/resource");

    // Without an Affinity Group the allowed and private boxes are hidden.
    cy.get("#edit-resource-allowed-on-affinity-group").should("not.be.visible");
    cy.get("#edit-private").should("not.be.visible");

    selectAffinityGroup(AG_TITLE);
    cy.get("#edit-resource-allowed-on-affinity-group").should("be.visible");
    cy.get("#edit-private").should("be.visible");
    cy.get("#edit-field-ag-nodes-select").should("not.be.disabled");
    cy.get("#edit-affinity-group").should("not.be.disabled");

    // Approving hides the allowed box and disables both AG controls.
    cy.get("#edit-approved").check();
    cy.get("#edit-resource-allowed-on-affinity-group").should("not.be.visible");
    cy.get("#edit-field-ag-nodes-select").should("be.disabled");
    cy.get("#edit-affinity-group").should("be.disabled");

    // Unticking restores them.
    cy.get("#edit-approved").uncheck();
    cy.get("#edit-resource-allowed-on-affinity-group").should("be.visible");
    cy.get("#edit-field-ag-nodes-select").should("not.be.disabled");
    cy.get("#edit-affinity-group").should("not.be.disabled");
  });
});
