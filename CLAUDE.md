@CLAUDE.personal.md

## Directory Notes
- `docroot/`: The Drupal root directory, containing all Drupal core files, contributed modules, themes, and custom code.
- 'web/': A symlink to `docroot/` for easier web server configuration.
- 'docroot/modules/custom/': Directory for custom Drupal modules developed in-house.

## Common Commands

```bash
# Local development (DDEV)
ddev start                    # Start local environment
ddev drush cr                 # Clear Drupal cache
ddev drush cex -y             # Export config
ddev drush cim -y             # Import config
ddev drush uli                # Generate one-time login link

# Robo tasks (run from project root)
vendor/bin/robo did                 # Reload DB from last backup
vendor/bin/robo ddevsetup           # Initial site setup
vendor/bin/robo gh:pulldb           # Pull latest DB artifact from GitHub
vendor/bin/robo gh:pullfiles        # Pull latest files artifact from GitHub
vendor/bin/robo snap:create         # Create DB snapshot
vendor/bin/robo snap:restore        # Restore DB snapshot
vendor/bin/robo cypress <folder>    # Run Cypress E2E tests on folder: accessmatch1, accessmatch3, campuschampion, coco, crct, kyct, ondemand, accessmatch2, axe, ccmnet, connectci, greatplains, nect, pascience

# Run PHPCS on a specific module
vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,test,profile,theme,info,txt,md,yml docroot/modules/custom/access
# Run PHPStan on a specific module
vendor/bin/phpstan analyse -l 0 docroot/modules/custom/<module>
# Run PHPUnit for a specific module
cd docroot/modules/custom/<module> && ../../../../vendor/bin/phpunit

# Interacting with Jira
acli

# When changing composer patches, be sure to run:
composer patches-relock
composer patches-repatch
```
# General Instructions
- do not commit any code, leave all git work to the developers
