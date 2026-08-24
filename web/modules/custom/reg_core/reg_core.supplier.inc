<?php

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;

/** Supplier portal token and private audit tables. */
function reg_core_supplier_schema(): array {
  return [
    'reg_core_supplier_verification' => [
      'description' => 'Expiring one-time supplier email verification tokens.',
      'fields' => [
        'token_hash' => ['type' => 'varchar_ascii', 'length' => 64, 'not null' => TRUE],
        'uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'expires' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'used' => ['type' => 'int', 'size' => 'tiny', 'not null' => TRUE, 'default' => 0],
        'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'destination' => ['type' => 'varchar', 'length' => 512, 'not null' => TRUE, 'default' => ''],
      ],
      'primary key' => ['token_hash'],
      'indexes' => ['uid' => ['uid'], 'expires' => ['expires']],
    ],
    'reg_core_supplier_invitation' => [
      'description' => 'Expiring organization-scoped supplier team invitations.',
      'fields' => [
        'token_hash' => ['type' => 'varchar_ascii', 'length' => 64, 'not null' => TRUE],
        'organization_nid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'email' => ['type' => 'varchar', 'length' => 254, 'not null' => TRUE],
        'email_hash' => ['type' => 'varchar_ascii', 'length' => 64, 'not null' => TRUE],
        'first_name' => ['type' => 'varchar', 'length' => 100, 'not null' => TRUE],
        'last_name' => ['type' => 'varchar', 'length' => 100, 'not null' => TRUE],
        'organization_role' => ['type' => 'varchar_ascii', 'length' => 32, 'not null' => TRUE],
        'invited_by' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'expires' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'accepted' => ['type' => 'int', 'size' => 'tiny', 'not null' => TRUE, 'default' => 0],
        'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
      ],
      'primary key' => ['token_hash'],
      'indexes' => ['organization' => ['organization_nid'], 'email_hash' => ['email_hash'], 'expires' => ['expires']],
    ],
    'reg_core_supplier_audit' => [
      'description' => 'Private supplier onboarding, review, membership, and bid audit events.',
      'fields' => [
        'id' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
        'organization_nid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'event' => ['type' => 'varchar_ascii', 'length' => 64, 'not null' => TRUE],
        'detail' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE, 'default' => ''],
        'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
      ],
      'primary key' => ['id'],
      'indexes' => ['organization' => ['organization_nid'], 'event' => ['event'], 'created' => ['created']],
    ],
  ];
}

