# Standalone job vacancies and Annual Recruitment Plan toggle

Source change prepared for the deployment branch. Applying it on AOS is a separate step; no production deployment is performed here.

## Staff operation

At `/admin/config/reg/site-settings`, under **Public Information Hub**, leave **Annual Recruitment Plan Integration** unchecked to create normal vacancies at `/node/add/reg_job` or manage them at `/admin/content/jobs`. The page uses the existing restricted `administer reg website settings` permission.

The default-OFF boolean is `reg_core.settings:public_information.recruitment_plan_integration`. OFF hides plan/exception fields, recruitment plan management navigation and Add content entries, limits the HR overview to jobs, and blocks plan management routes and forms. Job company, department, employment type and positions stay editable without a plan override permission. Application methods and external URL requirements remain enforced. Publishing, lifecycle permissions, attachments and application handling keep their existing behavior.

Stored plans, plan items, field definitions, database tables, translations, applications and existing plan relationships are retained. Editing a linked vacancy does not copy plan defaults or remove its relationship while OFF; changing an existing relationship requires enabling integration first. Historical public recruitment plan listings remain available, matching the procurement approach of hiding management rather than deleting public data.

## Re-enable later

Check **Annual Recruitment Plan Integration** at `/admin/config/reg/site-settings` and save. This restores plan management and the original approved-plan-or-approved-exception workflow for new jobs. Saving rebuilds menu links and invalidates configuration/node-type cache tags using the existing REG settings conventions. No code rebuild or data migration is needed.

CLI alternative:

```powershell
ddev drush config:set reg_core.settings public_information.recruitment_plan_integration true -y
ddev drush cr
```

Set it to `false` and rebuild caches to return to standalone vacancies.

## Apply and verify locally

```powershell
ddev exec php -l web/modules/custom/reg_core/src/Controller/PortalController.php
ddev exec "find web/modules/custom/reg_core web/themes/custom/reg_theme -name '*.php' -print0 | xargs -0 -n1 php -l"
ddev exec php -l web/modules/custom/reg_core/reg_core.module
ddev drush cr
ddev drush updb -y
ddev drush status
ddev drush php:script web/modules/custom/reg_core/tests/fixtures/recruitment_notice_acceptance.php
ddev drush php:script web/modules/custom/reg_core/tests/fixtures/tender_notice_acceptance.php
ddev exec vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/reg_core/tests/src/Unit/SimpleTenderWorkflowTest.php web/modules/custom/reg_core/tests/src/Unit/TenderSubmissionPolicyTest.php web/modules/custom/reg_core/tests/src/Unit/ProcurementCsvTest.php
```

The idempotent post-update adds OFF only when the flag is absent and rebuilds menu links. It does not save or delete content entities. The acceptance test creates a disposable job, publishes it, checks anonymous `/jobs`, edits/closes/unpublishes it, verifies reactivation, and deletes only that test job. It compares snapshots and counts of existing jobs, plans, applications and procurement content and restores settings in `finally`. It reuses an existing document, if present, without modifying it.
## Unpublish a vacancy

The existing Public Information workflow keeps Archived notices published. HR Approvers can use **Unpublish vacancy** to enter the additive Unpublished state (a non-public default revision). **Revise unpublished vacancy** returns it to Draft for normal review. Existing workflow states, transitions, tender permissions and published content are unchanged. This transition is available independently of the recruitment planning flag. The update grants the new transition permissions only to the existing HR roles; Drupal administrators retain their normal administrative access.

## Files changed for recruitment

Under `web/modules/custom/reg_core/`:

- `config/install/reg_core.settings.yml`
- `config/schema/reg_core.schema.yml`
- `reg_core.install`
- `reg_core.module`
- `reg_core.post_update.php`
- `reg_core.routing.yml`
- `src/Controller/BusinessContentAdminController.php`
- `src/Form/RegSettingsForm.php`
- `src/Recruitment/RecruitmentPlanWorkflow.php`
- `src/Recruitment/RecruitmentWorkflowSetup.php`
- `tests/fixtures/recruitment_notice_acceptance.php`

Documentation: `docs/recruitment-notice-mode.md`.
## Local verification results

- PHP lint passed for the custom PHP files, module hooks, installer and new setup helpers.
- Drupal cache rebuild and both post-updates completed successfully.
- Recruitment acceptance passed: OFF default, denied plan-management routes, hidden navigation/fields, editable standalone metadata, entity validation, draft creation, publication without a plan, anonymous public `/jobs` listing, editing with application URL/method/documents preserved, closing, unpublishing and anonymous access denial.
- Reactivation restored management access and the original plan-or-approved-exception check; configuration was restored to OFF afterward.
- Existing node data snapshots and record counts matched for jobs, recruitment plans/items, applicant/application records, tenders, procurement plans/items and bids. Only the disposable test job was deleted.
- Existing procurement unit regressions passed: 12 tests, 95 assertions.
- Existing live-Drupal tender notice acceptance passed all eight checks (standalone publication, homepage/archive inclusion, UMUCYO and external channels, deadline display, optional PDF download, disabled bid intake and hidden planning navigation). The missing-URL exception printed by that test is an intentional rejection check.
