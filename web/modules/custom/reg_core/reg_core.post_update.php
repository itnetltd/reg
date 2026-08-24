<?php

/**
 * @file
 * Post-update functions for REG Core.
 */

/**
 * Installs the phase-two structured content and service foundation.
 */
function reg_core_post_update_phase_two_foundation(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_apply_foundation();
  return 'REG content types, fields, editorial roles, multilingual readiness and public service tools were installed.';
}

/**
 * Adds source-selection defaults for the API-independent Outage Center.
 */
function reg_core_post_update_outage_center_client_selection(?array &$sandbox = NULL): string {
  $config = \Drupal::configFactory()->getEditable('reg_core.settings');
  if ($config->get('dms.driver') === NULL) {
    $config->set('dms.driver', 'mock');
  }
  if ($config->get('dms.outages_path') === NULL) {
    $config->set('dms.outages_path', '/outages');
  }
  $config->save(TRUE);
  return 'Configured the mock-first DMS client selection and GE outage endpoint placeholder.';
}

/**
 * Moves GE DMS secrets out of exportable Drupal configuration.
 */
function reg_core_post_update_outage_center_environment_credentials(?array &$sandbox = NULL): string {
  $config = \Drupal::configFactory()->getEditable('reg_core.settings');
  if ($config->get('dms.credential_environment_variable') === NULL) {
    $config->set('dms.credential_environment_variable', 'REG_GE_DMS_CREDENTIAL');
  }
  $config->clear('dms.api_key')->save(TRUE);
  return 'Configured environment-only GE DMS credentials and removed the exportable credential placeholder.';
}

/**
 * Builds the bilingual, governed FAQ knowledge base and analytics storage.
 */
function reg_core_post_update_bilingual_faq_knowledge_base(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_apply_faq_knowledge_base();
  return 'Configured bilingual FAQ fields, translation, moderation, roles, and privacy-filtered unanswered-query analytics.';
}

/**
 * Grants explicit FAQ translation permissions to translators and approvers.
 */
function reg_core_post_update_faq_translation_permissions(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_create_roles();
  return 'Granted FAQ translation creation and update permissions to translators and content approvers.';
}

/**
 * Builds the governed Public Information Hub and aggregate analytics storage.
 */
function reg_core_post_update_public_information_hub(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_apply_public_information_hub();
  return 'Configured governed tenders, jobs, publications, official Media documents, supplier subscriptions, and privacy-preserving download analytics.';
}

/**
 * Completes Media and translation permissions for hub editorial roles.
 */
function reg_core_post_update_public_information_editorial_permissions(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_create_roles();
  return 'Granted controlled document Media and public-information translation permissions to the appropriate editorial roles.';
}

/**
 * Builds the structured and governed REG Sports Portal.
 */
function reg_core_post_update_sports_portal(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_apply_sports_portal();
  return 'Configured sports teams, players, staff, connected fixtures/results, standings, news, galleries, videos, taxonomies, workflow, and editorial permissions.';
}

/**
 * Adds responsive sports derivatives and their breakpoint mapping.
 */
function reg_core_post_update_sports_responsive_images(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_ensure_sports_image_style();
  return 'Configured small, card, large, and logo sports derivatives with a Drupal responsive-image style.';
}

/**
 * Builds customer-support, Branch Locator, and complaint-routing architecture.
 */
function reg_core_post_update_customer_support_and_branches(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_apply_customer_support_phase();
  return 'Enhanced branches, customer-support routes, complaint-mode configuration, location taxonomies, and editorial governance.';
}

/**
 * Completes language, translation policy, and governance configuration.
 */
function reg_core_post_update_multilingual_completion(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_apply_multilingual_completion();
  return 'Enabled bilingual configuration, URL prefixes, public-bundle translation, field policy, and translation governance fields.';
}

/**
 * Adds aggregate analytics, provider configuration, and REG dashboards.
 */
function reg_core_post_update_analytics_and_admin_dashboards(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_apply_analytics_dashboards();
  return 'Installed privacy-preserving event aggregates, analytics provider placeholders, dashboard permissions, and governance defaults.';
}

/**
 * Installs Drupal-managed bilingual header navigation and public search.
 */
function reg_core_post_update_drupal_managed_public_navigation(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_apply_navigation();
  return 'Created the bilingual Main and REG utility menus from live routes, retained future links as disabled entries, and enabled public content search.';
}

/**
 * Removes the Standard profile Home link from the public REG menu.
 */
function reg_core_post_update_navigation_profile_link_cleanup(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_apply_navigation();
  return 'Disabled the Standard profile Home menu link while preserving the REG logo home link.';
}

