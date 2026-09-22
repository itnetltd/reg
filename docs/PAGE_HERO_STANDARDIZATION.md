# Public page hero standardization

## Continuation state

At continuation, Git showed only pre-existing homepage service/video edits. Those edits were preserved. No interrupted hero edit was present in the working tree.

The committed site already had a CMS-driven `reg-section-hero.html.twig`, route classification in `SectionHeroResolver`, section artwork, page-level hero ownership that avoids duplicate headings, and compact About breadcrumbs. The unfinished work was sizing: shared landing/standard/compact headers remained oversized, with separate news and image-based sports implementations and unused feature-specific hero CSS.

## Completed presentation changes

- Reused the section-hero template and its existing library.
- Added `.reg-page-hero` with `--landing`, `--archive`, and `--detail` variants, plus shared `__inner`, `__content`, `__eyebrow`, `__title`, and `__intro` classes.
- Centralized typography, padding, text width and responsive height floors in `css/section-hero.css`.
- Kept the resolver's existing data contract: `landing` maps to landing, `standard` to archive, and `compact` to detail. What We Do informational pages use landing sizing; Branch Locator explicitly uses archive.
- Preserved backgrounds, breadcrumbs, all copy, links and metadata. No controller, entity, field, configuration or backend behavior changes were introduced.
- News, tender/public-information and sports detail summaries now follow the compact title header as readable body text. They are not truncated.
- Sports landing retains the existing photograph; its existing match/story panel follows the hero and section navigation instead of increasing hero height.
- Removed unused legacy hero rules from feature CSS and removed tender-specific title-width/line-clamp overrides.
- Kept the homepage main hero, navigation and Drupal administration unchanged.

## Variant coverage

| Variant | Routes/templates |
| --- | --- |
| Landing | /about, /what-we-do and its informational descendants including /what-we-do/generation, /customer-services, /public-information, /media, /sports, sports team pages, /contact |
| Archive | About informational child pages; media news, press releases, announcements, newsletters, publications, documents, videos, gallery and social; sports news, fixtures, results, standings, players, gallery, videos and search; tenders, jobs, branches; support/search/listing pages using the shared standard size |
| Detail | Corporate and sports news articles; tender, job, publication, branch and outage details; project nodes via the existing resolver; sports player, match and gallery details |

## Responsive system

| Variant | Desktop floor / H1 | Tablet floor / H1 | Mobile floor / H1 |
| --- | --- | --- | --- |
| Landing | 300px / 52–56px | 260px / 42–48px | 210px / 34–40px |
| Archive | 240px / 44–50px | 220px / 38–44px | 190px / 30–36px |
| Detail | 200px / 36–40px | 190px / 32–40px | 180px / 28–34px |

Desktop begins at 1100px; tablet begins at 768px. Content containers cap at 1280px, title content at 780px, and shared hero introductions at 740px. Heights are minimums, not fixed boxes; translations and long titles wrap naturally. Desktop actions sit alongside copy. About breadcrumbs retain their approximately 44px band.

## Desktop measurements

Measured at a 1440 × 1000 CSS-pixel viewport.

| Route | Before | After | Final H1 |
| --- | ---: | ---: | ---: |
| /about | 390px | 300px | 56px |
| /what-we-do/generation | 384px | 310px | 56px |
| /media/news | 360px | 243px | 50px |
| /sports/news | 360px | 240px | 50px |

## Intentional exceptions

The homepage keeps its existing marketing hero. Long titles, preserved descriptions and stacked mobile action buttons can expand beyond the minimums; nothing is clipped or forced onto one line. Generation's long published introduction is one such mobile case (about 301px at 390px wide). The long tender title expands its header to about 260px desktop / 313px mobile; stacked Tenders and Contact buttons also exceed the nominal mobile floors. These are content-driven expansions, not extra route-specific sizes. Team-specific imagery/logos and metadata remain supported without a separate arbitrary size.

## Files changed for this task

Under `web/modules/custom/reg_core/`:

- `css/section-hero.css`
- `css/newsroom.css`
- `css/sports-portal.css`
- `css/tenders.css`
- `css/customer-support.css`
- `css/faq-page.css`
- `css/featured-videos.css`
- `css/outage-center.css`
- `css/public-information.css`
- `css/what-we-do.css`
- `templates/reg-section-hero.html.twig`
- `templates/reg-branch-locator.html.twig`
- `templates/reg-sports-landing.html.twig`
- `templates/reg-sports-team.html.twig`
- `templates/reg-sports-detail.html.twig`

Also:

- `web/themes/custom/reg_theme/templates/content/node--reg-news.html.twig`
- `scripts/page-heroes-browser-check.mjs`
- `docs/PAGE_HERO_STANDARDIZATION.md`

The other modified/untracked homepage files were already present and were not changed by this task.

## Validation

- Required PortalController and custom PHP lint: passed.
- `ddev drush cr`: passed.
- `ddev drush updb -y`: no pending updates.
- `ddev drush status`: successful bootstrap and database connection.
- Browser audit JavaScript syntax checked through DDEV.
- Initial required-route audit: 13 routes at 1440, 1280, 768, 390, 375 and 430px; one H1 and one hero per page, loaded artwork, no horizontal overflow.
- Extended checks cover section hubs, media and sports child templates and existing published news, tender, branch, player and match details.
- Final combined audit: **35 public routes × 3 widths (1440, 768, 390px), 105 checks passed**. Every page had one H1 and one hero, with loaded hero images and no horizontal or title overflow. All H1s remained within the shared responsive font limits.
- Focused final Tenders/Contact checks: desktop and mobile passed; screenshots visually reviewed.
- Final news detail examples measured approximately 210px on desktop. The long tender detail measured 260px; its full title remains readable.
- A missing closing Twig condition introduced during the final summary-layout edit was detected, corrected, cache-cleared and covered by the successful final audit. No rendering errors remain in the checked routes.
- Desktop, tablet and mobile screenshots were reviewed for About, Generation, Sports and representative archive/detail headers.

The in-app browser was unavailable. Verification used a separate headless Chrome instance, following the repository's existing browser-check approach. The audit writes JSON measurements and representative screenshots to the operating-system temporary directory. Run `node scripts/page-heroes-browser-check.mjs --all` on the Windows host to repeat the combined browser audit; `--extended` checks only additional routes and published detail links.

Remaining issues: none found in the tested routes. No published job/publication detail link was discovered in the current listings; their shared template was inspected, but those detail URLs were not browser-tested.

No commit or push was performed.
