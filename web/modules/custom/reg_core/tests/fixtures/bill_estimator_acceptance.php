<?php

use Drupal\reg_core\Energy\BillEstimator;
use Drupal\reg_core\Exception\BillEstimatorUnavailableException;

require_once DRUPAL_ROOT . '/modules/custom/reg_core/reg_core.bill_estimator.inc';

$storage = \Drupal::entityTypeManager()->getStorage('reg_bill_tariff_schedule');
$configFactory = \Drupal::configFactory();
$originalSettings = $configFactory->get('reg_core.bill_estimator')->getRawData();
$telecom = $storage->load('telecom_towers_2025_10');
if (!$telecom) {
  throw new RuntimeException('Migrated telecom tariff schedule is missing.');
}
$originalRate = (float) $telecom->get('flat_rate');

try {
  if (count($storage->loadMultiple()) !== 21) {
    throw new RuntimeException('Expected exactly 21 migrated tariff schedules.');
  }
  if (reg_core_bill_estimator_seed_schedules() !== [] || reg_core_bill_estimator_seed_settings()) {
    throw new RuntimeException('Estimator seed helpers are not idempotent.');
  }

  $service = \Drupal::service('reg_core.bill_estimator');
  if ($service->calculate('residential', 70)['total'] !== 18460.0) {
    throw new RuntimeException('Migrated progressive result changed.');
  }
  if ($service->calculate('industrial_smart_small', 100, ['peak' => 2, 'off_peak' => 5, 'shoulder' => 3])['total'] !== 51558.0) {
    throw new RuntimeException('Migrated industrial result changed.');
  }

  $telecom->set('flat_rate', $originalRate + 1)->save();
  if ($service->calculate('telecom_towers', 1)['total'] !== $originalRate + 1) {
    throw new RuntimeException('A saved schedule update did not change the calculation.');
  }
  $telecom->set('flat_rate', $originalRate)->save();

  $editable = $configFactory->getEditable('reg_core.bill_estimator');
  $editable->set('fees.vat', [
    'enabled' => TRUE, 'included' => TRUE, 'type' => 'percentage',
    'value' => 10.0, 'label' => 'VAT', 'explanation' => 'Acceptance test',
  ])->save(TRUE);
  if ($service->calculate('telecom_towers', 10)['total'] !== 3179.0) {
    throw new RuntimeException('An approved fee configuration update was not applied.');
  }

  try {
    BillEstimator::selectApplicableSchedule([], 'missing', '2026-01-01');
    throw new RuntimeException('Missing schedules did not fail closed.');
  }
  catch (BillEstimatorUnavailableException) {
  }

  print json_encode([
    'schedules' => 21,
    'progressive_total' => 18460,
    'industrial_total' => 51558,
    'idempotent' => TRUE,
    'schedule_update_applied' => TRUE,
    'fee_update_applied' => TRUE,
    'fallback_verified' => TRUE,
  ], JSON_PRETTY_PRINT) . PHP_EOL;
}
finally {
  $telecom->set('flat_rate', $originalRate)->save();
  $configFactory->getEditable('reg_core.bill_estimator')->setData($originalSettings)->save(TRUE);
}
