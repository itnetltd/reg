# REG Drupal 11 Project Instructions

## Project purpose
Build the Rwanda Energy Group public website as a service-first Drupal 11 platform based on the approved Figma homepage and the revised IT NET Ltd inception report.

## Local environment
- Windows project path: `C:\Sites\reg-drupal`
- DDEV project name: `reg-website`
- Drupal document root: `web`
- Run all PHP, Composer, Drush, database, and test commands through DDEV.

## Technology rules
- Drupal 11, PHP 8.3+, Twig, Drupal behaviors, semantic HTML, and plain component-scoped CSS.
- Do not add React, Tailwind, or a separate frontend runtime.
- Use Drupal Single-Directory Components where suitable.
- Keep content editable through Drupal entities, fields, Views, menus, blocks, media, configuration, and workflows.
- External operational systems remain authoritative. Integrate through approved APIs, deep links, secure redirects, or controlled embeds with caching and fallback messages.

## Product priorities
1. Mobile-first accessibility and WCAG 2.2 AA alignment.
2. Outages, online services, new connection, tariffs, complaints/faults, branch contacts, and call center 2727.
3. Structured news, announcements, tenders, jobs, publications, outages, branches, sports updates, and FAQs.
4. English and Kinyarwanda translation readiness.
5. FAQ-first assistant grounded only in approved REG content.
6. Bill and carbon calculators must remain disabled until approved inputs are configured.
7. Customer-specific data must not be stored or exposed without approved authenticated integrations.

## Figma direction
- File: REG Project Copy
- Homepage node: `4201:3`
- Core fonts: Hanken Grotesk and Inter
- Primary accent: `#DA291C`
- Main text: `#281715`
- Preserve the design hierarchy while making every section responsive and CMS-driven.

## Development workflow
Before editing:
1. Inspect the relevant module/theme and existing conventions.
2. Check `git status -sb` and avoid unrelated changes.
3. Preserve database compatibility and make update hooks idempotent.

After editing, run:
```bash
ddev exec php -l web/modules/custom/reg_core/src/Controller/PortalController.php
ddev exec "find web/modules/custom/reg_core web/themes/custom/reg_theme -name '*.php' -print0 | xargs -0 -n1 php -l"
ddev drush cr
ddev drush updb -y
ddev drush status
```

For frontend changes also run available JavaScript syntax checks and visually verify desktop and mobile pages in the browser.

## Current recovery task
The phase-two update failed because `PortalController` redeclared the inherited `ControllerBase::$entityTypeManager` property as readonly. Use a distinct injected property name such as `$regEntityTypeManager`, update all references, clear caches, and rerun database updates.
