/**
 * Appverse review page: the reviewer's worklist.
 *
 * Each app's findings are one list with what blocks a decision first. A
 * finding that blocks every app is listed where it lives and as a reference
 * row in the other apps' lists, and a reviewer can add a finding beside the
 * row they are looking at.
 *
 * Fixtures come from fixtures/scripts/appverse-seed.php (a repo with member
 * apps and a review) and appverse-review-findings.php (a verdict per app with
 * findings). Each case starts from a fresh seed.
 */

const ADMIN_EMAIL = 'administrator@amptesting.com';
const ADMIN_PASS = 'b8QW]X9h7#5n';
const SEED_SCRIPT = '/var/www/html/tests/cypress/cypress/fixtures/scripts/appverse-seed.php';
const FINDINGS_SCRIPT = '/var/www/html/tests/cypress/cypress/fixtures/scripts/appverse-review-findings.php';

// Seeds a repo with one review and its findings. Yields the review nid and
// the findings script's result: {apps: [verdict pid], findings: {rule: pid}}.
function seedReview(title, shape) {
  const apps = shape === 'single' ? 1 : 2;
  const seedCmd = `ddev drush php:script ${SEED_SCRIPT} -- '${title}' ready_for_review ${apps} draft review`;
  return cy.exec(seedCmd, { timeout: 90000 }).then((res) => {
    const match = (res.stdout || '').match(/^APPVERSE_SEED_RESULT:(\{.*\})\s*$/m);
    expect(match, `seed output: ${res.stdout} ${res.stderr}`).to.not.equal(null);
    const seeded = JSON.parse(match[1]);
    const cmd = `ddev drush php:script ${FINDINGS_SCRIPT} -- ${seeded.review_nid} ${shape}`;
    return cy.exec(cmd, { timeout: 60000 }).then((out) => {
      const found = (out.stdout || '').match(/^APPVERSE_REVIEW_FINDINGS:(\{.*\})\s*$/m);
      expect(found, `findings output: ${out.stdout} ${out.stderr}`).to.not.equal(null);
      return { reviewNid: seeded.review_nid, ...JSON.parse(found[1]) };
    });
  });
}

describe('Appverse review worklist', () => {
  beforeEach(() => {
    cy.clearCookies();
    cy.loginUser(ADMIN_EMAIL, ADMIN_PASS);
  });

  it('orders blocking items first', () => {
    seedReview('Review Worklist Order', 'single').then((seed) => {
      const list = `#worklist-app-${seed.apps[0]}`;
      cy.visit(`/appverse/review/${seed.reviewNid}`);
      cy.get(list).within(() => {
        cy.contains('h3', 'Blocking').should('exist');
        cy.get(`#finding-${seed.findings['OODT-01']}`)
          .should('contain.text', 'At least Request changes');
        cy.get('.finding').then(($rows) => {
          const ids = [...$rows].map((el) => el.id);
          const at = (rule) => ids.indexOf(`finding-${seed.findings[rule]}`);
          expect(at('OODT-01'), 'OODT-01 listed').to.be.at.least(0);
          expect(at('OODT-01')).to.be.lessThan(at('QUA-03'));
          expect(at('QUA-03')).to.be.lessThan(at('QUA-01'));
        });
        // The blocking item sits under "Blocking", the others under "Findings".
        cy.contains('h3', 'Blocking').nextAll('.finding').first()
          .should('have.attr', 'id', `finding-${seed.findings['OODT-01']}`);
        cy.contains('h3', 'Findings').nextAll('.finding').first()
          .should('have.attr', 'id', `finding-${seed.findings['QUA-03']}`);
      });
    });
  });

  it('adds a finding where the reviewer is', () => {
    seedReview('Review Worklist Add', 'single').then((seed) => {
      const list = `#worklist-app-${seed.apps[0]}`;
      const summary = 'Cypress added finding about the docs';
      cy.visit(`/appverse/review/${seed.reviewNid}`);
      cy.get(`#finding-${seed.findings['QUA-01']}`).within(() => {
        cy.contains('button', 'Add a finding here').click();
      });
      cy.get(`${list} details.add-finding`).should('have.attr', 'open');
      cy.get(`${list} select.arv-add-section`).find('option:selected').should('have.text', 'Documentation');
      cy.get(`${list} .arv-add-summary`).type(summary);
      cy.get(`${list} [name="add_finding__app-${seed.apps[0]}"]`).click();
      cy.url().should('match', /#finding-\d+$/);
      cy.location('hash').then((hash) => {
        cy.get(hash).should('have.class', 'finding').and('contain.text', summary);
        // The rule followed the section: a documentation finding, which
        // blocks nothing, not the first rule of all (STR-01, a gate).
        cy.get(hash).find('.rule').should('have.text', 'QUA-01');
        cy.get(hash).prevAll('h3.arv-worklist__heading').first().should('have.text', 'Findings');
      });
    });
  });

  it('lists an every-app blocker in each app', () => {
    seedReview('Review Worklist Multi', 'multi').then((seed) => {
      cy.visit(`/appverse/review/${seed.reviewNid}`);
      cy.get(`#worklist-app-${seed.apps[1]}`).within(() => {
        // The row links to the finding and says it blocks every app.
        cy.get(`a[href="#finding-${seed.findings['OODT-01']}"]`)
          .should('have.length', 1)
          .parents('.finding--reference').should('contain.text', 'Blocks every app');
      });
    });
  });
});
