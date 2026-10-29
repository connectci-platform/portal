describe("test an Affinity Group page", () => {
  it("Admin user adds a CI Link to the AG ACCESS Support", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit('/node/327/edit');
    cy.contains('Edit Affinity Group ACCESS Support');
    //cy.get('input[name="field_resources_entity_reference[0][target_id]"]').type('ci-link-for-user-200'); // this doesn't exit in dev db
    cy.get('input[name="field_group_slug[0][value]"]').type('access-support');
    cy.get('input[name="op"]').contains('Save').click();
    cy.contains('Affinity Group ACCESS Support has been updated.');

    // Should this be created in another test? CI link tests maybe?
    //cy.contains('ci-link-for-user-200').click();
    //cy.contains('ci-link-for-user-200');
  });

  it("Unauthenticated user tests an individual Affinity Group page", () => {
    cy.visit('/affinity-groups/cloud-computing');
    cy.get('img[alt="A blue cloud"]').should('be.visible');
    cy.contains('People who use or are considering the use of cloud resources');
    cy.contains('cloud-commercial').click();
    cy.url().should('include', '/tags/cloud-commercial');

    cy.visit('/affinity-groups/cloud-computing');
    cy.contains('People who use or are considering the use of cloud resources');
    cy.get('.affinity-group-buttons .btn:nth-child(1)').should('have.attr', 'disabled', 'disabled');
    cy.get('a').contains('Slack').should('have.attr', 'href', 'https://campuschampions.slack.com');
    cy.get('a').contains('Q&A').should('have.attr', 'href', 'https://ask.cyberinfrastructure.org/c/cloud-computing/66');
    cy.get('a').contains('Email').should('have.attr', 'href', 'mailto:jfossot@ncsu.edu');
    cy.contains('Coordinators');
    cy.contains('Jacob Fosso Tande').click();
    cy.url().should('include', '/community-persona/4295');
    cy.contains('research-facilitation');
    cy.contains('Affinity Groups');
    cy.visit('/affinity-groups/cloud-computing');
    cy.get('a').contains('How is storage performance for high I/O HPC jobs affected by running in the cloud?').should('have.attr', 'href', 'https://ask.cyberinfrastructure.org/t/how-is-storage-performance-for-high-i-o-hpc-jobs-affected-by-running-in-the-cloud/797');
    cy.get('a').contains('View on Ask.CI').should('have.attr', 'href', 'https://ask.cyberinfrastructure.org/c/cloud-computing/66');
    cy.contains('View Members').should('not.exist');
  });

  it("Unauthenticated user tests another AG, with a CI dummy_ci_link and a Ci Topic and an Event", () => {
    cy.visit('/affinity-groups/access-support');
    cy.contains('Events');
    cy.get('a').contains('Who can get an allocation to use HPC resources through ACCESS?').should('have.attr', 'href').and('include', '/t/who-can-get-an-allocation-to-use-hpc-resources-through-access/3019');
  });

  it("Unauthenticated user tests an AG with an announcement", () => {
    cy.visit('/affinity-groups/anvil');
    cy.contains('News');
    cy.contains('08/13/25');
    cy.contains('Anvil AI now available to ACCESS researchers!').click();
    cy.url().should('include', '/announcements/anvil-ai-now-available-access-researchers');
  });

  // it("Unauthenticated user tests an AG with a github link", () => {
  //   cy.visit('/affinity-groups/open-ondemand');
  //   cy.get('a').contains('GitHub').should('have.attr', 'href', 'https://github.com/OSC/ondemand');
  // });

  it("Unauthenticated user tests another AG with recommended resources", () => {
    cy.visit('/affinity-groups/ai-institutes-cyberinfrastructure');
    cy.contains('Recommended Resources');
    cy.contains('Delta GPU');
    cy.contains('The Delta GPU resource comprises 4 different node configurations').should('not.be.visible');
    cy.get('button').contains('Delta GPU').click();
    cy.wait(300);
    cy.contains('The Delta GPU resource comprises 4 different node configurations');
    cy.get('button').contains('Delta GPU').click();
    cy.wait(300);
    cy.contains('The Delta GPU resource comprises 4 different node configurations').should('not.be.visible');
  });

  it("Unauthenticated user tests another AG with Allocated CiDeR Resources", () => {
    cy.visit('/affinity-groups/delta');
    cy.get('img[alt="Delta ACCESS Affinity Group logo"]').should('be.visible');
    cy.contains('DELTA is a dedicated, ACCESS-allocated resource designed by HPE and NCSA');
    cy.contains('Associated Resources');
    cy.contains('Delta GPU');
    cy.contains('The Delta GPU resource comprises 5 different node configurations').should('not.be.visible');
    cy.get('button').contains('Delta GPU').click();
    cy.wait(300);
    cy.contains('The Delta GPU resource comprises 5 different node configurations');
    cy.get('button').contains('Delta GPU').click();
    cy.wait(300);
    cy.contains('The Delta GPU resource comprises 5 different node configurations').should('not.be.visible');
  });

  it("Unauthenticated user tests Ask.CI Recent Topics", () => {
    cy.visit('/affinity-groups/anvil');
    cy.contains('Ask.CI Recent Topics');
    cy.get('a').contains('About the ACCESS-Anvil category').should('have.attr', 'href', 'https://ask.cyberinfrastructure.org/t/about-the-access-anvil-category/2473');
  });

  it("Authenticated user can join & leave an AG", () => {
    cy.loginUser("authenticated@amptesting.com", "6%l7iF}6(4tI");
    cy.visit('/affinity-groups/cloud-computing');
    cy.get('.flag > .btn').contains('Join').click();
    cy.wait(500);
    cy.contains('You have joined this affinity group');
    cy.wait(500);
    cy.get('.flag > .btn').contains('Leave').click();
    cy.contains('You have left this affinity group');
  });

  it("AG Coordinator can see & download & email members", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit('/affinity-groups/cloud-computing');
    cy.contains('View Members').click();
    cy.url().should('include', '/affinity-groups/571/users/Cloud%20Computing?nid=189');
    cy.contains('Download CSV');
    cy.contains('Download CSV').click();
    cy.request('/affinity-groups/571/users/Cloud%20Computing?nid=189').its('status').should('eq', 200);
  });

  it('should navigate to Email Affinity Group page and verify elements', () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit('/affinity-groups/cloud-computing');
    cy.contains('Email Affinity Group').click();
    cy.contains('Use this form to send an email message to members of your Affinity Group through Constant Contact');
    cy.get('input[name="op"]').should('have.value', 'Send');
  });

});
