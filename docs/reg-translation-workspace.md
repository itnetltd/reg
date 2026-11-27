# REG Translation Workspace

Open Content → Translations, or /admin/reports/reg-translations (the existing dashboard link also works).

- Missing translations is the default tab. Search by original or accessible translated title or node ID.
- Select English, Kinyarwanda or both; narrow by content type, source publication status, owner or department.
- Counts reflect the current search and language filters, independently of the selected status tab. The report pages 25 matching items and checks both language directions.
- Add translation and Edit open the standard Drupal forms only when Drupal route access permits. The destination returns editors to the filtered worklist after saving, subject to core editorial redirects.
- Draft translations accessible in pending revisions are not counted as missing. Published translations with core's outdated metadata are shown as flagged outdated; the report does not claim to detect untranslated text copied into an existing translation or every unpublished edit to an already-published language.
- The workspace uses an explicit allowlist of public, translation-enabled node bundles. Private submissions are excluded. Official document contents, menu labels, interface strings and configuration are separate workflows. Existing dashboard aggregates and CSV behavior are unchanged.

No machine translation, external API, content update, migration or permission grant is performed. The existing view reg translation report permission and Drupal's translation/edit/publish permissions continue to apply.