/** Installs supplier organizations, memberships, account fields, and roles. */
function reg_core_apply_supplier_portal(): void {
  require_once __DIR__ . '/reg_core.plans.inc';
  $schema = \Drupal::database()->schema();
  foreach (reg_core_supplier_schema() as $table => $definition) if (!$schema->tableExists($table)) $schema->createTable($table, $definition);

  $vocabulary = Vocabulary::load('reg_supplier_category') ?: Vocabulary::create(['vid' => 'reg_supplier_category', 'name' => 'Supplier Categories']);
  $vocabulary->save();
  $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  foreach (['Goods', 'Works', 'Consultancy Services', 'Non-Consultancy Services', 'Other', 'Civil Works', 'Electrical Equipment', 'Energy Infrastructure', 'ICT', 'Professional Services', 'Maintenance', 'Vehicles', 'Office Equipment'] as $name) {
    $ids = $term_storage->getQuery()->accessCheck(FALSE)->condition('vid', 'reg_supplier_category')->condition('name', $name)->range(0, 1)->execute();
    if (!$ids) Term::create(['vid' => 'reg_supplier_category', 'name' => $name])->save();
  }
  $membership = NodeType::load('reg_supplier_membership') ?: NodeType::create(['type' => 'reg_supplier_membership', 'name' => 'Supplier Organization Membership', 'description' => 'Private relationship between a Drupal user and one supplier legal entity.', 'new_revision' => TRUE, 'preview_mode' => 0, 'display_submitted' => FALSE]);
  $membership->setNewRevision(TRUE); $membership->save();

  $user_reference = static fn(string $label, bool $required = TRUE): array => ['type' => 'entity_reference', 'label' => $label, 'required' => $required, 'translatable' => FALSE, 'storage_settings' => ['target_type' => 'user'], 'field_settings' => ['handler' => 'default:user', 'handler_settings' => []], 'widget' => 'entity_reference_autocomplete', 'formatter' => 'entity_reference_label'];
  $profile_fields = [
    'field_reg_company_name' => reg_core_string_field('Legal Company Name', FALSE, TRUE),
    'field_reg_trading_name' => reg_core_string_field('Trading Name', FALSE),
    'field_reg_country' => reg_core_string_field('Country', FALSE, TRUE),
    'field_reg_tin' => reg_core_string_field('TIN', FALSE),
    'field_reg_registration_no' => reg_core_string_field('Company Registration Number', FALSE, TRUE),
    'field_reg_company_type' => reg_core_list_field('Supplier Type', ['company' => 'Company', 'consultancy_firm' => 'Consultancy Firm', 'individual_consultant' => 'Individual Consultant', 'joint_venture' => 'Joint Venture', 'other' => 'Other approved supplier type', 'partnership' => 'Partnership', 'consultant' => 'Individual Consultant', 'ngo' => 'NGO / Institution', 'international' => 'International Company'], TRUE, FALSE),
    'field_reg_address' => reg_core_long_text_field('Registered Address'),
    'field_reg_province_city' => reg_core_string_field('Province / City', FALSE),
    'field_reg_province_ref' => reg_core_term_reference_field('Province', 'reg_province', FALSE, FALSE),
    'field_reg_district_ref' => reg_core_term_reference_field('District', 'reg_district', FALSE, FALSE),
    'field_reg_email' => reg_core_string_field('Official Company Email', FALSE, TRUE),
    'field_reg_telephone' => reg_core_string_field('Main Phone', FALSE, TRUE),
    'field_reg_contact_first_name' => reg_core_string_field('Primary Contact First Name', FALSE, TRUE),
    'field_reg_contact_last_name' => reg_core_string_field('Primary Contact Last Name', FALSE, TRUE),
    'field_reg_contact_position' => reg_core_string_field('Primary Contact Position', FALSE, TRUE),
    'field_reg_alternative_phone' => reg_core_string_field('Alternative Phone', FALSE),
    'field_reg_website' => reg_core_plan_link_field('Website'),
    'field_reg_supplier_categories' => array_replace(reg_core_term_reference_field('Supplier Categories', 'reg_supplier_category', TRUE, FALSE), ['cardinality' => -1]),
    'field_reg_supplier_status' => reg_core_list_field('Supplier Profile Status', ['email_unverified' => 'Email Not Verified', 'pending' => 'Pending Approval', 'approved' => 'Approved', 'request_update' => 'Update Requested', 'suspended' => 'Suspended', 'rejected' => 'Rejected', 'archived' => 'Archived'], TRUE, FALSE),
    'field_reg_submitted_at' => reg_core_date_field('Submitted At', FALSE),
    'field_reg_internal_review_note' => reg_core_long_text_field('Internal Review Note'),
    'field_reg_review_reason' => reg_core_long_text_field('Review Decision Reason'),
    'field_reg_document_scan_status' => reg_core_list_field('Document Scan Status', ['pending' => 'Pending Malware Scan', 'clean' => 'Passed Malware Scan', 'rejected' => 'Rejected by Security Scan'], TRUE, FALSE),
    'field_reg_test_record' => reg_core_boolean_field('Development / Test Record', FALSE),
  ];
  $membership_fields = [
    'field_reg_supplier_org' => reg_core_node_reference_field('Supplier Organization', 'reg_supplier_profile', 1, TRUE, FALSE),
    'field_reg_member_user' => $user_reference('Authorized User', TRUE),
    'field_reg_org_role' => reg_core_list_field('Organization Role', ['supplier_admin' => 'Supplier Administrator', 'bid_manager' => 'Bid Manager', 'bid_contributor' => 'Bid Contributor', 'supplier_viewer' => 'Supplier Viewer'], TRUE, FALSE),
    'field_reg_membership_status' => reg_core_list_field('Membership Status', ['active' => 'Active', 'invited' => 'Invited', 'suspended' => 'Suspended'], TRUE, FALSE),
    'field_reg_invited_by' => $user_reference('Invited By', FALSE),
  ];
  $bid_fields = [
    'field_reg_supplier_org' => reg_core_node_reference_field('Supplier Organization', 'reg_supplier_profile', 1, TRUE, FALSE),
    'field_reg_bid_scan_status' => reg_core_list_field('Bid Document Scan Status', ['pending' => 'Pending Malware Scan', 'clean' => 'Passed Malware Scan', 'rejected' => 'Rejected by Security Scan'], TRUE, FALSE),
    'field_reg_bid_information' => reg_core_long_text_field('Bid Information'),
    'field_reg_bid_declaration' => reg_core_boolean_field('Authorized Bid Declaration', FALSE),
  ];
  foreach (['reg_supplier_profile' => $profile_fields, 'reg_supplier_membership' => $membership_fields, 'reg_bid' => $bid_fields] as $bundle => $fields) {
    $weight = 0; foreach ($fields as $name => $definition) reg_core_ensure_field($bundle, $name, $definition, $weight += 2);
  }
  reg_core_ensure_private_file_field('reg_supplier_profile', 'field_reg_private_documents', 'Private Supplier Registration Documents');
  reg_core_ensure_private_file_field('reg_bid', 'field_reg_technical_documents', 'Private Technical Documents');
  reg_core_ensure_private_file_field('reg_bid', 'field_reg_financial_documents', 'Private Financial Documents');
  reg_core_ensure_supplier_user_fields();
  reg_core_create_supplier_roles();

  // Existing list fields predate the current supplier lifecycle. Keep their
  // storage definitions aligned without replacing the content model.
  if ($storage = FieldStorageConfig::loadByName('node', 'field_reg_company_type')) {
    $storage->setSetting('allowed_values', $profile_fields['field_reg_company_type']['storage_settings']['allowed_values'])->save();
  }
}

