# Homepage Opportunities

The existing homepage tender block now displays up to three latest published,
currently open tenders and one latest published, currently open job. If no open vacancy exists, the fourth card shows the latest published recruitment-results notice, clearly labelled as results. Open vacancies take priority. Selection
uses the issue/posting date, existing lifecycle status, future closing deadline,
published language fallback, and Drupal access checks. Recruitment results do not need a future application deadline, but must be published, visible and not scheduled in the future. Fewer tenders produce
fewer cards. Without an open job, the vacancy slot links to Jobs with a
"No current vacancies" message when neither open vacancies nor published recruitment results exist. No content records are created for this fallback.

The section retains the existing tender document routes and job detail routes.
Job application methods and links remain on the existing job detail pages.
The scoped grid uses four equal columns from 1200px, two from 768px, and one
below 768px. Existing REG card styles, typography, and translated labels remain.
The annual procurement/recruitment plan feature flags are unchanged.

## Deployment

This is a code-only change that retains the existing block plugin ID and
placement. No configuration import, database update, migration, content import,
or new module installation is required. After deploying the code through the
approved deployment process, rebuild Drupal caches:

```sh
vendor/bin/drush cr
```

The render array carries entity/list/configuration/access cache dependencies.
Its lifetime is capped at five minutes and the next selected deadline or
upcoming publication time. The scoped response subscriber also bounds Internal
Page Cache expiry, which otherwise ignores render-array max-age values.

## Local validation

```sh
ddev exec vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/reg_core/tests/src/Unit/HomepageOpportunitiesTest.php web/modules/custom/reg_core/tests/src/Unit/HomepageOpportunitiesExpiryTest.php web/modules/custom/reg_core/tests/src/Unit/PublicInformationRepositoryTest.php web/modules/custom/reg_core/tests/src/Unit/SimpleTenderWorkflowTest.php
ddev drush php:script web/modules/custom/reg_core/tests/fixtures/homepage_opportunities_readonly.php
ddev drush cr
ddev drush updb -y
ddev drush status
```

The acceptance script only reads existing content/configuration and renders
in-memory test inputs. It checks real anonymous homepage output, fallback and
four-card markup, escaping, cache HIT/expiry behavior, and unchanged content,
relationships and plan flags. It does not save or delete entities/configuration.

Validation on 2026-11-10: unit suite passed (10 tests, 128 assertions), and the
read-only acceptance passed using one actual open tender and no open vacancies.
PHP and JavaScript syntax checks passed. Desktop/mobile browser visual review
could not be completed because the Computer Use window capture timed out twice;
the 4/2/1 CSS rules were inspected, but this is not a visual verification.
