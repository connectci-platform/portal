/*
    CSSN Directory page - Authenticated User Tests.

    The CSSN Directory has two columns:
    - left column with a search form, and facets for roles, skills, and organizations.
    - right column with a list of users displaying name, role, organization, and skills.
    
    These tests verify functionality available only to authenticated users,
    specifically the roles and organization facets.
*/

describe('Tests the CSSN Directory Page for Authenticated Users', () => {
    beforeEach(() => {
        cy.loginAs('authenticated@amptesting.com', '6%l7iF}6(4tI')
    })

    it('Test CSSN Directory roles and organization facets for authenticated user', () => {
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

        // Facet clicks go through cy.clickFacetAndWait, which waits for the
        // widget's `facets_filter` binding before clicking and for the view
        // plus the facet blocks to settle after. Counting results immediately
        // after a click counted the pre-refresh list under CI load.

        // Check roles facet exists and works for authenticated users
        cy.clickFacetAndWait('#roles-cip:visible')
        cy.get('.cssn-directory-item').then(($cips) => {
            // There are at least 14 CIPs
            expect($cips.length).to.be.at.least(14)

            // add the c++ facet; should reduce the number of users.
            cy.clickFacetAndWait('#skills-c:visible')
            cy.get('.cssn-directory-item')
                .should('have.length.at.least', 1)
                .should('have.length.lessThan', $cips.length)
        })

        // Reset facets
        cy.clickFacetAndWait('#roles-cip:visible')
        cy.clickFacetAndWait('#skills-reset-all:visible')

        // Check the "Show more" link for Organizations facet. The link only
        // exists once the block has re-rendered with the full, unfiltered
        // list, which the reset waits above guarantee.
        cy.expandFacetSoftLimit('access_organization')

        // Check the organizations facet exists and works for authenticated users
        // Massachusetts Green High Performance Computing Center
        cy.clickFacetAndWait('#access-organization-4191:visible')
        cy.get('.cssn-directory-item')
            .should('have.length.at.least', 1)
    })

    it('Test CSSN Directory search functionality for authenticated user', () => {
        cy.visit('/community/cssn/directory')

        // Search form should still work for authenticated users
        cy.searchAndWait('[data-drupal-selector="edit-search-api-fulltext"]', 'Pasquale')

        cy.get('.cssn-directory-item').as('item')
        cy.get('@item').should('have.length', 1) // Only one user with the name Pasquale
        cy.get('@item').find('.cssn-photo img').verifyImage()

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
    })
})