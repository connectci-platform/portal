/*
    KB Resources page (/knowledge-base/resources) on ACCESS: facets, copy link,
    like sorting and listing visibility.

    The page is a Search API view over `resource` webform submissions. This spec
    creates its own resources, all titled with a per-run id, so it does not
    depend on what other specs or the database dump left in the index:

      alpha        approved, ACCESS, tag xalt,  Learning, beginner
      beta         approved, ACCESS, tag dmtcp, Code,     advanced
      unapproved   NOT approved, ACCESS, tag xalt
      otherdomain  approved, Great Plains only, tag xalt
      sort one/two approved, ACCESS, own token, for the like sort test
      count ten/two approved, ACCESS, own token, given 10 and 2 likes, for the
                   numeric like sort test
      per-user     approved, ACCESS, own token, owned by the admin, for the
                   per-user cache tests
      many 1-3     approved, ACCESS, six tags each: 18 distinct tags that put
                   the Tags facet past its soft limit of 10 (the form takes at
                   most six tags per resource)

    Most tests narrow the listing to this run with the fulltext filter, so the
    expected result sets are exact whatever else is indexed. The index has
    `index_directly` on: a saved submission is searchable immediately.

    Facet items are placed twice (a desktop block and a collapsed mobile copy)
    under the same ids, so they are always scoped with `:visible`. Category,
    Skill Level and the other facets are only shown to authenticated users;
    anonymous visitors get the Topics facet and a login prompt.
*/

import {
  ADMIN,
  AUTHENTICATED,
  SKILL,
  createKbResource,
} from "../../../support/kb-resources";

const RUN = Date.now().toString();
const PREFIX = `kbfacet-${RUN}`;
// Separate run ids keep the sort and many-tags resources out of the listings
// narrowed to RUN. The fulltext filter reads a hyphen as a minus, so the search
// term is the bare id, never the hyphenated title. It also matches partial
// words, so the other ids are rearrangements of RUN rather than RUN plus a
// prefix, which RUN would still match.
const SORT_ID = RUN.split("").reverse().join("");
const MANY_ID = RUN.slice(5) + RUN.slice(0, 5);
const USER_ID = RUN.slice(3) + RUN.slice(0, 3);
const COUNT_ID = RUN.slice(8) + RUN.slice(0, 8);
const TITLE = {
  alpha: `${PREFIX}-alpha`,
  beta: `${PREFIX}-beta`,
  unapproved: `${PREFIX}-unapproved`,
  otherDomain: `${PREFIX}-otherdomain`,
  sortOne: `kbsort-${SORT_ID}-one`,
  sortTwo: `kbsort-${SORT_ID}-two`,
  countTen: `kbcount-${COUNT_ID}-ten`,
  countTwo: `kbcount-${COUNT_ID}-two`,
  perUser: `kbuser-${USER_ID}-item`,
};
// Slugs of the clickable tag buttons (`#tag-<slug>`) used by the resources.
// Both tags are also the term names, which is what the facet filters on.
const TAG_A = "xalt";
const TAG_B = "dmtcp";
// Tags for the Tags limit test, six per resource. None is in the facet's
// exclude list, so all 18 reach the facet.
const MANY_TAGS = [
  ["account", "application-status", "change-organization", "delete-account", "email-change", "email-duplication"],
  ["errors", "password", "profile", "reporting", "tickets", "verification-code"],
  ["dft", "modeling", "parameter-sweeps", "random-numbers", "simulation", "cephfs"],
];

const RESOURCES = "/knowledge-base/resources";
const RUN_ONLY = `${RESOURCES}?search_api_fulltext=${RUN}`;
const ITEMS = ".view-search-ci-links .details-wrap";
const FACET_ITEMS = "li.facet-item:not(.honey)";

const sid = {};

/**
 * Runs a PHP snippet with drush and yields its stdout. Fails the test with the
 * stderr when drush exits non-zero (cy.drush does not).
 */
function phpEval(php) {
  return cy.drush("php:eval", [`'${php}'`]).then((result) => {
    if (result.exitCode !== 0) {
      throw new Error(`drush php:eval failed: ${JSON.stringify(result)}`);
    }
    return result.stdout.trim();
  });
}