/** Applies the repaired registration lifecycle and migrates legacy statuses. */
function reg_core_finalize_supplier_registration(): void {
  reg_core_apply_supplier_portal();
  $schema = \Drupal::database()->schema();
  if (!$schema->fieldExists('reg_core_supplier_verification', 'destination')) {
    $definition = reg_core_supplier_schema()['reg_core_supplier_verification']['fields']['destination'];
    $schema->addField('reg_core_supplier_verification', 'destination', $definition);
  }
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_supplier_profile')->execute();
  $mapping = ['draft' => 'email_unverified', 'verified' => 'approved', 'correction' => 'request_update', 'expired' => 'archived'];
  foreach ($storage->loadMultiple($ids) as $profile) {
    $old = (string) $profile->get('field_reg_supplier_status')->value;
    if (isset($mapping[$old])) {
      $profile->set('field_reg_supplier_status', $mapping[$old])->setNewRevision(TRUE);
      $profile->setRevisionLogMessage('Supplier lifecycle normalized from ' . $old . ' to ' . $mapping[$old] . '.');
      $profile->save();
    }
  }
  if ($field_storage = FieldStorageConfig::loadByName('node', 'field_reg_supplier_status')) {
    $field_storage->setSetting('allowed_values', ['email_unverified' => 'Email Not Verified', 'pending' => 'Pending Approval', 'approved' => 'Approved', 'request_update' => 'Update Requested', 'suspended' => 'Suspended', 'rejected' => 'Rejected', 'archived' => 'Archived'])->save();
  }
  // Align existing memberships with approval, without granting admin access.
  foreach ($storage->loadMultiple($storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_supplier_membership')->execute()) as $membership) {
    $profile = $membership->get('field_reg_supplier_org')->entity;
    $account = $membership->get('field_reg_member_user')->entity;
    if (!$profile || !$account) continue;
    // Repair only the primary self-registration relationship. Genuine team
    // invitations remain invited until their normal acceptance flow runs.
    $self_registration = (int) $profile->getOwnerId() === (int) $account->id();
    if ($self_registration) {
      if ((string) $membership->get('field_reg_membership_status')->value !== 'active') {
        $membership->set('field_reg_membership_status', 'active')->setNewRevision(TRUE);
        $membership->setRevisionLogMessage('Activated the primary membership during supplier login lifecycle repair.');
        $membership->save();
      }
      if ($account->isBlocked()) {
        $account->activate();
      }
    }
    if ((string) $profile->get('field_reg_supplier_status')->value === 'approved') {
      $account->addRole('reg_supplier')->removeRole('reg_supplier_pending');
    }
    else {
      $account->addRole('reg_supplier_pending')->removeRole('reg_supplier');
    }
    $account->save();
  }
}

/** Adds Drupal-user contact and email-verification fields. */
function reg_core_ensure_supplier_user_fields(): void {
  $definitions = [
    'field_reg_first_name' => ['type' => 'string', 'label' => 'First Name', 'required' => FALSE, 'storage_settings' => ['max_length' => 100], 'field_settings' => []],
    'field_reg_last_name' => ['type' => 'string', 'label' => 'Last Name', 'required' => FALSE, 'storage_settings' => ['max_length' => 100], 'field_settings' => []],
    'field_reg_phone' => ['type' => 'string', 'label' => 'Phone Number', 'required' => FALSE, 'storage_settings' => ['max_length' => 32], 'field_settings' => []],
    'field_reg_email_verified' => ['type' => 'boolean', 'label' => 'Supplier Email Verified', 'required' => FALSE, 'storage_settings' => [], 'field_settings' => ['on_label' => 'Verified', 'off_label' => 'Not verified']],
    'field_reg_supplier_terms_at' => ['type' => 'timestamp', 'label' => 'Supplier Terms Accepted At', 'required' => FALSE, 'storage_settings' => [], 'field_settings' => []],
  ];
  foreach ($definitions as $name => $definition) {
    $storage = FieldStorageConfig::loadByName('user', $name);
    if (!$storage) FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'user', 'type' => $definition['type'], 'cardinality' => 1, 'translatable' => FALSE, 'settings' => $definition['storage_settings']])->save();
    if (!FieldConfig::loadByName('user', 'user', $name)) FieldConfig::create(['field_name' => $name, 'entity_type' => 'user', 'bundle' => 'user', 'label' => $definition['label'], 'required' => $definition['required'], 'translatable' => FALSE, 'settings' => $definition['field_settings']])->save();
  }
}

