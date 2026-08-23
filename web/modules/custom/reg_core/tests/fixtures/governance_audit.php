<?php

/**
 * @file
 * Reports the installed editorial governance surface for acceptance checks.
 */

$entity_type_manager = \Drupal::entityTypeManager();
$types = [];
foreach ($entity_type_manager->getStorage('node_type')->loadMultiple() as $id => $type) {
  $types[$id] = $type->label();
}
ksort($types);

$media_types = [];
foreach ($entity_type_manager->getStorage('media_type')->loadMultiple() as $id => $type) {
  $media_types[$id] = $type->label();
}
ksort($media_types);

$vocabularies = [];
foreach ($entity_type_manager->getStorage('taxonomy_vocabulary')->loadMultiple() as $id => $vocabulary) {
  $vocabularies[$id] = $vocabulary->label();
}
ksort($vocabularies);

$workflows = [];
foreach ($entity_type_manager->getStorage('workflow')->loadMultiple() as $id => $workflow) {
  $settings = $workflow->get('type_settings');
  $workflows[$id] = [
    'label' => $workflow->label(),
    'bundles' => $settings['entity_types']['node'] ?? [],
    'states' => array_keys($settings['states'] ?? []),
    'transitions' => array_keys($settings['transitions'] ?? []),
  ];
}
ksort($workflows);

$role_ids = [
  'reg_sports_editor',
  'reg_communications_editor',
  'reg_content_approver',
  'reg_procurement_editor',
  'reg_procurement_approver',
  'reg_hr_editor',
  'reg_hr_approver',
  'reg_outage_editor',
  'reg_outage_approver',
  'reg_customer_service_editor',
  'reg_energy_content_editor',
  'administrator',
];
$roles = [];
foreach ($role_ids as $id) {
  $role = $entity_type_manager->getStorage('user_role')->load($id);
  $roles[$id] = $role ? [
    'label' => $role->label(),
    'permissions' => $role->getPermissions(),
  ] : NULL;
}

echo json_encode([
  'node_types' => $types,
  'media_types' => $media_types,
  'vocabularies' => $vocabularies,
  'workflows' => $workflows,
  'roles' => $roles,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
