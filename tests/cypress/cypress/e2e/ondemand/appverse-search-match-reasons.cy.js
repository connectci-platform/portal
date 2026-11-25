/**
 * Appverse catalog search: match reasons on software and repo cards (D8-3117).
 *
 * When a search query does not match a card's title, the card shows a
 * data-testid="match-reasons" section explaining why it was returned:
 *  - Software: own tag match (match-reason-tag), topic (match-reason-topic),
 *    or, only when nothing else matched, the description
 *    (match-reason-description).
 *  - Repos (monorepos, i.e. more than one app, on /appverse#/repos): matching
 *    apps (matched-app-row/-link/-tag, deep links ending in
 *    #/repo/<repo-slug>?app=<app-id>), the maintainer
 *    (match-reason-maintainer) or the organization (match-reason-organization).
 * Matching is a case-insensitive substring. A title match shows no section.
 *
 * Notes:
 *  - The test data is picked at runtime from the live cache
 *    (/sites/default/files/appverse-cache/appverse-data.json), so it keeps
 *    working as content changes. before() fails loudly if no suitable
 *    software tag or repo app exists. The maintainer/organization test skips
 *    (with a logged reason) when no monorepo fits.
 *  - The React app renders inside a shadow DOM (#appverse-shadow-host), so
 *    the describe block enables { includeShadowDom: true }. Routing is
 *    hash-based (/appverse#/...).
 *  - Requires the ood-appverse change (match-reasons section on SoftwareTile
 *    and RepoTile) to be on the @main build that the site loads.
 */

// Same slug rules as ood-appverse src/utils/slugify.js.
function slugify(str) {
  if (!str) return '';
  return str
    .toLowerCase()
    .trim()
    .replace(/[^\w\s-]/g, '')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '');
}

// Mirror of repoSlug() in ood-appverse src/utils/slugify.js.
function repoSlug(repo) {
  if (!repo) return null;
  if (repo.slug) return repo.slug;
  return repo.title ? slugify(repo.title) : null;
}

function lc(value) {
  return String(value || '').toLowerCase();
}

function stripHtml(html) {
  return String(html || '').replace(/<[^>]*>/g, ' ');
}

// Does the query appear in any part of the software's search haystack
// (mirrors matchesSearch in ood-appverse useBrowseFilters.js)?
function softwareMatches(software, q) {
  const parts = [software.title, stripHtml(software.body), software.description];
  (software.topics || []).forEach((t) => parts.push(t.name));
  (software.tags || []).forEach((t) => parts.push(t.name));
  (software.apps || []).forEach((app) => {
    parts.push(app.title);
    (app.tags || []).forEach((t) => parts.push(t.name));
  });
  return parts.some((s) => lc(s).includes(q));
}

function repoMatches(repo, q) {
  const parts = [repo.title, repo.description, repo.maintainer && repo.maintainer.name, repo.organization && repo.organization.name];
  (repo.apps || []).forEach((app) => {
    parts.push(app.title);
    (app.tags || []).forEach((t) => parts.push(t.name));
  });
  return parts.some((s) => lc(s).includes(q));
}

// Pick a software + own tag where the tag is not in the software's title, any
// of its apps' titles or any app tag names. Prefer a tag that is also not in
// the topics and does not match any other software card.
function findSoftwareTagCase(softwareList) {
  const candidates = [];
  softwareList.forEach((software) => {
    const apps = software.apps || [];
    (software.tags || []).forEach((tag) => {
      const q = lc(tag.name).trim();
      if (!q || lc(software.title).includes(q)) return;
      if (apps.some((app) => lc(app.title).includes(q) || (app.tags || []).some((t) => lc(t.name).includes(q)))) return;
      const inTopics = (software.topics || []).some((t) => lc(t.name).includes(q));
      const unique = !softwareList.some((other) => other.id !== software.id && softwareMatches(other, q));
      candidates.push({ software, tag: tag.name, query: tag.name, inTopics, unique });
    });
  });
  return candidates.find((c) => !c.inTopics && c.unique)
    || candidates.find((c) => !c.inTopics)
    || candidates[0]
    || null;
}

// Pick a software whose title is a robust search (no other card's title
// contains it).
function findSoftwareTitleCase(softwareList) {
  const withTitle = softwareList.filter((s) => s.title);
  const robust = withTitle.find((s) => !withTitle.some((o) => o.id !== s.id && lc(o.title).includes(lc(s.title))));
  return robust || withTitle[0] || null;
}

// Pick a monorepo + app whose title is not in the repo title. Prefer a query
// that matches no other repo.
function findRepoAppCase(monorepos) {
  const candidates = [];
  monorepos.forEach((repo) => {
    (repo.apps || []).forEach((app) => {
      const q = lc(app.title).trim();
      if (!q || lc(repo.title).includes(q)) return;
      candidates.push({ repo, app, query: app.title });
    });
  });
  const robust = candidates.find(({ repo, query }) => !monorepos.some(
    (other) => other.id !== repo.id && repoMatches(other, lc(query))
  ));
  return robust || candidates[0] || null;
}