/** Applies supplier and procurement-review permissions without editorial UI. */
function reg_core_create_supplier_roles(): void {
  $pending = Role::load('reg_supplier_pending') ?: Role::create(['id' => 'reg_supplier_pending', 'label' => 'Supplier Pending']);
  $pending->set('permissions', ['access content', 'access reg supplier portal'])->save();
  $supplier = Role::load('reg_supplier') ?: Role::create(['id' => 'reg_supplier', 'label' => 'Supplier']);
  $supplier->set('permissions', ['access content', 'access reg supplier portal', 'submit reg bids']);
  $supplier->save();
  foreach (['reg_procurement_approver'] as $id) if ($role = Role::load($id)) { $role->grantPermission('review reg suppliers'); $role->save(); }
}

/** Writes a bounded private supplier audit event. */
function reg_core_supplier_audit(string $event, int $organization_nid = 0, int $uid = 0, string $detail = ''): void {
  \Drupal::database()->insert('reg_core_supplier_audit')->fields(['organization_nid' => max(0, $organization_nid), 'uid' => max(0, $uid), 'event' => preg_replace('/[^a-z0-9_]/', '_', mb_strtolower($event)), 'detail' => mb_substr(trim(strip_tags($detail)), 0, 255), 'created' => \Drupal::time()->getRequestTime()])->execute();
}
