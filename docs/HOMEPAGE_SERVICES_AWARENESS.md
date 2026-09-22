# Homepage Quick Services and Energy Awareness

Only these two homepage sections were refined. Routes, calculators, the floating FAQ widget, and other homepage sections remain intact.

## Quick Services

The previous eight cards were Online Services, New Connection, Pay Bill, Check Outages, Bill Estimator, Report a Problem, Branch Locator, and Help & FAQs.

The new layout has one full-width Online Services gateway above three compact cards: Check Outages, Branch Locator, and Help & FAQs. On mobile they stack in that order.

The gateway description is: “Access REG's online customer service portal to apply for services, manage requests and get support digitally.” Eight compact tags describe connections, title transfer, electricity purchases and receipts, prepaid arrears, meter faults, no power supply, complaints, and other requests. The CTA uses the existing configured https://online.reg.rw/ link, opens a new tab with a screen-reader notice, and includes noopener/noreferrer.

Standalone New Connection, Pay Bill, Bill Estimator, and Report a Problem cards were removed from Quick Services. Their destinations remain available. The existing fallback/preprocess architecture and quick_services block region are retained. Matching reg_service nodes can still supply translated titles and summaries; none were configured on the inspected site.

## Energy Awareness and editorial video

The existing reg_energy_tool nodes remain authoritative for Bill Estimator, Carbon Footprint, and Safety & Efficiency. Their descriptions, CTAs, translations, and automatic status logic remain intact. Bill Estimator is Available; Carbon Footprint remains Awaiting approved factor.

The educational video is the fourth compact card in the same grid as the tools. Desktop uses four columns, tablet (601–1100px) uses two columns, and mobile uses one column. Without an eligible video, the three tools span evenly on desktop. The compact intro and cards target a 400–450px desktop section; cards grow with content rather than using fixed heights. It uses the existing reg_video fields, provider normalization, thumbnail fallback, and accessible modal behavior. No iframe is rendered until interaction. It is hidden when no eligible video exists.

To show a video:

1. Open Content → Add content → Video, or /node/add/reg_video (https://reg-website.ddev.site/node/add/reg_video), or edit an existing Videos (reg_video) item.
2. Set its title, approved YouTube URL/provider, description, thumbnail, publication date, and category.
3. Choose Customer Education (created by the idempotent post-update) or Energy Awareness.
4. Enable Featured and publish through the existing workflow.
5. Adjust Weight/display order if multiple videos qualify; publication date and node ID break ties. The existing category, featured/published fields, and video content type are reused.

Selection respects publication, view access, language, category, and Featured. Existing videos were not recategorized. Temporary verification content was removed.

## Validation

- Required DDEV PHP lint, cache rebuild, database updates, and Drupal status checks.
- Existing JavaScript syntax checks.
- Calculator status tests: 7 tests / 7 assertions.
- Video provider tests: 10 tests / 23 assertions.
- DDEV-run headless browser checks at 1440, 1280, 768, 390, and 320 pixels, including both configured-video and hidden-video states.
- Four gateway cards, requested order, eight portal tags, three energy tools, safe portal link, and no horizontal overflow.
- Lazy thumbnail loading, lazy iframe creation, correct video URL, no autoplay, Escape dismissal, player removal, and focus restoration.
- Repository checks exclude uncategorized, unpublished, and unfeatured videos.
- Official Online Services destination returned HTTP 200.

The in-app browser was unavailable; verification used isolated headless Chrome with the test runner inside DDEV. PHPUnit reported a pre-existing unwritable HTML-output directory warning; both unit suites passed.

## Changed files

- web/themes/custom/reg_theme/reg_theme.theme
- web/themes/custom/reg_theme/templates/layout/page--front.html.twig
- web/themes/custom/reg_theme/components/quick-service/quick-service.component.yml
- web/themes/custom/reg_theme/components/quick-service/quick-service.twig
- web/themes/custom/reg_theme/components/quick-service/quick-service.css
- web/themes/custom/reg_theme/components/energy-tool-card/energy-tool-card.css
- web/themes/custom/reg_theme/components/education-video/education-video.component.yml
- web/themes/custom/reg_theme/components/education-video/education-video.twig
- web/themes/custom/reg_theme/components/education-video/education-video.css
- web/modules/custom/reg_core/templates/reg-homepage-energy-tools.html.twig
- web/modules/custom/reg_core/src/Plugin/Block/HomepageEnergyToolsBlock.php
- web/modules/custom/reg_core/src/Video/VideoRepository.php
- web/modules/custom/reg_core/src/Video/VideoRepositoryInterface.php
- web/modules/custom/reg_core/reg_core.module
- web/modules/custom/reg_core/reg_core.post_update.php
- scripts/homepage-services-awareness-browser-check.mjs
- docs/HOMEPAGE_SERVICES_AWARENESS.md

The browser script accepts REG_BROWSER_CDP, REG_BROWSER_OUTPUT, and REG_EXPECT_EDUCATION=1. Run it through ddev exec node against a dedicated Chrome debugging endpoint, with REG_EXPECT_EDUCATION matching the test content. Screenshots default to /tmp/reg-homepage-review inside DDEV.


## Compact Energy Awareness follow-up

This follow-up changes only the Energy Awareness section presentation:

- Templates: reg-homepage-energy-tools.html.twig and education-video.twig.
- Styles: energy-tool-card.css and education-video.css.
- The existing browser check now verifies four/two/one columns, desktop section/card heights, thumbnail loading, and no autoplay.
- VideoRepository::educational() is unchanged: published + featured + Customer Education/Energy Awareness category, with language/access checks, ordered by Weight then newest publication date.
- Carbon Footprint remains gated by the approved-factor status; calculator behavior is unchanged.

Measured with an educational video: 440px total section height / 288px cards at 1440px; 434px / 287px at 1280px. Tablet is 2 × 2; mobile is one column. Temporary verification content was removed.
