<?php

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$account_switcher = \Drupal::service('account_switcher');
$account_switcher->switchTo(User::load(1));
$required_bundles = ['reg_procurement_plan', 'reg_procurement_plan_item', 'reg_recruitment_plan', 'reg_recruitment_plan_item', 'reg_supplier_profile', 'reg_bid', 'reg_applicant_profile', 'reg_job_application'];
foreach ($required_bundles as $bundle) $assert(\Drupal::entityTypeManager()->getStorage('node_type')->load($bundle) !== NULL, "Missing bundle $bundle.");
foreach ([['reg_tender', 'field_reg_proc_plan_item'], ['reg_tender', 'field_reg_submission_method'], ['reg_job', 'field_reg_recruit_plan_item'], ['reg_job', 'field_reg_application_method']] as [$bundle, $field]) $assert(FieldConfig::loadByName('node', $bundle, $field) !== NULL, "Missing $bundle.$field.");
$tender_form = (string) \Drupal::service('renderer')->renderRoot(\Drupal::service('entity.form_builder')->getForm(Node::create(['type' => 'reg_tender']), 'default'));
$job_form = (string) \Drupal::service('renderer')->renderRoot(\Drupal::service('entity.form_builder')->getForm(Node::create(['type' => 'reg_job']), 'default'));
$assert(str_contains($tender_form, 'Annual Procurement Plan Item') && str_contains($tender_form, 'Submission method'), 'New tender form lacks plan/channel controls.');
$assert(str_contains($job_form, 'Annual Recruitment Plan Item') && str_contains($job_form, 'Application method'), 'New vacancy form lacks plan/application controls.');
$private = FieldStorageConfig::loadByName('node', 'field_reg_private_documents');
$assert($private?->getSetting('uri_scheme') === 'private', 'Submission files are not configured for private storage.');
$assert((string) \Drupal::service('file_system')->realpath('private://') !== '', 'Drupal private file storage is unavailable.');
$supplier = Role::load('reg_supplier'); $applicant = Role::load('reg_applicant');
$assert($supplier?->hasPermission('access reg supplier portal') && !$supplier->hasPermission('create reg_bid content'), 'Supplier role can access an ordinary bid node form.');
$assert($applicant?->hasPermission('access reg candidate portal') && !$applicant->hasPermission('create reg_job_application content'), 'Applicant role can access an ordinary application node form.');

