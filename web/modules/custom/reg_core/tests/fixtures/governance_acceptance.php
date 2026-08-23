<?php

use Drupal\user\Entity\User;

$roles = [
  'reg_sports_editor', 'reg_communications_editor', 'reg_content_approver',
  'reg_procurement_officer', 'reg_procurement_approver', 'reg_hr_officer',
  'reg_hr_approver', 'reg_outage_editor', 'reg_outage_approver',
  'reg_customer_service_editor', 'reg_energy_content_editor',
];
$areas = [
  'reg_sports_editor' => ['reg_core.business_sports_admin', 'reg_sports_team'],
  'reg_communications_editor' => ['reg_core.business_communications_admin', 'reg_news'],
  'reg_procurement_officer' => ['reg_core.business_procurement_admin', 'reg_tender'],
  'reg_hr_officer' => ['reg_core.business_hr_admin', 'reg_job'],
  'reg_outage_editor' => ['reg_core.business_outages_admin', 'reg_outage'],
  'reg_customer_service_editor' => ['reg_core.business_customer_service_admin', 'reg_faq'],
  'reg_energy_content_editor' => ['reg_core.business_energy_admin', 'reg_fact'],
];
$failures = [];
$results = [];
$created = [];
$access_manager = \Drupal::service('access_manager');
$user_storage = \Drupal::entityTypeManager()->getStorage('user');
$node_storage = \Drupal::entityTypeManager()->getStorage('node');