/**
 * Allows verified sports results without fabricated match metadata or dates.
 */
function reg_core_post_update_sports_featured_result_fields(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_apply_sports_portal();
  return 'Added a fixture hero image and public result label, and made unconfirmed competition, season, and match-date fields optional.';
}

/**
 * Applies the streamlined public header navigation to existing installations.
 */
function reg_core_post_update_streamlined_header_navigation(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_apply_navigation();
  return 'Kept the REG logo as the home link and removed the Standard profile Home item from the streamlined public navigation.';
}

/**
 * Adds season-aware basketball rosters and verified 2026 league data.
 */
function reg_core_post_update_2026_basketball_rosters(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_apply_sports_portal();
  reg_core_apply_2026_basketball_data();
  return 'Created season-aware team memberships, 2026 REG basketball rosters, source metadata, and the verified men’s regular-season standing.';
}

/**
 * Makes homepage hero and partner content fully CMS-managed.
 */
function reg_core_post_update_cms_managed_homepage_content(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_apply_homepage_content();
  return 'Created CMS-managed Homepage Heroes and Partners, responsive image styles, homepage blocks, and idempotent migrated demo content.';
}

/**
 * Repairs the featured result Media relationship used by sports cards.
 */
function reg_core_post_update_featured_result_image_relationship(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  $result = reg_core_apply_featured_result_image();
  return $result['fixture'] && $result['media']
    ? 'Attached the approved REG 74-71 Patriots Media image to its existing featured result.'
    : 'No existing REG 74-71 Patriots result was changed; the approved image relationship could not be resolved.';
}

/**
 * Builds CMS-managed homepage Featured Videos and the public video archive.
 */
function reg_core_post_update_cms_managed_featured_videos(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_apply_homepage_videos();
  return 'Created the Videos content model, provider-safe playback, responsive thumbnails, homepage block, archive route, navigation, and editorial permissions.';
}

/**
 * Builds reusable, translated, CMS-managed section page heroes.
 */
function reg_core_post_update_cms_managed_section_heroes(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_apply_section_heroes();
  return 'Created Section Heroes, imported seven default Media backgrounds, configured responsive derivatives and editorial workflow, and added the Branch Locator page override.';
}

/**
 * Allows translated image overrides and reapplies idempotent hero settings.
 */
function reg_core_post_update_section_hero_translation_images(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_apply_section_heroes();
  return 'Enabled optional per-language Section Hero Media overrides and confirmed the idempotent default records.';
}

/**
 * Builds CMS-managed Homepage Energy Awareness tools and automatic statuses.
 */
function reg_core_post_update_cms_managed_energy_awareness(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_apply_energy_tools();
  return 'Created CMS-managed Energy Tools, seeded three approved homepage cards, marked the October 2025 tariff schedule available, and connected automatic carbon-factor status.';
}

/**
 * Replaces the static service strip with scheduled CMS-managed Public Alerts.
 */
function reg_core_post_update_cms_managed_public_alerts(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_apply_public_alerts();
  return 'Created moderated, translated and scheduled Public Alerts, migrated the homepage Service Notice, and placed the alert block.';
}

/**
 * Imports the supplied official REG FAQ set with review traceability.
 */
function reg_core_post_update_official_reg_faq_content(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  $counts = reg_core_apply_official_faq_content();
  return sprintf(
    'Imported %d official REG FAQs, tagged %d existing FAQs, and left %d previously imported FAQs unchanged.',
    $counts['created'],
    $counts['tagged_existing'],
    $counts['unchanged'],
  );
}

/**
 * Imports the official REG branch directory and contact information.
 */
function reg_core_post_update_official_reg_branch_directory(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  $counts = reg_core_apply_official_branch_content();
  return sprintf(
    'Imported %d official REG branches, tagged %d existing branches, and left %d previously imported branches unchanged.',
    $counts['created'],
    $counts['tagged_existing'],
    $counts['unchanged'],
  );
}

/**
 * Adds CMS-managed official social links to public REG placements.
 */
function reg_core_post_update_cms_managed_social_media(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_apply_social_media_configuration();
  return 'Created official social media configuration for the utility bar, footer, and reusable Media Center Follow REG block.';
}

/** Enables the centrally configured, lazy homepage X timeline. */
function reg_core_post_update_homepage_x_timeline(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_apply_social_media_configuration();
  return 'Enabled the official centrally configured X timeline for the homepage News section.';
}

/**
 * Builds the professional CMS-driven News & Insights newsroom.
 */
function reg_core_post_update_professional_newsroom(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_apply_newsroom();
  return 'Upgraded REG News with Media images and galleries, taxonomy, editorial dates and workflow, homepage/archive/article presentation, and the dedicated News administration view.';
}

