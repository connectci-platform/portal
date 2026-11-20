/**
 * Appverse catalog search: matching apps on software cards (D8-3084, D8-3116).
 *
 * When a search query matches an app's title or an app's tag, but not the
 * software's own title, the software card lists the matching apps (max 3,
 * title matches first, then tag matches, alphabetical within each group) as
 * deep links to /appverse#/<software-slug>?app=<app-uuid>.
 *
 * D8-3116: the matching apps section only lists apps that also meet every
 * active sidebar filter (App Type, Organization), not just the search query.
 *
 * Notes:
 *  - The test data is picked at runtime from the live cache
 *    (/sites/default/files/appverse-cache/appverse-data.json), so it keeps
 *    working as content changes. before() fails loudly if no suitable
 *    software/app pair exists.
 *  - The React app renders inside a shadow DOM (#appverse-shadow-host), so
 *    the describe block enables { includeShadowDom: true }. Routing is
 *    hash-based (/appverse#/...).
 *  - Requires the ood-appverse change (matched-apps section on SoftwareTile)
 *    to be on the @main build that the site loads.
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

const MAX_ROWS = 3;

function lc(value) {
  return String(value || '').toLowerCase();
}

// Mirror of the card's row ordering: title matches first, then tag-only
// matches, alphabetical (case-insensitive) by app title within each group.
// Returns the rows the card shows for `query` (at most MAX_ROWS).
function visibleRows(software, query) {
  const q = lc(query);
  const rows = [];
  (software.apps || []).forEach((app) => {
    const titleMatch = lc(app.title).includes(q);
    const tagMatch = (app.tags || []).some((tag) => lc(tag.name).includes(q));
    if (titleMatch || tagMatch) {
      rows.push({ app, group: titleMatch ? 0 : 1 });
    }
  });
  rows.sort((a, b) => a.group - b.group || lc(a.app.title).localeCompare(lc(b.app.title)));
  return rows.slice(0, MAX_ROWS);
}

// Pick a software + app where the query is the app's title (and is not in the
// software's title) and the app lands in the card's visible rows.
function findTitleCase(softwareList) {
  const candidates = [];
  softwareList.forEach((software) => {
    (software.apps || []).forEach((app) => {
      const q = lc(app.title);
      if (!q || lc(software.title).includes(q)) return;
      const shown = visibleRows(software, app.title).some((row) => row.app.id === app.id);
      if (shown) candidates.push({ software, app, query: app.title });
    });
  });
  // Prefer a query that does not appear in any other software's title.
  const robust = candidates.find(({ software, query }) => !softwareList.some(
    (other) => other.id !== software.id && lc(other.title).includes(lc(query))
  ));
  return robust || candidates[0] || null;
}

// Pick a software + app + tag where the tag is in neither the software's nor
// the app's title, and the app lands in the card's visible rows.
function findTagCase(softwareList) {
  for (const software of softwareList) {
    for (const app of software.apps || []) {
      for (const tag of app.tags || []) {
        const q = lc(tag.name);
        if (!q || lc(software.title).includes(q) || lc(app.title).includes(q)) continue;
        const shown = visibleRows(software, tag.name).some((row) => row.app.id === app.id);
        if (shown) return { software, app, tag: tag.name, query: tag.name };
      }
    }
  }
  return null;
}

// Organization names an app belongs to: the org of its repo (authoritative)
// and the app's own organization, mirroring the app's organization filter.
function appOrgNames(app, repoOrgById) {
  const names = [];
  if (app.repoId && repoOrgById[app.repoId]) names.push(repoOrgById[app.repoId]);
  if (app.organization && app.organization.name) names.push(app.organization.name);
  return names;
}

function appTypeNames(app) {
  return (app.appTypes || []).map((type) => type.name);
}

// Whether `app` meets a single sidebar filter ({ kind: 'appType' | 'organizations', value }).
function appMeetsFilter(app, filter, repoOrgById) {
  const names = filter.kind === 'appType' ? appTypeNames(app) : appOrgNames(app, repoOrgById);
  return names.includes(filter.value);
}

// Every app of `software` matching `query` by title or tag (unsliced).
function allMatches(software, query) {
  const q = lc(query);
  return (software.apps || []).filter((app) => lc(app.title).includes(q)
    || (app.tags || []).some((tag) => lc(tag.name).includes(q)));
}

// Pick a software + query where the query is not in the software title, at
// least 2 apps match it, and one sidebar filter value is carried by some but
// not all of the matching apps. `kind` is 'appType' or 'organizations'.
// Prefers a case where an excluded app would be in the card's first 3 rows
// without the filter (so the filter visibly changes the card).
function findFilterCase(softwareList, repoOrgById, kind) {
  const candidates = [];
  softwareList.forEach((software) => {
    const queries = new Set();
    (software.apps || []).forEach((app) => {
      queries.add(app.title);
      (app.tags || []).forEach((tag) => queries.add(tag.name));
    });
    queries.forEach((query) => {
      if (!query || !lc(query).trim() || lc(software.title).includes(lc(query))) return;
      const matches = allMatches(software, query);
      if (matches.length < 2) return;
      const values = new Set();
      matches.forEach((app) => {
        (kind === 'appType' ? appTypeNames(app) : appOrgNames(app, repoOrgById)).forEach((name) => values.add(name));
      });
      values.forEach((value) => {
        const filter = { kind, value };
        const kept = matches.filter((app) => appMeetsFilter(app, filter, repoOrgById));
        const excluded = matches.filter((app) => !appMeetsFilter(app, filter, repoOrgById));
        if (!kept.length || !excluded.length) return;
        const visible = visibleRows(software, query).map((row) => row.app.id);
        const excludedVisible = excluded.some((app) => visible.includes(app.id));
        candidates.push({ software, query, filter, kept, excluded, excludedVisible });
      });
    });
  });
  return candidates.find((c) => c.excludedVisible) || candidates[0] || null;
}

describe('Appverse search: matching apps on software cards', { includeShadowDom: true }, () => {
  let titleCase;
  let tagCase;
  let filterCase;
  let repoOrgById;
  let appsById;

  before(() => {
    cy.request('/sites/default/files/appverse-cache/appverse-data.json').then((response) => {
      expect(response.status).to.eq(200);
      const softwareList = response.body.software || [];
      expect(softwareList.length, 'software items in the appverse cache').to.be.greaterThan(0);

      titleCase = findTitleCase(softwareList);
      tagCase = findTagCase(softwareList);

      repoOrgById = {};
      (response.body.repos || []).forEach((repo) => {
        if (repo.id && repo.organization && repo.organization.name) repoOrgById[repo.id] = repo.organization.name;
      });
      appsById = {};
      softwareList.forEach((software) => (software.apps || []).forEach((app) => { appsById[app.id] = app; }));
      filterCase = findFilterCase(softwareList, repoOrgById, 'appType')
        || findFilterCase(softwareList, repoOrgById, 'organizations');

      if (!titleCase) {
        throw new Error('No software has an app whose title is not part of the software title and lands in the first 3 matching rows.');
      }
      if (!tagCase) {
        throw new Error('No software has an app with a tag that is not part of the software or app title and lands in the first 3 matching rows.');
      }
      if (!filterCase) {
        throw new Error('No software has a search query (not in the software title) matching 2+ apps where an App Type or Organization value is carried by some but not all of the matching apps.');
      }
      cy.log(`title case: "${titleCase.query}" in "${titleCase.software.title}"`);
      cy.log(`tag case: "${tagCase.query}" on "${tagCase.app.title}" in "${tagCase.software.title}"`);
      cy.log(`filter case: "${filterCase.query}" in "${filterCase.software.title}", ${filterCase.filter.kind}="${filterCase.filter.value}", excludes ${filterCase.excluded.map((a) => a.title).join(', ')}`);
    });
  });

  function searchFor(query) {
    cy.visit('/appverse');
    cy.get('input[placeholder="Search for apps"]', { timeout: 30000 }).should('be.visible').clear().type(query, { parseSpecialCharSequences: false });
  }

  // The software card whose title (the h3 link text) is exactly `title`.
  function tileFor(title) {
    return cy.get('[data-testid="software-tile"]', { timeout: 30000 })
      .filter((i, el) => {
        const h3 = el.querySelector('h3');
        return !!h3 && h3.textContent.trim() === title;
      })
      .should('have.length', 1);
  }

  it('lists an app whose title matches the search on the software card', () => {
    searchFor(titleCase.query);
    tileFor(titleCase.software.title).within(() => {
      cy.get('[data-testid="matched-apps"]').should('exist');
      cy.get('[data-testid="matched-app-row"]')
        .filter((i, el) => el.querySelector('[data-testid="matched-app-link"]')?.textContent.trim() === titleCase.app.title)
        .should('have.length.at.least', 1)
        .first()
        .within(() => {
          cy.get('[data-testid="matched-app-link"]')
            .should('have.attr', 'href')
            .and('match', new RegExp(`#/${slugify(titleCase.software.title)}\\?app=${titleCase.app.id}$`));
          cy.get('[data-testid="matched-app-tag"]').should('not.exist');
        });
    });
  });

  it('opens the software detail scrolled to the matching app when its link is clicked', () => {
    const slug = slugify(titleCase.software.title);
    searchFor(titleCase.query);
    tileFor(titleCase.software.title).within(() => {
      cy.contains('[data-testid="matched-app-link"]', titleCase.app.title).click();
    });

    cy.location('hash').should('contain', `/${slug}?app=${titleCase.app.id}`);

    // The row is scrolled into view (smooth scroll, so retry until it settles).
    cy.get(`#app-${titleCase.app.id}`, { timeout: 30000 })
      .should('exist')
      .should(($row) => {
        const top = $row[0].getBoundingClientRect().top;
        const viewportHeight = $row[0].ownerDocument.defaultView.innerHeight;
        expect(top, 'row top is within the viewport').to.be.at.least(0);
        expect(top, 'row top is above the viewport bottom').to.be.lessThan(viewportHeight);
      })
      // The deep-linked app is the opened one: its README panel is expanded.
      .and('contain.text', 'HIDE README');
  });

  it('lists an app whose tag matches the search, with the tag chip', () => {
    searchFor(tagCase.query);
    tileFor(tagCase.software.title).within(() => {
      cy.get('[data-testid="matched-apps"]').should('exist');
      cy.get('[data-testid="matched-app-row"]')
        .filter((i, el) => el.querySelector('[data-testid="matched-app-link"]')?.textContent.trim() === tagCase.app.title)
        .should('have.length.at.least', 1)
        .first()
        .within(() => {
          cy.get('[data-testid="matched-app-tag"]').should('contain.text', tagCase.tag);
        });
    });
  });

  it('shows no matching apps when the software title itself matches', () => {
    searchFor(titleCase.software.title);
    tileFor(titleCase.software.title).within(() => {
      cy.get('[data-testid="matched-apps"]').should('not.exist');
    });
  });

  it('opens the plain software page when the card itself is clicked', () => {
    const slug = slugify(titleCase.software.title);
    searchFor(titleCase.query);
    // Click inside the padding. The title link's overlay covers the padding
    // box but not the 2px border, so a click on the top edge hits nothing.
    tileFor(titleCase.software.title).click(24, 24);

    cy.location('hash').should('contain', `#/${slug}`).and('not.contain', '?app=');
  });

  it('only lists matching apps that meet the active sidebar filter', () => {
    const { software, query, filter, excluded } = filterCase;
    const sectionTitle = filter.kind === 'appType' ? /^App Type \(/ : /^Organization \(/;
    searchFor(query);
    tileFor(software.title).within(() => {
      cy.get('[data-testid="matched-apps"]').should('exist');
    });

    // The sidebar starts hidden. The mobile drawer renders a second copy of
    // the filters, so scope to the desktop sidebar wrapper (hidden lg:block).
    cy.contains('button', 'Show Filters').click();
    cy.get('div.lg\\:block', { timeout: 30000 })
      .find('h3')
      .filter((i, el) => sectionTitle.test(el.textContent.trim()))
      .should('have.length', 1)
      .closest('div')
      .find(`input[type="checkbox"][data-value="${filter.value.replace(/["\\]/g, '\\$&')}"]`)
      .check({ scrollBehavior: 'center' });
    cy.location('href').should('include', `${filter.kind}=`);

    // The card stays (an app meets the filter) and lists only apps that meet it.
    cy.get('[data-testid="software-tile"]', { timeout: 30000 }).should(($tiles) => {
      const found = $tiles.filter((i, el) => {
        const h3 = el.querySelector('h3');
        return !!h3 && h3.textContent.trim() === software.title;
      });
      expect(found.length, `card "${software.title}" is still shown after filtering by ${filter.kind}="${filter.value}"`).to.eq(1);
      const links = found[0].querySelectorAll('[data-testid="matched-app-link"]');
      expect(links.length, 'matching app links on the filtered card').to.be.greaterThan(0);
      const excludedIds = excluded.map((app) => app.id);
      links.forEach((link) => {
        const match = /[?&]app=([^&#]+)/.exec(link.getAttribute('href') || '');
        expect(match, `app uuid in href "${link.getAttribute('href')}"`).to.not.eq(null);
        const app = appsById[match[1]];
        expect(app, `app ${match[1]} exists in the cache`).to.not.eq(undefined);
        expect(appMeetsFilter(app, filter, repoOrgById), `"${app.title}" meets ${filter.kind}="${filter.value}"`).to.eq(true);
        expect(excludedIds, `excluded app "${app.title}" is not listed`).to.not.include(match[1]);
      });
    });
  });
});
