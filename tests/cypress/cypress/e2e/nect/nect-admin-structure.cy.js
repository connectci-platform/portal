describe("Test Structure admin page", () => {
  it("check block layout", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/structure/block');
    cy.get('h1').should('contain', 'Block layout');

    // Nect Block check - truncated for memory
    cy.contains('Main navigation');
    cy.contains('Search form');
    cy.contains('User account menu');
    cy.contains('Page title');
    cy.contains('Add Organization');
    cy.contains('Messages');
    cy.contains('Status messages');
    cy.contains('request tag button');
    cy.contains('Affinity Group intro text - NECT/CampusChampions');
    cy.contains('Tabs');
    cy.contains('KB Resources Intro block');
    cy.contains('Tags subtitle');
    cy.contains('ccmnet Mentorship Resources header (/knowledge-base/resources/mentorship)');
    cy.contains('Exposed form: tags-page_1');
    cy.contains('Projects - CAREERS');
    cy.contains('Projects - Great Plains');
    cy.contains('Projects - Kentucky');
    cy.contains('Projects - MINES');
    cy.contains('Projects - Northeast');

    // Campus Champions block check - truncated for memory
    cy.visit('/admin/structure/block/list/champions');
    cy.contains('CC - Promotional message');
    cy.contains('Main navigation');
    cy.contains('CC Main menu');
    cy.contains('User account menu');
    cy.contains('Status messages');
    cy.contains('Page title');
    cy.contains('Project Submissions - At Large');
    cy.contains('Project Submissions - CAREERS');
    cy.contains('Project Submissions - Great Plains');
    cy.contains('Project Submissions - Kentucky');
    cy.contains('Project Submissions - MINES');
    cy.contains('Project Submissions - Northeast');
    cy.contains('User Profile: User Profile');
    cy.contains('Events');
    cy.contains('CC - Front - Quick Links');
    cy.contains('CC - Front - Tackling Grand Challenges');
    cy.contains('CC - Front - Become a Champion');
    cy.contains('CC - Front - Find other champions');
    cy.contains('CC - Front - Community');

    // Access support blocks
    cy.visit('/admin/structure/block/list/aspTheme');
    cy.get(':nth-child(4) > .tabs__link').click();
    cy.contains('Breadcrumbs');
    cy.contains('Status messages');
    cy.contains('Masquerade');
    cy.contains('CCEP CTA');
    cy.contains('Home CTA');
    cy.contains('MATCH CTA');
    cy.contains('OnDemand CTA');
    cy.contains('Pegasus CTA');
    cy.contains('Science Gateways CTA');
    cy.contains('Video Learning CTA');
    cy.contains('XDMoD CTA');
    cy.contains('cssn: banner CTA');
    cy.contains('Page title');
    cy.contains('KB Resources Intro block');
    cy.contains('asp-theme: /affinity-groups description');
    cy.contains('ASP Quick Links');
    cy.contains('Interests Description');
    cy.contains('Skills Description');
    cy.contains('Interests Description');
    cy.contains('Tabs');
    cy.contains('Ticketing choices');
    cy.contains('Exposed form: tags-page_1');
    cy.contains('Tags subtitle');
    cy.contains('User login');
    cy.contains('cssn: description/links');
    cy.contains('Main page content');
    cy.contains('match engagement view: Plus Engagements Block');
    cy.contains('Outages block');
    cy.contains('Affinity Group: Affinity Group - link to users');
    cy.contains('Exposed form: cssn_directory-page_1');
    cy.contains('Exposed form: affinity_group_search-page_1');
    cy.contains('Affinity Groups with Tag');
    cy.contains('Community persona block');
    cy.contains('resource upvote');
    cy.contains('Exposed form: search_ci_links-page_1');
    cy.contains('Topics');
    cy.contains('Programming Language');
    cy.contains('Science Domain');
    cy.contains('Skill Level');
    cy.contains('Content Type');
    cy.contains('Exposed form: cssn_directory-page_1');
    cy.contains('Roles');
    cy.contains('Skills');
    cy.contains('Category');
    cy.contains('Organization');
    cy.contains('Tags');
    cy.contains('Node add tags: sidebar block: tag parents');
    cy.contains('request tag button');
  });

  it("Block types, comment types", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/structure/block-content');
    cy.get('h1').should('contain', 'Block types');
    cy.contains('Basic');

    cy.visit('/admin/structure/comment');
    cy.get('h1').should('contain', 'Comment types');
    cy.contains('Article comment');
    cy.contains('Basic page comment');
    cy.contains('Default comments');
  });

  it("Contact forms", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/structure/contact');
    cy.get('h1').should('contain', 'Contact forms');

    cy.contains('Campus Champions');
    cy.contains('CAREERS Cyberteam');
    cy.contains('CoCo');
    cy.contains('Connect ci');
    cy.contains('Contact ACCESS Support');
    cy.contains('Contact Us');
    cy.contains('Great Plains Cyberteam');
    cy.contains('Kentucky Cyberteam');
    cy.contains('MINES Cyberteam');
    cy.contains('Northeast Cyberteam');
    cy.contains('Northeast Cyberteam');
    cy.contains('Personal contact form');
    cy.contains('USRSE');
    cy.contains('Website feedback');
  });

  it("Content types", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/structure/types');
    cy.get('h1').should('contain', 'Content types');

    cy.contains('ACCESS Active Resources from CiDeR');
    cy.contains('Announcement');
    cy.contains('ACCESS Organization');
    cy.contains('Affinity Group');
    cy.contains('Article');
    cy.contains('Basic page');
    cy.contains('Blog post');
    cy.contains('Blog post (region)');
    cy.contains('Community Events');
    cy.contains('Infrastructure News');
    cy.contains('MATCH Engagement');
    cy.contains('Mentorship Engagement');
    cy.contains('Organization');
    cy.contains('PeopleCardView');
    cy.contains('PeopleListView');
    cy.contains('Press Release');
    cy.contains('Published Articles');
    cy.contains('Recommended Resource from CiDeR');
    cy.contains('Regional About');
    cy.contains('Webform');
  });

  it("Display modes", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/structure/display-modes');
    cy.get('h1').should('contain', 'Display modes');
    cy.contains('Form modes');
    cy.contains('View modes');

    cy.visit('/admin/structure/display-modes/form');
    cy.get('h1').should('contain', 'Form modes');
    cy.contains('Media library');
    cy.contains('CampusChampionsAdmin');
    cy.contains('Register');

    cy.visit('/admin/structure/display-modes/view');
    cy.get('h1').should('contain', 'View modes');

    cy.contains('Affinity Group');
    cy.contains('alt_teaser');
    cy.contains('Full');
    cy.contains('match engagement view mode');
    cy.contains('RSS');
    cy.contains('Search index');
    cy.contains('Search result highlighting input');
    cy.contains('Teaser');
    cy.contains('Token');
    cy.contains('Full');
    cy.contains('Token');

  });

  it("Events", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/structure/events');
    cy.get('h1').should('contain', 'Events Management');

    cy.visit('/admin/structure/events/series/settings');
    cy.get('h1').should('contain', 'Event Series Settings');

    cy.visit('/admin/structure/events/series/types');
    cy.get('h1').should('contain', 'Event Series types');

    cy.visit('/admin/structure/events/instance/settings');
    cy.get('h1').should('contain', 'Event Instance Settings');

    cy.visit('/admin/structure/events/instance/types');
    cy.get('h1').should('contain', 'Event Instance types');

    cy.visit('/admin/structure/events/orphaned-instances');
    cy.get('h1').should('contain', 'Orphaned Event Instances');

  });

  it("Events", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/structure/feeds');
    cy.get('h1').should('contain', 'Feed types');

    cy.contains('ACCESS Active Resources from CiDeR Feed');
    cy.contains('Infrastructure News');
  });

  it("Field Inheritance", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/structure/field_inheritance');
    cy.get('h1').should('contain', 'Field inheritance entities');

    cy.contains('eventinstance_default_affinity_group');
    cy.contains('eventinstance_default_affinity_group_node_ref');
    cy.contains('eventinstance_default_contact');
    cy.contains('eventinstance_default_description');
    cy.contains('eventinstance_default_event_affiliation');
    cy.contains('eventinstance_default_event_type');
    cy.contains('eventinstance_default_location');
    cy.contains('eventinstance_default_registration');
    cy.contains('eventinstance_default_skill_level');
    cy.contains('eventinstance_default_speakers');
    cy.contains('eventinstance_default_tags');
    cy.contains('eventinstance_default_title');

    cy.visit('/admin/structure/field_inheritance/settings');
    cy.get('h1').should('contain', 'Settings');
  });

  it("Flags", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/structure/flags');
    cy.get('h1').should('contain', 'Flags');
    cy.contains('Completed');
    cy.contains('Affinity Group');
    cy.contains('Inaccurate');
    cy.contains('Interest');
    cy.contains('Interested in Project');
    cy.contains('Not Useful');
    cy.contains('Outdated');
    cy.contains('Skill');
    cy.contains('Upvote');
  });

  it("Media Types", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/structure/media');
    cy.get('h1').should('contain', 'Media types');

    cy.contains('Audio');
    cy.contains('Document');
    cy.contains('Image');
    cy.contains('Remote video');
    cy.contains('Video');
  });

  it("Menus", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/structure/menu');
    cy.get('h1').should('contain', 'Menus');

    cy.contains('ACCESS Additional Menu');
    cy.contains('ACCESS Additional Universal Menu');
    cy.contains('ACCESS Secondary Menu');
    cy.contains('ACCESS Universal Nav Menu');
    cy.contains('CC Main menu');
    cy.contains('Footer');
    cy.contains('Main menu');
    cy.contains('Management');
    cy.contains('Navigation');
    cy.contains('User menu');
  });

  it("Taxonomy", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/structure/taxonomy');
    cy.get('h1').should('contain', 'Taxonomy');

    cy.contains('Affinity Groups');
    cy.contains('Badges');
    cy.contains('Organization Type');
    cy.contains('Region');
    cy.contains('Regional Experts');
    cy.contains('Skill Level');
    cy.contains('State');
    cy.contains('Tags');
    cy.contains('Track');

  });

  it("Views", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/structure/views');
    cy.get('h1').should('contain', 'Views');

    cy.contains('ACCESS Active Resources');
    cy.contains('ACCESS News');
    cy.contains('Added Interests');
    cy.contains('Added Skills');
    cy.contains('Add Interest');
    cy.contains('Add Skill');
    cy.contains('Affinity Group');
    cy.contains('Affinity Group Card View');
    cy.contains('Affinity Group Community Events');
    cy.contains('Affinity Group Instances API');
    cy.contains('Affinity Group Instances API - Knowledgebase');
    cy.contains('Affinity Group Members');
    cy.contains('Affinity Group Recurring Events');
    cy.contains('Affinity Group search');
    cy.contains('Affinity Groups with Tag');
    cy.contains('Blog');
    cy.contains('Blog Post');
    cy.contains('Campus Champion Applications');
    cy.contains('Campus Champion Institutions');
    cy.contains('Campus Champions - User export');
    cy.contains('Campus Champions Administration');
    cy.contains('CCMNet Members');
    cy.contains('CCMNet PMs');
    cy.contains('Cider resources on AG groups');
    cy.contains('Comments');
    cy.contains('Community Events');
    cy.contains('Content');
    cy.contains('Content blocks');
    cy.contains('CSSN Directory');
    cy.contains('CSSN Directory Users by Tag');
    cy.contains('CSSN Members');
    cy.contains('CurrentUserBlocks');
    cy.contains('Duplicate of Projects');
    cy.contains('Entity Series json feed');
    cy.contains('Event Instances API');
    cy.contains('Events');
    cy.contains('Event Series');
    cy.contains('Expert With Tag');
    cy.contains('Feeds');
    cy.contains('Files');
    cy.contains('Front Projects');
    cy.contains('Help Desk');
    cy.contains('Infrastructure News');
    cy.contains('Interested in Project');
    cy.contains('Knowledge Base Resources Search');
    cy.contains('Match Engagements submissions');
    cy.contains('match engagement view');
    cy.contains('match interested users');
    cy.contains('Media');
    cy.contains('Media library');
    cy.contains('Mentorship Engagement Item');
    cy.contains('Mentorship Facet Search');
    cy.contains('Mentorship Status');
    cy.contains('Moderated content');
    cy.contains('My Project Submissions');
    cy.contains('News');
    cy.contains('Node add tags');
    cy.contains('Organization');
    cy.contains('People');
    cy.contains('People');
    cy.contains('People All');
    cy.contains('PeopleButtonBlocks');
    cy.contains('People Card View');
    cy.contains('People Filter emails');
    cy.contains('People List View');
    cy.contains('People with expertise tags');
    cy.contains('People with interest tags');
    cy.contains('Project');
    cy.contains('Project Information');
    cy.contains('Projects');
    cy.contains('ProjectSubmissionData');
    cy.contains('Project Submissions');
    cy.contains('Quarterly Events');
    cy.contains('Recent comments');
    cy.contains('Recent content');
    cy.contains('Redirect');
    cy.contains('Reference: ACCESS Active Resources from CiDeR ID');
    cy.contains('Regional About');
    cy.contains('Related Blog');
    cy.contains('Resources');
    cy.contains('Resources Entity Reference');
    cy.contains('Search Knowledge Base Resources');
    cy.contains('Search Tags');
    cy.contains('Students with Projects');
    cy.contains('Suggested Resources');
    cy.contains('Tag by Date');
    cy.contains('Tagged Announcements Block');
    cy.contains('Tags');
    cy.contains('tags-export');
    cy.contains('Tags Hierarchal');
    cy.contains('Tag Usage');
    cy.contains('Taxonomy term');
    cy.contains('Term from Ask.CI');
    cy.contains('Terms Used');
    cy.contains('User Profile');
    cy.contains('User Projects');
    cy.contains('Users with Tag');
    cy.contains('Webform submissions');
  });

  it("Webforms", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/structure/webform');
    cy.get('h1').should('contain', 'Webforms');

    cy.contains('ACCESS Organization Request');
    cy.contains('Affinity Group Contact');
    cy.contains('Affinity Group Request');
    cy.contains('CCMNet Mentors Contact');
    cy.contains('Contact');
    cy.contains('Distributed Experts Network Help Request Form');
    cy.contains('Edit your roles');
    cy.contains('Get Updates about AMP');
    cy.contains('Get Updates about CCMNET');
    cy.contains('Having trouble logging in?');
    cy.contains('Help Desk Support Ticket');
    cy.contains('Join CCMNet');
    cy.contains('Join Campus Champions');
    cy.contains('Join the CSSN Network');
    cy.contains('Knowledge Base Resources');
    cy.contains('Organization');
    cy.contains('Project');
    cy.contains('Proposal Support');
    cy.contains('Request Tag');
  });

});