/**
 * Imports the approved EDCL valuation tender and upgrades tender UX.
 */
function reg_core_post_update_edcl_valuation_tender(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  $result = reg_core_apply_tender_upgrade();
  return sprintf(
    '%s EDCL tender node %d with Terms of Reference Media %d/File %d, tender archive filters, homepage cards, and Procurement administration.',
    $result['created'] ? 'Created' : 'Updated',
    $result['node_id'],
    $result['media_id'],
    $result['file_id'],
  );
}

/**
 * Builds the structured, sourced and translated About REG section.
 */
function reg_core_post_update_structured_about_reg(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  require_once __DIR__ . '/reg_core.about.inc';
  reg_core_apply_about_section(TRUE);
  return 'Created CMS-managed About pages, values, leadership, partner classifications and employee recognition; imported official portraits into Media; rebuilt the About menu and preserved legacy URLs.';
}

/**
 * Repairs the module-owned About menu link after legacy navigation migration.
 */
function reg_core_post_update_about_menu_route_repair(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  require_once __DIR__ . '/reg_core.about.inc';
  reg_core_sync_about_navigation();
  return 'Replaced the legacy disabled About menu subtree with the canonical CMS-driven About REG routes.';
}

/**
 * Applies the approved four-level What We Do information architecture.
 */
function reg_core_post_update_approved_what_we_do_navigation(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_sync_what_we_do_navigation();
  return 'Rebuilt the module-owned What We Do subtree with Electricity System, Energy Solutions, Projects, Programs, and Investment.';
}

/**
 * Adds the official-source What We Do pages and structured record types.
 */
function reg_core_post_update_official_what_we_do_content_model(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  require_once __DIR__ . '/reg_core.what_we_do.inc';
  reg_core_apply_what_we_do_content_model();
  return 'Created source-traceable What We Do pages, projects, power plants, dated facts, district access statistics, and official Media metadata.';
}

/**
 * Adds structured project scope and reapplies the idempotent model.
 */
function reg_core_post_update_official_what_we_do_project_scope(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  require_once __DIR__ . '/reg_core.what_we_do.inc';
  reg_core_apply_what_we_do_content_model();
  return 'Added structured project scope storage for official REG project details.';
}

/**
 * Adds the CMS-managed REG At a Glance homepage statistics section.
 */
function reg_core_post_update_homepage_statistics(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  require_once __DIR__ . '/reg_core.what_we_do.inc';
  reg_core_apply_what_we_do_content_model();
  reg_core_apply_homepage_statistics();
  return 'Added six approved, ordered and translatable REG At a Glance statistics with homepage block and editorial management.';
}

/**
 * Preserves editor-controlled decimal precision for animated statistics.
 */
function reg_core_post_update_homepage_statistics_decimal_precision(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  require_once __DIR__ . '/reg_core.what_we_do.inc';
  reg_core_apply_what_we_do_content_model();
  reg_core_apply_homepage_statistics();
  return 'Added editable display precision for REG At a Glance values and retained the approved integer and decimal formats.';
}

/**
 * Adds scheduled, accessible rotation for heroes and public alerts.
 */
function reg_core_post_update_hero_and_alert_rotation(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_apply_homepage_content();
  reg_core_apply_public_alerts();
  return 'Added scheduled multi-item hero and Public Alert rotation, accessible controls, responsive presentation, and homepage hero administration.';
}

/**
 * Adds the expanded editorial type options to rotating Public Alerts.
 */
function reg_core_post_update_alert_rotation_type_options(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_apply_public_alerts();
  return 'Added Service Notice, Customer Notice, and Procurement type options to the rotating Public Alert editor.';
}

/**
 * Replaces the homepage Operations prototype with approved manual facts.
 */
function reg_core_post_update_energy_at_glance(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  require_once __DIR__ . '/reg_core.what_we_do.inc';
  reg_core_apply_what_we_do_content_model();
  reg_core_apply_energy_at_glance();
  return 'Added the manually managed Energy at a Glance section using reusable REG fact records.';
}

/**
 * Consolidates duplicate homepage fact sections into REG At a Glance.
 */
function reg_core_post_update_consolidate_homepage_statistics(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  require_once __DIR__ . '/reg_core.what_we_do.inc';
  reg_core_apply_what_we_do_content_model();
  reg_core_apply_energy_at_glance();
  reg_core_apply_homepage_statistics();
  return 'Removed the duplicate Energy at a Glance block and retained the six-stat REG At a Glance homepage section.';
}

