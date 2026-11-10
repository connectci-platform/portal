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
  const seedCmd = `ddev drush php:script ${SEED_SCRIPT} -- '${title}' ready_for_review ${apps} draft review < /dev/null`;
  return cy.exec(seedCmd, { timeout: 90000 }).then((res) => {
    const match = (res.stdout || '').match(/^APPVERSE_SEED_RESULT:(\{.*\})\s*$/m);
    expect(match, `seed output: ${res.stdout} ${res.stderr}`).to.not.equal(null);
    const seeded = JSON.parse(match[1]);
    const cmd = `ddev drush php:script ${FINDINGS_SCRIPT} -- ${seeded.review_nid} ${shape} < /dev/null`;
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
      const near = `#finding-${seed.findings['QUA-01']}`;
      cy.visit(`/appverse/review/${seed.reviewNid}`);
      // Set on the page: a full reload would lose it.
      cy.window().then((win) => { win.arvNoReload = true; });
      cy.get(near).within(() => {
        cy.contains('button', 'Add a finding here').click();
      });
      cy.get(`${list} details.add-finding`).should('have.attr', 'open');
      cy.get(`${list} select.arv-add-section`).find('option:selected').should('have.text', 'Documentation');
      cy.get(`${list} .arv-add-summary`).type(summary);
      cy.get(`${list} [name="add_finding__app-${seed.apps[0]}"]`).click();
      cy.get(list).contains('.finding', summary).as('added');
      cy.window().its('arvNoReload').should('equal', true);
      cy.get('@added').then(($row) => {
        // Focus is on the new finding.
        cy.focused().should(($el) => {
          expect($row[0].contains($el[0]) || $row[0] === $el[0], 'focus in the new finding').to.equal(true);
        });
        // The rule followed the section: a documentation finding, which
        // blocks nothing, not the first rule of all (STR-01, a gate).
        cy.wrap($row).find('.rule').should('have.text', 'QUA-01');
        cy.wrap($row).prevAll('h3.arv-worklist__heading').first().should('have.text', 'Findings');
        // The confirmation is seen as well as announced.
        cy.wrap($row).find('.arv-saved').should('have.text', 'Finding added.').and('have.attr', 'aria-hidden', 'true');
      });
      // The add form is back where it was, open and empty.
      cy.get(near).next('details.add-finding').should('have.attr', 'open');
      cy.get(`${list} .arv-add-summary`).should('have.value', '');
    });
  });

  it('saves a severity change in place', () => {
    seedReview('Review Worklist Severity', 'single').then((seed) => {
      const pid = seed.findings['OODT-01'];
      const row = `#finding-${pid}`;
      const list = `#worklist-app-${seed.apps[0]}`;
      cy.visit(`/appverse/review/${seed.reviewNid}`);
      cy.location('href').then((url) => {
        cy.window().then((win) => { win.arvNoReload = true; });
        cy.get(row).find('.expand-hint').click();
        cy.get(row).find(`select[name="override[${pid}][severity]"]`).select('low');
        cy.get(row).find(`textarea[name="prose[${pid}]"]`).type('Only reachable by the reviewer.');
        cy.get(row).find('[name^="save_finding__"]').click();
        cy.get(row).find('.arv-saved').should('have.text', 'Saved.');
        // No reload: same address, and what the page held before is still there.
        cy.window().its('arvNoReload').should('equal', true);
        cy.location('href').should('equal', url);
        // The finding no longer blocks, so it is not under "Blocking".
        cy.get(list).contains('h3', 'Blocking').should('not.exist');
        cy.get(`${list} ${row}`).prevAll('h3.arv-worklist__heading').first().should('have.text', 'Findings');
        // With nothing blocking, the sidebar offers Accept.
        cy.get(`select[name="conclusion[${seed.apps[0]}]"] option[value="accept"]`).should('exist');
      });
    });
  });

  it('keeps what was typed elsewhere when a finding saves', () => {
    seedReview('Review Worklist Save', 'single').then((seed) => {
      const row = `#finding-${seed.findings['QUA-01']}`;
      const letter = 'Thanks for submitting, a few things to fix.';
      cy.visit(`/appverse/review/${seed.reviewNid}`);
      cy.window().then((win) => { win.arvNoReload = true; });
      // Half-typed elsewhere: the letter, the repo's add form, an open fold.
      cy.get('textarea[name="response"]').type(letter);
      cy.get('#fold-repo-add summary').click();
      cy.get('#fold-repo-add .arv-add-summary').type('half typed');
      cy.get(`#fold-app-${seed.apps[0]}-passed > summary`).click();
      cy.get(row).find('.expand-hint').click();
      cy.get(row).find('textarea[name^="prose["]').type('Please document the setup steps.');
      cy.get(row).find('[name^="save_finding__"]').click();
      cy.get(row).find('.finding-note').should('contain.text', 'Please document the setup steps.');
      // Seen as well as announced.
      cy.get(row).find('.arv-saved').should('have.text', 'Saved.').and('have.attr', 'aria-hidden', 'true');
      cy.window().its('arvNoReload').should('equal', true);
      cy.get('#arv-review-form').should('not.have.attr', 'aria-busy');
      cy.get('#fold-repo-add').should('have.attr', 'open');
      cy.get('#fold-repo-add .arv-add-summary').should('have.value', 'half typed');
      cy.get(`#fold-app-${seed.apps[0]}-passed`).should('have.attr', 'open');
      cy.get('textarea[name="response"]').should('have.value', letter);
      // The edit box that was open is open again.
      cy.get(row).find('.fnote').should('not.have.class', 'collapsed');
      // The letter was saved with the finding.
      cy.reload();
      cy.get('textarea[name="response"]').should('have.value', letter);
    });
  });

  it('shows the error in place', () => {
    seedReview('Review Worklist Error', 'single').then((seed) => {
      const pid = seed.findings['OODT-01'];
      const row = `#finding-${pid}`;
      cy.visit(`/appverse/review/${seed.reviewNid}`);
      cy.window().then((win) => { win.arvNoReload = true; });
      cy.get(row).find('.expand-hint').click();
      cy.get(row).find(`select[name="override[${pid}][severity]"]`).select('low');
      cy.get(row).find('[name^="save_finding__"]').click();
      cy.get('#arv-review-form .messages--error, #arv-review-form [role="alert"]')
        .should('contain.text', 'Add a note saying why');
      cy.window().its('arvNoReload').should('equal', true);
      // The box stays open on the field to fix, and the error is in the row.
      cy.get(row).find('.fnote').should('not.have.class', 'collapsed');
      cy.get(row).find(`textarea[name="prose[${pid}]"]`).should('have.attr', 'aria-invalid', 'true');
      cy.get(row).contains('Add a note saying why').should('be.visible');
      cy.focused().should('have.attr', 'name', `prose[${pid}]`);
      cy.focused().closest(row).should('exist');
    });
  });

  it('shows an error inside a closed fold', () => {
    seedReview('Review Worklist Fold', 'single').then((seed) => {
      const pid = seed.findings['QUA-04'];
      const fold = `#fold-app-${seed.apps[0]}-dismissed`;
      const other = `#finding-${seed.findings['QUA-01']}`;
      cy.visit(`/appverse/review/${seed.reviewNid}`);
      cy.get(`${fold} summary`).click();
      cy.get(`${fold} #finding-${pid} .expand-hint`).click();
      cy.get(`${fold} select[name="override[${pid}][severity]"]`).select('medium');
      // The dismissal's own note is cleared, so the change has no note.
      cy.get(`${fold} textarea[name="prose[${pid}]"]`).clear();
      // The fold is closed again with the unsaved change inside it.
      cy.get(`${fold} summary`).click();
      cy.get(fold).should('not.have.attr', 'open');
      cy.get(other).find('.expand-hint').click();
      cy.get(other).find('textarea[name^="prose["]').type('A note on another finding.');
      cy.get(other).find('[name^="save_finding__"]').click();
      // Nothing was saved: the fold is open and its error is showing.
      cy.get(fold).should('have.attr', 'open');
      cy.get(`${fold} #finding-${pid}`).contains('Add a note saying why').should('be.visible');
      cy.get(other).find('.finding-note').should('not.exist');
    });
  });

  it('deletes a finding it added, in place', () => {
    seedReview('Review Worklist Delete', 'single').then((seed) => {
      const list = `#worklist-app-${seed.apps[0]}`;
      const summary = 'Cypress finding to delete';
      cy.visit(`/appverse/review/${seed.reviewNid}`);
      cy.get(`#fold-app-${seed.apps[0]}-add summary`).click();
      cy.get(`${list} .arv-add-summary`).type(summary);
      cy.get(`${list} [name="add_finding__app-${seed.apps[0]}"]`).click();
      cy.get(list).contains('.finding', summary).then(($row) => {
        const id = $row.attr('id');
        cy.get(`#${id} .expand-hint`).click();
        cy.get(`#${id} [name^="delete_finding__"]`).click();
        cy.get(`#${id}`).should('not.exist');
        cy.get(list).find('.arv-saved').should('have.text', 'Finding deleted.');
      });
      cy.focused().closest(`#fold-app-${seed.apps[0]}-add`).should('exist');
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
