<?php

use Drupal\Core\Form\FormState;
use Drupal\Core\File\FileExists;
use Drupal\file\Entity\File;
use Drupal\node\NodeInterface;
use Drupal\reg_core\Form\SupplierRegisterForm;
use Drupal\reg_core\Form\SupplierReviewForm;
use Drupal\user\Entity\User;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) $failures[] = $message; };
$email = 'reg-supplier-test@reg.test.invalid';
$user_storage = \Drupal::entityTypeManager()->getStorage('user');
$node_storage = \Drupal::entityTypeManager()->getStorage('node');
$uids = $user_storage->getQuery()->accessCheck(FALSE)->condition('mail', $email)->execute();
$assert(count($uids) === 1, 'Registration did not create exactly one user.');
$user = $uids ? $user_storage->load(reset($uids)) : NULL;
$profiles = $user ? $node_storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_supplier_profile')->condition('uid', $user->id())->execute() : [];
$assert(count($profiles) === 1, 'Registration did not create exactly one supplier profile.');
$profile = $profiles ? $node_storage->load(reset($profiles)) : NULL;
$memberships = $user ? $node_storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_supplier_membership')->condition('field_reg_member_user.target_id', $user->id())->execute() : [];
$assert(count($memberships) === 1, 'Registration did not create exactly one membership.');
$assert($user && $user->isActive() && (bool) $user->get('field_reg_email_verified')->value, 'Email verification did not activate and mark the test account verified.');
$initially_pending = $profile instanceof NodeInterface && (string) $profile->get('field_reg_supplier_status')->value === 'pending';
if ($initially_pending) $assert($user && $user->hasRole('reg_supplier_pending') && !$user->hasRole('reg_supplier'), 'Verified pending user has the wrong supplier role.');
$assert($profile instanceof NodeInterface && (string) $profile->get('field_reg_tin')->value === 'TESTTIN001' && (string) $profile->get('field_reg_registration_no')->value === 'TESTRDB001', 'Supplier identities were not normalized and stored.');
$verification_used = $user ? (int) \Drupal::database()->select('reg_core_supplier_verification', 'v')->condition('uid', $user->id())->fields('v', ['used'])->execute()->fetchField() : 0;
$assert($verification_used === 1, 'Verification token was not marked used.');

$manager = \Drupal::service('reg_core.supplier_manager');
$assert($manager->identityExists('TEST-TIN-001', 'NEW-RDB'), 'Duplicate TIN was not detected.');
$assert($manager->identityExists('NEW-TIN', 'TEST-RDB-001'), 'Duplicate registration number was not detected.');
$register_form = SupplierRegisterForm::create(\Drupal::getContainer());
$base = ['first_name' => 'Duplicate', 'last_name' => 'Test', 'position' => 'Manager', 'phone' => '+250788123456', 'alternative_phone' => '', 'password' => 'Strong!Password2026', 'tin' => 'TEST-TIN-001', 'registration_no' => 'NEW-RDB-002', 'terms' => 1, 'business_certificate' => [], 'tin_document' => [], 'other_document' => []];
$state = (new FormState())->setValues(array_replace($base, ['email' => 'duplicate-tin@reg.test.invalid'])); $dummy = []; $register_form->validateForm($dummy, $state);
$assert(isset($state->getErrors()['tin']), 'Registration validation did not reject a duplicate TIN.');
$state = (new FormState())->setValues(array_replace($base, ['email' => $email, 'tin' => 'NEW-TIN-002', 'registration_no' => 'NEW-RDB-003'])); $register_form->validateForm($dummy, $state);
$assert(isset($state->getErrors()['email']), 'Registration validation did not reject a duplicate email.');

$test_dir = 'private://supplier-registration/acceptance';
\Drupal::service('file_system')->prepareDirectory($test_dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);
$bad_uri = $test_dir . '/blocked.php';
\Drupal::service('file_system')->saveData('<?php echo "blocked";', $bad_uri, FileExists::Replace);
$bad_file = File::create(['uri' => $bad_uri, 'filename' => 'blocked.php', 'filemime' => 'application/x-php', 'status' => 0]); $bad_file->save();
$state = (new FormState())->setValues(array_replace($base, ['email' => 'invalid-upload@reg.test.invalid', 'tin' => 'NEW-TIN-004', 'registration_no' => 'NEW-RDB-004', 'business_certificate' => [$bad_file->id()]])); $register_form->validateForm($dummy, $state);
$assert(isset($state->getErrors()['business_certificate']), 'Executable upload was not rejected server-side.');
$bad_file->delete();

