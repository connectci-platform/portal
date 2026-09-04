// Env-driven gating: by default DO NOT fail the test.
// Set CYPRESS_A11Y_FAIL_ON to a comma list (e.g., "serious,critical") to fail on those impacts.
// Only WCAG 2.1 AA violations (errors) can trigger failures; warnings never fail.
const failOnImpacts = (Cypress.env('A11Y_FAIL_ON') || '')
  .split(',')
  .map(s => s.trim().toLowerCase())
  .filter(Boolean);

// WCAG 2.1 AA tags - violations with these tags are "errors" (compliance requirement)
const wcag21AATags = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

// Advisory tags - violations with these tags are "warnings" (future improvements)
const advisoryTags = ['wcag22aa', 'best-practice'];

// Helper: categorize a violation as 'error' or 'warning' based on its tags
function categorizeViolation(violation) {
  const tags = violation.tags || [];
  // If it has any WCAG 2.1 AA tag, it's an error
  if (tags.some(tag => wcag21AATags.includes(tag))) {
    return 'error';
  }
  // If it only has advisory tags (or other tags), it's a warning
  return 'warning';
}

// Admin toolbar (incl. environment indicator) has a known, accepted color-contrast
// issue on the active tab - see MD-2782. Excluded from all axe scans rather than
// fixed, since the root cause is in the environment_indicator contrib module's JS.
const DEFAULT_EXCLUDE = [['#toolbar-administration']];

// Merge default excludes into whatever context a spec passes (or none).
function withDefaultExclude(context) {
  if (context === null || context === undefined) {
    return { exclude: DEFAULT_EXCLUDE };
  }
  if (typeof context === 'object' && !Array.isArray(context)) {
    const existingExclude = Array.isArray(context.exclude) ? context.exclude : [];
    return { ...context, exclude: [...existingExclude, ...DEFAULT_EXCLUDE] };
  }
  return context;
}

// Helper: run axe, write report, and decide whether to fail.
Cypress.Commands.add('a11yCheckWithReport', (context = null, options = {}) => {
  context = withDefaultExclude(context);
  const includedImpacts = ['minor', 'moderate', 'serious', 'critical']; // all levels
  const axeOptions = { includedImpacts, ...options };

  const specPath = Cypress.mocha.getRunner().suite?.file || '';
  const specName = specPath.split(/[\\/]/).pop();
  const safeUrl = (win) => (win?.location?.href || '').replace(/[^a-z0-9]/gi, '_').slice(0, 150);
  let rawResults = null;

  // Use cypress-axe wrapper but capture raw results via custom callback.
  cy.checkA11y(context, axeOptions, (violations) => {
    // violations provided for convenience; we’ll re-run axe.run to get full results object
  }, /* skipFailures = */ true);

  // Grab full results directly from axe for reporting
  cy.window().then(async (win) => {
    // Handle axe.run properly - if context is null, we want to run on document
    let contextElement = context;
    if (context === null) {
      contextElement = win.document;
    }
    const results = await win.axe.run(contextElement, axeOptions);
    rawResults = results;
    const base = `${specName || 'spec'}__${safeUrl(win) || 'page'}`;

    // Categorize each violation as error or warning
    const categorizedViolations = (results.violations || []).map(v => ({
      ...v,
      category: categorizeViolation(v)
    }));

    return cy.task('a11y:writeReport', {
      spec: specName,
      url: win.location.href,
      fileBase: base,
      results: { ...results, violations: categorizedViolations },
    });
  }).then(({ counts }) => {
    // Log a nice summary
    cy.task('log', `[a11y] Errors (WCAG 2.1 AA) — critical:${counts.errors.critical} serious:${counts.errors.serious} moderate:${counts.errors.moderate} minor:${counts.errors.minor}`);
    cy.task('log', `[a11y] Warnings (WCAG 2.2/Best Practices) — critical:${counts.warnings.critical} serious:${counts.warnings.serious} moderate:${counts.warnings.moderate} minor:${counts.warnings.minor}`);

    // Decide whether to fail the test - only errors (WCAG 2.1 AA) can trigger failures
    if (failOnImpacts.length && rawResults) {
      const errors = (rawResults.violations || []).filter(v => categorizeViolation(v) === 'error');
      const shouldFail = errors.some(v =>
        v.impact && failOnImpacts.includes(v.impact.toLowerCase())
      );
      if (shouldFail) {
        throw new Error(
          `WCAG 2.1 AA violations at disallowed impacts (${failOnImpacts.join(', ')}). See report.`
        );
      }
    }
  });
});
