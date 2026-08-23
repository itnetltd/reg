<?php

/**
 * @file
 * Exercises approved analytics events and compares dashboard aggregates.
 */

use Drupal\reg_core\Analytics\AnalyticsEventTrackerInterface;

/** @var \Drupal\reg_core\Analytics\AnalyticsEventTrackerInterface $tracker */
$tracker = \Drupal::service(AnalyticsEventTrackerInterface::class);
$branch = \Drupal::state()->get('reg_core.branch_acceptance') ?: [];
$public_information = \Drupal::state()->get('reg_core.public_information_acceptance') ?: [];
$sports = \Drupal::state()->get('reg_core.sports_acceptance') ?: [];

$events = [
  ['branch_view', (int) ($branch['kigali'] ?? 0)],
  ['faq_search', 0],
  ['tender_download', (int) ($public_information['current_tender'] ?? 0)],
  ['sports_result_view', (int) ($sports['fixture'] ?? 0)],
];
foreach ($events as [$event, $entity_id]) {
  if (!$tracker->record($event, [
    'dimension_type' => 'section',
    'dimension_value' => 'acceptance',
    'entity_id' => $entity_id,
  ])) {
    throw new RuntimeException(sprintf('Approved event %s was not recorded.', $event));
  }
}

$pii_event_rejected = !$tracker->record('customer_email_capture', [
  'dimension_type' => 'category',
  'dimension_value' => 'person@example.invalid',
]);
$pii_context_rejected = !$tracker->record('faq_search', [
  'dimension_type' => 'category',
  'dimension_value' => 'person@example.invalid',
]);

$query = \Drupal::database()->select('reg_core_analytics_event', 'a')
  ->condition('dimension_type', 'section')
  ->condition('dimension_value', 'acceptance');
$query->addField('a', 'event_name');
$query->addExpression('SUM(occurrences)', 'total');
$query->groupBy('event_name');
$stored = [];
foreach ($query->execute() as $record) {
  $stored[$record->event_name] = (int) $record->total;
}
$customer = \Drupal::service('reg_core.dashboard_repository')->section('customer-services');
$customer_cards = [];
foreach ($customer['cards'] as $card) {
  $customer_cards[$card['label']] = $card['value'];
}
$database_branch_total_query = \Drupal::database()->select('reg_core_analytics_event', 'a')
  ->condition('event_name', 'branch_view');
$database_branch_total_query->addExpression('COALESCE(SUM(occurrences), 0)', 'total');
$database_branch_total = (int) $database_branch_total_query->execute()->fetchField();

$module_path = \Drupal::service('extension.list.module')->getPath('reg_core');
require_once DRUPAL_ROOT . '/' . $module_path . '/reg_core.install';
$columns = array_keys(reg_core_schema()['reg_core_analytics_event']['fields']);
$forbidden_columns = array_intersect($columns, ['ip', 'uid', 'email', 'phone', 'name', 'account_number', 'complaint_text', 'query']);
$result = [
  'stored_events' => $stored,
  'pii_event_rejected' => $pii_event_rejected,
  'pii_context_rejected' => $pii_context_rejected,
  'forbidden_columns' => $forbidden_columns,
  'dashboard_branch_view' => $customer_cards['Branch View'] ?? NULL,
  'database_branch_view' => $database_branch_total,
];
if (count($stored) !== 4 || !$pii_event_rejected || !$pii_context_rejected || $forbidden_columns || $result['dashboard_branch_view'] !== $result['database_branch_view']) {
  throw new RuntimeException('Analytics acceptance assertions failed: ' . json_encode($result));
}
print json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
