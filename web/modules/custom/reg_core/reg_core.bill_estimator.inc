<?php

/**
 * Seeds the current October 2025 estimator values without overwriting records.
 *
 * @return string[]
 *   IDs of schedules created during this invocation.
 */
function reg_core_bill_estimator_seed_schedules(): array {
  $source = [
    'status' => TRUE,
    'unit' => 'RWF/kWh',
    'effective_from' => '2025-10-01',
    'effective_to' => '',
    'source_title' => 'Approved electricity end-user tariff schedule effective October 2025',
    'source_reference' => 'Existing REG public bill estimator schedule',
    'source_url' => '',
    'approval_date' => '2025-10-01',
    'internal_notes' => 'Migrated from the previously deployed BillEstimatorForm constants. Create a new dated schedule for future tariff changes.',
    'updated' => gmdate(DATE_ATOM, \Drupal::time()->getCurrentTime()),
    'updated_by' => 0,
  ];
  $definitions = [
    'residential_2025_10' => [
      'label' => 'Residential customer — progressive monthly blocks', 'category' => 'residential',
      'customer_group' => 'household_general', 'tariff_type' => 'progressive',
      'bands' => [
        ['enabled' => TRUE, 'label' => 'Residential block: 0–20 kWh', 'min' => 0.0, 'max' => 20.0, 'rate' => 89.0, 'weight' => 0],
        ['enabled' => TRUE, 'label' => 'Residential block: above 20–50 kWh', 'min' => 20.0, 'max' => 50.0, 'rate' => 310.0, 'weight' => 1],
        ['enabled' => TRUE, 'label' => 'Residential block: above 50 kWh', 'min' => 50.0, 'max' => '', 'rate' => 369.0, 'weight' => 2],
      ],
    ],
    'non_residential_2025_10' => [
      'label' => 'Non-residential customer — progressive monthly blocks', 'category' => 'non_residential',
      'customer_group' => 'household_general', 'tariff_type' => 'progressive',
      'bands' => [
        ['enabled' => TRUE, 'label' => 'Non-residential block: 0–100 kWh', 'min' => 0.0, 'max' => 100.0, 'rate' => 355.0, 'weight' => 0],
        ['enabled' => TRUE, 'label' => 'Non-residential block: above 100 kWh', 'min' => 100.0, 'max' => '', 'rate' => 376.0, 'weight' => 1],
      ],
    ],
  ];
  $flat = [
    'telecom_towers' => ['Telecom towers', 289.0],
    'hotel_under_660000' => ['Hotel — annual consumption below 660,000 kWh', 239.0],
    'hotel_over_660000' => ['Hotel — annual consumption above 660,000 kWh', 175.0],
    'health_facilities' => ['Health facility', 214.0],
    'schools' => ['School or higher-learning institution', 214.0],
    'broadcaster_under_660000' => ['Broadcaster — annual consumption below 660,000 kWh', 276.0],
    'broadcasting_shared_660000' => ['Shared broadcasting infrastructure — at least 660,000 kWh/year', 110.0],
    'commercial_data_centres' => ['Commercial data centre', 175.0],
    'water_pumping' => ['Water pumping station', 133.0],
    'water_treatment' => ['Water treatment plant', 133.0],
    'public_ev_charging' => ['Public electric charging infrastructure', 110.0],
    'steel_mining_cement_standard' => ['Steel, mining or cement industry — at least 1,000,000 kWh/year, standard energy rate', 97.0],
  ];
  foreach ($flat as $category => [$label, $rate]) {
    $definitions[$category . '_2025_10'] = [
      'label' => $label, 'category' => $category, 'customer_group' => 'sector_specific',
      'tariff_type' => 'flat', 'flat_rate' => $rate,
    ];
  }
  $prepaid = [
    'industrial_prepaid_small' => ['Small industry — prepaid flat rate', 175.0],
    'industrial_prepaid_medium' => ['Medium industry — prepaid flat rate', 156.0],
    'industrial_prepaid_large' => ['Large industry — prepaid flat rate', 124.0],
  ];
  foreach ($prepaid as $category => [$label, $rate]) {
    $definitions[$category . '_2025_10'] = [
      'label' => $label, 'category' => $category, 'customer_group' => 'industrial_prepaid',
      'tariff_type' => 'flat', 'flat_rate' => $rate,
    ];
  }
  $industrial = [
    'industrial_smart_small' => ['Small industry — smart meter', 175.0, 11017.0, 0.0, 4008.0],
    'industrial_smart_medium' => ['Medium industry — smart meter', 133.0, 10514.0, 0.0, 3588.0],
    'industrial_smart_large' => ['Large industry — smart meter', 110.0, 7184.0, 0.0, 2004.0],
    'industrial_smart_steel_mining_cement' => ['Steel, mining or cement — at least 1,000,000 kWh/year, smart meter', 97.0, 7184.0, 0.0, 2004.0],
  ];
  foreach ($industrial as $category => [$label, $energy, $peak, $offPeak, $shoulder]) {
    $definitions[$category . '_2025_10'] = [
      'label' => $label, 'category' => $category, 'customer_group' => 'industrial_smart',
      'tariff_type' => 'industrial', 'energy_rate' => $energy,
      'peak_demand_rate' => $peak, 'off_peak_demand_rate' => $offPeak,
      'shoulder_demand_rate' => $shoulder,
    ];
  }

  $storage = \Drupal::entityTypeManager()->getStorage('reg_bill_tariff_schedule');
  $created = [];
  foreach ($definitions as $id => $definition) {
    if ($storage->load($id)) {
      continue;
    }
    $storage->create(['id' => $id] + $definition + $source)->save();
    $created[] = $id;
  }
  return $created;
}

/**
 * Adds global estimator defaults only when a value has not been configured.
 */
function reg_core_bill_estimator_seed_settings(): bool {
  $config = \Drupal::configFactory()->getEditable('reg_core.bill_estimator');
  $defaults = [
    'currency' => 'RWF',
    'public_note' => 'All estimates are VAT and regulatory-fee exclusive unless an approved fee is shown in the calculation breakdown.',
    'disclaimer' => 'VAT and regulatory fees are not included because approved percentages were not supplied with this schedule. This estimate is for guidance only and is not an official REG/EUCL invoice.',
    'fees.vat' => ['enabled' => FALSE, 'included' => FALSE, 'type' => 'percentage', 'value' => 0.0, 'label' => 'VAT', 'explanation' => ''],
    'fees.regulatory' => ['enabled' => FALSE, 'included' => FALSE, 'type' => 'percentage', 'value' => 0.0, 'label' => 'Regulatory fee', 'explanation' => ''],
    'fees.other' => ['enabled' => FALSE, 'included' => FALSE, 'type' => 'percentage', 'value' => 0.0, 'label' => 'Other approved fee', 'explanation' => ''],
  ];
  $changed = FALSE;
  foreach ($defaults as $key => $value) {
    if ($config->get($key) === NULL) {
      $config->set($key, $value);
      $changed = TRUE;
    }
  }
  if ($changed) {
    $config->save(TRUE);
  }
  return $changed;
}
