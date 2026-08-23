<?php

use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); print "PASS: $message\n"; };
$email = 'demo.supplier@reg.test.invalid';
$users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['mail' => $email]);
$assert(!$users, 'demo supplier does not pre-exist');
$user = User::create(['name' => $email, 'mail' => $email, 'pass' => bin2hex(random_bytes(20)), 'status' => 1, 'roles' => ['reg_supplier'], 'field_reg_first_name' => 'Demo', 'field_reg_last_name' => 'Supplier', 'field_reg_phone' => '+250700000000', 'field_reg_email_verified' => 1, 'field_reg_supplier_terms_at' => \Drupal::time()->getRequestTime()]); $user->save();
$files = [];
$fixture_directory = 'private://supplier-acceptance'; \Drupal::service('file_system')->prepareDirectory($fixture_directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);
foreach (['registration.pdf', 'technical.pdf', 'financial.pdf'] as $name) { $uri = 'private://supplier-acceptance/' . $name; \Drupal::service('file_system')->saveData("LOCAL ACCEPTANCE FIXTURE\n", $uri, \Drupal\Core\File\FileExists::Replace); $file = File::create(['uri' => $uri, 'filename' => $name, 'status' => 1, 'uid' => $user->id()]); $file->save(); $files[$name] = $file; }
$category_ids = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->getQuery()->accessCheck(FALSE)->condition('vid', 'reg_supplier_category')->range(0, 1)->execute();
$org = Node::create(['type' => 'reg_supplier_profile', 'title' => 'Demo Supplier Ltd [LOCAL TEST]', 'uid' => $user->id(), 'status' => 0, 'field_reg_company_name' => 'Demo Supplier Ltd [LOCAL TEST]', 'field_reg_country' => 'RW', 'field_reg_tin' => 'TESTTIN900001', 'field_reg_registration_no' => 'LOCAL-TEST-001', 'field_reg_company_type' => 'company', 'field_reg_address' => 'Local DDEV acceptance fixture', 'field_reg_email' => $email, 'field_reg_telephone' => '+250700000000', 'field_reg_supplier_categories' => array_values($category_ids), 'field_reg_private_documents' => [$files['registration.pdf']->id()], 'field_reg_supplier_status' => 'pending', 'field_reg_document_scan_status' => 'pending', 'field_reg_test_record' => 1]); $org->save();
$switcher = \Drupal::service('account_switcher'); $switcher->switchTo($user);
try {
  $manager = \Drupal::service('reg_core.supplier_manager'); $membership = $manager->createMembership($org, $user, 'supplier_admin');
  $tender_storage = \Drupal::entityTypeManager()->getStorage('node'); $ids = $tender_storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_tender')->condition('status', 1)->condition('field_reg_submission_method', 'reg_online')->condition('field_reg_tender_status', 'active')->sort('field_reg_closing_date', 'DESC')->execute(); $tender = NULL; foreach ($tender_storage->loadMultiple($ids) as $candidate) if ($manager->tenderAcceptsRegBids($candidate)) { $tender = $candidate; break; }
  $assert($tender instanceof NodeInterface, 'an open REG Online tender is available');
  $original_method = (string) $tender->get('field_reg_submission_method')->value; $tender->set('field_reg_submission_method', 'umucyo'); $assert(!$manager->tenderAcceptsRegBids($tender), 'UMUCYO tender rejects local bid submission'); $tender->set('field_reg_submission_method', $original_method);
  $assert($manager->canPrepareBid($tender), 'pending supplier administrator can prepare a draft'); $assert(!$manager->canSubmitBid($tender), 'pending supplier cannot submit a final bid');
  $org->set('field_reg_supplier_status', 'verified')->set('field_reg_document_scan_status', 'clean')->save(); $tender_storage->resetCache([$org->id(), $membership->id()]); $assert($manager->canSubmitBid($tender), 'verified clean supplier administrator can submit');
  $assert($manager->tinExists('TEST-TIN-900001'), 'normalized duplicate TIN is detected without revealing another organization');
  $membership = $tender_storage->load($membership->id()); $membership->set('field_reg_org_role', 'bid_contributor')->save(); $tender_storage->resetCache([$org->id(), $membership->id()]); $assert($manager->canPrepareBid($tender) && !$manager->canSubmitBid($tender), 'contributor can prepare but cannot submit'); $membership = $tender_storage->load($membership->id()); $membership->set('field_reg_org_role', 'supplier_admin')->save(); $tender_storage->resetCache([$org->id(), $membership->id()]);
  $external_ids = $tender_storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_tender')->condition('field_reg_submission_method', ['umucyo', 'external'], 'IN')->range(0, 1)->execute(); if ($external_ids) $assert(!$manager->tenderAcceptsRegBids($tender_storage->load(reset($external_ids))), 'external or UMUCYO tender rejects local bid submission');
  $bid = Node::create(['type' => 'reg_bid', 'title' => 'LOCAL TEST BID', 'uid' => $user->id(), 'status' => 0, 'field_reg_bid_tender' => $tender->id(), 'field_reg_supplier_org' => $org->id(), 'field_reg_bid_information' => 'Local acceptance fixture', 'field_reg_technical_documents' => [$files['technical.pdf']->id()], 'field_reg_financial_documents' => [$files['financial.pdf']->id()], 'field_reg_bid_declaration' => 1, 'field_reg_bid_scan_status' => 'clean', 'field_reg_submission_status' => 'submitted']); $bid->save();
  $assert((string) $bid->get('field_reg_submission_ref')->value !== '', 'final bid receives an immutable server receipt');
  $assert(!$user->hasPermission('access administration pages') && !$user->hasPermission('access toolbar'), 'supplier account has no administration or toolbar permission');
  print 'DEMO_UID=' . $user->id() . "\nDEMO_ORG='" . $org->id() . "'\nDEMO_BID=" . $bid->id() . "\n";
}
finally { $switcher->switchBack(); }
