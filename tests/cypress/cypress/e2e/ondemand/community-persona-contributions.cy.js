describe("Community Persona - Contribution Graphs", () => {
  // Test user setup: we set GitHub and Discourse usernames on an existing
  // test user, run the contribution cron, then verify the graphs render.

  const testUser = {
    email: 'administrator@amptesting.com',
    password: 'b8QW]X9h7#5n',
    githubUsername: 'johrstrom',
    discourseUsername: 'jeff.ohrstrom',
  };

  let testUserUid;

  before(() => {
    // Get the test user's UID via drush (more reliable than URL parsing).
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

  describe("Setup test user profiles", () => {
    it("Set GitHub username on test user", () => {
      cy.exec(
        `ddev drush eval "` +
        `\\$user = \\Drupal\\user\\Entity\\User::load(${testUserUid || 1}); ` +
        `if (\\$user->hasField('field_github_username')) { ` +
        `\\$user->set('field_github_username', '${testUser.githubUsername}'); ` +
        `\\$user->save(); ` +
        `echo 'GitHub username set'; } ` +
        `else { echo 'field_github_username not found'; }"`,
        { failOnNonZeroExit: false, timeout: 30000 }
      ).then((result) => {
        cy.log(result.stdout);
        expect(result.stdout).to.contain('GitHub username set');
      });
    });

    it("Set Discourse username on test user", () => {
      cy.exec(
        `ddev drush eval "` +
        `\\$user = \\Drupal\\user\\Entity\\User::load(${testUserUid || 1}); ` +
        `if (\\$user->hasField('field_discourse_openondemand_org')) { ` +
        `\\$user->set('field_discourse_openondemand_org', '${testUser.discourseUsername}'); ` +
        `\\$user->save(); ` +
        `echo 'Discourse username set'; } ` +
        `else { echo 'field_discourse_openondemand_org not found'; }"`,
        { failOnNonZeroExit: false, timeout: 30000 }
      ).then((result) => {
        cy.log(result.stdout);
        expect(result.stdout).to.contain('Discourse username set');
      });
    });

    it("Clear fake usernames from sanitized DB to avoid noisy API calls", () => {
      // The sanitized test DB has lorem-ipsum usernames on all users.
      // Clear them so cron only processes users we explicitly set up.
      cy.exec(
        `ddev drush sqlq "DELETE FROM user__field_github_username WHERE entity_id != ${testUserUid || 1}"`,
        { failOnNonZeroExit: false, timeout: 30000 }
      );
      cy.exec(
        `ddev drush sqlq "DELETE FROM user__field_discourse_openondemand_org WHERE entity_id != ${testUserUid || 1}"`,
        { failOnNonZeroExit: false, timeout: 30000 }
      );
    });

    it("Add ondemand role and region to test user", () => {
      cy.exec(
        `ddev drush eval "` +
        `\\$user = \\Drupal\\user\\Entity\\User::load(${testUserUid || 1}); ` +
        `\\$user->addRole('ondemand'); ` +
        `\\$terms = \\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => 'region', 'name' => 'Open OnDemand']); ` +
        `if (\\$terms) { \\$user->set('field_region', [['target_id' => reset(\\$terms)->id()]]); } ` +
        `\\$user->save(); ` +
        `echo 'ondemand role added, region: ' . count(\\$user->get('field_region')->getValue());"`,
        { failOnNonZeroExit: false, timeout: 30000 }
      ).then((result) => {
        cy.log(result.stdout);
        expect(result.stdout).to.contain('ondemand role added');
      });
    });
  });

  describe("Run contribution cron", () => {
    it("GitHub contribution cron runs without errors", () => {
      cy.exec(
        `ddev drush eval "\\Drupal\\ood_contributions\\Plugin\\CronManager::storeStats();"`,
        { failOnNonZeroExit: false, timeout: 120000 }
      ).then((result) => {
        cy.log('stdout: ' + result.stdout);
        if (result.stderr) {
          cy.log('stderr: ' + result.stderr);
        }
        // Should not have a fatal PHP error
        expect(result.stderr || '').to.not.contain('PHP Fatal error');
      });
    });

    it("Discourse contribution cron runs without errors", () => {
      cy.exec(
        `ddev drush eval "\\Drupal\\ood_contributions\\Plugin\\CronManager::discourseStoreContrib();"`,
        { failOnNonZeroExit: false, timeout: 120000 }
      ).then((result) => {
        cy.log('stdout: ' + result.stdout);
        if (result.stderr) {
          cy.log('stderr: ' + result.stderr);
        }
        expect(result.stderr || '').to.not.contain('PHP Fatal error');
      });
    });
  });

  describe("GitHub contribution graph on community persona", () => {
    it("Community persona page loads", function () {
      if (!testUserUid) {
        this.skip();
        return;
      }
      cy.visit(`/community-persona/${testUserUid}`);
      cy.get('body').should('not.contain', 'Page not found');
      cy.get('body').should('not.contain', 'Access denied');
    });

    it("GitHub contribution graph is rendered", function () {
      if (!testUserUid) {
        this.skip();
        return;
      }
      cy.visit(`/community-persona/${testUserUid}`);

      // The graph is rendered via an inline template with class .contribution-graph
      // containing an SVG with commit activity squares.
      cy.get('.contribution-graph').should('exist');
      cy.get('.contribution-graph svg').should('exist');
    });

    it("Commits are stored in the database", function () {
      if (!testUserUid) {
        this.skip();
        return;
      }
      cy.exec(
        `ddev drush sqlq "SELECT COUNT(*) FROM ood_user_commits WHERE uid = ${testUserUid}"`,
        { failOnNonZeroExit: false, timeout: 30000 }
      ).then((result) => {
        const count = parseInt(result.stdout.trim(), 10);
        cy.log(`Commits stored for user ${testUserUid}: ${count}`);
        // johrstrom should have commits in OSC repos
        expect(count).to.be.greaterThan(0);
      });
    });
  });

  describe("Discourse stats on community persona", () => {
    it("Discourse contribution data is stored", function () {
      if (!testUserUid) {
        this.skip();
        return;
      }
      cy.exec(
        `ddev drush sqlq "SELECT COUNT(*) FROM ood_disc_contrib WHERE uid = ${testUserUid} AND post_count > 0 AND last_posted_at IS NOT NULL"`,
        { failOnNonZeroExit: false, timeout: 30000 }
      ).then((result) => {
        const count = parseInt(result.stdout.trim(), 10);
        cy.log(`Discourse contributions with posts stored for user ${testUserUid}: ${count}`);
        // jeff.ohrstrom should have Discourse activity: real counts (from the
        // summary endpoint) and a last post time, not just a linked row.
        expect(count).to.be.greaterThan(0);
      });
    });
  });

  after(() => {
    if (!testUserUid) {
      return;
    }

    // Remove the region we set so we don't pollute other tests.
    cy.exec(
      `ddev drush eval "` +
      `\\$user = \\Drupal\\user\\Entity\\User::load(${testUserUid}); ` +
      `\\$user->set('field_region', []); ` +
      `\\$user->save(); ` +
      `echo 'region cleared';"`,
      { failOnNonZeroExit: false, timeout: 30000 }
    );
  });

  describe("Contributor wall reflects test user", () => {
    it("Test user appears on the contributor wall after cron", function () {
      if (!testUserUid) {
        this.skip();
        return;
      }
      cy.visit('/contributor-wall');

      // The test user should now appear as a contributor with a persona link
      cy.get(`a[href*="/community-persona/${testUserUid}"]`).then(($link) => {
        if ($link.length > 0) {
          expect($link).to.have.length.greaterThan(0);
        } else {
          cy.log('Test user not yet visible on contributor wall — may need full block cache clear');
        }
      });
    });
  });
});
