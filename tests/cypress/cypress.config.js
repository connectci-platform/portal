const { defineConfig } = require("cypress");
const fs = require('fs');
const path = require('path');

// Get base URL from environment variable or default to local DDEV
const baseUrl = process.env.CYPRESS_BASE_URL || 'https://cyberteam-drupal.ddev.site';

function ensureDir(dir) {
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
}

module.exports = defineConfig({

  component: {
    fixturesFolder: "cypress/fixtures",
    integrationFolder: "cypress/integration",
    pluginsFile: "cypress/plugins/index.js",
    screenshotsFolder: "cypress/screenshots",
    supportFile: "cypress/support/e2e.js",
    videosFolder: "cypress/videos",
  },

  e2e: {
    baseUrl: baseUrl,
    chromeWebSecurity: false,
    specPattern: "cypress/**",
    supportFile: "cypress/support/e2e.js",
    fixturesFolder: "cypress/fixtures",
    experimentalStudio: true,
    viewportWidth: 1440,
    viewportHeight: 900,

    setupNodeEvents(on, config) {
      // implement node event listeners here
      on('task', {
        log(message) {
          // Then to see the log messages in the terminal
          //   cy.task("log", "my message");
          console.log('log: ' + message);
          return null;
        },
      });
      on('task', {
        'a11y:writeReport'(payload) {
          const reportDir = config.env.A11Y_REPORT_DIR || 'cypress-a11y-reports';
          ensureDir(reportDir);

          // Create base filenames
          const base = payload.fileBase || `report_${Date.now()}`;

          // Write CSV instead of JSON for each test
          const detailedCsvPath = path.join(reportDir, `${base}.csv`);

          // HTML report path
          const htmlReportPath = path.join(reportDir, `${base}.html`);

          // Convert violations to CSV format with detailed node information
          const csvHeader = 'category,impact,id,description,help,helpUrl,tags,html,target,failureSummary\n';

          let csvRows = [];

          // Calculate counts early for both HTML and CSV reports
          // Separate counts for errors (WCAG 2.1 AA) and warnings (WCAG 2.2/best practices)
          const counts = {
            errors: { critical: 0, serious: 0, moderate: 0, minor: 0 },
            warnings: { critical: 0, serious: 0, moderate: 0, minor: 0 },
            total: { critical: 0, serious: 0, moderate: 0, minor: 0 }
          };
          (payload.results.violations || []).forEach(v => {
            const k = (v.impact || 'minor').toLowerCase();
            const category = v.category || 'error'; // default to error if not categorized
            if (counts.total[k] !== undefined) {
              counts.total[k] += 1;
              if (category === 'error') {
                counts.errors[k] += 1;
              } else {
                counts.warnings[k] += 1;
              }
            }
          });

          // Generate HTML content
          let htmlContent = `
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accessibility Test Results</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
        h1, h2, h3 { margin-top: 20px; }
        .summary { background: #f5f5f5; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .violation { border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 4px; }
        .critical { border-left: 5px solid #d9534f; }
        .serious { border-left: 5px solid #f0ad4e; }
        .moderate { border-left: 5px solid #5bc0de; }
        .minor { border-left: 5px solid #5cb85c; }
        .impact { font-weight: bold; }
        .impact.critical { color: #d9534f; }
        .impact.serious { color: #f0ad4e; }
        .impact.moderate { color: #5bc0de; }
        .impact.minor { color: #5cb85c; }
        .category { font-weight: bold; padding: 2px 8px; border-radius: 3px; display: inline-block; margin-bottom: 5px; }
        .category-error { background-color: #f8d7da; color: #721c24; }
        .category-warning { background-color: #fff3cd; color: #856404; }
        .violation.category-warning { border-right: 5px solid #ffc107; }
        .node { border-left: 3px solid #eee; padding-left: 15px; margin: 10px 0; }
        .code { background: #f8f9fa; padding: 10px; border-radius: 4px; white-space: pre-wrap; overflow-wrap: break-word; }
        .target { font-family: monospace; margin-top: 5px; }
        .failure-summary { background: #fff3cd; padding: 10px; border-radius: 4px; margin-top: 10px; }
    </style>
</head>
<body>
    <h1>Accessibility Test Results</h1>
    <div class="summary">
        <h2>Test Summary</h2>
        <p><strong>URL:</strong> ${payload.url || 'N/A'}</p>
        <p><strong>Test Spec:</strong> ${payload.spec || 'N/A'}</p>
        <p><strong>Date:</strong> ${new Date().toLocaleString()}</p>
        <p><strong>Total Violations:</strong> ${(payload.results.violations || []).length}</p>
        <h3>Errors (WCAG 2.1 AA - Compliance Required)</h3>
        <p><strong>Critical:</strong> ${counts.errors.critical} | <strong>Serious:</strong> ${counts.errors.serious} | <strong>Moderate:</strong> ${counts.errors.moderate} | <strong>Minor:</strong> ${counts.errors.minor}</p>
        <h3>Warnings (WCAG 2.2 / Best Practices)</h3>
        <p><strong>Critical:</strong> ${counts.warnings.critical} | <strong>Serious:</strong> ${counts.warnings.serious} | <strong>Moderate:</strong> ${counts.warnings.moderate} | <strong>Minor:</strong> ${counts.warnings.minor}</p>
    </div>
`;

          // Using the existing counts object for both HTML and CSV reports

          // Expand each violation to include node-specific details
          if (payload.results.violations && payload.results.violations.length > 0) {
            htmlContent += '<h2>Violations</h2>';

            (payload.results.violations || []).forEach(violation => {
              // Impact for this violation
              const impact = (violation.impact || 'minor').toLowerCase();

              // Add violation to HTML
              const category = violation.category || 'error';
              const categoryLabel = category === 'error' ? 'Error (WCAG 2.1 AA)' : 'Warning (WCAG 2.2/Best Practice)';
              const categoryClass = category === 'error' ? 'category-error' : 'category-warning';
              htmlContent += `
    <div class="violation ${impact} ${categoryClass}">
        <h3>${violation.id} - ${violation.help}</h3>
        <p class="category ${categoryClass}">${categoryLabel}</p>
        <p class="impact ${impact}">Impact: ${violation.impact}</p>
        <p><strong>Description:</strong> ${violation.description}</p>
        <p><strong>Help:</strong> <a href="${violation.helpUrl}" target="_blank">${violation.help}</a></p>
        <p><strong>Tags:</strong> ${(violation.tags || []).join(', ')}</p>
        <h4>Affected Elements (${violation.nodes.length})</h4>
`;

              // Process each node for this violation
              violation.nodes.forEach(node => {
                htmlContent += `
        <div class="node">
            <div class="code">${node.html.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</div>
            <p class="target"><strong>Target:</strong> ${(node.target || []).join(', ')}</p>
            <div class="failure-summary"><strong>Failure Summary:</strong><br>${node.failureSummary.replace(/\n/g, '<br>')}</div>
        </div>`;

                csvRows.push([
                  violation.category || 'error',
                  violation.impact || '',
                  violation.id || '',
                  JSON.stringify(violation.description || ''),
                  JSON.stringify(violation.help || ''),
                  JSON.stringify(violation.helpUrl || ''),
                  JSON.stringify((violation.tags || []).join(';')),
                  JSON.stringify(node.html || ''),
                  JSON.stringify((node.target || []).join(';')),
                  JSON.stringify(node.failureSummary || '')
                ].join(','));
              });

              htmlContent += `
    </div>`;
            });
          } else {
            htmlContent += '<h2>No violations found!</h2>';
          }

          htmlContent += `
</body>
</html>`;

          // Write HTML and CSV files
          fs.writeFileSync(htmlReportPath, htmlContent);
          fs.writeFileSync(detailedCsvPath, csvHeader + csvRows.join('\n'));

          // Append CSV summary (create header if new)
          const csvPath = path.join(reportDir, `summary.csv`);
          if (!fs.existsSync(csvPath)) {
            fs.writeFileSync(
              csvPath,
              'spec,url,total_violations,errors_critical,errors_serious,errors_moderate,errors_minor,warnings_critical,warnings_serious,warnings_moderate,warnings_minor,csv,html\n'
            );
          }

          // Add HTML filename to the summary CSV
          const specName = payload.spec || '';
          const line = [
            JSON.stringify(specName),
            JSON.stringify(payload.url || ''),
            (payload.results.violations || []).length,
            counts.errors.critical, counts.errors.serious, counts.errors.moderate, counts.errors.minor,
            counts.warnings.critical, counts.warnings.serious, counts.warnings.moderate, counts.warnings.minor,
            JSON.stringify(path.basename(detailedCsvPath)),
            JSON.stringify(path.basename(htmlReportPath)),
          ].join(',') + '\n';
          fs.appendFileSync(csvPath, line);

          // Generate or update summary HTML
          const summaryHtmlPath = path.join(reportDir, 'summary.html');

          // Read existing CSV data to generate the summary HTML
          const generateSummaryHtml = () => {
            let summaryData = [];

            // Read the CSV file if it exists
            if (fs.existsSync(csvPath)) {
              const csvContent = fs.readFileSync(csvPath, 'utf8');
              const lines = csvContent.split('\n').filter(Boolean);

              // Skip header row
              if (lines.length > 1) {
                // Parse each line of the CSV (simple parsing, not handling complex cases)
                for (let i = 1; i < lines.length; i++) {
                  try {
                    const parts = lines[i].split(',');
                    console.log(`Parsing line ${i}: parts=${parts.length}`);

                    if (parts.length >= 13) {
                      // New format with errors/warnings separated
                      const specName = parts[0] ? JSON.parse(parts[0]) : '';
                      const url = JSON.parse(parts[1]);
                      const violations = parseInt(parts[2], 10) || 0;
                      const errors = {
                        critical: parseInt(parts[3], 10) || 0,
                        serious: parseInt(parts[4], 10) || 0,
                        moderate: parseInt(parts[5], 10) || 0,
                        minor: parseInt(parts[6], 10) || 0
                      };
                      const warnings = {
                        critical: parseInt(parts[7], 10) || 0,
                        serious: parseInt(parts[8], 10) || 0,
                        moderate: parseInt(parts[9], 10) || 0,
                        minor: parseInt(parts[10], 10) || 0
                      };
                      const csvFile = JSON.parse(parts[11]);
                      let htmlFile = csvFile.replace('.csv', '.html');
                      try {
                        htmlFile = JSON.parse(parts[12]);
                      } catch (htmlErr) {
                        console.log(`Using generated HTML filename: ${htmlFile}`);
                      }

                      summaryData.push({
                        specName: specName || 'Unnamed Test',
                        url,
                        violations,
                        errors,
                        warnings,
                        csvFile,
                        htmlFile,
                        timestamp: new Date().toISOString()
                      });
                      console.log(`Added test for ${url} with ${violations} violations`);
                    } else if (parts.length >= 9) {
                      // Legacy format - treat all as errors for backwards compatibility
                      const specName = parts[0] ? JSON.parse(parts[0]) : '';
                      const url = JSON.parse(parts[1]);
                      const violations = parseInt(parts[2], 10) || 0;
                      const errors = {
                        critical: parseInt(parts[3], 10) || 0,
                        serious: parseInt(parts[4], 10) || 0,
                        moderate: parseInt(parts[5], 10) || 0,
                        minor: parseInt(parts[6], 10) || 0
                      };
                      const warnings = { critical: 0, serious: 0, moderate: 0, minor: 0 };
                      const csvFile = JSON.parse(parts[7]);
                      let htmlFile = csvFile.replace('.csv', '.html');
                      if (parts.length >= 9) {
                        try {
                          htmlFile = JSON.parse(parts[8]);
                        } catch (htmlErr) {
                          console.log(`Using generated HTML filename: ${htmlFile}`);
                        }
                      }

                      summaryData.push({
                        specName: specName || 'Unnamed Test',
                        url,
                        violations,
                        errors,
                        warnings,
                        csvFile,
                        htmlFile,
                        timestamp: new Date().toISOString()
                      });
                      console.log(`Added test for ${url} with ${violations} violations (legacy format)`);
                    } else {
                      console.log(`Skipping line ${i}: insufficient columns (${parts.length})`);
                    }
                  } catch (e) {
                    console.error('Error parsing CSV line:', lines[i], e);
                  }
                }
              }
            }

            // Generate HTML content
            let totalStats = {
              tests: summaryData.length,
              violations: 0,
              errors: { critical: 0, serious: 0, moderate: 0, minor: 0 },
              warnings: { critical: 0, serious: 0, moderate: 0, minor: 0 }
            };

            // Calculate totals
            summaryData.forEach(data => {
              totalStats.violations += data.violations;
              totalStats.errors.critical += data.errors.critical;
              totalStats.errors.serious += data.errors.serious;
              totalStats.errors.moderate += data.errors.moderate;
              totalStats.errors.minor += data.errors.minor;
              totalStats.warnings.critical += data.warnings.critical;
              totalStats.warnings.serious += data.warnings.serious;
              totalStats.warnings.moderate += data.warnings.moderate;
              totalStats.warnings.minor += data.warnings.minor;
            });

            // Sort by most recent first (assuming we added timestamp)
            summaryData.sort((a, b) => {
              // First by spec name for grouping
              if (a.specName !== b.specName) {
                return a.specName.localeCompare(b.specName);
              }
              // Then by violation count (highest first)
              return b.violations - a.violations;
            });

            return `
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accessibility Test Summary</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
        h1, h2 { margin-top: 20px; }
        .summary { background: #f5f5f5; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; position: sticky; top: 0; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        tr:hover { background-color: #f1f1f1; }
        .critical { color: #d9534f; font-weight: bold; }
        .serious { color: #f0ad4e; font-weight: bold; }
        .moderate { color: #5bc0de; }
        .minor { color: #5cb85c; }
        .no-violations { color: #5cb85c; font-weight: bold; }
        .filter-container { margin-bottom: 15px; }
        .search { padding: 8px; width: 300px; margin-right: 10px; }
        .sort-btn { padding: 8px; background: #f2f2f2; border: 1px solid #ddd; cursor: pointer; }
        .sort-btn:hover { background: #e2e2e2; }
        .timestamp { font-size: 0.85em; color: #777; }
        .sticky-header { position: sticky; top: 0; background: white; padding: 10px 0; z-index: 1000; }
        .auto-refresh { display: flex; align-items: center; margin-left: 20px; }
    </style>
</head>
<body>
    <div class="sticky-header">
        <h1>Accessibility Test Summary</h1>
        <div class="filter-container">
            <input type="text" id="searchInput" class="search" placeholder="Search specs, URLs, issues...">
            <button class="sort-btn" onclick="sortTable('violations', 'desc')">Most Violations</button>
            <button class="sort-btn" onclick="sortTable('timestamp', 'desc')">Most Recent</button>
            <button class="sort-btn" onclick="sortTable('specName', 'asc')">By Spec Name</button>
            <label class="auto-refresh">
                <input type="checkbox" id="autoRefresh"> Auto-refresh (30s)
            </label>
        </div>
    </div>

    <div class="summary">
        <h2>Overview</h2>
        <p><strong>Total Tests:</strong> ${totalStats.tests}</p>
        <p><strong>Total Violations:</strong> ${totalStats.violations}</p>
        <h3>Errors (WCAG 2.1 AA - Compliance Required)</h3>
        <p>
            <span class="critical">Critical: ${totalStats.errors.critical}</span> |
            <span class="serious">Serious: ${totalStats.errors.serious}</span> |
            <span class="moderate">Moderate: ${totalStats.errors.moderate}</span> |
            <span class="minor">Minor: ${totalStats.errors.minor}</span>
        </p>
        <h3>Warnings (WCAG 2.2 / Best Practices)</h3>
        <p>
            <span class="critical">Critical: ${totalStats.warnings.critical}</span> |
            <span class="serious">Serious: ${totalStats.warnings.serious}</span> |
            <span class="moderate">Moderate: ${totalStats.warnings.moderate}</span> |
            <span class="minor">Minor: ${totalStats.warnings.minor}</span>
        </p>
        <p><strong>Last Generated:</strong> ${new Date().toLocaleString()}</p>
    </div>

    <h2>Test Reports</h2>
    <table id="reportTable">
        <thead>
            <tr>
                <th>Spec Name</th>
                <th>Page URL</th>
                <th>Total</th>
                <th colspan="4">Errors (WCAG 2.1 AA)</th>
                <th colspan="4">Warnings</th>
                <th>Reports</th>
            </tr>
            <tr>
                <th></th>
                <th></th>
                <th></th>
                <th>Crit</th>
                <th>Ser</th>
                <th>Mod</th>
                <th>Min</th>
                <th>Crit</th>
                <th>Ser</th>
                <th>Mod</th>
                <th>Min</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            ${summaryData.map(data => `
            <tr data-spec="${data.specName}" data-url="${data.url}" data-violations="${data.violations}" data-errors="${data.errors.critical + data.errors.serious}" data-timestamp="${data.timestamp}">
                <td>${data.specName || 'Unnamed Test'}</td>
                <td><a href="${data.url}" target="_blank">${data.url}</a></td>
                <td class="${data.violations > 0 ? 'critical' : 'no-violations'}">${data.violations}</td>
                <td class="${data.errors.critical > 0 ? 'critical' : ''}">${data.errors.critical}</td>
                <td class="${data.errors.serious > 0 ? 'serious' : ''}">${data.errors.serious}</td>
                <td class="${data.errors.moderate > 0 ? 'moderate' : ''}">${data.errors.moderate}</td>
                <td class="${data.errors.minor > 0 ? 'minor' : ''}">${data.errors.minor}</td>
                <td class="${data.warnings.critical > 0 ? 'critical' : ''}">${data.warnings.critical}</td>
                <td class="${data.warnings.serious > 0 ? 'serious' : ''}">${data.warnings.serious}</td>
                <td class="${data.warnings.moderate > 0 ? 'moderate' : ''}">${data.warnings.moderate}</td>
                <td class="${data.warnings.minor > 0 ? 'minor' : ''}">${data.warnings.minor}</td>
                <td>
                    <a href="./${data.htmlFile}">HTML</a> |
                    <a href="./${data.csvFile}" target="_blank">CSV</a>
                </td>
            </tr>
            `).join('')}
        </tbody>
    </table>

    <script>
        function sortTable(column, direction) {
            const table = document.getElementById('reportTable');
            const rows = Array.from(table.querySelectorAll('tbody tr'));
            const sortedRows = rows.sort((a, b) => {
                let aValue, bValue;

                if (column === 'specName') {
                    aValue = a.cells[0].textContent;
                    bValue = b.cells[0].textContent;
                    return direction === 'asc' ? aValue.localeCompare(bValue) : bValue.localeCompare(aValue);
                } else if (column === 'violations') {
                    aValue = parseInt(a.getAttribute('data-violations'), 10);
                    bValue = parseInt(b.getAttribute('data-violations'), 10);
                } else if (column === 'timestamp') {
                    aValue = new Date(a.getAttribute('data-timestamp')).getTime();
                    bValue = new Date(b.getAttribute('data-timestamp')).getTime();
                }

                if (direction === 'asc') {
                    return aValue - bValue;
                } else {
                    return bValue - aValue;
                }
            });

            // Remove existing rows
            rows.forEach(row => row.remove());

            // Add sorted rows
            sortedRows.forEach(row => {
                table.querySelector('tbody').appendChild(row);
            });
        }

        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('#reportTable tbody tr');

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const spec = row.getAttribute('data-spec').toLowerCase();
                const url = row.getAttribute('data-url').toLowerCase();

                if (text.includes(searchTerm) || spec.includes(searchTerm) || url.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });

        // Auto-refresh functionality
        document.getElementById('autoRefresh').addEventListener('change', function() {
            if (this.checked) {
                window.refreshTimer = setInterval(() => {
                    location.reload();
                }, 30000); // 30 seconds
            } else if (window.refreshTimer) {
                clearInterval(window.refreshTimer);
            }
        });
    </script>
</body>
</html>
            `;
          };

          fs.writeFileSync(summaryHtmlPath, generateSummaryHtml());

          return { detailedCsvPath, csvPath, htmlReportPath, summaryHtmlPath, counts };
        },
        log(msg) {
          console.log(msg);
          return null;
        }
      });

      // The run date (YYYY-MM-DD, UTC) that every generated event date is an
      // offset from. It is computed once per run so specs agree even if the
      // run crosses midnight. Override it with CYPRESS_RUN_DATE, e.g. a date a
      // year out to prove no spec depends on a fixed date. Read dates with
      // eventDate() / anonEventDate() from support/event-dates.js.
      if (!config.env.RUN_DATE) {
        config.env.RUN_DATE = new Date().toISOString().slice(0, 10);
      }
      if (!/^\d{4}-\d{2}-\d{2}$/.test(config.env.RUN_DATE)
        || Number.isNaN(Date.parse(`${config.env.RUN_DATE}T00:00:00Z`))) {
        throw new Error(`RUN_DATE must be a valid YYYY-MM-DD date, got "${config.env.RUN_DATE}"`);
      }

      return config;
    },

  },
});