/**
 * Replaces DMS-first outage presentation with manual multi-window notices.
 */
function reg_core_post_update_manual_outage_announcements(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_apply_manual_outages();
  return 'Added manual, revisioned multi-window outage announcements, controlled geography, documents, workflow, and public status fields.';
}

/**
 * Normalizes supplied announcement times from Kigali local time to UTC.
 */
function reg_core_post_update_manual_outage_sample_timezones(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_seed_manual_outage_samples();
  return 'Normalized historical outage announcement windows to Drupal UTC storage.';
}

/**
 * Applies the final outage editor, approver, and translator permissions.
 */
function reg_core_post_update_manual_outage_permissions(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  reg_core_create_roles();
  return 'Applied manual outage authoring, document, workflow, geography, approval, and translation permissions.';
}

/**
 * Adds plan-linked procurement/recruitment and secure portal foundations.
 */
function reg_core_post_update_plan_linked_publishing(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  require_once __DIR__ . '/reg_core.plans.inc';
  reg_core_apply_plan_linked_publishing();
  return 'Added annual plans, plan items, tender/job relationships, channel controls, separated roles, and private submission foundations.';
}

/** Applies final plan-linked form ordering and least-privilege portal roles. */
function reg_core_post_update_plan_linked_form_order(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  require_once __DIR__ . '/reg_core.plans.inc';
  reg_core_configure_plan_forms();
  reg_core_create_plan_roles();
  return 'Ordered tender/vacancy plan and channel controls and enforced least-privilege supplier/applicant roles.';
}

/** Applies controlled plan-default override permissions. */
function reg_core_post_update_plan_default_override_permissions(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  require_once __DIR__ . '/reg_core.plans.inc';
  reg_core_create_plan_roles();
  return 'Restricted overrides of plan-controlled tender and vacancy defaults to approver roles.';
}

/** Installs supplier organization onboarding, verification, and membership. */
function reg_core_post_update_supplier_registration_portal(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  require_once __DIR__ . '/reg_core.supplier.inc';
  reg_core_apply_supplier_portal();
  return 'Installed supplier onboarding, verification, organization membership, private documents, and review controls.';
}

/** Installs native, workflow-managed social posts for the homepage. */
function reg_core_post_update_native_homepage_social_posts(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  require_once __DIR__ . '/reg_core.social_post.inc';
  reg_core_apply_social_posts();
  reg_core_apply_social_media_configuration();
  return 'Replaced the homepage X widget dependency with native, moderated social-post records.';
}

/** Applies least-privilege, business-area editorial governance. */
function reg_core_post_update_business_area_governance(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  require_once __DIR__ . '/reg_core.governance.inc';
  reg_core_apply_business_governance();
  return 'Applied scoped business roles, review workflows, media policy, and administration access.';
}

/** Repairs the existing supplier registration and approval lifecycle. */
function reg_core_post_update_supplier_registration_repair(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  require_once __DIR__ . '/reg_core.supplier.inc';
  reg_core_finalize_supplier_registration();
  return 'Repaired supplier registration, pending approval roles, structured profiles, and lifecycle migration.';
}

/** Installs configurable supplier-registration notification settings. */
function reg_core_post_update_supplier_registration_notifications(?array &$sandbox = NULL): string {
  $config = \Drupal::configFactory()->getEditable('reg_core.procurement_settings');
  $defaults = [
    'supplier_registration_notification_email' => '',
    'secondary_notification_email' => '',
    'notification_sender_name' => 'REG Procurement Portal',
    'notification_sender_email' => '',
    'send_procurement_notification' => FALSE,
    'send_supplier_confirmation' => TRUE,
    'require_supplier_approval' => TRUE,
  ];
  foreach ($defaults as $key => $value) {
    if ($config->get($key) === NULL) {
      $config->set($key, $value);
    }
  }
  $config->save(TRUE);
  return 'Added configurable supplier registration recipients, sender identity, notifications, and approval policy.';
}

/** Activates primary supplier accounts and their self-registration membership. */
function reg_core_post_update_supplier_login_lifecycle(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  require_once __DIR__ . '/reg_core.supplier.inc';
  reg_core_finalize_supplier_registration();
  return 'Activated supplier self-registration accounts and memberships while preserving genuine team invitations.';
}

/** Repairs legacy primary memberships that retained an inviter value. */
function reg_core_post_update_supplier_primary_membership_activation(?array &$sandbox = NULL): string {
  require_once __DIR__ . '/reg_core.install';
  require_once __DIR__ . '/reg_core.supplier.inc';
  reg_core_finalize_supplier_registration();
  return 'Activated supplier profile owners and their primary organization memberships.';
}