// Pick a monorepo + maintainer (else organization) not in the repo title.
function findRepoPersonCase(monorepos) {
  const fields = [
    { key: 'maintainer', testid: 'match-reason-maintainer' },
    { key: 'organization', testid: 'match-reason-organization' },
  ];
  for (const { key, testid } of fields) {
    const candidates = monorepos
      .map((repo) => ({ repo, query: repo[key] && repo[key].name, testid }))
      .filter(({ repo, query }) => query && !lc(repo.title).includes(lc(query).trim()));
    const robust = candidates.find(({ repo, query }) => !monorepos.some(
      (other) => other.id !== repo.id && repoMatches(other, lc(query))
    ));
    if (robust || candidates[0]) return robust || candidates[0];
  }
  return null;
}

describe('Appverse search: match reasons on cards', { includeShadowDom: true }, () => {
  let softwareTagCase;
  let softwareTitleCase;
  let repoAppCase;
  let repoPersonCase;

  before(() => {
    cy.request('/sites/default/files/appverse-cache/appverse-data.json').then((response) => {
      expect(response.status).to.eq(200);
      const softwareList = response.body.software || [];
      const monorepos = (response.body.repos || []).filter((repo) => (repo.apps || []).length > 1);
      expect(softwareList.length, 'software items in the appverse cache').to.be.greaterThan(0);

      softwareTagCase = findSoftwareTagCase(softwareList);
      softwareTitleCase = findSoftwareTitleCase(softwareList);
      repoAppCase = findRepoAppCase(monorepos);
      repoPersonCase = findRepoPersonCase(monorepos);

      if (!softwareTagCase) {
        throw new Error('No software has its own tag that is not part of the software title, any app title or any app tag.');
      }
      if (!softwareTitleCase) {
        throw new Error('No software with a title found in the appverse cache.');
      }
      if (!repoAppCase) {
        throw new Error('No monorepo (more than one app) has an app whose title is not part of the repo title.');
      }
      cy.log(`software tag case: "${softwareTagCase.query}" on "${softwareTagCase.software.title}"`);
      cy.log(`software title case: "${softwareTitleCase.title}"`);
      cy.log(`repo app case: "${repoAppCase.query}" in "${repoAppCase.repo.title}"`);
      cy.log(repoPersonCase
        ? `repo person case: "${repoPersonCase.query}" (${repoPersonCase.testid}) in "${repoPersonCase.repo.title}"`
        : 'repo person case: none available');
    });
  });

  function searchSoftware(query) {
    cy.visit('/appverse');
    cy.get('input[placeholder="Search for apps"]', { timeout: 30000 }).should('be.visible').clear().type(query, { parseSpecialCharSequences: false });
  }

  function searchRepos(query) {
    cy.visit('/appverse#/repos');
    cy.get('input[placeholder="Search Monorepos"]', { timeout: 30000 }).should('be.visible').clear().type(query, { parseSpecialCharSequences: false });
  }

  // The card (by data-testid) whose title (the h3 text) is exactly `title`.
  function tileFor(testid, title) {
    return cy.get(`[data-testid="${testid}"]`, { timeout: 30000 })
      .filter((i, el) => {
        const h3 = el.querySelector('h3');
        return !!h3 && h3.textContent.trim() === title;
      })
      .should('have.length', 1);
  }

  it('shows the matching own tag on the software card', () => {
    searchSoftware(softwareTagCase.query);
    tileFor('software-tile', softwareTagCase.software.title).within(() => {
      cy.get('[data-testid="match-reasons"]').should('exist');
      cy.get('[data-testid="match-reason-tag"]')
        .should('have.length.at.least', 1)
        .and('contain.text', softwareTagCase.tag);
    });
  });

  it('shows no match reasons on the software card when its title matches', () => {
    const title = softwareTitleCase.title;
    searchSoftware(title);
    tileFor('software-tile', title).within(() => {
      cy.get('[data-testid="match-reasons"]').should('not.exist');
    });
  });

  it('lists the matching app with a deep link on the repo card', () => {
    searchRepos(repoAppCase.query);
    tileFor('repo-tile', repoAppCase.repo.title).within(() => {
      cy.get('[data-testid="match-reasons"]').should('exist');
      cy.get('[data-testid="matched-app-link"]')
        .filter((i, el) => el.textContent.trim() === repoAppCase.app.title)
        .should('have.length.at.least', 1)
        .first()
        .should('have.attr', 'href')
        .and('match', new RegExp(`#/repo/${repoSlug(repoAppCase.repo)}\\?app=${repoAppCase.app.id}$`));
    });
  });

  it('shows the matching maintainer or organization on the repo card', function () {
    if (!repoPersonCase) {
      cy.log('Skipping: no monorepo has a maintainer or organization name that is absent from its title.');
      this.skip();
    }
    searchRepos(repoPersonCase.query);
    tileFor('repo-tile', repoPersonCase.repo.title).within(() => {
      cy.get('[data-testid="match-reasons"]').should('exist');
      cy.get(`[data-testid="${repoPersonCase.testid}"]`).should('contain.text', repoPersonCase.query);
    });
  });
});
