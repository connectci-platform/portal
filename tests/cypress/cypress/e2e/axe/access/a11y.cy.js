// Helper function to check accessibility for a given URL
const checkA11y = (url) => {
  cy.visit(url);
  cy.injectAxe();
  cy.a11yCheckWithReport({
    exclude: [
      [
        ['#universal-menus'],
        ['#header'],
        ['#site-menus']
      ]
    ]
  });
};

// Helper function to check multiple URLs in a single test
const checkMultipleUrls = (urls) => {
  urls.forEach(url => checkA11y(url));
};

describe('Accessibility Testing - reports all violations without failing CI (initial phase)', () => {
  it('home', () => {
    checkA11y('/');
  });

  it('announcements', () => {
    checkMultipleUrls([
      '/announcements',
      '/announcements/tacc-status-saturday-september-27-2025'
    ]);
  });

  it('events', () => {
    checkMultipleUrls([
      '/events',
      '/events/8482'
    ]);
  });

  it('Outages', () => {
    checkA11y('/outages');
  });

  it('Community', () => {
    checkA11y('/community/overview');
  });

  it('Documentation', () => {
    checkMultipleUrls([
      '/documentation',
      '/documentation/resources/anvil-cpu',
      '/documentation/resources/jetstream2-gpu',
      '/documentation/resources/aces-0',
      '/documentation/schedulers'
    ]);
  });

  it('Affinity Groups', () => {
    checkMultipleUrls([
      '/affinity-groups',
      '/affinity-groups/anvil'
    ]);
  });

  it('CSSN', () => {
    checkMultipleUrls([
      '/community/cssn',
      '/community/cssn/directory',
      '/community-persona/100',
      '/community/badges',
      '/community/scipe'
    ]);
  });

  it('Workforce Dev Workshops', () => {
    checkA11y('/ccep-workforce-development-funded-workshops');
  });

  it('Knowledge Base Overview', () => {
    checkMultipleUrls([
      '/knowledge-base/overview',
      '/knowledge-base/resources'
    ]);
  });

  it('RP Documentation', () => {
    checkMultipleUrls([
      '/documentation/resources',
      '/documentation/resources/anvil-ai'
    ]);
  });

  it('RP Documentation admin pages', () => {
    cy.exec('ddev drush user:role:add rp_documentation_manager "authenticated_test_user"', { failOnNonZeroExit: false });
    cy.loginAs('authenticated@amptesting.com', '6%l7iF}6(4tI');
    checkMultipleUrls([
      '/rp-resources/manage',
      '/rp-groups/manage',
      '/node/add/resource_group'
    ]);

    // Resource and resource-group edit forms — navigate via the manage view
    // since node IDs aren't stable across DB artifact rebuilds.
    cy.visit('/rp-resources/manage');
    cy.get('table tbody tr').first().within(() => { cy.contains('Edit').click(); });
    cy.url().should('include', '/edit');
    cy.injectAxe();
    cy.a11yCheckWithReport({ exclude: [[['#universal-menus'], ['#header'], ['#site-menus']]] });

    cy.visit('/rp-groups/manage');
    cy.get('table tbody tr').first().within(() => { cy.contains('Edit').click(); });
    cy.url().should('include', '/edit');
    cy.injectAxe();
    cy.a11yCheckWithReport({ exclude: [[['#universal-menus'], ['#header'], ['#site-menus']]] });

    cy.exec('ddev drush user:role:remove rp_documentation_manager "authenticated_test_user"', { failOnNonZeroExit: false });
  });

  it('Find', () => {
    checkA11y('/find');
  });

  it('Tags', () => {
    checkA11y('/tags');
  });

  it('Video Learning Center', () => {
    checkA11y('/video-learning-center');
  });

  it('MATCH Services', () => {
    checkA11y('/match/overview');
  });

  it('ACCESS and NAIRR Pilot Office Hours', () => {
    checkA11y('/access-and-nairr-pilot-office-hours');
  });

  it('ACCESS Tools', () => {
    checkA11y('/tools/overview');
    checkA11y('/tools/researcher-stories');
  });

  it('OnDemand', () => {
    checkA11y('/tools/ondemand');
  });

  it('Pegasus Workflows', () => {
    checkA11y('/tools/pegasus');
  });

  it('Science Gateways', () => {
    checkA11y('/tools/science-gateways');
  });

  it('XDMoD', () => {
    checkA11y('/tools/xdmod');
  });

  it('open a help ticket', () => {
    checkA11y('/help-ticket');
  });

  it('create an announcement', () => {
    cy.loginAs('pecan@pie.org', 'Pecan');
    checkA11y('/node/add/access_news');
  });

  it('create an event', () => {
    cy.loginAs('pecan@pie.org', 'Pecan');
    checkA11y('/events/add');
  });

  it('request an affinity group', () => {
    cy.loginAs('pecan@pie.org', 'Pecan');
    checkA11y('/events/add/default');
  });

  it('add a KB resource', () => {
    cy.loginAs('pecan@pie.org', 'Pecan');
    checkA11y('/form/resource');
  });

  it('request an engagement', () => {
    cy.loginAs('pecan@pie.org', 'Pecan');
    checkA11y('/node/add/match_engagement?type=plus');
  });

  it('Edit Community Persona', () => {
    cy.loginAs('pecan@pie.org', 'Pecan');
    checkA11y('/user/edit');
  });

});