$storage = \Drupal::entityTypeManager()->getStorage('node');
$legacy_tenders = array_filter($storage->loadMultiple($storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_tender')->execute()), static fn(NodeInterface $node): bool => $node->get('field_reg_proc_plan_item')->isEmpty());
$assert($legacy_tenders !== [], 'Historical tenders without fabricated plan items were not preserved.');
$assert(FieldConfig::loadByName('node', 'reg_job', 'field_reg_recruit_plan_item')?->isRequired() === FALSE, 'Legacy vacancies would be forced into fabricated recruitment plan items.');
$created = []; $files = [];
$save = static function (array $values) use (&$created): NodeInterface { $node = Node::create($values); $node->save(); $created[] = (int) $node->id(); return $node; };
$future = gmdate('Y-m-d\\TH:i:s', \Drupal::time()->getRequestTime() + 86400 * 10);
$past = gmdate('Y-m-d\\TH:i:s', \Drupal::time()->getRequestTime() - 3600);

try {
  $proc_plan = $save(['type' => 'reg_procurement_plan', 'title' => 'Acceptance Annual Procurement Plan 2026/27', 'field_reg_financial_year' => '2026/27', 'field_reg_entity' => 'reg', 'field_reg_proc_plan_status' => 'approved', 'status' => 1, 'moderation_state' => 'published']);
  $proc_item = $save(['type' => 'reg_procurement_plan_item', 'title' => 'Acceptance Grid Equipment Procurement', 'field_reg_procurement_plan_ref' => $proc_plan->id(), 'field_reg_plan_reference' => 'APP-2026-001', 'field_reg_entity' => 'reg', 'field_reg_proc_category_key' => 'goods', 'field_reg_procurement_method' => 'open_competitive', 'field_reg_budget_visibility' => 'private', 'field_reg_estimated_budget' => '99999999.00', 'field_reg_procurement_stage' => 'new', 'field_reg_contract_type' => 'regular', 'field_reg_planned_quarter' => 'q2', 'field_reg_planned_channel' => 'both', 'field_reg_proc_item_status' => 'published', 'field_reg_public_visibility' => 1, 'status' => 1, 'moderation_state' => 'published']);
  $umucyo = $save(['type' => 'reg_tender', 'title' => 'Acceptance UMUCYO Tender', 'field_reg_proc_plan_item' => $proc_item->id(), 'field_reg_reference' => 'REG/TEST/UMUCYO', 'field_reg_summary' => 'Plan-linked UMUCYO tender.', 'field_reg_tender_status' => 'active', 'field_reg_issue_date' => gmdate('Y-m-d\\TH:i:s'), 'field_reg_closing_date' => $future, 'field_reg_publication_channel' => 'both', 'field_reg_submission_method' => 'umucyo', 'field_reg_umucyo_url' => ['uri' => 'https://www.umucyo.gov.rw/test-tender'], 'status' => 1, 'moderation_state' => 'published']);
  $reg_tender = $save(['type' => 'reg_tender', 'title' => 'Acceptance REG Online Tender', 'field_reg_proc_plan_item' => $proc_item->id(), 'field_reg_reference' => 'REG/TEST/ONLINE', 'field_reg_summary' => 'Plan-linked REG portal tender.', 'field_reg_tender_status' => 'active', 'field_reg_issue_date' => gmdate('Y-m-d\\TH:i:s'), 'field_reg_closing_date' => $future, 'field_reg_publication_channel' => 'reg', 'field_reg_submission_method' => 'reg_online', 'status' => 1, 'moderation_state' => 'published']);
  $closed = $save(['type' => 'reg_tender', 'title' => 'Acceptance Closed Tender', 'field_reg_proc_plan_item' => $proc_item->id(), 'field_reg_reference' => 'REG/TEST/CLOSED', 'field_reg_tender_status' => 'closed', 'field_reg_issue_date' => gmdate('Y-m-d\\TH:i:s'), 'field_reg_closing_date' => $future, 'field_reg_publication_channel' => 'reg', 'field_reg_submission_method' => 'reg_online', 'status' => 1, 'moderation_state' => 'published']);
  $assert($reg_tender->get('field_reg_entity')->value === 'reg' && $reg_tender->get('field_reg_procurement_method')->value === 'open_competitive' && $reg_tender->get('field_reg_financial_year')->value === '2026/27', 'Tender did not inherit safe plan defaults.');
  $document_ids = \Drupal::entityTypeManager()->getStorage('media')->getQuery()->accessCheck(FALSE)->condition('bundle', 'document')->range(0, 1)->execute();
  $assert($document_ids !== [], 'No approved document Media exists for exception-control testing.');
  $save(['type' => 'reg_tender', 'title' => 'Acceptance Approved Exceptional Procurement', 'field_reg_unplanned' => 1, 'field_reg_exception_reason' => 'Approved urgent operational need', 'field_reg_approval_ref' => 'EXC-TEST-001', 'field_reg_approval_date' => gmdate('Y-m-d'), 'field_reg_approval_document' => reset($document_ids), 'field_reg_reference' => 'REG/TEST/EXCEPTION', 'field_reg_tender_status' => 'active', 'field_reg_issue_date' => gmdate('Y-m-d\\TH:i:s'), 'field_reg_closing_date' => $future, 'field_reg_publication_channel' => 'reg', 'field_reg_submission_method' => 'offline', 'status' => 0]);

  $recruit_plan = $save(['type' => 'reg_recruitment_plan', 'title' => 'Acceptance Annual Recruitment Plan 2026/27', 'field_reg_financial_year' => '2026/27', 'field_reg_entity' => 'reg', 'field_reg_recruit_plan_status' => 'approved', 'status' => 1, 'moderation_state' => 'published']);
  $recruit_item = $save(['type' => 'reg_recruitment_plan_item', 'title' => 'Acceptance ICT Security Specialist', 'field_reg_recruitment_plan_ref' => $recruit_plan->id(), 'field_reg_plan_reference' => 'ARP-2026-001', 'field_reg_entity' => 'reg', 'field_reg_department' => 'ICT', 'field_reg_vacancies' => 2, 'field_reg_job_type' => 'permanent', 'field_reg_recruitment_type' => 'external', 'field_reg_planned_quarter' => 'q2', 'field_reg_recruit_item_status' => 'published', 'field_reg_public_visibility' => 1, 'status' => 1, 'moderation_state' => 'published']);
  $external_job = $save(['type' => 'reg_job', 'title' => 'Acceptance MIFOTRA Vacancy', 'field_reg_recruit_plan_item' => $recruit_item->id(), 'field_reg_reference' => 'REG/JOB/MIFOTRA', 'field_reg_summary' => 'Plan-linked external vacancy.', 'field_reg_job_status' => 'active', 'field_reg_posting_date' => gmdate('Y-m-d\\TH:i:s'), 'field_reg_closing_date' => $future, 'field_reg_application_method' => 'mifotra', 'field_reg_application_url' => ['uri' => 'https://recruitment.mifotra.gov.rw/test'], 'status' => 1, 'moderation_state' => 'published']);
  $reg_job = $save(['type' => 'reg_job', 'title' => 'Acceptance REG Online Vacancy', 'field_reg_recruit_plan_item' => $recruit_item->id(), 'field_reg_reference' => 'REG/JOB/ONLINE', 'field_reg_job_status' => 'active', 'field_reg_posting_date' => gmdate('Y-m-d\\TH:i:s'), 'field_reg_closing_date' => $future, 'field_reg_application_method' => 'reg_online', 'status' => 1, 'moderation_state' => 'published']);
  $expired_job = $save(['type' => 'reg_job', 'title' => 'Acceptance Expired Online Vacancy', 'field_reg_recruit_plan_item' => $recruit_item->id(), 'field_reg_reference' => 'REG/JOB/EXPIRED', 'field_reg_job_status' => 'active', 'field_reg_posting_date' => $past, 'field_reg_closing_date' => $past, 'field_reg_application_method' => 'reg_online', 'status' => 1, 'moderation_state' => 'published']);
  $assert($reg_job->get('field_reg_entity')->value === 'reg' && $reg_job->get('field_reg_department')->value === 'ICT' && (int) $reg_job->get('field_reg_vacancies')->value === 2, 'Vacancy did not inherit safe recruitment-plan defaults.');
  $save(['type' => 'reg_job', 'title' => 'Acceptance Approved Exceptional Recruitment', 'field_reg_unplanned' => 1, 'field_reg_exception_reason' => 'Approved specialist requirement', 'field_reg_approval_ref' => 'HR-EXC-001', 'field_reg_reference' => 'REG/JOB/EXCEPTION', 'field_reg_job_status' => 'active', 'field_reg_posting_date' => gmdate('Y-m-d\\TH:i:s'), 'field_reg_closing_date' => $future, 'field_reg_application_method' => 'instructions', 'status' => 0]);

  $repository = \Drupal::service(\Drupal\reg_core\PublicInformation\PublicInformationRepositoryInterface::class);
  \Drupal::service('cache_tags.invalidator')->invalidateTags(['node_list:reg_tender', 'node_list:reg_job', 'reg_core:public_information']);
  $umucyo_public = $repository->find('reg_tender', (int) $umucyo->id());
  $reg_public = $repository->find('reg_tender', (int) $reg_tender->id());
  $closed_public = $repository->find('reg_tender', (int) $closed->id());
  $job_public = $repository->find('reg_job', (int) $external_job->id());
  $assert(($umucyo_public['action']['label'] ?? '') === 'BID ON UMUCYO' && ($umucyo_public['action']['external'] ?? FALSE), 'UMUCYO CTA did not hand off externally.');
  $assert(($reg_public['action']['label'] ?? '') === 'BID ONLINE' && str_contains($reg_public['action']['url'] ?? '', '/supplier/tenders/'), 'Open REG tender did not expose the controlled portal CTA.');
  $assert(!($closed_public['action']['enabled'] ?? TRUE), 'Closed tender accepts online bids.');
  $assert(($job_public['action']['label'] ?? '') === 'APPLY ON E-RECRUITMENT' && ($job_public['action']['external'] ?? FALSE), 'MIFOTRA vacancy did not hand off externally.');

  $plan_html = (string) \Drupal::httpClient()->get('http://localhost/procurement/annual-plan')->getBody();
  $recruit_html = (string) \Drupal::httpClient()->get('http://localhost/careers/recruitment-plan')->getBody();
  $assert(str_contains($plan_html, 'Acceptance Grid Equipment Procurement') && !str_contains($plan_html, '99999999'), 'Procurement plan visibility/budget controls failed.');
  $assert(str_contains($recruit_html, 'Acceptance ICT Security Specialist'), 'Recruitment plan did not show its approved public item.');

  $rejected = FALSE;
  try { reg_core_secure_submission_presave(Node::create(['type' => 'reg_bid', 'title' => 'Invalid UMUCYO bid', 'field_reg_bid_tender' => $umucyo->id(), 'field_reg_supplier_profile_ref' => 1, 'field_reg_submission_status' => 'draft', 'uid' => 1])); } catch (\Drupal\Core\Entity\EntityStorageException) { $rejected = TRUE; }
  $assert($rejected, 'UMUCYO tender accepted a Drupal bid.');
  $rejected = FALSE;
  try { reg_core_secure_submission_presave(Node::create(['type' => 'reg_job_application', 'title' => 'Expired application', 'field_reg_application_job' => $expired_job->id(), 'field_reg_applicant_profile_ref' => 1, 'field_reg_application_status' => 'draft', 'uid' => 1])); } catch (\Drupal\Core\Entity\EntityStorageException) { $rejected = TRUE; }
  $assert($rejected, 'Expired vacancy accepted an application.');

  $private_directory = 'private://secure-submissions';
  \Drupal::service('file_system')->prepareDirectory($private_directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
  $file = \Drupal::service('file.repository')->writeData("%PDF-1.4\n% REG acceptance fixture\n", 'private://secure-submissions/reg-acceptance.pdf', FileExists::Replace); $files[] = $file;
  $profile = $save(['type' => 'reg_supplier_profile', 'title' => 'Acceptance Supplier', 'field_reg_company_name' => 'Acceptance Supplier Ltd', 'field_reg_tin' => 'TEST-TIN', 'field_reg_country' => 'RW', 'field_reg_registration_no' => 'TEST-REG', 'field_reg_email' => 'supplier@example.invalid', 'field_reg_telephone' => '000', 'field_reg_supplier_status' => 'verified', 'field_reg_document_scan_status' => 'clean', 'field_reg_private_documents' => $file->id(), 'field_reg_test_record' => 1, 'uid' => 1, 'status' => 0]);
  $membership = \Drupal::service('reg_core.supplier_manager')->createMembership($profile, User::load(1), 'supplier_admin'); $created[] = (int) $membership->id();
  $bid = $save(['type' => 'reg_bid', 'title' => 'Acceptance sealed-bid foundation record', 'field_reg_bid_tender' => $reg_tender->id(), 'field_reg_supplier_org' => $profile->id(), 'field_reg_supplier_profile_ref' => $profile->id(), 'field_reg_submission_status' => 'submitted', 'field_reg_bid_declaration' => 1, 'field_reg_bid_scan_status' => 'clean', 'field_reg_technical_documents' => $file->id(), 'field_reg_financial_documents' => $file->id(), 'field_reg_private_documents' => $file->id(), 'uid' => 1, 'status' => 0]);
  $assert(!$bid->get('field_reg_submitted_at')->isEmpty() && !$bid->get('field_reg_submission_ref')->isEmpty() && !$bid->get('field_reg_submission_hash')->isEmpty(), 'Server receipt/timestamp/audit hash was not generated.');
  $private_url = \Drupal::service('file_url_generator')->generateString($file->getFileUri());
  try { $response = \Drupal::httpClient()->get('http://localhost' . $private_url, ['http_errors' => FALSE]); $assert($response->getStatusCode() === 403, 'Anonymous request could access a private bid file.'); } catch (Throwable $e) { throw new RuntimeException('Private file access check failed: ' . $e->getMessage()); }
}
finally {
  if (isset($profile) && $profile instanceof NodeInterface) \Drupal::database()->delete('reg_core_supplier_audit')->condition('organization_nid', $profile->id())->execute();
  if ($created) $storage->delete($storage->loadMultiple(array_reverse($created)));
  foreach ($files as $file) if ($file) $file->delete();
  \Drupal::service('cache_tags.invalidator')->invalidateTags(['node_list', 'reg_core:public_information']);
  $account_switcher->switchBack();
}

print json_encode(['plans' => ['procurement' => TRUE, 'recruitment' => TRUE], 'legacy_preserved' => TRUE, 'channels' => ['reg', 'umucyo', 'both'], 'application_methods' => ['reg_online', 'mifotra', 'external'], 'private_scheme' => 'private', 'portals_production_ready' => FALSE], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
