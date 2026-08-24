<?php

use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

$email = 'supplier-login-acceptance@reg.test.invalid';
$user_storage = \Drupal::entityTypeManager()->getStorage('user');
$node_storage = \Drupal::entityTypeManager()->getStorage('node');
$existing = $user_storage->loadByProperties(['mail' => $email]);

if (($extra[0] ?? '') === 'cleanup') {
  foreach ($existing as $account) {
    $memberships = $node_storage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'reg_supplier_membership')
      ->condition('field_reg_member_user.target_id', $account->id())
      ->execute();
    if ($memberships) {
      $node_storage->delete($node_storage->loadMultiple($memberships));
    }
    $account->delete();
  }
  print "Disposable supplier login account removed.\n";
  return;
}

foreach ($existing as $account) {
  $memberships = $node_storage->getQuery()->accessCheck(FALSE)
    ->condition('type', 'reg_supplier_membership')
    ->condition('field_reg_member_user.target_id', $account->id())
    ->execute();
  if ($memberships) {
    $node_storage->delete($node_storage->loadMultiple($memberships));
  }
  $account->delete();
}

$organization = $node_storage->load(776);
if (!$organization || $organization->bundle() !== 'reg_supplier_profile') {
  throw new RuntimeException('Expected IT NET Ltd supplier profile 776 was not found.');
}

$account = User::create([
  'name' => $email,
  'mail' => $email,
  'status' => 1,
  'roles' => ['reg_supplier_pending'],
  'field_reg_email_verified' => 1,
]);
$account->setPassword('SupplierLogin!Acceptance2026');
$account->save();

$membership = Node::create([
  'type' => 'reg_supplier_membership',
  'title' => 'Disposable supplier login acceptance membership',
  'uid' => $account->id(),
  'status' => 0,
  'field_reg_supplier_org' => $organization->id(),
  'field_reg_member_user' => $account->id(),
  'field_reg_org_role' => 'supplier_viewer',
  'field_reg_membership_status' => 'active',
]);
$membership->save();

print "Disposable supplier login account created.\n";
