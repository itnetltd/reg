<?php

use Drupal\Core\Form\FormState;
use Drupal\reg_core\Form\SupplierRegisterForm;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) $failures[] = $message; };
$form_object = SupplierRegisterForm::create(\Drupal::getContainer());
$reflection = new ReflectionObject($form_object);
$dependencies = ['database', 'time', 'flood', 'mailManager', 'regLanguageManager', 'regEntityTypeManager', 'supplierManager', 'registrationLock', 'documentScanner'];
foreach ($dependencies as $property_name) {
  $property = $reflection->getProperty($property_name);
  $assert($property->isInitialized($form_object), "Dependency $property_name is not initialized.");
}

$built = $form_object->buildForm([], new FormState());
foreach (['company_name', 'tin', 'registration_no'] as $field) $assert(!empty($built['company'][$field]['#required']), "$field is not required.");
foreach (['first_name', 'last_name', 'position', 'email', 'phone'] as $field) $assert(!empty($built['contact'][$field]['#required']), "$field is not required.");
$assert(!empty($built['declaration']['terms']['#required']), 'Declaration is not required.');

$email = 'reg-supplier-test@reg.test.invalid';
$user_storage = \Drupal::entityTypeManager()->getStorage('user');
$node_storage = \Drupal::entityTypeManager()->getStorage('node');
$uids = $user_storage->getQuery()->accessCheck(FALSE)->condition('mail', $email)->execute();
$assert(count($uids) === 1, 'Anonymous submission did not create exactly one user.');
$user = $uids ? $user_storage->load(reset($uids)) : NULL;
$profiles = $user ? $node_storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_supplier_profile')->condition('uid', $user->id())->execute() : [];
$memberships = $user ? $node_storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_supplier_membership')->condition('field_reg_member_user.target_id', $user->id())->execute() : [];
$assert(count($profiles) === 1, 'Anonymous submission did not create exactly one supplier profile.');
$assert(count($memberships) === 1, 'Anonymous submission did not create exactly one supplier membership.');
$profile = $profiles ? $node_storage->load(reset($profiles)) : NULL;
$assert($user && !$user->isActive() && $user->hasRole('reg_supplier_pending') && !$user->hasRole('reg_supplier'), 'New account does not have the unverified pending state.');
$assert($profile && (string) $profile->get('field_reg_supplier_status')->value === 'email_unverified', 'New supplier profile is not Email Not Verified.');

$base = ['first_name' => 'Validation', 'last_name' => 'Test', 'position' => 'Manager', 'email' => $email, 'phone' => '+250788123456', 'alternative_phone' => '', 'password' => 'Strong!Password2026', 'tin' => 'TEST-TIN-001', 'registration_no' => 'TEST-RDB-001', 'terms' => 1, 'business_certificate' => [], 'tin_document' => [], 'other_document' => []];
$state = (new FormState())->setValues($base); $dummy = []; $form_object->validateForm($dummy, $state); $errors = $state->getErrors();
$assert(isset($errors['email']), 'Duplicate email was not rejected.');
$assert(isset($errors['tin']), 'Duplicate TIN was not rejected.');
$weak = array_replace($base, ['email' => 'weak-password@reg.test.invalid', 'tin' => 'NEW-TIN-123', 'registration_no' => 'NEW-RDB-123', 'password' => 'weak']);
$state = (new FormState())->setValues($weak); $form_object->validateForm($dummy, $state);
$assert(isset($state->getErrors()['password']), 'Weak password was not rejected.');

print json_encode(['ok' => !$failures, 'failures' => $failures, 'dependencies' => $dependencies, 'uid' => $user?->id(), 'profile' => $profile?->id()], JSON_PRETTY_PRINT) . PHP_EOL;
if ($failures) exit(1);
