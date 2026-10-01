/**
 * Mailpit API commands for email testing
 * 
 * Mailpit API documentation: https://github.com/axllent/mailpit/blob/develop/docs/apiv1/README.md
 */

// Get mailpit URL from environment or use default DDEV URL
const getMailpitUrl = () => {
  return Cypress.env('MAILPIT_URL') || 'https://cyberteam-drupal.ddev.site:8026';
};

// Escape a literal string for safe embedding inside a `new RegExp(...)`
// source string.
const escapeRegExp = (str) => str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/**
 * Get all messages from mailpit
 */
Cypress.Commands.add('getMailpitMessages', () => {
  const mailpitUrl = getMailpitUrl();
  return cy.request({
    method: 'GET',
    url: `${mailpitUrl}/api/v1/messages`,
    failOnStatusCode: false
  }).then((response) => {
    expect(response.status).to.eq(200);
    return response.body.messages || [];
  });
});

/**
 * Search for messages in mailpit
 * @param {object} searchParams - Search parameters
 * @param {string} searchParams.to - To email address
 * @param {string} searchParams.from - From email address
 * @param {string} searchParams.subject - Subject contains
 * @param {string} searchParams.query - Full text search query
 */
Cypress.Commands.add('searchMailpitMessages', (searchParams) => {
  const mailpitUrl = getMailpitUrl();

  // Mailpit's search API takes a SINGLE `query` parameter whose space-separated
  // terms are ANDed together. Appending multiple `query` params (one per
  // criterion) does NOT AND them — Mailpit honors only the first, silently
  // dropping the rest. So a {to, subject} search would degrade to `to:` alone
  // and match the most recent email to that address regardless of subject,
  // which let stale cross-spec emails satisfy waitForEmail(). Build one
  // combined query string instead.
  const terms = [];
  if (searchParams.to) terms.push(`to:"${searchParams.to}"`);
  if (searchParams.from) terms.push(`from:"${searchParams.from}"`);
  if (searchParams.subject) terms.push(`subject:"${searchParams.subject}"`);
  if (searchParams.query) terms.push(searchParams.query);

  const params = new URLSearchParams();
  params.append('query', terms.join(' '));

  return cy.request({
    method: 'GET',
    url: `${mailpitUrl}/api/v1/search?${params.toString()}`,
    failOnStatusCode: false
  }).then((response) => {
    expect(response.status).to.eq(200);
    return response.body.messages || [];
  });
});

/**
 * Get a specific message by ID
 * @param {string} messageId - Message ID
 */
Cypress.Commands.add('getMailpitMessage', (messageId) => {
  const mailpitUrl = getMailpitUrl();
  return cy.request({
    method: 'GET',
    url: `${mailpitUrl}/api/v1/message/${messageId}`,
    failOnStatusCode: false
  }).then((response) => {
    expect(response.status).to.eq(200);
    return response.body;
  });
});

/**
 * Delete all messages from mailpit
 */
Cypress.Commands.add('clearMailpit', () => {
  const mailpitUrl = getMailpitUrl();
  return cy.request({
    method: 'DELETE',
    url: `${mailpitUrl}/api/v1/messages`,
    failOnStatusCode: false
  }).then((response) => {
    expect(response.status).to.eq(200);
  });
});

/**
 * Wait for an email to arrive and return it
 * @param {object} criteria - Search criteria
 * @param {string} criteria.to - To email address
 * @param {string} criteria.subject - Subject contains
 * @param {number} timeout - Max time to wait in ms (default 10000)
 */
Cypress.Commands.add('waitForEmail', (criteria, timeout = 10000) => {
  const startTime = Date.now();
  
  const checkForEmail = () => {
    return cy.searchMailpitMessages(criteria).then((messages) => {
      if (messages.length > 0) {
        // Return the most recent message
        return messages[0];
      }
      
      if (Date.now() - startTime > timeout) {
        throw new Error(`Email not found after ${timeout}ms with criteria: ${JSON.stringify(criteria)}`);
      }
      
      // Wait 2000ms (2 seconds) and try again to avoid triggering autoban
      cy.wait(2000);
      return checkForEmail();
    });
  };
  
  return checkForEmail();
});

/**
 * Assert email content
 * @param {object} message - Email message from mailpit
 * @param {object} expectations - Expected values
 * @param {string} expectations.subject - Expected subject
 * @param {string} expectations.from - Expected from address
 * @param {string} expectations.to - Expected to address
 * @param {string} expectations.replyTo - Expected reply-to address
 * @param {string} expectations.bodyContains - Text that should be in body
 * @param {string} expectations.htmlContains - HTML that should be in body
 */
