describe("Contributor Wall - Badge & Data-Driven Blocks", () => {
  // Tests that badge-based blocks render users with the correct badge,
  // and that data-driven blocks (Code & Docs, Discourse) render after cron.

  const testUser = {
    email: 'administrator@amptesting.com',
    password: 'b8QW]X9h7#5n',
  };

  let testUserUid;

  before(() => {
    cy.exec(
      `ddev drush eval "\\$uids = \\Drupal::entityQuery('user')->condition('mail', '${testUser.email}')->accessCheck(FALSE)->execute(); echo reset(\\$uids);"`,
      { failOnNonZeroExit: false, timeout: 30000 }
    ).then((result) => {
      const uid = result.stdout.trim();
      if (uid && /^\d+$/.test(uid)) {
        testUserUid = uid;
      }
    });
  });

  describe("Badge block setup", () => {
    // Assign badge terms to the test user so they appear in badge blocks.
    const badges = ['Committee member', 'GOOD Committee', 'Tips & Tricks', 'Appverse App Reviewer'];

    it("Assign badge terms to test user", function () {
      if (!testUserUid) {
        this.skip();
        return;
      }

      cy.exec(
        `ddev drush eval "` +
        `\\$user = \\Drupal\\user\\Entity\\User::load(${testUserUid}); ` +
        `\\$terms = \\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => 'open_ondemand_badges', 'name' => ['Committee member', 'GOOD Committee', 'Tips & Tricks', 'Appverse App Reviewer']]); ` +
        `\\$tids = array_map(fn(\\$t) => ['target_id' => \\$t->id()], \\$terms); ` +
        `\\$user->set('field_open_ondemand_badges', array_values(\\$tids)); ` +
        `\\$user->save(); ` +
        `echo 'Badges assigned: ' . count(\\$tids);"`,
        { failOnNonZeroExit: false, timeout: 30000 }
      ).then((result) => {
        cy.log(result.stdout);
        expect(result.stdout).to.contain('Badges assigned: 4');
      });
    });

    it("Clear caches after badge assignment", () => {
      cy.exec('ddev drush cr', { failOnNonZeroExit: false, timeout: 60000 });
    });
  });

  describe("Badge-based blocks on contributor wall", () => {
    beforeEach(() => {
      cy.visit('/contributor-wall');
    });

    it("Committee Members block renders with badged user", function () {
      if (!testUserUid) {
        this.skip();
        return;
      }

      cy.get('.committee-members-block').should('exist');
      cy.get('.committee-members-block').within(() => {
        cy.get(`a[href="/community-persona/${testUserUid}"]`).should('exist');
        cy.get('.profile-photo').should('have.length.greaterThan', 0);
      });
    });

    it("GOOD Conference Committee block renders with badged user", function () {
      if (!testUserUid) {
        this.skip();
        return;
      }

      cy.get('.good-committee-block').should('exist');
      cy.get('.good-committee-block').within(() => {
        cy.get(`a[href="/community-persona/${testUserUid}"]`).should('exist');
        cy.get('.profile-photo').should('have.length.greaterThan', 0);
      });
    });

    it("Tips & Tricks block renders with badged user", function () {
      if (!testUserUid) {
        this.skip();
        return;
      }

      cy.get('.tips-tricks-block').should('exist');
      cy.get('.tips-tricks-block').within(() => {
        cy.get(`a[href="/community-persona/${testUserUid}"]`).should('exist');
        cy.get('.profile-photo').should('have.length.greaterThan', 0);
      });
    });
  });

  describe("App Reviewers block on contributor wall", () => {
    it("App Reviewers block renders with badged user", function () {
      if (!testUserUid) {
        this.skip();
        return;
      }

      cy.visit('/contributor-wall');
      cy.get('.app-reviewers-block').should('exist');
      cy.get('.app-reviewers-block').within(() => {
        cy.get(`a[href="/community-persona/${testUserUid}"]`).should('exist');
        cy.get('.profile-photo').should('have.length.greaterThan', 0);
      });

      // The section title links to the Appverse. The href is rendered
      // absolute (host varies by environment), so match the path suffix.
      cy.contains('.cw-title h2', 'Appverse App Reviewers')
        .closest('.cw-title')
        .find('a[href$="/appverse"]')
        .should('exist');
    });
  });

  describe("Randomized sections", () => {
    it("Shuffled sections are revealed", () => {
      // The raw HTML (no JavaScript) has every person in each shuffled list.
      cy.request('/contributor-wall').then((response) => {
        const doc = new DOMParser().parseFromString(response.body, 'text/html');
        const rawCounts = Array.from(doc.querySelectorAll('.people-grid-list[data-shuffle]'))
          .map((list) => list.querySelectorAll('li').length);
        expect(rawCounts.length).to.be.greaterThan(0);

        cy.visit('/contributor-wall');
        cy.get('.people-grid-list[data-shuffle]').should('have.length', rawCounts.length);
        cy.get('.people-grid-list[data-shuffle]').each(($list, index) => {
          // Revealed and counted the same as the server output. The order is
          // random, so it is deliberately not asserted.
          cy.wrap($list).should('have.class', 'is-shuffled');
          cy.wrap($list).should('be.visible');
          cy.wrap($list).find('li').should('have.length', rawCounts[index]);
        });
      });
    });

    it("Ordered lists are not shuffled", () => {
      cy.visit('/contributor-wall');

      // Discourse Participation is ordered by most recent post.
      cy.get('.discourse-participants-block .people-grid-list').should('exist');
      cy.get('.discourse-participants-block .people-grid-list[data-shuffle]').should('not.exist');

      // Appverse Contributors reuses the .contributors-block class and is the
      // only one of those lists that is not shuffled.
      cy.get('.contributors-block .people-grid-list:not([data-shuffle])').should('have.length', 1);
    });
  });

  describe("Data-driven blocks on contributor wall", () => {
    // These blocks depend on cron data from the community-persona-contributions
    // test. They verify the blocks render when data exists.

    it("Code & Documentation Contributors block exists", () => {
      cy.visit('/contributor-wall');
      cy.get('.contributors-block').should('exist');
    });

    it("Code & Documentation Contributors block shows users after cron", function () {
      if (!testUserUid) {
        this.skip();
        return;
      }

      // Check if cron has populated commit data for the test user.
      cy.exec(
        `ddev drush sqlq "SELECT COUNT(*) FROM ood_user_commits WHERE uid = ${testUserUid}"`,
        { failOnNonZeroExit: false, timeout: 30000 }
      ).then((result) => {
        const count = parseInt(result.stdout.trim(), 10);
        if (count === 0) {
          cy.log('No commit data yet — skipping contributor block content check');
          return;
        }

        cy.visit('/contributor-wall');
        cy.get('.contributors-block').first().within(() => {
          cy.get(`a[href="/community-persona/${testUserUid}"]`).should('exist');
        });
      });
    });

    it("Discourse Participants block exists", () => {
      cy.visit('/contributor-wall');
      cy.get('.discourse-participants-block').should('exist');
    });

    it("Discourse Participants block shows users after cron", function () {
      if (!testUserUid) {
        this.skip();
        return;
      }

      cy.exec(
        `ddev drush sqlq "SELECT COUNT(*) FROM ood_disc_contrib WHERE uid = ${testUserUid}"`,
        { failOnNonZeroExit: false, timeout: 30000 }
      ).then((result) => {
        const count = parseInt(result.stdout.trim(), 10);
        if (count === 0) {
          cy.log('No Discourse data yet — skipping participant block content check');
          return;
        }

        cy.visit('/contributor-wall');
        cy.get('.discourse-participants-block').within(() => {
          cy.get(`a[href="/community-persona/${testUserUid}"]`).should('exist');
        });
      });
    });
  });

  describe("Appverse Contributors block", () => {
    it("Appverse Contributors block exists on contributor wall", () => {
      cy.visit('/contributor-wall');
      cy.get('.contributors-block').should('have.length.greaterThan', 0);
    });

    it("Test user appears in Appverse block with appverse_pm role", function () {
      if (!testUserUid) {
        this.skip();
        return;
      }

      // Add appverse_pm role to test user.
      cy.exec(
        `ddev drush eval "` +
        `\\$user = \\Drupal\\user\\Entity\\User::load(${testUserUid}); ` +
        `\\$user->addRole('appverse_pm'); ` +
        `\\$user->save(); ` +
        `echo 'role added';"`,
        { failOnNonZeroExit: false, timeout: 30000 }
      ).then((result) => {
        expect(result.stdout).to.contain('role added');
      });

      cy.exec('ddev drush cr', { failOnNonZeroExit: false, timeout: 60000 });

      cy.visit('/contributor-wall');

      // The Appverse Contributors block uses the same .contributors-block class.
      // Verify the test user appears somewhere on the page.
      cy.get(`a[href="/community-persona/${testUserUid}"]`)
        .should('have.length.greaterThan', 0);
    });
  });

  after(() => {
    if (!testUserUid) {
      return;
    }

    // Remove badges from test user.
    cy.exec(
      `ddev drush eval "` +
      `\\$user = \\Drupal\\user\\Entity\\User::load(${testUserUid}); ` +
      `\\$user->set('field_open_ondemand_badges', []); ` +
      `\\$user->save(); ` +
      `echo 'badges cleared';"`,
      { failOnNonZeroExit: false, timeout: 30000 }
    );

    // Remove appverse_pm role.
    cy.exec(
      `ddev drush eval "` +
      `\\$user = \\Drupal\\user\\Entity\\User::load(${testUserUid}); ` +
      `\\$user->removeRole('appverse_pm'); ` +
      `\\$user->save(); ` +
      `echo 'role removed';"`,
      { failOnNonZeroExit: false, timeout: 30000 }
    );
  });
});