/** The deep link to a single item of the listing. */
const deepLink = (id) => `${RESOURCES}?f%5B0%5D=submission_id%3A${id}`;

/** The Edit button of a resource: a link to its webform submission edit form. */
const EDIT_LINK = (id) => `a[href$="/admin/structure/webform/manage/resource/submission/${id}/edit"]`;

/** Title of every listed item, in page order. */
function listedTitles() {
  return cy.get(ITEMS).then(($items) =>
    $items.toArray().map((el) => el.querySelector("summary > div").textContent.trim())
  );
}

/** The listed item with this title. */
function item(title) {
  return cy.contains(ITEMS, title);
}

/** Visible facet items of the facet with this URL alias. */
function visibleFacetItems(alias) {
  return cy.get(`ul[data-drupal-facet-alias="${alias}"]:visible ${FACET_ITEMS}:visible`);
}

describe("KB Resources page facets, copy link and sorting", () => {
  before(() => {
    cy.loginAs(...ADMIN);
    createKbResource({ title: TITLE.alpha, category: "Learning", skillLevels: [SKILL.beginner], tags: [TAG_A] }).then((id) => { sid.alpha = id; });
    createKbResource({ title: TITLE.beta, category: "Code", skillLevels: [SKILL.advanced], tags: [TAG_B] }).then((id) => { sid.beta = id; });
    createKbResource({ title: TITLE.unapproved, approved: false, tags: [TAG_A] }).then((id) => { sid.unapproved = id; });
    createKbResource({ title: TITLE.otherDomain, domain: "Great Plains", tags: [TAG_A] }).then((id) => { sid.otherDomain = id; });
    createKbResource({ title: TITLE.sortOne, tags: [TAG_A] });
    createKbResource({ title: TITLE.sortTwo, tags: [TAG_A] });
    createKbResource({ title: TITLE.countTen, tags: [TAG_A] }).then((id) => { sid.countTen = id; });
    createKbResource({ title: TITLE.countTwo, tags: [TAG_A] }).then((id) => { sid.countTwo = id; });
    MANY_TAGS.forEach((tags, i) => {
      createKbResource({ title: `kbmany-${MANY_ID}-${i + 1}`, tags });
    });
    cy.drupalLogout();
  });

  describe("anonymous visitor", () => {
    it("sees the Topics facet and a login prompt instead of the other facets", () => {
      cy.visit(RUN_ONLY);
      cy.get('ul[data-drupal-facet-alias="tags"]:visible').should("exist");
      cy.contains("Login to use more filters");
      cy.get('ul[data-drupal-facet-alias="content-type"]').should("not.exist");
      cy.get('ul[data-drupal-facet-alias="skill-level"]').should("not.exist");
    });
  });

  describe("authenticated user", () => {
    beforeEach(() => {
      cy.loginAs(...AUTHENTICATED);
    });

    it("renders the facet blocks with Topics first", () => {
      cy.visit(RESOURCES);

      // Topics is the first facet block in the sidebar, ahead of Category
      // (the "Content Type" facet) and Skill Level.
      cy.get(".region-sidebar-first .block-facets:visible").then(($blocks) => {
        const aliases = $blocks.toArray().map((block) =>
          block.querySelector("ul[data-drupal-facet-alias]").getAttribute("data-drupal-facet-alias")
        );
        expect(aliases[0], "first facet block").to.eq("tags");
        expect(aliases).to.include("content-type");
        expect(aliases).to.include("skill-level");
        expect(aliases.indexOf("tags")).to.be.lessThan(aliases.indexOf("content-type"));
        expect(aliases.indexOf("tags")).to.be.lessThan(aliases.indexOf("skill-level"));
      });
      cy.get(".region-sidebar-first .block-facets:visible").first().find("h2").should("have.text", "Topics");
      cy.contains(".region-sidebar-first .block-facets:visible h2", "Content Type");
      cy.contains(".region-sidebar-first .block-facets:visible h2", "Skill Level");
    });

    it("filters by a tag and restores the list when it is unchecked", () => {
      cy.visit(RUN_ONLY);
      // Alpha and beta are all that is listed: the unapproved and other-domain
      // resources are not.
      cy.get(ITEMS).should("have.length", 2);
      item(TITLE.alpha);
      item(TITLE.beta);

      cy.clickFacetAndWait(`#tags-${TAG_A}:visible`);
      cy.location("search").should((search) => {
        expect(decodeURIComponent(search)).to.include(`f[0]=tags:${TAG_A}`);
      });
      cy.get(ITEMS).should("have.length", 1);
      item(TITLE.alpha);
      cy.contains(ITEMS, TITLE.beta).should("not.exist");

      // Uncheck: both are back and the filter is gone from the URL.
      cy.clickFacetAndWait(`#tags-${TAG_A}:visible`);
      cy.location("search").should((search) => {
        expect(decodeURIComponent(search)).to.not.include("f[0]=tags");
      });
      cy.get(ITEMS).should("have.length", 2);
      item(TITLE.beta);
    });

    it("filters by Category, whose options are categories and not tags", () => {
      cy.visit(RUN_ONLY);

      visibleFacetItems("content-type").then(($options) => {
        const labels = $options.toArray().map((el) =>
          el.querySelector(".facet-item__value").textContent.trim()
        );
        expect(labels).to.include("learning");
        expect(labels).to.include("code");
        expect(labels, "no tag names in Category").to.not.include(TAG_A);
        expect(labels).to.not.include(TAG_B);
      });

      cy.clickFacetAndWait("#content-type-code:visible");
      cy.location("search").should((search) => {
        expect(decodeURIComponent(search)).to.include("f[0]=content-type:code");
      });
      cy.get(ITEMS).should("have.length", 1);
      item(TITLE.beta);
      cy.contains(ITEMS, TITLE.alpha).should("not.exist");
      cy.clickFacetAndWait("#content-type-code:visible");
      cy.get(ITEMS).should("have.length", 2);
    });

    it("filters by Skill Level", () => {
      cy.visit(RUN_ONLY);

      cy.clickFacetAndWait("#skill-level-advanced:visible");
      cy.location("search").should((search) => {
        expect(decodeURIComponent(search)).to.include("f[0]=skill-level:advanced");
      });
      cy.get(ITEMS).should("have.length", 1);
      item(TITLE.beta);
      cy.contains(ITEMS, TITLE.alpha).should("not.exist");
      cy.clickFacetAndWait("#skill-level-advanced:visible");

      cy.clickFacetAndWait("#skill-level-beginner:visible");
      cy.get(ITEMS).should("have.length", 1);
      item(TITLE.alpha);
      cy.contains(ITEMS, TITLE.beta).should("not.exist");
    });

    it("limits the Tags facet to 10 items, then 30 after Show more", () => {
      // Unfiltered, so the facet is fed by the whole index. Whatever else is
      // indexed (a full dump has dozens of tags, a cleared index only this
      // spec's), the "many" resources alone carry 18 distinct tags, so the
      // facet always has more than 10 items and the soft limit is meaningful.
      // The hard limit of 30 is applied by the query before the exclude list
      // removes a few names, so 30 is a ceiling that a full index approaches
      // and a cleared one stays well under.
      cy.visit(RESOURCES);
      cy.get('ul[data-drupal-facet-alias="tags"]:visible').as("tags");

      visibleFacetItems("tags").should("have.length.at.most", 10).and("have.length.greaterThan", 0);
      cy.get("@tags").parent().find("a.facets-soft-limit-link:visible").should("have.text", "Show more");

      cy.expandFacetSoftLimit("tags");
      visibleFacetItems("tags").should("have.length.greaterThan", 10).and("have.length.at.most", 30);
      cy.get("@tags").parent().find("a.facets-soft-limit-link:visible").should("have.text", "Show less");
    });

    it("copies the deep link without opening the item, and the link shows only that item", () => {
      cy.visit(RUN_ONLY, {
        onBeforeLoad(win) {
          // Headless Chrome may deny clipboard access. Stub it and assert on
          // the call instead of the clipboard contents.
          cy.stub(win.navigator.clipboard, "writeText").as("writeText").resolves();
        },
      });

      item(TITLE.alpha).within(() => {
        cy.get("details.search-ci-links").should("not.have.attr", "open");
        // The button only shows while the pointer is over the summary (a CSS
        // :has(:hover) rule) and Cypress cannot hover, so click it unseen.
        cy.get("button.copyclip").click({ force: true });
        // The button is display:none without a hover, so check the classes
        // the script toggles rather than visibility.
        cy.get(".copied-message").should("contain", "Copied!").and("not.have.class", "hidden").and("not.have.class", "d-none");
        cy.get(".default-message").should("have.class", "hidden");
        // The button sits outside <details>: copying must not expand the item.
        cy.get("details.search-ci-links").should("not.have.attr", "open");
      });
      cy.get("@writeText").should("have.been.calledOnce");
      cy.get("@writeText").should((stub) => {
        const url = stub.getCall(0).args[0];
        expect(url).to.include(`/knowledge-base/resources?f%5B0%5D=submission_id%3A${sid.alpha}`);
        expect(url).to.match(/^https?:\/\//);
      });

      // The copied link lands on exactly one result.
      cy.visit(`${RESOURCES}?f%5B0%5D=submission_id%3A${sid.alpha}`);
      cy.get(ITEMS).should("have.length", 1);
      item(TITLE.alpha);
    });

    it("lists neither the unapproved nor the other-domain resource", () => {
      cy.visit(RUN_ONLY);
      cy.get(ITEMS).should("have.length", 2);
      listedTitles().should("have.members", [TITLE.alpha, TITLE.beta]);
      cy.contains(ITEMS, TITLE.unapproved).should("not.exist");
      cy.contains(ITEMS, TITLE.otherDomain).should("not.exist");

      // Not even by deep link.
      cy.visit(`${RESOURCES}?f%5B0%5D=submission_id%3A${sid.unapproved}`);
      cy.get(ITEMS).should("not.exist");
      cy.visit(`${RESOURCES}?f%5B0%5D=submission_id%3A${sid.otherDomain}`);
      cy.get(ITEMS).should("not.exist");
    });

    it("lists a liked resource above one with fewer likes, and unliking restores the count", () => {
      const sortUrl = `${RESOURCES}?search_api_fulltext=${SORT_ID}`;
      const likeCount = (title) =>
        item(title).find("details.search-ci-links > summary .linkcount").then(($count) => Number($count.text().trim()));
      // Flag and unflag are plain links: the server flags, then redirects.
      cy.intercept("GET", "**/flag/*/upvote/**").as("upvote");

      cy.visit(sortUrl);
      listedTitles().should("include.members", [TITLE.sortOne, TITLE.sortTwo]);
      likeCount(TITLE.sortOne).should("eq", 0);
      likeCount(TITLE.sortTwo).should("eq", 0);

      // With equal counts the order is the index's tie order. Like whichever
      // is listed second, so that moving to first proves the like sorted it.
      listedTitles().then((titles) => {
        const [first, second] = titles.filter((t) => t === TITLE.sortOne || t === TITLE.sortTwo);

        item(second).find("details.search-ci-links > summary").click();
        item(second).find(".flag-upvote a").click();
        cy.wait("@upvote").its("response.statusCode").should("eq", 302);

        cy.visit(sortUrl);
        likeCount(second).should("eq", 1);
        likeCount(first).should("eq", 0);
        item(second).find(".flag-upvote.action-unflag a").should("exist");
        listedTitles().should((after) => {
          expect(after.indexOf(second), "liked resource first").to.eq(0);
          expect(after.indexOf(second)).to.be.lessThan(after.indexOf(first));
        });

        // Unlike to leave the user as found.
        item(second).find("details.search-ci-links > summary").click();
        item(second).find(".flag-upvote.action-unflag a").click();
        cy.wait("@upvote").its("response.statusCode").should("eq", 302);

        cy.visit(sortUrl);
        likeCount(second).should("eq", 0);
        item(second).find(".flag-upvote.action-flag a").should("exist");
      });
    });

    // The sort field is indexed as an integer. As a string, 2 would sort above
    // 10 ("2" > "10"), which the 1-vs-0 test above cannot tell apart.
    describe("like count sorting", () => {
      const countUrl = `${RESOURCES}?search_api_fulltext=${COUNT_ID}`;
      const likeCount = (title) =>
        item(title).find("details.search-ci-links > summary .linkcount").then(($count) => Number($count.text().trim()));

      before(() => {
        // Flag through the flag service, as 10 and 2 distinct active users.
        // Flagging reindexes the item (index_directly), so no explicit index
        // run is needed.
        const php =
          '$flag = \\Drupal::service("flag")->getFlagById("upvote");' +
          '$uids = \\Drupal::entityQuery("user")->condition("status", 1)->condition("uid", 0, ">")->accessCheck(FALSE)->sort("uid")->range(0, 10)->execute();' +
          '$out = [];' +
          'foreach ([[' + sid.countTen + ', 10], [' + sid.countTwo + ', 2]] as [$sid, $n]) {' +
          '$sub = \\Drupal\\webform\\Entity\\WebformSubmission::load($sid);' +
          'foreach (array_slice($uids, 0, $n) as $uid) {' +
          '$account = \\Drupal\\user\\Entity\\User::load($uid);' +
          'if (!\\Drupal::service("flag")->getFlagging($flag, $sub, $account)) { \\Drupal::service("flag")->flag($flag, $sub, $account); }' +
          '}' +
          '$out[] = $sid . "=" . (\\Drupal::service("flag.count")->getEntityFlagCounts($sub)["upvote"] ?? 0);' +
          '}' +
          'print implode(",", $out);';
        phpEval(php).should("eq", `${sid.countTen}=10,${sid.countTwo}=2`);
      });

      after(() => {
        // Remove only the likes seeded above.
        const php =
          '$flag = \\Drupal::service("flag")->getFlagById("upvote");' +
          'foreach ([' + sid.countTen + ', ' + sid.countTwo + '] as $sid) {' +
          '$sub = \\Drupal\\webform\\Entity\\WebformSubmission::load($sid);' +
          'foreach (\\Drupal::service("flag")->getEntityFlaggings($flag, $sub) as $flagging) {' +
          '\\Drupal::service("flag")->unflag($flag, $sub, $flagging->getOwner());' +
          '}' +
          '}' +
          'print "done";';
        phpEval(php).should("eq", "done");
      });

      it("lists 10 likes above 2 likes", () => {
        cy.loginAs(...AUTHENTICATED);
        cy.visit(countUrl);
        cy.get(ITEMS).should("have.length", 2);
        likeCount(TITLE.countTen).should("eq", 10);
        likeCount(TITLE.countTwo).should("eq", 2);
        listedTitles().should("deep.eq", [TITLE.countTen, TITLE.countTwo]);
      });
    });
  });

  // Each row's per-user bits (flag link with its CSRF token and destination,
  // Like/Unlike state, Edit button, report menu) must be built per request.
  // Rows are cached per permission set, so each test warms the cache both as
  // the admin (the owner) and in an earlier session of the visitor under test:
  // any per-user bit left in a cached row then reaches the next visitor.
  describe("per-user content is not shared through the cache", () => {
    const itemUrl = () => deepLink(sid.perUser);
    const likeCount = () =>
      item(TITLE.perUser).find("details.search-ci-links > summary .linkcount").then(($count) => Number($count.text().trim()));
    const notDenied = () => {
      cy.contains("Access denied").should("not.exist");
      cy.contains("You are not authorized").should("not.exist");
    };

    before(() => {
      cy.loginAs(...ADMIN);
      createKbResource({ title: TITLE.perUser, tags: [TAG_A] }).then((id) => { sid.perUser = id; });
      cy.drupalLogout();
    });

    beforeEach(() => {
      cy.intercept("GET", "**/flag/*/upvote/**").as("upvote");
    });

    it("gives another user their own Like link, state and no Edit button", () => {
      // Warm the cache as the owner.
      cy.loginAs(...ADMIN);
      cy.visit(itemUrl());
      item(TITLE.perUser).find(EDIT_LINK(sid.perUser)).should("contain.text", "Edit");

      // Rows are cached per permission set, so the admin's visit alone never
      // reaches this user. Also warm the cache in an earlier session of this
      // user: the CSRF token is tied to the session, so a token cached then
      // fails with "Access denied" in the next one (each loginAs submits the
      // login form, which starts a new session).
      cy.loginAs(...AUTHENTICATED);
      cy.visit(itemUrl());
      item(TITLE.perUser).find(".flag-upvote.action-flag a").should("exist");

      cy.loginAs(...AUTHENTICATED);
      cy.visit(itemUrl());
      cy.get(ITEMS).should("have.length", 1);
      item(TITLE.perUser).find(EDIT_LINK(sid.perUser)).should("not.exist");
      item(TITLE.perUser).find(".flag-upvote.action-flag a").should("exist");
      item(TITLE.perUser).find(".flag-upvote.action-unflag a").should("not.exist");
      notDenied();

      likeCount().then((before) => {
        item(TITLE.perUser).find("details.search-ci-links > summary").click();
        item(TITLE.perUser).find(".flag-upvote.action-flag a").click();
        cy.wait("@upvote").then(({ response }) => {
          // A token or destination from the admin's session would give 403, or
          // send this user back to the admin's page.
          expect(response.statusCode).to.eq(302);
          const location = decodeURIComponent(response.headers.location);
          expect(location, "redirect back to this item").to.include(`/knowledge-base/resources?f[0]=submission_id:${sid.perUser}`);
        });

        cy.visit(itemUrl());
        notDenied();
        item(TITLE.perUser).find(".flag-upvote.action-unflag a").should("exist");
        likeCount().should("eq", before + 1);

        // Unlike to leave the user as found.
        item(TITLE.perUser).find("details.search-ci-links > summary").click();
        item(TITLE.perUser).find(".flag-upvote.action-unflag a").click();
        cy.wait("@upvote").its("response.statusCode").should("eq", 302);

        cy.visit(itemUrl());
        notDenied();
        item(TITLE.perUser).find(".flag-upvote.action-flag a").should("exist");
        likeCount().should("eq", before);
      });
    });

    it("shows an anonymous visitor a login prompt, not the admin's Edit button or report menu", () => {
      cy.loginAs(...ADMIN);
      cy.visit(itemUrl());
      item(TITLE.perUser).find(EDIT_LINK(sid.perUser)).should("exist");

      cy.drupalLogout();
      cy.visit(itemUrl());
      cy.get(ITEMS).should("have.length", 1);
      item(TITLE.perUser).should("contain.text", "Login to like");
      item(TITLE.perUser).find(EDIT_LINK(sid.perUser)).should("not.exist");
      item(TITLE.perUser).should("not.contain.text", "Flag as");
      item(TITLE.perUser).find(".flag-upvote a").should("not.exist");
      item(TITLE.perUser)
        .contains("a", "Login to like")
        .should("have.attr", "href")
        .and("include", "destination=")
        .then((href) => {
          const destination = decodeURIComponent(new URL(href, "http://x").searchParams.get("destination"));
          expect(destination).to.include(`/knowledge-base/resources?f[0]=submission_id:${sid.perUser}`);
        });
    });

    it("does the same on the single resource page", () => {
      const page = `${RESOURCES}/${sid.perUser}`;

      cy.loginAs(...ADMIN);
      cy.visit(page);
      cy.get(EDIT_LINK(sid.perUser)).should("contain.text", "Edit");

      // Warm the page in an earlier session of this user (see above).
      cy.loginAs(...AUTHENTICATED);
      cy.visit(page);
      cy.get(".flag-upvote.action-flag a").should("exist");

      cy.loginAs(...AUTHENTICATED);
      cy.visit(page);
      cy.get(EDIT_LINK(sid.perUser)).should("not.exist");
      cy.get(".flag-upvote.action-flag a").should("exist");
      notDenied();

      cy.get(".flag-upvote.action-flag a").click();
      cy.wait("@upvote").its("response.statusCode").should("eq", 302);
      notDenied();
      cy.get(".flag-upvote.action-unflag a").should("exist");

      // Unlike to leave the user as found.
      cy.get(".flag-upvote.action-unflag a").click();
      cy.wait("@upvote").its("response.statusCode").should("eq", 302);
      notDenied();
      cy.get(".flag-upvote.action-flag a").should("exist");
    });
  });
});
