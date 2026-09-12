/*
    CSSN Directory page.

    The CSSN Directory has two columns:
    - left column with a search form, and facets for roles, skills, and organizations.
    - right column with a list of users displaying name, role, organization, and skills.
*/

describe('Tests the CSSN Directory Page for Anonymous Users', () => {
    it('Test CSSN Directory for anonymous user', () => {
        cy.visit('/community/cssn/directory')

        const crumbs = [
            ['Support', '/'],
            ['Community', '/community/overview'],
            ['CSSN', '/community/cssn'],
            ['Directory', null]
        ];
        cy.checkBreadcrumbs(crumbs);

        // Page Title
        cy.get('.page-title').contains('CSSN Directory')

        // Search form should work for anonymous users
        cy.searchAndWait('[data-drupal-selector="edit-search-api-fulltext"]', 'Pasquale')

        cy.get('.cssn-directory-item').as('item')
        cy.get('@item').should('have.length', 1) // Only one user with the name Pasquale
        cy.get('@item').find('.cssn-photo img')
            .should('be.visible')
            .should(($img) => {
              expect($img[0].naturalWidth).to.be.greaterThan(0)
            })
            .verifyImage()

        // Card should link to the community persona page.
        cy.get('@item')
            .find('a.stretched-link')
            .should("have.attr", "href")
            .and("contain", '/community-persona/1373');

        // Organization should be displayed.
        cy.get('@item')
            .find('.leading-5 a')
            .should('contain', 'Sweet and Fizzy')

        // Tags should be displayed.
        cy.get('@item')
            .find('.square-tags ul li')
            .should('have.length.at.least', 1)
            .find('a')
            .should('have.attr', 'href')
            .and('contain', '/taxonomy/term/')

        // Clear search for facet tests
        cy.clearSearchAndWait('[data-drupal-selector="edit-search-api-fulltext"]')

        // Verify roles facet does NOT exist for anonymous users
        cy.get('#roles-cip').should('not.exist')

        // Verify organizations facet does NOT exist for anonymous users
        cy.get('#access-organization-4191').should('not.exist')
        cy.get('.block-facet-blockaccess-organization').should('not.exist')

        // Skills facet might still be available, check if it exists.
        // The branch reads the DOM before any facet click, so it stays valid;
        // the clicks themselves wait for the refresh via cy.clickFacetAndWait.
        cy.get('body').then($body => {
            if ($body.find('#skills-c').length > 0) {
                cy.clickFacetAndWait('#skills-c:visible')
                cy.get('.cssn-directory-item').should('have.length.at.least', 1)
                cy.clickFacetAndWait('#skills-reset-all:visible')
            }
        })

        // todo: '+ X more' tags text is not currently displaying in Cypress tests.
    })
})
