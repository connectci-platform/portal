/**
 * Mailpit API commands for email testing
 * 
 * Mailpit API documentation: https://github.com/axllent/mailpit/blob/develop/docs/apiv1/README.md
 */

// Get mailpit URL from environment or use default DDEV URL
const getMailpitUrl = () => {
  return Cypress.env('MAILPIT_URL') || 'https://cyberteam-drupal.ddev.site:8026';
};

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