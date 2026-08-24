<?php

$emails = ['supplier-diagnosis@reg.test.invalid', 'reg-supplier-test@reg.test.invalid'];
$user_storage = \Drupal::entityTypeManager()->getStorage('user');
$node_storage = \Drupal::entityTypeManager()->getStorage('node');
$uids = $user_storage->getQuery()->accessCheck(FALSE)->condition('mail', $emails, 'IN')->execute();
$organization_ids = [];
if ($uids) {
  $membership_ids = $node_storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_supplier_membership')->condition('field_reg_member_user.target_id', $uids, 'IN')->execute();
  foreach ($node_storage->loadMultiple($membership_ids) as $membership) {
    if (!$membership->get('field_reg_supplier_org')->isEmpty()) $organization_ids[] = (int) $membership->get('field_reg_supplier_org')->target_id;
  }
  if ($organization_ids) {
    $related = $node_storage->getQuery()->accessCheck(FALSE)->condition('type', ['reg_supplier_membership', 'reg_bid'], 'IN')->condition('field_reg_supplier_org.target_id', array_unique($organization_ids), 'IN')->execute();
    $node_storage->delete($node_storage->loadMultiple($related));
    $node_storage->delete($node_storage->loadMultiple(array_unique($organization_ids)));
  }
  \Drupal::database()->delete('reg_core_supplier_verification')->condition('uid', $uids, 'IN')->execute();
  \Drupal::database()->delete('reg_core_supplier_audit')->condition('uid', $uids, 'IN')->execute();
  if ($organization_ids) \Drupal::database()->delete('reg_core_supplier_audit')->condition('organization_nid', array_unique($organization_ids), 'IN')->execute();
  $user_storage->delete($user_storage->loadMultiple($uids));
}
\Drupal::database()->delete('flood')->condition('event', 'reg_core_supplier_register')->execute();
print "Supplier registration acceptance records removed.\n";
