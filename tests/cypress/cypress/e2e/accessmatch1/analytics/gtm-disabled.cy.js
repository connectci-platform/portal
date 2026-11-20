/*
    D8-3118: Google Tag Manager (GTM-5XV3K7T) must load only on Pantheon live.
    This test runs against a non-live environment (DDEV, CI, multidev), so it
    proves the settings.php override that disables gtm.settings is in effect.
*/

describe("Anonymous user checks that Google Tag Manager is disabled outside live", () => {
  it("Should not include the GTM script or container ID on the home page", () => {
    cy.request("/").then((response) => {
      expect(response.status).to.eq(200);
      expect(response.body).to.not.include("googletagmanager.com");
      expect(response.body).to.not.include("GTM-5XV3K7T");
    });
  });
});
