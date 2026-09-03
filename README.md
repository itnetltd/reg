# REG Drupal 11 Website

A clean Drupal 11 codebase for the Rwanda Energy Group website, structured to reproduce the approved Figma homepage and to remain maintainable by REG content administrators.

Server-side homepage X feed setup is documented in
[`docs/X_API_FEED.md`](docs/X_API_FEED.md).

## Technology

- Drupal 11.4+
- PHP 8.3+
- Composer 2
- Drush 13
- Twig, CSS and Drupal behaviors
- DDEV for local development

## Figma source

- File key: `EylqtwZFAU4lq3hfzO26cd`
- Homepage frame: `4201:3`
- Desktop reference: 1280 px wide
- Fonts: Hanken Grotesk and Inter
- Primary red: `#DA291C`
- Main text: `#281715`

The initial theme implements the page architecture and design tokens. Production images and SVG exports should be committed under `web/themes/custom/reg_theme/assets/` after downloading the original Figma exports.

## Quick start with DDEV

```bash
ddev start
ddev composer install
ddev exec ./scripts/install-site.sh
```

Open the site:

```bash
ddev launch
```

Default local administrator created by the install script:

- Username: `regadmin`
- Password: set through `REG_ADMIN_PASSWORD`, otherwise a development-only fallback is used

Example:

```bash
REG_ADMIN_PASSWORD='Use-A-Strong-Local-Password' ddev exec ./scripts/install-site.sh
```

## Without DDEV

```bash
composer install
cp web/sites/default/default.settings.php web/sites/default/settings.php
vendor/bin/drush site:install standard \
  --db-url=mysql://USER:PASSWORD@HOST/DATABASE \
  --site-name='Rwanda Energy Group' \
  --account-name=regadmin \
  --account-pass='CHANGE-ME' -y
vendor/bin/drush theme:enable reg_theme -y
vendor/bin/drush config:set system.theme default reg_theme -y
vendor/bin/drush en reg_core -y
vendor/bin/drush cr
```

## Theme architecture

`web/themes/custom/reg_theme` provides:

- Header and secondary navigation
- Hero section
- Quick-service cards
- Operations and impact section
- Service-status list
- News and insights cards
- Tender list
- Featured-video section
- Partner logos region
- Footer and floating action controls
- Responsive mobile navigation

The frontend is plain Drupal Twig/CSS/JavaScript. Tailwind and React are intentionally not included.

## Content integration plan

The theme currently includes fallback demonstration content so the homepage is visible immediately. Replace each fallback with Drupal-managed content in this order:

1. Menus and site branding
2. Hero block/paragraph
3. Quick services
4. Impact statistics
5. Outage and maintenance Views
6. News View
7. Tenders View
8. Featured video entities
9. Partners
10. Footer menus

See `docs/DRUPAL_CONTENT_MODEL.md` and `docs/FIGMA_MAPPING.md`.

## Useful commands

```bash
ddev drush cr
ddev drush status
ddev drush theme:enable reg_theme -y
ddev drush config:set system.theme default reg_theme -y
ddev drush en reg_core -y
```

## Git workflow

```bash
git checkout -b agent/initial-reg-drupal
git add .
git commit -m "Initialize REG Drupal 11 website"
git push -u origin agent/initial-reg-drupal
```
