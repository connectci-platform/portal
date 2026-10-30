/*
    Checks the hero search on the ACCESS Support homepage (/support-home): the
    Ask form attributes, empty-submit behavior, that submitting sends the typed
    question to the configured URL as q, and that each suggested-prompt chip
    links to that same URL with its own text as q. The target URL is
    configurable, so it is always read from the form's action attribute.
*/
describe("Support homepage hero", () => {
  // Matches the action URL plus any query string, without relying on globs.
  const actionMatcher = (action) => {
    const escaped = action.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    return new RegExp("^" + escaped + "(\\?.*)?$");
  };

  beforeEach(() => {
    cy.visit("/support-home");
  });

  it("Should have a required q input and submit with GET", () => {
    cy.get(".homepage-hero__search").should("have.attr", "method", "get");
    cy.get(".homepage-hero__search input[name='q']")
      .should("have.attr", "required")
      .and("not.be.undefined");
    cy.get(".homepage-hero__search input[name='q']").should(
      "have.attr",
      "maxlength",
      "500",
    );
  });

  it("Should do nothing when Ask is clicked with an empty box", () => {
    // /support-home may redirect (e.g. to / when it is the front page), so
    // compare against wherever the visit landed.
    cy.location("pathname").then((pathname) => {
      cy.get(".homepage-hero__search input[name='q']").should("have.value", "");
      cy.get(".homepage-hero__search button[type='submit']").click();
      cy.location("pathname").should("eq", pathname);
      cy.location("search").should("eq", "");
    });
  });

  it("Should send the typed question to the configured URL as q", () => {
    const question = "What is an allocation? Cost & time";

    cy.get(".homepage-hero__search")
      .invoke("attr", "action")
      .then((action) => {
        const actionUrl = new URL(action);
        cy.intercept(
          { method: "GET", url: actionMatcher(action) },
          { statusCode: 200, body: "ok" },
        ).as("ask");

        cy.get(".homepage-hero__search input[name='q']").type(question);
        cy.get(".homepage-hero__search button[type='submit']").click();

        cy.wait("@ask").then(({ request }) => {
          const url = new URL(request.url);
          expect(url.origin + url.pathname).to.eq(
            actionUrl.origin + actionUrl.pathname,
          );
          expect(url.searchParams.get("q")).to.eq(question);
        });
      });
  });

  it("Should link each suggested prompt to the configured URL with its text as q", () => {
    cy.get(".homepage-hero__search")
      .invoke("attr", "action")
      .then((action) => {
        const actionUrl = new URL(action);

        cy.get(".homepage-hero__prompts a")
          .should("have.length.at.least", 1)
          .each(($a) => {
            const url = new URL($a.attr("href"), actionUrl);
            expect(url.origin + url.pathname).to.eq(
              actionUrl.origin + actionUrl.pathname,
            );
            expect(url.searchParams.get("q")).to.eq($a.text().trim());
            expect($a.attr("target")).to.be.undefined;
          });
      });
  });
});
