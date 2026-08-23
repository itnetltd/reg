<?php

/**
 * @file
 * Business-area governance for REG editorial content.
 */

use Drupal\content_moderation\Plugin\WorkflowType\ContentModerationInterface;
use Drupal\user\Entity\Role;
use Drupal\workflows\Entity\Workflow;

/**
 * Applies workflows and exact, least-privilege business role permissions.
 */
function reg_core_apply_business_governance(): void {
  reg_core_configure_governance_workflows();

  $sports = reg_core_sports_bundles();
  $communications = [
    'reg_news', 'reg_social_post', 'reg_publication', 'reg_public_alert',
    'reg_homepage_hero', 'reg_section_hero', 'reg_video', 'reg_about_page',
    'reg_value', 'reg_leader', 'reg_partner', 'reg_employee_recognition',
  ];
  $energy = ['reg_what_we_do_page', 'reg_project', 'reg_power_plant', 'reg_fact', 'reg_access_statistic'];
  $customer = ['reg_service', 'reg_faq', 'reg_branch', 'reg_energy_tool'];
  $procurement = ['reg_tender', 'reg_procurement_plan', 'reg_procurement_plan_item'];
  $hr = ['reg_job', 'reg_recruitment_plan', 'reg_recruitment_plan_item'];

  $definitions = [
    'reg_sports_editor' => ['Sports Manager', $sports, FALSE, ['access reg sports administration', 'view reg sports dashboard', 'view reg analytics dashboard', 'export reg analytics reports'], ['reg_sports_editorial'], ['image', 'remote_video']],
    'reg_communications_editor' => ['Communications Editor', $communications, FALSE, ['access reg communications administration', 'view reg content dashboard', 'view reg publications dashboard'], ['reg_news_editorial', 'reg_social_post_editorial', 'reg_public_information_editorial', 'reg_customer_service_editorial', 'reg_section_hero_editorial', 'reg_about_editorial', 'reg_communications_editorial'], ['image', 'document', 'remote_video', 'video']],
    'reg_content_approver' => ['Communications Approver', array_values(array_unique(array_merge($communications, $sports, $energy, $customer))), TRUE, ['access reg communications administration', 'access reg sports administration', 'access reg customer service administration', 'access reg energy administration', 'view reg content dashboard', 'view reg publications dashboard', 'view reg sports dashboard', 'view reg translation report', 'view reg public information reports'], ['reg_news_editorial', 'reg_social_post_editorial', 'reg_public_information_editorial', 'reg_sports_editorial', 'reg_customer_service_editorial', 'reg_faq_editorial', 'reg_section_hero_editorial', 'reg_about_editorial', 'reg_communications_editorial', 'reg_energy_editorial'], ['image', 'document', 'remote_video', 'video']],
    'reg_procurement_officer' => ['Procurement Officer', $procurement, FALSE, ['access reg procurement administration', 'administer reg procurement plans', 'view reg procurement dashboard'], ['reg_public_information_editorial'], ['document']],
    'reg_procurement_approver' => ['Procurement Approver', $procurement, TRUE, ['access reg procurement administration', 'administer reg procurement plans', 'override reg procurement plan defaults', 'change reg procurement lifecycle', 'view reg procurement dashboard'], ['reg_public_information_editorial'], ['document']],
    'reg_hr_officer' => ['HR Officer', $hr, FALSE, ['access reg hr administration', 'administer reg recruitment plans', 'view reg recruitment dashboard'], ['reg_public_information_editorial'], ['document']],
    'reg_hr_approver' => ['HR Approver', $hr, TRUE, ['access reg hr administration', 'administer reg recruitment plans', 'override reg recruitment plan defaults', 'change reg recruitment lifecycle', 'view reg recruitment dashboard'], ['reg_public_information_editorial'], ['document']],
    'reg_outage_editor' => ['Outage Editor', ['reg_outage'], FALSE, ['access reg outage administration', 'view reg operations dashboard'], ['reg_customer_service_editorial'], ['document']],
    'reg_outage_approver' => ['Outage Approver', ['reg_outage'], TRUE, ['access reg outage administration', 'change reg outage operational status', 'view reg operations dashboard'], ['reg_customer_service_editorial'], ['document']],
    'reg_customer_service_editor' => ['Customer Service Editor', $customer, FALSE, ['access reg customer service administration', 'view reg customer service dashboard', 'view reg faq unanswered report'], ['reg_customer_service_editorial', 'reg_faq_editorial'], ['image', 'document']],
    'reg_energy_content_editor' => ['Energy Content Editor', $energy, FALSE, ['access reg energy administration'], ['reg_energy_editorial'], ['image', 'document']],
  ];

  $available = array_fill_keys(array_keys(\Drupal::service('user.permissions')->getPermissions()), TRUE);
  foreach ($definitions as $id => [$label, $bundles, $approver, $extras, $workflows, $media_types]) {
    $permissions = [
      'access content',
      'access administration pages',
      'view the administration theme',
      'view own unpublished content',
    ];
    foreach ($bundles as $bundle) {
      $permissions[] = "create $bundle content";
      $permissions[] = "edit own $bundle content";
      $permissions[] = "edit any $bundle content";
      $permissions[] = "view $bundle revisions";
      $permissions[] = "revert $bundle revisions";
    }
    foreach ($workflows as $workflow) {
      $permissions[] = "use $workflow transition create_new_draft";
      $permissions[] = "use $workflow transition submit_for_review";
      if ($approver) {
        $permissions[] = "use $workflow transition return_to_draft";
        $permissions[] = "use $workflow transition publish";
        $permissions[] = "use $workflow transition archive";
      }
    }
    if ($media_types) {
      $permissions[] = 'view media';
      $permissions[] = 'access media overview';
      foreach ($media_types as $media_type) {
        $permissions[] = "create $media_type media";
        $permissions[] = "edit own $media_type media";
        if ($approver) {
          $permissions[] = "edit any $media_type media";
        }
      }
    }
    $permissions = array_values(array_filter(array_unique(array_merge($permissions, $extras)), static fn (string $permission): bool => isset($available[$permission])));
    $role = Role::load($id) ?: Role::create(['id' => $id, 'label' => $label]);
    $role->set('label', $label);
    $role->set('permissions', $permissions);
    $role->save();
  }

  // Limited taxonomy maintenance needed by the subject-matter teams.
  reg_core_governance_add_permissions('reg_sports_editor', [
    'create terms in reg_sports_competition', 'edit terms in reg_sports_competition',
    'create terms in reg_sports_season', 'edit terms in reg_sports_season',
    'create terms in reg_sports_sport', 'edit terms in reg_sports_sport',
    'create terms in reg_sports_position', 'edit terms in reg_sports_position',
  ], $available);
  foreach (['reg_outage_editor', 'reg_outage_approver'] as $role_id) {
    reg_core_governance_add_permissions($role_id, [
      'create terms in reg_district', 'edit terms in reg_district',
      'create terms in reg_sector', 'edit terms in reg_sector',
    ], $available);
  }
  foreach (['reg_communications_editor', 'reg_content_approver'] as $role_id) {
    reg_core_governance_add_permissions($role_id, [
      'create terms in reg_news_category', 'edit terms in reg_news_category',
      'create terms in reg_video_category', 'edit terms in reg_video_category',
    ], $available);
  }

  // Preserve assignments while retiring the two superseded duplicate roles.
  reg_core_governance_migrate_role('reg_procurement_editor', 'reg_procurement_officer');
  reg_core_governance_migrate_role('reg_procurement_planner', 'reg_procurement_officer');
  reg_core_governance_migrate_role('reg_hr_editor', 'reg_hr_officer');
  reg_core_governance_migrate_role('reg_hr_planner', 'reg_hr_officer');
}