try {
  foreach ($areas as $role_id => [$allowed_route, $bundle]) {
    $account = User::create([
      'name' => 'governance_test_' . $role_id . '_' . substr(hash('sha256', random_bytes(8)), 0, 8),
      'status' => 1,
      'roles' => [$role_id],
    ]);
    $account->save();
    $created[] = $account;

    $allowed = $access_manager->checkNamedRoute($allowed_route, [], $account, TRUE)->isAllowed();
    if ($role_id === 'reg_sports_editor' && !$access_manager->checkNamedRoute('reg_core.dashboard_sports', [], $account, TRUE)->isAllowed()) {
      $failures[] = 'Sports Manager cannot access the sports dashboard';
    }
    $denied = [];
    foreach (['reg_core.business_sports_admin', 'reg_core.business_communications_admin', 'reg_core.business_procurement_admin', 'reg_core.business_hr_admin', 'reg_core.business_outages_admin', 'reg_core.business_customer_service_admin', 'reg_core.business_energy_admin', 'entity.user.collection', 'system.admin_config'] as $route) {
      if ($route !== $allowed_route && $access_manager->checkNamedRoute($route, [], $account, TRUE)->isAllowed()) {
        $denied[] = $route;
      }
    }
    // Communications Approver intentionally spans communications, sports,
    // customer-service, and energy approvals; editors remain single-area.
    if (!$allowed) {
      $failures[] = "$role_id cannot access $allowed_route";
    }
    if ($denied) {
      $failures[] = "$role_id crossed into: " . implode(', ', $denied);
    }

    $ids = $node_storage->getQuery()->accessCheck(FALSE)->condition('type', $bundle)->range(0, 1)->execute();
    $own_edit = NULL;
    if ($ids && ($node = $node_storage->load(reset($ids)))) {
      $own_edit = $access_manager->checkNamedRoute('entity.node.edit_form', ['node' => $node->id()], $account, TRUE)->isAllowed();
      if (!$own_edit) {
        $failures[] = "$role_id cannot edit representative $bundle content";
      }
    }
    else {
      $own_edit = $access_manager->checkNamedRoute('node.add', ['node_type' => $bundle], $account, TRUE)->isAllowed();
      if (!$own_edit) {
        $failures[] = "$role_id cannot open the $bundle creation route";
      }
    }
    $results[$role_id] = ['allowed_route' => $allowed, 'cross_area_routes' => $denied, 'representative_edit' => $own_edit];
  }

  $all_area_routes = [
    'reg_core.business_sports_admin', 'reg_core.business_communications_admin',
    'reg_core.business_procurement_admin', 'reg_core.business_hr_admin',
    'reg_core.business_outages_admin', 'reg_core.business_customer_service_admin',
    'reg_core.business_energy_admin',
  ];
  $approver_routes = [
    'reg_content_approver' => [
      'reg_core.business_sports_admin', 'reg_core.business_communications_admin',
      'reg_core.business_customer_service_admin', 'reg_core.business_energy_admin',
    ],
    'reg_procurement_approver' => ['reg_core.business_procurement_admin'],
    'reg_hr_approver' => ['reg_core.business_hr_admin'],
    'reg_outage_approver' => ['reg_core.business_outages_admin'],
  ];
  foreach ($approver_routes as $role_id => $allowed_routes) {
    $account = User::create([
      'name' => 'governance_test_' . $role_id . '_' . substr(hash('sha256', random_bytes(8)), 0, 8),
      'status' => 1,
      'roles' => [$role_id],
    ]);
    $account->save();
    $created[] = $account;
    $wrong = [];
    foreach ($all_area_routes as $route) {
      $allowed = $access_manager->checkNamedRoute($route, [], $account, TRUE)->isAllowed();
      if ($allowed !== in_array($route, $allowed_routes, TRUE)) {
        $wrong[] = $route;
      }
    }
    foreach (['entity.user.collection', 'system.admin_config'] as $route) {
      if ($access_manager->checkNamedRoute($route, [], $account, TRUE)->isAllowed()) {
        $wrong[] = $route;
      }
    }
    if ($wrong) {
      $failures[] = "$role_id route matrix mismatch: " . implode(', ', $wrong);
    }
    $results[$role_id] = ['allowed_routes' => $allowed_routes, 'matrix_mismatches' => $wrong];
  }

  foreach ($roles as $role_id) {
    $role = \Drupal\user\Entity\Role::load($role_id);
    if (!$role) {
      $failures[] = "Missing role $role_id";
      continue;
    }
    foreach ($role->getPermissions() as $permission) {
      if ($permission === 'administer nodes' || $permission === 'access content overview' || str_starts_with($permission, 'delete any ') || str_starts_with($permission, 'delete own ')) {
        $failures[] = "$role_id has forbidden permission: $permission";
      }
    }
  }

  foreach (['reg_sports_editor', 'reg_communications_editor', 'reg_procurement_officer', 'reg_hr_officer', 'reg_outage_editor', 'reg_customer_service_editor', 'reg_energy_content_editor'] as $editor_id) {
    foreach (\Drupal\user\Entity\Role::load($editor_id)->getPermissions() as $permission) {
      if (str_starts_with($permission, 'use ') && (str_ends_with($permission, ' transition publish') || str_ends_with($permission, ' transition archive'))) {
        $failures[] = "$editor_id can publish/archive: $permission";
      }
    }
  }

  $field_rules = [
    'reg_outage_editor' => 'change reg outage operational status',
    'reg_procurement_officer' => 'change reg procurement lifecycle',
    'reg_hr_officer' => 'change reg recruitment lifecycle',
  ];
  foreach ($field_rules as $editor => $permission) {
    if (\Drupal\user\Entity\Role::load($editor)->hasPermission($permission)) {
      $failures[] = "$editor can change protected lifecycle field";
    }
  }
  foreach (['reg_outage_approver' => 'change reg outage operational status', 'reg_procurement_approver' => 'change reg procurement lifecycle', 'reg_hr_approver' => 'change reg recruitment lifecycle'] as $approver => $permission) {
    if (!\Drupal\user\Entity\Role::load($approver)->hasPermission($permission)) {
      $failures[] = "$approver cannot change protected lifecycle field";
    }
  }

  $anonymous = \Drupal::currentUser();
  $public_routes = [];
  foreach (['reg_core.home', 'reg_core.news', 'reg_core.outages', 'reg_core.tenders', 'reg_core.jobs', 'reg_core.sports'] as $route) {
    $public_routes[$route] = $access_manager->checkNamedRoute($route, [], $anonymous, TRUE)->isAllowed();
    if (!$public_routes[$route]) {
      $failures[] = "Anonymous public route access failed: $route";
    }
  }
  $results['public_routes'] = $public_routes;
}
finally {
  foreach ($created as $account) {
    $account->delete();
  }
}

print json_encode(['ok' => !$failures, 'failures' => $failures, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
if ($failures) {
  exit(1);
}
