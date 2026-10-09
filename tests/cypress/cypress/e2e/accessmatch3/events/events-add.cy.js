/*
    This test is specifically focused on the Events form tested for an authenticated user.
    This test checks for major functions like:
    Page Title,
    Header text,
    Form Functionality
    The "My Events" page (/events/mine) listing and search for the events created here

*/

// The exposed "Search My Events" title filter on /events/mine
const MY_EVENTS_SEARCH = '[data-drupal-selector="edit-title"]';

describe("Authenticated user tests the Events Form without Affinity Group", () => {
  it("Should test Events Form for authenticated user", () => {
    cy.clearMailpit();

    // login user with the "authenticated" role
    cy.loginAs("administrator@amptesting.com", "b8QW]X9h7#5n");
    cy.visit("/");
    cy.get(':nth-child(1) > .views-field-title-2 > .field-content > .block')
    cy.visit("/events/add");

    // Page Title
    cy.get("h1").contains("Create Community Event");

    // Body
    cy.get('.field--name-body .ck-content').then(el => {
      const editor = el[0].ckeditorInstance
      editor.setData('ipsum dolor sit amet, consectetur adipiscing elit. Donec a diam lectus, consequat semper nisi. Quisque congue porttitor ullamcorper. Aliquam nunc dui, tincidunt quis accumsan et, porttitor at sem. Aliquam erat volutpat. Sed nisi nunc, finibus in sodales ut, placerat in libero. Sed a efficitur ligula. Donec efficitur, enim nec fringilla facilisis, enim est vehicula ligula, id gravida augue felis ac nunc.')
    })

    // summary
    cy.get('#edit-summary-text').type('Lorem ipsum dolor sit amet.', { delay: 0 })

    // User filling out form title
    cy.get("#edit-title-0-value").type("cypress-example-event", { delay: 0 });

    // Date and Time of Event
    cy.get("#edit-recur-type-custom").click();
    cy.get("#custom-date-values > thead > tr > .field-label").contains(
      "Custom Date(s) and Time(s)"
    );
    cy.get("#edit-custom-date-0-value-date").type("2026-12-12", { delay: 0 });
    cy.get("#edit-custom-date-0-end-value-date").type("2027-12-12", { delay: 0 });
    cy.get("#edit-custom-date-0-value-time").type("04:30:00", { delay: 0 });
    cy.get("#edit-custom-date-0-end-value-time").type("04:30:00", { delay: 0 });

    // Event Location
    cy.get("#edit-field-location-0-value").type("Zoom", { delay: 0 });

    // Event Contact
    cy.get("#edit-field-contact-0-value").type("Pecan Pie", { delay: 0 });

    // Event Tag
    cy.get("details.tags summary").click().get("#tag-login").click();

    // Registration
    cy.get('input[data-drupal-selector="edit-event-registration-0-registration"]').check();
    cy.get("#edit-event-registration-0-capacity").type("1", { delay: 0 });
    cy.get('input[data-drupal-selector="edit-event-registration-0-waitlist"]').check();

    //Save As Selection
    cy.get("#edit-moderation-state-0-state").select("Published");

    //Event Type
    cy.get("#edit-field-event-type-training").click();

    //Event Affiliation
    cy.get("#edit-field-affiliation-community").click();

    //Event Skill Level
    cy.get("#edit-field-skill-level-advanced").click();

    cy.screenshot('before-submit');
    cy.get("#edit-submit").click();
    cy.screenshot('after-submit');

    // Check for content published message.
    // Note: site_mail is disabled, so email has no TO field and author is in BCC
    cy.waitForEmail({
      subject: 'Your Event was published'
    }).then((message) => {
      cy.assertEmailContent(message, {
        subject: 'Your Event was published',
        from: 'noreply@mg.support.access-ci.org',
      });
    });

  });

  it("Should create a simple event for anonymous user testing", () => {
    cy.clearMailpit();

    // login user with the "administrator" role
    cy.loginAs("administrator@amptesting.com", "b8QW]X9h7#5n");
    cy.visit("/events/add");

    // Page Title
    cy.get("h1").contains("Create Community Event");

    // Body
    cy.get('.field--name-body .ck-content').then(el => {
      const editor = el[0].ckeditorInstance
      editor.setData('This is a simple test event visible to anonymous users.')
    })

    // summary
    cy.get('#edit-summary-text').type('Simple test event for anonymous users.', { delay: 0 })

    // User filling out form title
    cy.get("#edit-title-0-value").type("cypress anonymous test event", { delay: 0 });

    // Date and Time of Event
    cy.get("#edit-recur-type-custom").click();
    cy.get("#edit-custom-date-0-value-date").type("2026-11-15", { delay: 0 });
    cy.get("#edit-custom-date-0-end-value-date").type("2026-11-15", { delay: 0 });
    cy.get("#edit-custom-date-0-value-time").type("10:00:00", { delay: 0 });
    cy.get("#edit-custom-date-0-end-value-time").type("11:00:00", { delay: 0 });

    // Event Location
    cy.get("#edit-field-location-0-value").type("Online", { delay: 0 });

    // Event Contact
    cy.get("#edit-field-contact-0-value").type("Test Contact", { delay: 0 });

    // Event Tag
    cy.get("details.tags summary").click().get("#tag-training").click();

    // Registration
    cy.get('input[data-drupal-selector="edit-event-registration-0-registration"]').check();
    cy.get("#edit-event-registration-0-capacity").type("50", { delay: 0 });

    //Save As Selection
    cy.get("#edit-moderation-state-0-state").select("Published");

    //Event Type - Conference (should be visible to anonymous users)
    cy.get("#edit-field-event-type-conference").click();

    // Skip affiliation and skill level to test basic visibility
    
    cy.get("#edit-submit").click();

    // Check for content published message.
    // Note: site_mail is disabled, so email has no TO field and author is in BCC
    cy.waitForEmail({
      subject: 'Your Event was published'
    }).then((message) => {
      cy.assertEmailContent(message, {
        subject: 'Your Event was published',
        from: 'noreply@mg.support.access-ci.org',
      });
    });
  });

  it("Should list the created events on My Events and filter them by title", () => {
    // login as the user who created the events above
    cy.loginAs("administrator@amptesting.com", "b8QW]X9h7#5n");
    cy.visit("/events/mine");

    // Event instances may lag indexing after save, so retry until the row shows up
    cy.get("h1").should("exist");
    cy.contains("a", "cypress-example-event", { timeout: 15000 })
      .should("have.attr", "href")
      .and("match", /^\/events\//);

    // A search with no matches leaves the view empty. The view defines no
    // empty-state text, so assert that no event rows remain.
    cy.get(MY_EVENTS_SEARCH).should("have.attr", "placeholder", "Search My Events");
    cy.searchAndWait(MY_EVENTS_SEARCH, "Random string");
    cy.contains("cypress-example-event").should("not.exist");
    cy.get("table tbody tr").should("not.exist");

    // Searching by title shows the event again
    cy.clearSearchAndWait(MY_EVENTS_SEARCH);
    cy.searchAndWait(MY_EVENTS_SEARCH, "example");
    cy.contains("a", "cypress-example-event", { timeout: 15000 })
      .should("have.attr", "href")
      .and("match", /^\/events\//);
  });

  it("Should show an empty My Events page for a user who has created no events", () => {
    // authenticated@amptesting.com only views events in the other specs
    cy.loginAs("authenticated@amptesting.com", "6%l7iF}6(4tI");
    cy.visit("/events/mine");

    cy.get(MY_EVENTS_SEARCH).should("exist");
    cy.contains("cypress-example-event").should("not.exist");
    cy.contains("cypress anonymous test event").should("not.exist");
    cy.get("table tbody tr").should("not.exist");
  });
});
