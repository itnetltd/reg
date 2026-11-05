# REG public-theme fonts

Inter is the body, navigation, control and paragraph family. Hanken Grotesk is the heading/title family, including footer headings. Both normal and italic faces expose their real variable `wght` axis from 100 to 900. This supports existing intermediate weights such as 650, 750 and 850 without separate downloads or synthetic weight matching. Existing font sizes, line heights, spacing and component layout rules are retained.

## License and provenance

These fonts are distributed under the SIL Open Font License 1.1. Preserve `inter-OFL.txt` and `hankengrotesk-OFL.txt` with the assets. Source files come from the official Google Fonts repository:

- Inter: https://github.com/google/fonts/tree/0b58fb370093f9a9f4ff785d94405710b79de67c/ofl/inter
- Hanken Grotesk: https://github.com/google/fonts/tree/c1eda9233c33ad7775b27efd794f931095cf6133/ofl/hankengrotesk

`manifest.json` records exact source URLs, commits, font versions, axes, byte sizes and output SHA-256 hashes. The full upstream variable fonts were converted from TTF to WOFF2 using fontTools 4.68.0 and Brotli 1.2.1. No glyph subsetting, outline editing, weight instancing or font-name changes were performed. English and Kinyarwanda glyph coverage is retained. Fonts remain OFL-licensed.

## Loading and deployment

The public `reg_theme/global` library depends once on `reg_theme/fonts`, which loads `css/fonts.css`. Each face has `font-display: swap`. Only styles actually used by a page are requested; no JavaScript font loader, Google Fonts stylesheet, preload duplicates, `local()` lookup or external font service is used. Content-hashed WOFF2 filenames prevent older font binaries surviving updates. The CSS and assets use relative URLs so Drupal CSS aggregation, subdirectories and different hostnames can resolve them normally.

Deploy `reg_theme.libraries.yml`, `css/fonts.css`, the footer heading family adjustment in `css/layout.css`, and the entire `assets/fonts` directory together. Run `drush cr` on each target after the normal approved deployment. Invalidate cached CSS in any reverse proxy/CDN as appropriate. Serve WOFF2 as `font/woff2`; an existing `font-src` CSP must allow the same origin (or the configured asset CDN with its appropriate CORS headers). No database update, configuration import, runtime package installation or external font domain is required for this typography change. QA-only npm/Python packages in ignored tmp directories are not deployment dependencies.

The Drupal admin theme is unaffected: this library belongs exclusively to reg_theme. Confirm real rendered fonts in Chrome/Edge DevTools rather than relying only on computed `font-family`, which can list a family that has not loaded. A clean homepage should request each normal face once; italic faces load only when italic text is used. Original source CSS had no font loader, and baseline Chrome/Edge testing showed Arial on both local and staging.
