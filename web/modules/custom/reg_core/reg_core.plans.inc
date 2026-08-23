<?php

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\workflows\Entity\Workflow;
use Drupal\content_moderation\Plugin\WorkflowType\ContentModerationInterface;

/** Returns a standard external-link field definition. */
function reg_core_plan_link_field(string $label): array {
  return ['type' => 'link', 'label' => $label, 'required' => FALSE, 'translatable' => FALSE, 'storage_settings' => [], 'field_settings' => ['link_type' => 16, 'title' => 0], 'widget' => 'link_default', 'formatter' => 'link'];
}

/**
 * Installs plan-linked procurement and recruitment publishing foundations.
 */
function reg_core_apply_plan_linked_publishing(): void {
  \Drupal::service('module_installer')->install(['file', 'language', 'content_translation', 'workflows', 'content_moderation'], TRUE);
  $types = [
    'reg_procurement_plan' => ['Annual Procurement Plan', 'Approved annual procurement governance record.'],
    'reg_procurement_plan_item' => ['Procurement Plan Item', 'Planned procurement activity that may produce a tender.'],
    'reg_recruitment_plan' => ['Annual Recruitment Plan', 'Approved annual recruitment governance record.'],
    'reg_recruitment_plan_item' => ['Recruitment Plan Item', 'Planned position that may produce a vacancy.'],
    'reg_supplier_profile' => ['Supplier Profile', 'Private reusable supplier identity record.'],
    'reg_bid' => ['Supplier Bid', 'Private bid-submission foundation for explicitly enabled REG tenders.'],
    'reg_applicant_profile' => ['Applicant Profile', 'Private reusable candidate profile.'],
    'reg_job_application' => ['Job Application', 'Private application foundation for explicitly enabled REG vacancies.'],
  ];
  foreach ($types as $id => [$label, $description]) {
    $type = NodeType::load($id) ?: NodeType::create(['type' => $id, 'name' => $label, 'description' => $description, 'new_revision' => TRUE, 'preview_mode' => 0, 'display_submitted' => FALSE]);
    $type->setNewRevision(TRUE);
    $type->save();
  }

  $entity = reg_core_list_field('REG entity', reg_core_company_options(), TRUE, FALSE);
  $financial_year = reg_core_string_field('Financial year', FALSE, TRUE);
  $date = static fn(string $label): array => array_replace(reg_core_date_field($label, FALSE), ['storage_settings' => ['datetime_type' => 'date']]);
  $yes_no = static fn(string $label): array => reg_core_boolean_field($label, FALSE);
  $fields = [
    'reg_procurement_plan' => [
      'field_reg_financial_year' => $financial_year, 'field_reg_entity' => $entity,
      'field_reg_proc_plan_status' => reg_core_list_field('Plan status', ['draft' => 'Draft', 'under_review' => 'Under Review', 'approved' => 'Approved', 'published' => 'Published', 'superseded' => 'Superseded', 'archived' => 'Archived'], TRUE, FALSE),
      'field_reg_approval_date' => $date('Approval date'), 'field_reg_approval_ref' => reg_core_string_field('Approval reference', FALSE),
      'field_reg_plan_document' => reg_core_media_reference_field('Approved plan document', 1, FALSE, 'document'),
    ],
    'reg_procurement_plan_item' => [
      'field_reg_procurement_plan_ref' => reg_core_node_reference_field('Annual Procurement Plan', 'reg_procurement_plan', 1, TRUE, FALSE),
      'field_reg_plan_reference' => reg_core_string_field('Plan reference', FALSE, TRUE), 'field_reg_description' => reg_core_long_text_field('Description'),
      'field_reg_entity' => $entity, 'field_reg_department' => reg_core_string_field('Department'), 'field_reg_project' => reg_core_string_field('Project'),
      'field_reg_proc_category_key' => reg_core_list_field('Procurement category', ['goods' => 'Goods', 'works' => 'Works', 'consultancy' => 'Consultancy Services', 'non_consultancy' => 'Non-Consultancy Services', 'other' => 'Other approved category'], TRUE, FALSE),
      'field_reg_procurement_method' => reg_core_list_field('Procurement method', ['qcbs' => 'Quality- and Cost-Based Selection (QCBS)', 'open_competitive' => 'Open competitive bidding', 'restricted' => 'Restricted tendering', 'request_for_proposals' => 'Request for proposals', 'request_for_quotations' => 'Request for quotations', 'single_source' => 'Single-source procurement', 'other' => 'Other approved method'], FALSE, FALSE),
      'field_reg_estimated_budget' => reg_core_decimal_field('Estimated budget', 18, 2, FALSE),
      'field_reg_budget_visibility' => reg_core_list_field('Budget visibility', ['private' => 'Private', 'public' => 'Public'], TRUE, FALSE),
      'field_reg_funding_source' => reg_core_string_field('Funding source'),
      'field_reg_procurement_stage' => reg_core_list_field('Procurement stage', ['new' => 'New Tender', 'ongoing' => 'Ongoing Contract'], TRUE, FALSE),
      'field_reg_contract_type' => reg_core_list_field('Contract type', ['regular' => 'Regular', 'framework' => 'Framework', 'multi_year' => 'Multi-Year'], TRUE, FALSE),
      'field_reg_planned_publication' => $date('Planned publication date'), 'field_reg_planned_quarter' => reg_core_list_field('Planned quarter', ['q1' => 'Q1', 'q2' => 'Q2', 'q3' => 'Q3', 'q4' => 'Q4'], TRUE, FALSE),
      'field_reg_expected_contract' => $date('Expected contract date'),
      'field_reg_planned_channel' => reg_core_list_field('Planned publication channel', ['reg' => 'REG Platform', 'umucyo' => 'UMUCYO', 'both' => 'Both', 'tbd' => 'To Be Determined'], TRUE, FALSE),
      'field_reg_proc_item_status' => reg_core_list_field('Status', ['planned' => 'Planned', 'preparing' => 'Preparing Tender', 'published' => 'Published', 'closed' => 'Closed', 'evaluation' => 'Evaluation', 'awarded' => 'Awarded', 'cancelled' => 'Cancelled', 'deferred' => 'Deferred'], TRUE, FALSE),
      'field_reg_related_tender' => reg_core_node_reference_field('Related Tender', 'reg_tender', 1, FALSE, FALSE),
      'field_reg_public_visibility' => $yes_no('Public visibility'), 'field_reg_internal_source' => reg_core_string_field('Source', FALSE),
    ],
    'reg_recruitment_plan' => [
      'field_reg_financial_year' => $financial_year, 'field_reg_entity' => $entity,
      'field_reg_recruit_plan_status' => reg_core_list_field('Approval status', ['draft' => 'Draft', 'under_review' => 'Under Review', 'approved' => 'Approved', 'published' => 'Published', 'superseded' => 'Superseded', 'archived' => 'Archived'], TRUE, FALSE),
      'field_reg_approval_date' => $date('Approval date'), 'field_reg_approval_ref' => reg_core_string_field('Approval reference', FALSE),
      'field_reg_plan_document' => reg_core_media_reference_field('Approved plan document', 1, FALSE, 'document'),
    ],
    'reg_recruitment_plan_item' => [
      'field_reg_recruitment_plan_ref' => reg_core_node_reference_field('Annual Recruitment Plan', 'reg_recruitment_plan', 1, TRUE, FALSE),
      'field_reg_plan_reference' => reg_core_string_field('Plan reference', FALSE, TRUE), 'field_reg_entity' => $entity,
      'field_reg_department' => reg_core_string_field('Department'), 'field_reg_job_family' => reg_core_string_field('Job family'), 'field_reg_grade' => reg_core_string_field('Grade', FALSE),
      'field_reg_vacancies' => reg_core_integer_field('Number of positions', FALSE, TRUE),
      'field_reg_job_type' => reg_core_list_field('Employment type', ['permanent' => 'Permanent', 'contract' => 'Contract', 'temporary' => 'Temporary', 'internship' => 'Internship', 'consultancy' => 'Consultancy'], TRUE, FALSE),
      'field_reg_recruitment_type' => reg_core_list_field('Recruitment type', ['external' => 'External', 'internal' => 'Internal', 'mixed' => 'Internal and External'], TRUE, FALSE),
      'field_reg_planned_quarter' => reg_core_list_field('Planned quarter', ['q1' => 'Q1', 'q2' => 'Q2', 'q3' => 'Q3', 'q4' => 'Q4'], TRUE, FALSE),
      'field_reg_planned_publication' => $date('Planned publication date'),
      'field_reg_recruit_item_status' => reg_core_list_field('Status', ['planned' => 'Planned', 'preparing' => 'Preparing', 'published' => 'Published', 'recruiting' => 'Recruiting', 'selection' => 'Selection', 'filled' => 'Filled', 'cancelled' => 'Cancelled', 'deferred' => 'Deferred'], TRUE, FALSE),
      'field_reg_public_visibility' => $yes_no('Public visibility'), 'field_reg_related_job' => reg_core_node_reference_field('Related Job Vacancy', 'reg_job', 1, FALSE, FALSE),
      'field_reg_internal_notes' => reg_core_long_text_field('Internal notes'),
    ],
    'reg_tender' => [
      'field_reg_proc_plan_item' => reg_core_node_reference_field('Annual Procurement Plan Item', 'reg_procurement_plan_item', 1, FALSE, FALSE),
      'field_reg_financial_year' => array_replace($financial_year, ['required' => FALSE]), 'field_reg_unplanned' => $yes_no('Unplanned / Exceptional Procurement'),
      'field_reg_exception_reason' => reg_core_long_text_field('Exception reason'), 'field_reg_approval_ref' => reg_core_string_field('Exception approval reference', FALSE),
      'field_reg_approval_date' => $date('Exception approval date'), 'field_reg_approval_document' => reg_core_media_reference_field('Exception approval document', 1, FALSE, 'document'),
      'field_reg_publication_channel' => reg_core_list_field('Publication channel', ['reg' => 'REG Platform', 'umucyo' => 'UMUCYO', 'both' => 'REG + UMUCYO'], FALSE, FALSE),
      'field_reg_umucyo_number' => reg_core_string_field('UMUCYO tender number', FALSE), 'field_reg_umucyo_url' => reg_core_plan_link_field('Approved UMUCYO tender URL'),
      'field_reg_submission_method' => reg_core_list_field('Submission method', ['reg_online' => 'REG Online Bid', 'umucyo' => 'UMUCYO Online Bid', 'external' => 'External Approved Platform', 'offline' => 'Offline/Other Approved Method'], FALSE, FALSE),
      'field_reg_submission_url' => reg_core_plan_link_field('External submission URL'),
    ],
    'reg_job' => [
      'field_reg_recruit_plan_item' => reg_core_node_reference_field('Annual Recruitment Plan Item', 'reg_recruitment_plan_item', 1, FALSE, FALSE),
      'field_reg_financial_year' => array_replace($financial_year, ['required' => FALSE]), 'field_reg_unplanned' => $yes_no('Unplanned / Exceptional Recruitment'),
      'field_reg_exception_reason' => reg_core_long_text_field('Exception reason'), 'field_reg_approval_ref' => reg_core_string_field('Exception approval reference', FALSE),
      'field_reg_approval_document' => reg_core_media_reference_field('Exception approval document', 1, FALSE, 'document'),
      'field_reg_application_method' => reg_core_list_field('Application method', ['reg_online' => 'REG Online Application', 'mifotra' => 'MIFOTRA e-Recruitment', 'external' => 'External Recruitment Platform', 'instructions' => 'Approved External Instructions'], FALSE, FALSE),
      'field_reg_external_reference' => reg_core_string_field('External reference', FALSE),
    ],
    'reg_supplier_profile' => [
      'field_reg_company_name' => reg_core_string_field('Company name', FALSE, TRUE), 'field_reg_tin' => reg_core_string_field('TIN', FALSE, TRUE),
      'field_reg_registration_no' => reg_core_string_field('Registration number', FALSE), 'field_reg_country' => reg_core_string_field('Country', FALSE, TRUE),
      'field_reg_address' => reg_core_long_text_field('Address'), 'field_reg_contact_person' => reg_core_string_field('Contact person', FALSE, TRUE),
      'field_reg_email' => reg_core_string_field('Email', FALSE, TRUE), 'field_reg_telephone' => reg_core_string_field('Telephone', FALSE, TRUE),
    ],
    'reg_bid' => [
      'field_reg_bid_tender' => reg_core_node_reference_field('Tender', 'reg_tender', 1, TRUE, FALSE), 'field_reg_supplier_profile_ref' => reg_core_node_reference_field('Supplier profile', 'reg_supplier_profile', 1, TRUE, FALSE),
      'field_reg_submission_ref' => reg_core_string_field('Submission reference', FALSE), 'field_reg_submission_status' => reg_core_list_field('Status', ['draft' => 'Draft', 'submitted' => 'Submitted', 'withdrawn' => 'Withdrawn', 'closed' => 'Closed'], TRUE, FALSE),
      'field_reg_submitted_at' => reg_core_date_field('Submitted at', FALSE), 'field_reg_declaration' => $yes_no('Declaration accepted'), 'field_reg_submission_hash' => reg_core_string_field('Submission hash / audit metadata', FALSE),
    ],
    'reg_applicant_profile' => [
      'field_reg_contact_person' => reg_core_string_field('Full name', FALSE, TRUE), 'field_reg_email' => reg_core_string_field('Email', FALSE, TRUE), 'field_reg_telephone' => reg_core_string_field('Telephone', FALSE),
      'field_reg_address' => reg_core_long_text_field('Personal and contact information'), 'field_reg_education' => reg_core_long_text_field('Education'), 'field_reg_experience' => reg_core_long_text_field('Experience'),
      'field_reg_certifications' => reg_core_long_text_field('Professional certifications'), 'field_reg_languages' => reg_core_long_text_field('Languages'), 'field_reg_references' => reg_core_long_text_field('References'),
    ],
    'reg_job_application' => [
      'field_reg_application_job' => reg_core_node_reference_field('Job', 'reg_job', 1, TRUE, FALSE), 'field_reg_applicant_profile_ref' => reg_core_node_reference_field('Applicant profile', 'reg_applicant_profile', 1, TRUE, FALSE),
      'field_reg_application_ref' => reg_core_string_field('Application reference', FALSE), 'field_reg_cover_letter' => reg_core_long_text_field('Cover letter'), 'field_reg_required_answers' => reg_core_long_text_field('Required question responses'),
      'field_reg_declaration' => $yes_no('Declaration accepted'), 'field_reg_submitted_at' => reg_core_date_field('Submitted at', FALSE),
      'field_reg_application_status' => reg_core_list_field('Candidate-visible status', ['draft' => 'Draft', 'submitted' => 'Submitted', 'under_review' => 'Under Review', 'shortlisted' => 'Shortlisted', 'not_selected' => 'Not Selected', 'interview' => 'Interview/Assessment', 'selected' => 'Offer/Selected', 'closed' => 'Closed'], TRUE, FALSE),
    ],
  ];
  foreach ($fields as $bundle => $definitions) {
    $weight = 0;
    foreach ($definitions as $name => $definition) reg_core_ensure_field($bundle, $name, $definition, $weight += 2);
  }
  foreach (['reg_bid', 'reg_job_application', 'reg_applicant_profile'] as $bundle) {
    reg_core_ensure_private_file_field($bundle, 'field_reg_private_documents', $bundle === 'reg_applicant_profile' ? 'CV and reusable supporting documents' : 'Private submission documents');
  }
  reg_core_configure_plan_forms();

  foreach (['reg_procurement_plan', 'reg_procurement_plan_item', 'reg_recruitment_plan', 'reg_recruitment_plan_item', 'reg_tender', 'reg_job'] as $bundle) {
    ContentLanguageSettings::loadByEntityTypeBundle('node', $bundle)->setDefaultLangcode('site_default')->setLanguageAlterable(TRUE)->setThirdPartySetting('content_translation', 'enabled', TRUE)->save();
  }
  $workflow = Workflow::load('reg_public_information_editorial');
  $moderation = $workflow?->getTypePlugin();
  if ($workflow && $moderation instanceof ContentModerationInterface) {
    foreach (['reg_procurement_plan', 'reg_procurement_plan_item', 'reg_recruitment_plan', 'reg_recruitment_plan_item'] as $bundle) $moderation->addEntityTypeAndBundle('node', $bundle);
    $workflow->save();
  }
  reg_core_create_plan_roles();
}

