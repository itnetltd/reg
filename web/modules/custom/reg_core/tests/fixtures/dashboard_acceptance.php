<?php

/**
 * @file
 * Renders permission-scoped dashboards through Drupal's HTTP kernel.
 */

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

$account = User::create([
  'name' => 'reg_dashboard_acceptance_' . \Drupal::time()->getRequestTime(),
  'status' => 1,
  'roles' => ['reg_content_approver'],
]);
$account->save();
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo($account);

try {
  $results = [];
  foreach ([
    '/admin/reg-dashboard',
    '/admin/reg-dashboard/customer-services',
    '/admin/reg-dashboard/content',
    '/admin/reg-dashboard/procurement',
    '/admin/reg-dashboard/recruitment',
    '/admin/reg-dashboard/publications',
    '/admin/reg-dashboard/sports',
    '/admin/reg-dashboard/translations',
    '/admin/reg-dashboard/operations',
  ] as $path) {
    $request = Request::create($path, 'GET');
    $response = \Drupal::service('http_kernel')->handle($request, HttpKernelInterface::SUB_REQUEST);
    $content = $response->getContent();
    $results[$path] = [
      'status' => $response->getStatusCode(),
      'has_dashboard' => str_contains($content, 'reg-admin-report'),
      'has_secret_placeholder' => str_contains($content, 'REG_GE_DMS_CREDENTIAL'),
      'has_endpoint' => str_contains($content, 'api_base_url'),
    ];
  }
  $anonymous_access = \Drupal::service('access_manager')->checkNamedRoute(
    'reg_core.dashboard',
    [],
    new AnonymousUserSession(),
    TRUE,
  );
  foreach ($results as $result) {
    if ($result['status'] !== 200 || !$result['has_dashboard'] || $result['has_secret_placeholder'] || $result['has_endpoint']) {
      throw new RuntimeException('Dashboard render acceptance failed: ' . json_encode($results));
    }
  }
  $export_response = \Drupal::service('http_kernel')->handle(
    Request::create('/admin/reg-dashboard/export/branch-usage', 'GET'),
    HttpKernelInterface::SUB_REQUEST,
  );
  $results['csv_export'] = [
    'status' => $export_response->getStatusCode(),
    'content_type' => $export_response->headers->get('Content-Type'),
    'contains_email_column' => str_contains(strtolower($export_response->getContent()), 'email'),
    'contains_ip_column' => str_contains(strtolower($export_response->getContent()), 'ip address'),
  ];
  if ($results['csv_export']['status'] !== 200 || !str_starts_with((string) $results['csv_export']['content_type'], 'text/csv') || $results['csv_export']['contains_email_column'] || $results['csv_export']['contains_ip_column']) {
    throw new RuntimeException('Dashboard CSV acceptance failed.');
  }
  if ($anonymous_access->isAllowed()) {
    throw new RuntimeException('Anonymous dashboard access was allowed.');
  }
  $results['anonymous_forbidden'] = TRUE;
  print json_encode($results, JSON_PRETTY_PRINT) . PHP_EOL;
}
finally {
  $switcher->switchBack();
  $account->delete();
}