if ($initially_pending) $assert(!$user->hasPermission('submit reg bids'), 'Pending supplier has bid submission permission.');
$route_access = \Drupal::service('access_manager');
$assert(!$route_access->checkNamedRoute('reg_core.supplier_admin', [], $user, TRUE)->isAllowed(), 'Supplier can access procurement review administration.');
$reviewer = User::create(['name' => 'supplier_review_acceptance_' . substr(hash('sha256', random_bytes(8)), 0, 8), 'status' => 1, 'roles' => ['reg_procurement_approver']]); $reviewer->save();
$assert($route_access->checkNamedRoute('reg_core.supplier_admin', [], $reviewer, TRUE)->isAllowed(), 'Procurement Approver cannot access supplier review administration.');
$reviewer->delete();

if ($profile instanceof NodeInterface && (string) $profile->get('field_reg_supplier_status')->value !== 'approved') {
  $profile->set('field_reg_document_scan_status', 'clean')->save();
  $review = SupplierReviewForm::create(\Drupal::getContainer());
  $review_state = (new FormState())->set('supplier_id', $profile->id())->setValues(['decision' => 'approved', 'reason' => '', 'internal_note' => 'Local acceptance approval.']);
  $review->submitForm($dummy, $review_state);
  $node_storage->resetCache([$profile->id()]); $user_storage->resetCache([$user->id()]);
  $profile = $node_storage->load($profile->id()); $user = $user_storage->load($user->id());
  $assert((string) $profile->get('field_reg_supplier_status')->value === 'approved', 'Approval did not update supplier status.');
  $assert($user->hasRole('reg_supplier') && !$user->hasRole('reg_supplier_pending') && $user->hasPermission('submit reg bids'), 'Approval did not swap supplier roles correctly.');
}
$node_storage->resetCache([$profile->id()]); $user_storage->resetCache([$user->id()]);
$profile = $node_storage->load($profile->id()); $user = $user_storage->load($user->id());
$assert((string) $profile->get('field_reg_supplier_status')->value === 'approved', 'Supplier is not approved after review.');
$assert($user->hasRole('reg_supplier') && !$user->hasRole('reg_supplier_pending') && $user->hasPermission('submit reg bids'), 'Approved supplier role is not bid-capable.');
$audit = \Drupal::database()->select('reg_core_supplier_audit', 'a')->fields('a', ['detail'])->condition('organization_nid', $profile->id())->condition('event', 'status_changed')->condition('detail', '%pending%approved%', 'LIKE')->range(0, 1)->execute()->fetchField();
$assert((bool) $audit, 'Approval audit does not contain old and new status.');

$tender_ids = $node_storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_tender')->condition('status', 1)->condition('field_reg_submission_method', 'reg_online')->condition('field_reg_tender_status', 'active')->execute();
$tender = NULL; foreach ($node_storage->loadMultiple($tender_ids) as $candidate) if ($manager->tenderAcceptsRegBids($candidate)) { $tender = $candidate; break; }
$assert($tender instanceof NodeInterface && $manager->canPrepareBid($tender, (int) $user->id()) && $manager->canSubmitBid($tender, (int) $user->id()), 'Approved supplier cannot prepare and submit an REG Online bid.');
$external_ids = $node_storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_tender')->condition('field_reg_submission_method', ['umucyo', 'external'], 'IN')->range(0, 1)->execute();
if ($external_ids) $assert(!$manager->tenderAcceptsRegBids($node_storage->load(reset($external_ids))), 'UMUCYO/external tender accepted a local bid.');
$assert((string) \Drupal::config('system.file')->get('path.temporary') !== 'sites/default/files', 'Temporary file configuration unexpectedly uses public files.');
$assert(\Drupal::service('stream_wrapper_manager')->getViaScheme('private') !== FALSE, 'Private file stream wrapper is unavailable.');
$private_uri = $test_dir . '/private-access-test.pdf';
\Drupal::service('file_system')->saveData("%PDF-1.4\nLocal private acceptance file\n", $private_uri, FileExists::Replace);
$private_file = File::create(['uri' => $private_uri, 'filename' => 'private-access-test.pdf', 'filemime' => 'application/pdf', 'status' => 1, 'uid' => $user->id()]); $private_file->save();
$private_url = \Drupal::service('file_url_generator')->generateAbsoluteString($private_uri);
$private_response = \Drupal::httpClient()->get($private_url, ['http_errors' => FALSE]);
$assert($private_response->getStatusCode() === 403, 'Anonymous request could access a private supplier document.');
$private_file->delete();

print json_encode(['ok' => !$failures, 'failures' => $failures, 'uid' => $user?->id(), 'profile' => $profile?->id(), 'tender' => $tender?->id()], JSON_PRETTY_PRINT) . PHP_EOL;
if ($failures) exit(1);