/** Adds valid permissions without exposing config-wide taxonomy administration. */
function reg_core_governance_add_permissions(string $role_id, array $permissions, array $available): void {
  if ($role = Role::load($role_id)) {
    foreach ($permissions as $permission) {
      if (isset($available[$permission])) {
        $role->grantPermission($permission);
      }
    }
    $role->save();
  }
}

/** Migrates users from a legacy role and removes it. */
function reg_core_governance_migrate_role(string $legacy_id, string $replacement_id): void {
  $legacy = Role::load($legacy_id);
  if (!$legacy || !Role::load($replacement_id)) {
    return;
  }
  $storage = \Drupal::entityTypeManager()->getStorage('user');
  $ids = $storage->getQuery()->accessCheck(FALSE)->condition('roles', $legacy_id)->execute();
  foreach ($storage->loadMultiple($ids) as $account) {
    $account->addRole($replacement_id)->removeRole($legacy_id)->save();
  }
  $legacy->delete();
}

/** Configures the remaining business workflows and missing review states. */
function reg_core_configure_governance_workflows(): void {
  $states = [
    'draft' => ['label' => 'Draft', 'weight' => 0, 'published' => FALSE, 'default_revision' => FALSE],
    'needs_review' => ['label' => 'Needs review', 'weight' => 5, 'published' => FALSE, 'default_revision' => FALSE],
    'published' => ['label' => 'Published', 'weight' => 10, 'published' => TRUE, 'default_revision' => TRUE],
    'archived' => ['label' => 'Archived', 'weight' => 20, 'published' => FALSE, 'default_revision' => TRUE],
  ];
  $transitions = [
    'create_new_draft' => ['label' => 'Create new draft', 'from' => array_keys($states), 'to' => 'draft', 'weight' => 0],
    'submit_for_review' => ['label' => 'Submit for review', 'from' => ['draft'], 'to' => 'needs_review', 'weight' => 5],
    'return_to_draft' => ['label' => 'Return to draft', 'from' => ['needs_review'], 'to' => 'draft', 'weight' => 10],
    'publish' => ['label' => 'Publish', 'from' => ['needs_review', 'published'], 'to' => 'published', 'weight' => 15],
    'archive' => ['label' => 'Archive', 'from' => ['published', 'archived'], 'to' => 'archived', 'weight' => 20],
  ];
  $definitions = [
    'reg_energy_editorial' => ['REG energy editorial', ['reg_what_we_do_page', 'reg_project', 'reg_power_plant', 'reg_fact', 'reg_access_statistic']],
    'reg_communications_editorial' => ['REG communications editorial', ['reg_homepage_hero', 'reg_video']],
    'reg_social_post_editorial' => ['REG social post editorial', ['reg_social_post']],
  ];
  foreach ($definitions as $id => [$label, $bundles]) {
    $workflow = Workflow::load($id) ?: Workflow::create(['id' => $id, 'label' => $label, 'type' => 'content_moderation']);
    $workflow->set('type_settings', [
      'states' => $states,
      'transitions' => $transitions,
      'entity_types' => [],
      'default_moderation_state' => 'draft',
    ]);
    $plugin = $workflow->getTypePlugin();
    if ($plugin instanceof ContentModerationInterface) {
      foreach ($bundles as $bundle) {
        $plugin->addEntityTypeAndBundle('node', $bundle);
      }
    }
    $workflow->save();
  }
  // FAQ predated the four-state pattern; retain its bundle association while
  // adding the same review, publication, and archive controls.
  if ($workflow = Workflow::load('reg_faq_editorial')) {
    $settings = $workflow->get('type_settings');
    $settings['states'] = $states;
    $settings['transitions'] = $transitions;
    $settings['default_moderation_state'] = 'draft';
    $workflow->set('type_settings', $settings)->save();
  }
  // Add Energy Tools to the existing customer-service workflow.
  if (($workflow = Workflow::load('reg_customer_service_editorial')) && ($plugin = $workflow->getTypePlugin()) instanceof ContentModerationInterface) {
    $plugin->addEntityTypeAndBundle('node', 'reg_energy_tool');
    $workflow->save();
  }
}