/** Orders plan linkage, exception, channel, and publication controls clearly. */
function reg_core_configure_plan_forms(): void {
  $orders = [
    'reg_tender' => ['field_reg_financial_year' => -31, 'field_reg_proc_plan_item' => -30, 'field_reg_unplanned' => -29, 'field_reg_exception_reason' => -28, 'field_reg_approval_ref' => -27, 'field_reg_approval_date' => -26, 'field_reg_approval_document' => -25, 'field_reg_publication_channel' => -5, 'field_reg_umucyo_number' => -4, 'field_reg_umucyo_url' => -3, 'field_reg_submission_method' => -2, 'field_reg_submission_url' => -1],
    'reg_job' => ['field_reg_financial_year' => -31, 'field_reg_recruit_plan_item' => -30, 'field_reg_unplanned' => -29, 'field_reg_exception_reason' => -28, 'field_reg_approval_ref' => -27, 'field_reg_approval_document' => -26, 'field_reg_application_method' => 30, 'field_reg_application_url' => 31, 'field_reg_external_reference' => 32],
  ];
  foreach ($orders as $bundle => $weights) {
    $form = EntityFormDisplay::load("node.$bundle.default");
    if (!$form) continue;
    foreach ($weights as $field => $weight) {
      $component = $form->getComponent($field);
      if ($component) { $component['weight'] = $weight; $form->setComponent($field, $component); }
    }
    $form->save();
  }
}

