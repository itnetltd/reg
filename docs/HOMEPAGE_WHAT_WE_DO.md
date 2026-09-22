# Homepage What We Do redesign

## Position and scope

Old position: after Energy Awareness, following Service Status.
New position: Quick Services → What We Do → Service Status / Outages.

There is exactly one What We Do section. The existing outage lookup, all other homepage sections, and inner page content were preserved. This revision supersedes the earlier four-column / theme-image-selector implementation.

## Drupal content and selection

Content type: existing `reg_what_we_do_page`; no new content type or duplicate nodes.

Fields used:

- `title`: current-language card heading.
- `field_reg_summary`: preferred current-language description.
- `field_reg_featured_image`: primary Image Media reference.
- `field_reg_wwd_key`: stable selection and display order.
- `status` and `langcode`: published/current-language eligibility.
- Node canonical URL: card destination.

`field_reg_wwd_group` was verified but is not needed for primary selection: matching exact primary keys excludes child pages reliably. No `field_reg_order` exists on this content type.

The homepage-only theme loader queries the four primary keys with entity access checks, current content language and published translation status. It then explicitly verifies the requested translation and view access. No cross-language node fallback is allowed. Display order is `generation`, `transmission`, `distribution`, `access`; neither titles nor node IDs identify or order cards. If duplicate keys exist, latest changed content then UUID determines the candidate.

`HomepageRepository` is not involved. Cache metadata covers node lists, entities, media, files, responsive image configuration, access and language.

## Descriptions and editorial flags

Descriptions use the first sentence of an evergreen public summary, capped at 150 characters and two visual lines. Missing summaries and summaries containing legacy time markers (`Currently`, `As of`, `today`, financial years or explicit years) are flagged by the loader. They receive short, translatable navigation copy derived from the node title. The old body field is never used as a homepage paragraph or overwritten.

| Item | Summary review | Featured image |
|---|---|---|
| Generation | Dated installed-capacity statistics; evergreen summary needed | Existing Energy Mix chart, Media 53 |
| Transmission | Dated June 2023 statistics; evergreen summary needed | Existing transmission substation photo, Media 58 |
| Distribution | Dated “As of today” statistics; evergreen summary needed | Existing distribution network map, Media 60 |
| Electricity Access | Dated July 2025 statistics; evergreen summary needed | Missing; existing What We Do fallback Media 17 is used |

Editorial action: update each public summary through **Content → What We Do → Edit**. If photographs are preferred over the current chart/map, select them in the same node's Featured image field. Current fields and original historical bodies were intentionally preserved. Generic Media alt text can also be improved editorially.

All four current records are English-only. The Kinyarwanda homepage therefore omits this section until published Kinyarwanda translations exist; it does not silently show English cards.

## Images and editor control

Images come directly from each node's `field_reg_featured_image`. An empty or unavailable image uses the published, active, section-wide `reg_section_hero` record identified by `field_reg_section_key = what_we_do`, through `field_reg_background_image`. There are no hard-coded image paths or legacy hotlinks.

The new responsive image style `reg_homepage_work` selects existing `max_325x325`, `max_650x650` and `max_1300x1300` derivatives according to card width. Its fallback is `max_650x650`. Images retain dimensions and Media alt text and load lazily. CSS crops with `object-fit: cover`.

Obsolete per-card theme image overrides and their theme-settings controls were removed. Editors now control title, summary, featured image and publication through the existing node edit form. A repeatable post-update installs the responsive image style and retires only those old overrides without changing node content.

## Files

- Twig: `web/themes/custom/reg_theme/templates/layout/page--front.html.twig` and `templates/partials/homepage-work.html.twig`.
- CSS: `web/themes/custom/reg_theme/css/homepage-work.css` only for this revision.
- PHP: homepage functions in `web/themes/custom/reg_theme/reg_theme.theme`; focused configuration update in `web/modules/custom/reg_core/reg_core.post_update.php`.
- Configuration: `web/modules/custom/reg_core/config/install/responsive_image.styles.reg_homepage_work.yml`.
- Removed obsolete What We Do-only `theme-settings.php` and `config/schema/reg_theme.schema.yml` from the earlier implementation.

## Responsive verification

| Width | Columns | Card height | Section padding per side |
|---|---:|---:|---:|
| 1920 | 2 | 527px | 80px |
| 1440 | 2 | 527px | 80px |
| 1366 | 2 | 526px | 80px |
| 1024 | 2 | 431px | 64px |
| 768 | 2 | 373px | 64px |
| 390 | 1 | 392px | 48px |

Every viewport passed section order, no duplication, four English cards, image loading, responsive derivatives, dimensions, two-line descriptions and no horizontal overflow. All four canonical links and Explore all resolved. Inner pages contained no homepage cards.

Read-only checks using unsaved node copies passed: unpublished exclusion, unpublished-translation exclusion, published-translation handling, editor summary/image changes, missing-field fallback, cache metadata and idempotent update. Original revisions, body hashes, public summaries and featured-image references matched the pre-change snapshot.

Passed `ddev drush cr`, required PHP lint, theme PHP lint, JavaScript syntax, `ddev drush updb -y` and `ddev drush status`. Visual verification used isolated Chrome because the in-app browser was unavailable.

Keyboard Tab navigation showed a visible 3px focus outline. Desktop hover measured 1.03 image scale, 3px upward card movement and 3px arrow movement; reduced motion disabled those transforms. The existing node edit form exposes title, public summary, Media Library featured image, status and moderation controls.
