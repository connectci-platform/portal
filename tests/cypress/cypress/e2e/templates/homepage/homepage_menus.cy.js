describe("For anonymous & authenticated user, the Menu Items test.", () => {
  beforeEach(() => {
    cy.visit('/');
  });

  it('should display menu items', () => {
    cy.contains('About Us');
    cy.contains('Community');
    cy.contains('Get Help');
    cy.contains('Projects');
  });

  it('should navigate to Get Help page', () => {
    cy.contains('Get Help').click();
    cy.contains('Get Research Computing Help');
  });

  it('should navigate to Projects page', () => {
    cy.contains('Projects').click();
    cy.contains('Project');
  });

  it('should navigate to Tags page', () => {
    // Domain visibility is decided server-side now, so the link no longer
    // carries a hide-ccmnet class to select on. See D8-2795.
    cy.get('.nav-link[href="/tags"]').click();
    cy.contains('Tags');
  });
});

context('Authenticated user', () => {
  beforeEach(() => {
    cy.loginUser("authenticated@amptesting.com", "6%l7iF}6(4tI");
    cy.visit('/');
  });

  it('should display menu items', () => {
    cy.contains('About Us');
    cy.contains('Community');
    cy.contains('Get Help');
    cy.contains('Projects');
  });

  it('should navigate to Get Help page', () => {
    cy.contains('Get Help').click();
    cy.contains('Get Research Computing Help');
  });

  it('should navigate to Projects page', () => {
    cy.contains('Projects').click();
    cy.contains('Project');
  });

  it('should navigate to Tags page', () => {
    // Domain visibility is decided server-side now, so the link no longer
    // carries a hide-ccmnet class to select on. See D8-2795.
    cy.get('.nav-link[href="/tags"]').click();
    cy.contains('Tags');
  });
});