/** Creates an explicitly private file field for candidate/bid records. */
function reg_core_ensure_private_file_field(string $bundle, string $name, string $label): void {
  $storage = FieldStorageConfig::loadByName('node', $name);
  if (!$storage) FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node', 'type' => 'file', 'cardinality' => -1, 'translatable' => FALSE, 'settings' => ['uri_scheme' => 'private']])->save();
  $field = FieldConfig::loadByName('node', $bundle, $name);
  if (!$field) FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => $bundle, 'label' => $label, 'required' => FALSE, 'translatable' => FALSE, 'settings' => ['file_extensions' => 'pdf doc docx xls xlsx', 'file_directory' => 'secure-submissions/[date:custom:Y-m]', 'max_filesize' => '25 MB', 'description_field' => TRUE]])->save();
  $form = EntityFormDisplay::load("node.$bundle.default");
  $form?->setComponent($name, ['type' => 'file_generic', 'weight' => 80, 'settings' => ['progress_indicator' => 'throbber']])->save();
}

/** Creates permission-separated planning and portal roles. */
function reg_core_create_plan_roles(): void {
  $roles = [
    'reg_procurement_planner' => ['Procurement Planner', ['reg_procurement_plan', 'reg_procurement_plan_item'], ['administer reg procurement plans']],
    'reg_procurement_officer' => ['Procurement Officer', ['reg_procurement_plan', 'reg_procurement_plan_item', 'reg_tender'], ['administer reg procurement plans']],
    'reg_procurement_approver' => ['Procurement Approver', ['reg_procurement_plan', 'reg_procurement_plan_item', 'reg_tender'], ['administer reg procurement plans']],
    'reg_hr_planner' => ['HR Planner', ['reg_recruitment_plan', 'reg_recruitment_plan_item'], ['administer reg recruitment plans']],
    'reg_hr_officer' => ['HR Officer', ['reg_recruitment_plan', 'reg_recruitment_plan_item', 'reg_job'], ['administer reg recruitment plans']],
    'reg_hr_approver' => ['HR Approver', ['reg_recruitment_plan', 'reg_recruitment_plan_item', 'reg_job'], ['administer reg recruitment plans']],
    'reg_supplier' => ['Supplier', ['reg_supplier_profile', 'reg_bid'], ['access reg supplier portal', 'submit reg bids']],
    'reg_applicant' => ['Applicant', ['reg_applicant_profile', 'reg_job_application'], ['access reg candidate portal', 'submit reg applications']],
  ];
  foreach ($roles as $id => [$label, $bundles, $extra]) {
    $role = Role::load($id) ?: Role::create(['id' => $id, 'label' => $label]);
    $staff = !in_array($id, ['reg_supplier', 'reg_applicant'], TRUE);
    foreach (array_merge($staff ? ['access content', 'access administration pages', 'view the administration theme'] : ['access content'], $extra) as $permission) $role->grantPermission($permission);
    if ($staff) foreach ($bundles as $bundle) foreach (["create $bundle content", "edit own $bundle content", "view own unpublished content"] as $permission) $role->grantPermission($permission);
    else foreach ($bundles as $bundle) foreach (["create $bundle content", "edit own $bundle content", "view own unpublished content"] as $permission) $role->revokePermission($permission);
    if (str_contains($id, 'approver')) {
      foreach (['use reg_public_information_editorial transition publish', 'use reg_public_information_editorial transition archive'] as $permission) $role->grantPermission($permission);
      foreach ($bundles as $bundle) foreach (["edit any $bundle content", "view $bundle revisions", "revert $bundle revisions"] as $permission) $role->grantPermission($permission);
      $role->grantPermission(str_starts_with($id, 'reg_procurement') ? 'override reg procurement plan defaults' : 'override reg recruitment plan defaults');
    }
    elseif (!in_array($id, ['reg_supplier', 'reg_applicant'], TRUE)) foreach (['use reg_public_information_editorial transition create_new_draft', 'use reg_public_information_editorial transition submit_for_review'] as $permission) $role->grantPermission($permission);
    $role->save();
  }
  foreach (['reg_translator' => FALSE] as $id => $approve) {
    if (!$role = Role::load($id)) continue;
    foreach (['reg_procurement_plan', 'reg_procurement_plan_item', 'reg_recruitment_plan', 'reg_recruitment_plan_item'] as $bundle) {
      foreach (["edit any $bundle content", "view $bundle revisions", "translate $bundle node"] as $permission) $role->grantPermission($permission);
      if ($approve) foreach (["revert $bundle revisions", "delete any $bundle content"] as $permission) $role->grantPermission($permission);
    }
    if ($approve) { $role->grantPermission('override reg procurement plan defaults'); $role->grantPermission('override reg recruitment plan defaults'); }
    $role->save();
  }
}
