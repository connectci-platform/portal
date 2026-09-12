/*
 * Verify test users can login
 */
describe('Verify test users can login', () => {
  it('should login', () => {
    // The profile page shows the display name (first + last), which these
    // fixture users now have, rather than the raw username.
    cy.loginAs('authenticated@amptesting.com', '6%l7iF}6(4tI');
    cy.visit('/user/authenticatedtestuser')
      .contains('Authenticated Testuser');
    cy.task('log', 'logged in as authenticated_test_user');
    cy.loginAs('administrator@amptesting.com', 'b8QW]X9h7#5n');
    cy.visit('/user/administratortestuser')
      .contains('Administrator Testuser');
    cy.task('log', 'logged in as administrator_test_user');
  });
});
