describe("Check admin configuration pages", () => {
  it("People config", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/config/people/accounts');
    cy.get('h1').should('contain', 'Account settings');

    cy.visit('/admin/config/people/accounts/fields');
    cy.get('h1').should('contain', 'Manage fields');

    cy.contains('Academic Status');
    cy.contains('Allocated Resources');
    cy.contains('Ask.CI Username');
    cy.contains('GitHub Username');
    cy.contains('Open OnDemand Discourse Username');
    cy.contains('Badges');
    cy.contains('Bio');
    cy.contains('Blocked Affinity Groups');
    cy.contains('Carnegie Code');
    cy.contains('Citizenships');
    cy.contains('Constant Contact id');
    cy.contains('Current Degree Program');
    cy.contains('CV/Resume');
    cy.contains('Degree');
    cy.contains('Domain Access');
    cy.contains('Domain administrator');
    cy.contains('Editor for all affiliates');
    cy.contains('First Name');
    cy.contains('HPC Experience');
    cy.contains('I\'m a Campus Champion');
    cy.contains('Institution');
    cy.contains('Job Title');
    cy.contains('Last Name');
    cy.contains('Organization');
    cy.contains('Picture');
    cy.contains('Preferred pronouns');
    cy.contains('Program');

    cy.visit('/admin/config/people/accounts/form-display');
    cy.get('h1').should('contain', 'Manage form display');

    cy.contains('Program');
    cy.contains('User name and password');
    cy.contains('First Name');
    cy.contains('Last Name');
    cy.contains('Preferred pronouns');
    cy.contains('Organization');
    cy.contains('Ask.CI Username');
    cy.contains('GitHub Username');
    cy.contains('Open OnDemand Discourse Username');
    cy.contains('Job Title');
    cy.contains('Bio');
    cy.contains('Badges');
    cy.contains('I\'m a Campus Champion');
    cy.contains('Picture');
    cy.contains('Social authentications');
    cy.contains('Academic Status');
    cy.contains('HPC Experience');
    cy.contains('CV/Resume');
    cy.contains('Timezone');
    cy.contains('Language settings');
    cy.contains('URL alias');
    cy.contains('Citizenships');
    cy.contains('Constant Contact id');
    cy.contains('Allocated Resources');
    cy.contains('Blocked Affinity Groups');
    cy.contains('Carnegie Code');
    cy.contains('Disabled');
    cy.contains('Current Degree Program');
    cy.contains('Degree');
    cy.contains('Domain Access');
    cy.contains('Domain administrator');
    cy.contains('Editor for all affiliates');
    cy.contains('Institution');
    cy.contains('Contact settings');

    cy.visit('/admin/config/people/accounts/display');
    cy.get('h1').should('contain', 'Manage display');

    cy.contains('Ask.CI Username');
    cy.contains('GitHub Username');
    cy.contains('Open OnDemand Discourse Username');
    cy.contains('Academic Status');
    cy.contains('Organization');
    cy.contains('Blocked Affinity Groups');
    cy.contains('Carnegie Code');
    cy.contains('Allocated Resources');
    cy.contains('Citizenships');
    cy.contains('Constant Contact id');
    cy.contains('Current Degree Program');
    cy.contains('Job Title');
    cy.contains('CV/Resume');
    cy.contains('Degree');
    cy.contains('Domain Access');
    cy.contains('Domain administrator');
    cy.contains('Editor for all affiliates');
    cy.contains('HPC Experience');
    cy.contains('Institution');
    cy.contains('I\'m a Campus Champion');
    cy.contains('Program');
    cy.contains('Badges');
    cy.contains('Bio');
    cy.contains('First Name');
    cy.contains('Last Name');
    cy.contains('Preferred pronouns');
    cy.contains('Picture');
    cy.contains('Masquerade');
    cy.contains('Real name');
    cy.contains('Search result excerpt');
    cy.contains('Member for');

    cy.visit('/admin/config/people/email-change');
    cy.get('h1').should('contain', 'Email change settings');

    cy.visit('/admin/config/people/login-disable');
    cy.get('h1').should('contain', 'Login Disable');

    cy.visit('/admin/config/people/mail-login');
    cy.get('h1').should('contain', 'Mail Login');

    cy.visit('/admin/config/people/multiple_registration');
    cy.get('h1').should('contain', 'Multiple registration pages');

    cy.contains('/user/register/student');
    cy.contains('/user/register/mentor');
    cy.contains('/user/register/researcher');
    cy.contains('/user/register/representative');
    cy.contains('/user/register/rcf');
    cy.contains('/user/register/rse');
    cy.contains('/user/register/cise');
    cy.contains('/user/register/student_champion');
    cy.contains('/user/register/domain_champion');
    cy.contains('/user/register/ccmnet');
    cy.contains('/user/register/ondemand');

    cy.visit('/admin/config/people/realname');
    cy.get('h1').should('contain', 'Real name');
    cy.get('#edit-realname-pattern').should('have.value', '[user:field_user_first_name:value] [user:field_user_last_name:value]');

    cy.visit('/admin/config/people/tfa');
    cy.get('h1').should('contain', 'TFA Settings');
  });

  it("Content config", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/config/content/honeypot');
    cy.get('h1').should('contain', 'Honeypot configuration');

    cy.visit('/admin/config/content/layout_builder_style');
    cy.get('h1').should('contain', 'Layout builder styles');

    cy.contains('Accordion Wrapper');
    cy.contains('bg-light-teal');
    cy.contains('bg-light-teal-overflow-section');
    cy.contains('border-b-1');
    cy.contains('border-gray');
    cy.contains('frontpage block');
    cy.contains('mb-3');
    cy.contains('mb-5');
    cy.contains('mb-10');
    cy.contains('mb-10-section');
    cy.contains('md teal box');
    cy.contains('mobile-row-reverse');
    cy.contains('mt-4-section');
    cy.contains('order-1');
    cy.contains('Page Title');
    cy.contains('pb-4');
    cy.contains('pe-3');
    cy.contains('pt-4');
    cy.contains('pt-20');
    cy.contains('Tags');
    cy.contains('[&>div:nth-of-type(1)]--md--order-1');
    cy.contains('[&>div:nth-of-type(1)]--order-2');
    cy.contains('[&>div:nth-of-type(2)]--md--order-2');
    cy.contains('[&>div:nth-of-type(2)]--order-1');

    cy.visit('/admin/config/content/access_by_ref');
    cy.get('h1').should('contain', 'Access by reference configuration');

    cy.contains('match_consultant');
    cy.contains('match_mentors');
    cy.contains('match_researcher');
    cy.contains('match_student');

    cy.visit('/admin/config/content/formats');
    cy.get('h1').should('contain', 'Text formats and editors');

    cy.contains('Basic HTML');
    cy.contains('Email HTML');
    cy.contains('Filtered HTML');
    cy.contains('Full no editor');
    cy.contains('Restricted HTML');
    cy.contains('Full HTML');
    cy.contains('Plain text');
  });

  it("Check Development pages", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/config/development/performance');
    cy.get('h1').should('contain', 'Performance');

    cy.visit('/admin/config/development/settings');
    cy.get('h1').should('contain', 'Development settings');

    cy.visit('/admin/config/development/logging');
    cy.get('h1').should('contain', 'Logging and errors');

    cy.visit('/admin/config/development/maintenance');
    cy.get('h1').should('contain', 'Maintenance mode');

    cy.visit('/admin/config/development/configuration/config-split');
    cy.get('h1').should('contain', 'Configuration Split setting');

    cy.contains('live');
    cy.contains('local');

    cy.visit('/admin/config/development/configuration');
    cy.get('h1').should('contain', 'Synchronize');

    cy.visit('/admin/config/development/configuration');
    cy.get('h1').should('contain', 'Synchronize');

    cy.visit('/admin/config/development/yaml_editor');
    cy.get('h1').should('contain', 'YAML Editor');
  });

  it("Search and Metadata config pages", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/config/search/pages');
    cy.get('h1').should('contain', 'Search pages');

    cy.visit('/admin/config/search/path');
    cy.get('h1').should('contain', 'URL aliases');

    cy.visit('/admin/config/search/path/patterns');
    cy.get('h1').should('contain', 'Patterns');

    cy.contains('ACCESS Announcements');
    cy.contains('Affinity Groups');
    cy.contains('Article');
    cy.contains('Basic Page');
    cy.contains('Blog');
    cy.contains('Blog region');
    cy.contains('Community Events');
    cy.contains('MATCH Engagement');
    cy.contains('Mentorship Engagement');
    cy.contains('Organizations');
    cy.contains('PeopleCardView');
    cy.contains('PeopleListView');
    cy.contains('Press Release');
    cy.contains('Published Article');
    cy.contains('Term');
    cy.contains('About');
    cy.contains('User');

    cy.visit('/admin/config/search/path/settings');
    cy.get('h1').should('contain', 'Settings');

    cy.visit('/admin/config/search/path/update_bulk');
    cy.get('h1').should('contain', 'Bulk generate');

    cy.visit('/admin/config/search/path/delete_bulk');
    cy.get('h1').should('contain', 'Delete aliases');

    cy.visit('/admin/config/search/datalayer');
    cy.get('h1').should('contain', 'Data Layer');

    cy.visit('/admin/config/search/search404');
    cy.get('h1').should('contain', 'Search 404 settings');

    cy.visit('/admin/config/search/search-api-pages');
    cy.get('h1').should('contain', 'Search API pages');
    cy.contains('search-page');

    cy.visit('/admin/config/search/redirect');
    cy.get('h1').should('contain', 'Redirect');

    cy.visit('/admin/config/search/facets');
    cy.get('h1').should('contain', 'Facets');

    cy.contains('search_api:search_api_page__search');
    cy.contains('search_api:views_page__affinity_group_search__page_1');
    cy.contains('affinity_group_category');
    cy.contains('affinity_search_tags');
    cy.contains('search_api:views_page__ccmnet_members__page_1');
    cy.contains('access_organization_members');
    cy.contains('program_members');
    cy.contains('user_skills_members');
    cy.contains('search_api:views_page__cssn_directory__page_1');
    cy.contains('access_organization');
    cy.contains('roles');
    cy.contains('skills');
    cy.contains('search_api:views_block__cssn_directory_by_tag__block_2');
    cy.contains('search_api:views_block__cssn_directory_by_tag__block_1');
    cy.contains('search_api:views_block__mentorship_facet_search__block_1');
    cy.contains('search_api:views_page__mentorship_facet_search__page_1');
    cy.contains('mentee');
    cy.contains('mentor');
    cy.contains('me_tags');
    cy.contains('state');
    cy.contains('search_api:views_page__nect_cc_people__page_1');
    cy.contains('program_people_page');
    cy.contains('search_api:views_page__search_ci_links__page_2');
    cy.contains('content_type_mentorship');
    cy.contains('tags_ci_mentorship');
    cy.contains('search_api:views_page__search_ci_links__page_1');
    cy.contains('content_type');
    cy.contains('programming_language');
    cy.contains('science_domain');
    cy.contains('skill_level');
    cy.contains('submission_id');
    cy.contains('tags');
    cy.contains('vote_count');

  });

  it("Domains config pages", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/config/domain');
    cy.get('h1').should('contain', 'Domains');

    cy.contains('Northeast Cyberteam');
    cy.contains('Great Plains Cyberteam');
    cy.contains('Kentucky Cyberteam');
    cy.contains('CAREERS Cyberteam');
    cy.contains('MINES Cyberteam');
    cy.contains('Campus Champions');
    cy.contains('Connect CI');
    cy.contains('ACCESS Support');
    cy.contains('CoCo');
    cy.contains('USRSE');
    cy.contains('CCMNet');

    cy.visit('/admin/config/domain/settings');
    cy.get('h1').should('contain', 'Domain settings');

    cy.visit('/admin/config/domain/domain_access');
    cy.get('h1').should('contain', 'Domain Access settings');

    cy.visit('/admin/config/domain/domain_source');
    cy.get('h1').should('contain', 'Domain Source settings');

    cy.visit('/admin/config/domain/domain_theme_switch/config');
    cy.get('h1').should('contain', 'Domain theme configuration');
  });

  it("web services config pages", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/services/constantcontact-token');
    cy.get('h1').should('contain', 'Constant Contact');

    cy.visit('/admin/config/services/jsonapi');
    cy.get('h1').should('contain', 'JSON:API');

    cy.visit('/admin/config/services/rest');
    cy.get('h1').should('contain', 'REST resources');

    cy.visit('/admin/config/services/rest');
    cy.get('h1').should('contain', 'REST resources');

    cy.visit('/admin/config/services/rss-publishing');
    cy.get('h1').should('contain', 'RSS publishing');

    cy.visit('/admin/config/services/linkset');
    cy.get('h1').should('contain', 'Menu Linkset Settings');
  });

  it("System config pages", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/config/system/site-information');
    cy.get('h1').should('contain', 'Basic site settings');

    cy.get('#edit-site-name').should('have.value', 'Northeast Cyberteam');
    cy.get('#edit-site-mail').should('have.value', 'noreply@wpi.edu');
    cy.get('#edit-site-frontpage').should('have.value', '/front-projects');

    cy.visit('/admin/config/system/mailsystem');
    cy.get('h1').should('contain', 'Configure the Mail System');

    cy.visit('/admin/config/system/auditfiles');
    cy.get('h1').should('contain', 'Audit Files');

    cy.visit('/admin/config/system/encryption/profiles');
    cy.get('h1').should('contain', 'Encryption profiles');
    cy.contains('2fa-encrypt-profile');

    cy.visit('/admin/config/system/encryption/profiles/settings');
    cy.get('h1').should('contain', 'Encrypt settings');

    cy.visit('/admin/config/system/gtm');
    cy.get('h1').should('contain', 'GTM Settings');

    cy.visit('/admin/config/system/mailer');
    cy.get('h1').should('contain', 'Mailer policy');

    cy.visit('/admin/config/system/r4032login/settings');
    cy.get('h1').should('contain', 'r4032login settings');

    cy.visit('/admin/config/system/r4032login/settings/anonymous');
    cy.get('h1').should('contain', 'r4032login anonymous behavior');

    cy.visit('/admin/config/system/r4032login/settings/authenticated');
    cy.get('h1').should('contain', 'r4032login authenticated behavior');

    cy.visit('/admin/config/system/cron');
    cy.get('h1').should('contain', 'Cron');
    cy.get('#edit-interval').should('have.value', '0');

    cy.visit('/admin/config/system/keys');
    cy.get('h1').should('contain', 'Keys');

    cy.contains('2fa key');
    cy.contains('constant_contact_client_id');
    cy.contains('constant_contact_client_secret');
    cy.contains('Open Ai api');
    cy.contains('simplelists');

    cy.visit('/admin/config/fieldblock/fieldblockconfig');
    cy.get('h1').should('contain', 'Field as Block settings');

    cy.visit('/admin/config/menu-item-role-access');
    cy.get('h1').should('contain', 'Configuration for Menu Item Role Access module');
  });

  it("User Interface config pages", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/config/user-interface/coffee');
    cy.get('h1').should('contain', 'Coffee configuration');

    cy.visit('/admin/config/user-interface/easy-breadcrumb');
    cy.get('h1').should('contain', 'Easy Breadcrumb');

    cy.visit('/admin/config/user-interface/shortcut');
    cy.get('h1').should('contain', 'Shortcuts');

  });

  it("Media config pages", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/config/media/file-system');
    cy.get('h1').should('contain', 'File system');

    cy.visit('/admin/config/media/image-styles');
    cy.get('h1').should('contain', 'Image styles');

    cy.contains('3-by-4 forced dimensions');
    cy.contains('access match sidebar');
    cy.contains('Access News (730x350)');
    cy.contains('Image (480x180)');
    cy.contains('Landscape (480x220)');
    cy.contains('Landscape (480x220, keep ratio)');
    cy.contains('Large (480x480)');
    cy.contains('Media Library thumbnail (220×220)');
    cy.contains('Medium (220×220)');
    cy.contains('Portrait');
    cy.contains('Three By Four');
    cy.contains('Thumbnail (100×100)');

    cy.visit('/admin/config/media/imce');
    cy.get('h1').should('contain', 'Imce File Manager');

    cy.contains('Admin profile');
    cy.contains('Member profile');
    cy.contains('anonymous user');
    cy.contains('student-facilitator');
    cy.contains('mentor');
    cy.contains('regional facilitator');
    cy.contains('researcher/educator');
    cy.contains('authenticated user');
    cy.contains('representative');
    cy.contains('administrator');
    cy.contains('Masquerade');
    cy.contains('regional expert');
    cy.contains('CampusChampionsAdmin');
    cy.contains('ExportPeople');
    cy.contains('research computing facilitator');
    cy.contains('research software engineer');
    cy.contains('ci systems engineer');
    cy.contains('mentee');
    cy.contains('student champion');
    cy.contains('domain champion');
    cy.contains('cssn');
    cy.contains('Affinity Group Leader');
    cy.contains('MATCH PM');
    cy.contains('steering committee');
    cy.contains('leadership team');
    cy.contains('regional admin');
    cy.contains('Coco PM');
    cy.contains('ConnectCi PM');
    cy.contains('News PM');
    cy.contains('KB PM');
    cy.contains('Careers SC');
    cy.contains('CCMNet PM');
    cy.contains('Consultant');
    cy.contains('Match SC');
    cy.contains('CIP');
    cy.contains('Site Developer');
    cy.contains('CCMNet');
    cy.contains('CCMNet Admin');

    cy.visit('/admin/config/media/media-library');
    cy.get('h1').should('contain', 'Media Library settings');

    cy.visit('/admin/config/media/media-settings');
    cy.get('h1').should('contain', 'Media settings');

    cy.visit('/admin/config/media/private-files-download-permission');
    cy.get('h1').should('contain', 'Private files download permission');

    cy.contains('_resume');
    cy.contains('_views_data_export');
    cy.contains('_webform');

    cy.visit('/admin/config/media/image-toolkit');
    cy.get('h1').should('contain', 'Image toolkit');
  });

  it("Region and language config pages", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/config/regional/settings');
    cy.get('h1').should('contain', 'Regional settings');

    cy.visit('/admin/config/regional/date-time');
    cy.get('h1').should('contain', 'Date and time formats');
  });

  it("Social API settings", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/config/social-api/social-auth');
    cy.get('h1').should('contain', 'User authentication');

    cy.contains('social_auth_google');
  });

  it("Workflow pages", () => {
    cy.loginUser('administrator@amptesting.com', 'b8QW]X9h7#5n');

    cy.visit('/admin/config/workflow/workflows');
    cy.get('h1').should('contain', 'Workflows');

    cy.contains('Editorial');
    cy.contains('MATCH Engagement');

    cy.visit('/admin/config/workflow/notifications');
    cy.get('h1').should('contain', 'Content Moderation Notifications');
  });

});