Cypress.Commands.add('assertEmailContent', (message, expectations) => {
  if (expectations.subject) {
    expect(message.Subject).to.contain(expectations.subject);
  }
  
  if (expectations.from) {
    const fromAddress = message.From?.Address || message.From?.address;
    expect(fromAddress).to.equal(expectations.from);
  }
  
  if (expectations.to) {
    const toAddresses = message.To?.map(t => t.Address || t.address) || [];
    expect(toAddresses).to.include(expectations.to);
  }

  // Get full message details if we need to check body content or reply-to
  if (expectations.bodyContains || expectations.htmlContains || expectations.replyTo) {
    return cy.getMailpitMessage(message.ID).then((fullMessage) => {
      if (expectations.replyTo) {
        const replyToAddresses = fullMessage.ReplyTo?.map(r => r.Address || r.address) || [];
        expect(replyToAddresses).to.include(expectations.replyTo);
      }
      if (expectations.bodyContains) {
        // Collapse whitespace so plaintext line-wrapping (emails hard-wrap at
        // ~78 cols, which can split a title or URL across lines) does not break
        // a substring match. Both the body and each expected string are
        // normalized to single spaces before comparing.
        const collapse = (s) => s.replace(/\s+/g, ' ');
        const text = collapse(fullMessage.Text || '');
        const expected = Array.isArray(expectations.bodyContains)
          ? expectations.bodyContains
          : [expectations.bodyContains];
        expected.forEach((str) => {
          expect(text).to.contain(collapse(str));
        });
      }
      
      if (expectations.htmlContains) {
        const html = fullMessage.HTML || '';
        // Support both string and array of strings
        if (Array.isArray(expectations.htmlContains)) {
          expectations.htmlContains.forEach(text => {
            expect(html).to.contain(text);
          });
        } else {
          expect(html).to.contain(expectations.htmlContains);
        }
      }
    });
  }
});

/**
 * Assert that an email renders specific links as real HTML anchors, and that
 * the corresponding Text part places each link's URL immediately after its
 * label, ending the line.
 *
 * This is deliberately stricter than assertEmailContent's bodyContains,
 * which collapses all whitespace before comparing substrings — that check
 * passes even when body lines are concatenated with no separator (no <p>
 * wrapping, no <a> tags), because the collapsed strings still contain the
 * expected substrings. assertEmailLinks instead parses the HTML with
 * DOMParser and requires a matching <a> element, and checks the Text part
 * line-by-line so a label glued to the previous sentence (e.g.
 * "...Site.Repository: https://...") or a URL glued to the next label (e.g.
 * "https://...Review queue:") fails.
 *
 * @param {object} message - Email message from mailpit (from waitForEmail /
 *   searchMailpitMessages; only .ID is required — the full message is
 *   fetched here).
 * @param {object[]} links - Links expected in the email.
 * @param {string} links[].label - The text immediately preceding the URL,
 *   used to anchor the Text-part line check (e.g. "Repository:"). Internal
 *   whitespace in the label is matched tolerantly (`\s+`), since the Text
 *   part hard-wraps long lines at spaces.
 * @param {string|RegExp} links[].href - Expected href. A plain string must
 *   match the `<a href>` exactly; a RegExp is tested against it.
 * @param {object} [opts]
 * @param {number} [opts.minParagraphs] - If given, asserts the HTML body
 *   contains at least this many `<p>` elements.
 */
Cypress.Commands.add('assertEmailLinks', (message, links, opts = {}) => {
  return cy.getMailpitMessage(message.ID).then((fullMessage) => {
    const html = fullMessage.HTML || '';
    const text = fullMessage.Text || '';

    const doc = new DOMParser().parseFromString(html, 'text/html');
    const anchors = Array.from(doc.querySelectorAll('a'));

    const matchesHref = (href, candidate) => (
      href instanceof RegExp ? href.test(candidate) : candidate === href
    );

    links.forEach(({ label, href }) => {
      // --- HTML: a real <a> whose href matches, and whose visible text is
      // exactly the URL (not the URL plus a swallowed trailing word).
      const match = anchors.find((a) => matchesHref(href, a.getAttribute('href') || ''));
      // Not `.to.exist`: Cypress overrides it for DOM elements to mean
      // "attached to the page document", which a DOMParser node never is.
      expect(match, `HTML: expected an <a> with href matching ${href} (label "${label}")`).to.not.equal(undefined);
      const linkText = (match.textContent || '').trim();
      expect(
        matchesHref(href, linkText),
        `HTML: <a> text "${linkText}" should equal its href, with no swallowed trailing text, for label "${label}"`
      ).to.equal(true);

      // --- Text: the label begins a line (not preceded by other text on the
      // same line), followed by whitespace, then the URL, which itself ends
      // the line (allowing for hard-wrap immediately after the URL).
      if (label) {
        const escapedLabel = label.split(/\s+/).map(escapeRegExp).join('\\s+');
        // A RegExp href usually matches only the tail of the URL (e.g.
        // /\/appverse\/manage-repos$/), so allow any non-space prefix
        // (scheme + host) before it.
        const urlPart = href instanceof RegExp
          ? `\\S*${href.source.replace(/^\^/, '').replace(/\$$/, '')}`
          : escapeRegExp(href);
        const lineRe = new RegExp(`(^|\\n)\\s*${escapedLabel}\\s+${urlPart}\\s*(\\r?\\n|$)`);
        expect(
          lineRe.test(text),
          `Text: expected label "${label}" to begin a line, followed by whitespace then a URL matching ${href} that ends the line.\nFull Text was:\n${text}`
        ).to.equal(true);
      }
    });

    if (opts.minParagraphs) {
      const count = doc.querySelectorAll('p').length;
      expect(
        count,
        `HTML: expected at least ${opts.minParagraphs} <p> elements, found ${count}`
      ).to.be.at.least(opts.minParagraphs);
    }
  });
});
