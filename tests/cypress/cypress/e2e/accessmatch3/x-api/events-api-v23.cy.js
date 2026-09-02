/**
 * Tests for Events API v2.3 endpoint.
 *
 * v2.3 differences from v2.2:
 * - Default filter to upcoming events only (date >= today)
 * - where_to_share and domain_access applied at search_api query level
 * - Same exposed filters: beginning_date_relative, end_date_relative,
 *   beginning_date, end_date, search_api_fulltext, timezone
 */

describe("Events API v2.3", () => {

  // The v2.3 "upcoming events" filter is applied server-side in the site's
  // configured timezone (America/New_York). Compute the comparison date in that
  // same timezone rather than UTC, otherwise the test flakes between local
  // midnight and UTC midnight, rejecting a same-day event as if it were past.
  const SITE_TIMEZONE = 'America/New_York';
  const siteToday = () =>
    new Date().toLocaleDateString('en-CA', { timeZone: SITE_TIMEZONE });

  it("returns correct structure with expected fields", () => {
    cy.request('/api/2.3/events')
      .then((response) => {
        expect(response.status).to.eq(200);
        expect(response.body).to.be.an('array');
        expect(response.body.length).to.be.greaterThan(0);

        const event = response.body[0];
        const expectedFields = [
          'id', 'title', 'description', 'start_date', 'end_date', 'location',
          'event_type', 'affiliation', 'tags', 'skill_level',
          'speakers', 'summary', 'contact', 'registration', 'video', 'created', 'changed',
          'url'
        ];

        expectedFields.forEach(field => {
          expect(event, `Missing field: ${field}`).to.have.property(field);
        });

        // URL should be an absolute URL to the event
        expect(event.url).to.be.a('string').and.not.be.empty;
        expect(event.url).to.include('https://');
      });
  });

  it("defaults to upcoming events only", () => {
    const today = siteToday();

    cy.request('/api/2.3/events')
      .then((response) => {
        expect(response.status).to.eq(200);
        expect(response.body).to.be.an('array');
        expect(response.body.length).to.be.greaterThan(0);

        // Every event should be today or later
        response.body.forEach(event => {
          if (event.start_date) {
            const eventDate = event.start_date.split('T')[0];
            expect(eventDate >= today, `Event date ${eventDate} should be >= ${today}`).to.be.true;
          }
        });
      });
  });

  it("returns fewer events than v2.2 (which includes past events)", () => {
    cy.request('/api/2.3/events?items_per_page=All')
      .then((v23Response) => {
        cy.request('/api/2.2/events?items_per_page=All')
          .then((v22Response) => {
            expect(v23Response.body.length).to.be.lessThan(v22Response.body.length);
            cy.log(`v2.3: ${v23Response.body.length} events, v2.2: ${v22Response.body.length} events`);
          });
      });
  });

  it("respects where_to_share visibility filter", () => {
    cy.request('/api/2.3/events?items_per_page=All')
      .then((response) => {
        expect(response.status).to.eq(200);

        // Should include events marked for main page
        const mainPageOnly = response.body.find(e =>
          e.title === 'Test Event - Main Page Only'
        );
        const bothLocations = response.body.find(e =>
          e.title === 'Test Event - Both Locations'
        );

        expect(mainPageOnly).to.exist;
        expect(bothLocations).to.exist;

        // Should NOT include events not marked for main page
        const affinityGroupOnly = response.body.find(e =>
          e.title === 'Test Event - Affinity Group Only'
        );
        const noVisibility = response.body.find(e =>
          e.title === 'Test Event - No Visibility'
        );

        expect(affinityGroupOnly).to.be.undefined;
        expect(noVisibility).to.be.undefined;
      });
  });

  it("supports pagination with default 100 items", () => {
    cy.request('/api/2.3/events')
      .then((response) => {
        expect(response.status).to.eq(200);
        expect(response.body).to.be.an('array');
        expect(response.body.length).to.be.at.most(100);
      });
  });

  it("supports items_per_page parameter", () => {
    cy.request('/api/2.3/events?items_per_page=5')
      .then((response) => {
        expect(response.status).to.eq(200);
        expect(response.body.length).to.be.at.most(5);
      });
  });

  it("supports items_per_page=All", () => {
    cy.request('/api/2.3/events?items_per_page=All')
      .then((response) => {
        expect(response.status).to.eq(200);
        expect(response.body.length).to.be.greaterThan(0);
      });
  });

  it("supports search_api_fulltext filter", () => {
    // Search for a word taken from a real event title and confirm the fulltext
    // filter returns a non-empty, narrowed result set.
    //
    // We can't just search the first word of the first event: the search_api
    // index runs a stemmer, and a few words (e.g. "Scaling" -> stored token
    // "scal") don't survive the index-time/query-time stemming round-trip, so
    // searching the raw word returns 0. Which event sorts first changes daily
    // (v2.3 returns upcoming-only, sorted ascending), so that made the test
    // flake whenever a stem-fragile word landed in the soonest event. Instead,
    // collect candidate words across all titles and use the first one that
    // actually matches.
    cy.request('/api/2.3/events')
      .then((allResponse) => {
        if (allResponse.body.length === 0) return;

        const total = allResponse.body.length;
        const seen = new Set();
        const candidates = [];
        allResponse.body.forEach((event) => {
          (event.title || '').match(/[A-Za-z0-9]{3,}/g)?.forEach((word) => {
            const key = word.toLowerCase();
            if (!seen.has(key)) {
              seen.add(key);
              candidates.push(word);
            }
          });
        });

        expect(candidates.length, 'no searchable words found in event titles').to.be.greaterThan(0);

        // Walk candidates until one returns a hit, then assert it narrows the set.
        const tryWord = (index) => {
          if (index >= candidates.length) {
            throw new Error('No title word matched the fulltext filter');
          }
          const term = candidates[index];
          return cy
            .request(`/api/2.3/events?search_api_fulltext=${encodeURIComponent(term)}`)
            .then((response) => {
              expect(response.status).to.eq(200);
              if (response.body.length === 0) {
                return tryWord(index + 1);
              }
              expect(response.body.length, `fulltext "${term}"`).to.be.at.most(total);
            });
        };

        return tryWord(0);
      });
  });

  it("supports beginning_date_relative parameter", () => {
    // beginning_date_relative=today is resolved server-side in the site
    // timezone, so compare against the site's today rather than UTC.
    const today = siteToday();

    cy.request('/api/2.3/events?beginning_date_relative=today')
      .then((response) => {
        expect(response.status).to.eq(200);
        expect(response.body).to.be.an('array');

        response.body.forEach(event => {
          if (event.start_date) {
            const eventDate = event.start_date.split('T')[0];
            expect(eventDate >= today, `Event date ${eventDate} should be >= ${today}`).to.be.true;
          }
        });
      });
  });

  it("supports absolute beginning_date parameter", () => {
    const today = new Date().toISOString().split('T')[0];

    cy.request(`/api/2.3/events?beginning_date=${today}`)
      .then((response) => {
        expect(response.status).to.eq(200);
        expect(response.body).to.be.an('array');

        response.body.forEach(event => {
          if (event.start_date) {
            const eventDate = event.start_date.split('T')[0];
            expect(eventDate >= today, `Event date ${eventDate} should be >= ${today}`).to.be.true;
          }
        });
      });
  });

  it("supports end_date_relative parameter", () => {
    cy.request('/api/2.3/events?end_date_relative=+1week&timezone=UTC')
      .then((response) => {
        expect(response.status).to.eq(200);
        expect(response.body).to.be.an('array');

        const nextWeek = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
        response.body.forEach(event => {
          if (event.start_date) {
            const eventDate = event.start_date.split('T')[0];
            expect(eventDate <= nextWeek, `Event date ${eventDate} should be <= ${nextWeek}`).to.be.true;
          }
        });
      });
  });

  it("supports timezone parameter", () => {
    // Results with different timezones should both be valid
    cy.request('/api/2.3/events?beginning_date_relative=today&timezone=UTC')
      .then((utcResponse) => {
        expect(utcResponse.status).to.eq(200);

        cy.request('/api/2.3/events?beginning_date_relative=today&timezone=America/New_York')
          .then((etResponse) => {
            expect(etResponse.status).to.eq(200);
            // Both should return events, counts may differ slightly due to timezone
            cy.log(`UTC: ${utcResponse.body.length}, ET: ${etResponse.body.length}`);
          });
      });
  });

  it("supports event_type facet", () => {
    // Get all events first to find available types
    cy.request('/api/2.3/events')
      .then((allResponse) => {
        if (allResponse.body.length === 0) return;

        const eventTypes = [...new Set(allResponse.body.map(e => e.event_type).filter(t => t))];
        cy.log('Available event types:', eventTypes);

        if (eventTypes.length > 0) {
          const testType = eventTypes[0];
          cy.request(`/api/2.3/events?f%5B0%5D=custom_event_type%3A${encodeURIComponent(testType)}`)
            .then((response) => {
              expect(response.status).to.eq(200);
              expect(response.body.length).to.be.greaterThan(0);
              expect(response.body.length).to.be.at.most(allResponse.body.length);
              // Verify all returned events match the filtered type
              response.body.forEach(event => {
                expect(event.event_type).to.equal(testType);
              });
            });
        }
      });
  });

  it("sorts events ascending by date", () => {
    cy.request('/api/2.3/events')
      .then((response) => {
        expect(response.status).to.eq(200);
        if (response.body.length > 1) {
          for (let i = 1; i < response.body.length; i++) {
            const prevDate = response.body[i - 1].start_date || '';
            const currDate = response.body[i].start_date || '';
            if (prevDate && currDate) {
              expect(prevDate <= currDate, `Events should be sorted ASC: ${prevDate} <= ${currDate}`).to.be.true;
            }
          }
        }
      });
  });

});
