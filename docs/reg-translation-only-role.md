# REG Translation Only role

After deploying, run Drush updatedb and cache:rebuild. Assign only the role
REG Translation Only (reg_translation_only) to the translator. Other roles,
including the older REG Translator role, must not be assigned to this account.
Authenticated-role permissions are inherited; review them for excessive grants.

The translator's own account page redirects to /admin/reports/reg-translations.
Login/password reset/logout are unchanged. Direct workspace links remain valid.

The role uses native per-bundle translation permissions, not general node edit,
create, delete or publish permissions. It grants only configured draft/review
transitions whose destination is unpublished and non-default. Non-moderated
bundles are intentionally excluded. Interface translation is enabled with Drupal's translate interface permission;
general configuration translation, administration, media upload and unsafe
text formats are not granted. The workspace links to interface translation
when route access allows it. Locale translations apply immediately and this
permission also permits Locale translation import/export and update reports.
It covers interface strings across configured languages, including admin UI.
Original language edit links are suppressed for the dedicated role.
Review and publication are performed by an existing authorized approver.
Public site and own account settings remain available.

After deployment, verify with the actual account: workspace loads, add/edit a
translation, save as draft/review, original node edit/create/delete and user/
configuration/media admin routes are denied. Check inherited permissions,
including for authenticated users. Verify the source language is unchanged.
Some existing text formats or unpublished drafts may require separately
reviewed permissions. Never grant general node editing as a workaround.

To refresh role permissions after changing workflow or translation settings:
Drush php:eval '\Drupal\reg_core\Translation\TranslationOnlyRole::apply();'
