describe("Authenticated user creates a Mentorship Engagement", () => {
  it("Authenticated user creates a Recruiting Mentorship Engagement", () => {
    cy.loginWith("pecan@pie.org", "Pecan")
    cy.visit("/node/add/mentorship_engagement")

    // check the box for "I am looking for a mentor"
    cy.get("#edit-field-me-looking-for-mentor").check()

    cy.get("#edit-title-0-value").type("Recruiting Title")

    cy.get("#edit-body-0-summary").type("Recruiting Summary")

    cy.get('.form-item-body-0-value .ck-content').then(el => {
      const editor = el[0].ckeditorInstance
      editor.setData('Mentoring supports the growth of a vibrant HPC community by connecting students with experienced mentors. Matches are based on multiple factors, including research interests, career goals, long-term plans, and general interests. Providing valuable professional advice and resources that will benefit mentees which might include: sharing expertise in your specific research domain/discipline, sharing personal experiences and advice that might benefit your student mentee, encouraging students to stay abreast of scholarly literature and cutting-edge ideas in their field, Encouraging the open exchange of ideas, Facilitating networking opportunities with other faculty or professionals on campus or within the broader research community as appropriate.')
    })

    cy.get("details.tags summary").click()
    cy.get("#tag-ai").click()

    cy.get('.form-item-field-me-preferred-attributes-0-value .ck-content').then(el => {
      const editor = el[0].ckeditorInstance
      editor.setData('Be nice.')
    })

    cy.get('#edit-field-me-state').select('Recruiting')

    cy.get(".node-mentorship-engagement-form #edit-submit").click()
    cy.get('.layout__region--second > :nth-child(2)').should('contain', 'Pecan Pie')
  });

  it("Admin approves", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit("/admin/content");
    cy.get(':nth-child(1) > .views-field-operations > .dropbutton-wrapper > .dropbutton-widget > .dropbutton > .edit > a').click();
    cy.get('#edit-field-ccmnet-approved-value').scrollIntoView().check({force: true});
    cy.get('.node-mentorship-engagement-edit-form #edit-submit').click();
  });

  it("Authenticated user creates a Recruiting Mentorship Engagement", () => {
    cy.loginWith("pecan@pie.org", "Pecan")
    cy.visit("/node/add/mentorship_engagement")

    // check the box for "I am looking for a mentor"
    cy.get("#edit-field-me-looking-for-mentee").check()

    cy.get("#edit-title-0-value").type("In Progress Title")

    cy.get("#edit-body-0-summary").type("In ProgressSummary")

    cy.get('.form-item-body-0-value .ck-content').then(el => {
      const editor = el[0].ckeditorInstance
      editor.setData('Mentoring supports the growth of a vibrant HPC community by connecting students with experienced mentors. Matches are based on multiple factors, including research interests, career goals, long-term plans, and general interests. Providing valuable professional advice and resources that will benefit mentees which might include: sharing expertise in your specific research domain/discipline, sharing personal experiences and advice that might benefit your student mentee, encouraging students to stay abreast of scholarly literature and cutting-edge ideas in their field, Encouraging the open exchange of ideas, Facilitating networking opportunities with other faculty or professionals on campus or within the broader research community as appropriate.')
    })

    cy.get("details.tags summary").click()
    cy.get("#tag-ai").click()

    cy.get('.form-item-field-me-preferred-attributes-0-value .ck-content').then(el => {
      const editor = el[0].ckeditorInstance
      editor.setData('Be nice.')
    })

    cy.get('#edit-field-me-state').select('In Progress')

    cy.get(".node-mentorship-engagement-form #edit-submit").click()
    cy.get('.layout__region--second > :nth-child(2)').should('contain', 'Pecan Pie')
  });

  it("Admin approves", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit("/admin/content");
    cy.get(':nth-child(1) > .views-field-operations > .dropbutton-wrapper > .dropbutton-widget > .dropbutton > .edit > a').click();
    cy.get('#edit-field-ccmnet-approved-value').scrollIntoView().check({force: true});
    cy.get('.node-mentorship-engagement-edit-form #edit-submit').click();
  });

  it("Check Mentorships page as authenticated user", () => {
    cy.loginWith("pecan@pie.org", "Pecan")
    cy.visit("/mentorships");

    // check 'In Progress' filter (requires authentication). Each toggle fires a
    // Facets AJAX re-render that rebuilds the checkbox list, so wait for it to
    // settle (and use force) before touching the next facet — otherwise the
    // next checkbox can detach mid-action. Matches the affinity-groups facet
    // specs' established cy.wait pattern.
    cy.get('#state-829').should('exist').check({ force: true });
    cy.wait(1000);
    cy.contains('In Progress Title');
    cy.contains('Recruiting Title').should('not.exist');

    // uncheck 'In Progress' filter and check 'Recruiting' filter.
    cy.get('#state-829').uncheck({ force: true });
    cy.wait(1000);
    cy.get('#state-827').should('exist').check({ force: true });
    cy.wait(1000);
    cy.contains('Recruiting Title');
    cy.contains('In Progress Title').should('not.exist');
  });

  it("Tests Campus Champions mentorship filter functionality as authenticated user", () => {
    // Login first since program filters require authentication
    cy.loginWith("pecan@pie.org", "Pecan")
    
    // Go to CCMNet mentorships page with Campus Champions filter
    cy.visit("/mentorships?f%5B0%5D=mentorship_program%3A910");
    
    // Check that we have the Campus Champions filter applied
    cy.url().should('include', 'f%5B0%5D=mentorship_program%3A910');
    
    // Verify that the mentorships page loads
    cy.get('h1, .page-title').should('exist');
    
    // Verify mentorships content area exists (may be empty if no matching items)
    cy.get('body').should('contain.text', 'mentorships');
  });

});
