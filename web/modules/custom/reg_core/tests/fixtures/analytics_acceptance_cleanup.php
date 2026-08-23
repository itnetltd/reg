<?php

/**
 * @file
 * Removes analytics rows recorded by the acceptance fixture.
 */

$deleted = \Drupal::database()->delete('reg_core_analytics_event')
  ->condition('dimension_type', 'section')
  ->condition('dimension_value', 'acceptance')
  ->execute();
$fixture_ids = [];
foreach (['reg_core.branch_acceptance', 'reg_core.public_information_acceptance', 'reg_core.sports_acceptance'] as $state_key) {
  $fixture_ids = array_merge($fixture_ids, (array) ((\Drupal::state()->get($state_key) ?: [])['nodes'] ?? []));
}
if ($fixture_ids) {
  $deleted += \Drupal::database()->delete('reg_core_analytics_event')
    ->condition('entity_id', array_values(array_unique(array_map('intval', $fixture_ids))), 'IN')
    ->execute();
}
$deleted += \Drupal::database()->delete('reg_core_analytics_event')
  ->condition('event_name', 'language_switch')
  ->condition('dimension_type', 'language')
  ->condition('dimension_value', 'rw')
  ->execute();
\Drupal::service('cache_tags.invalidator')->invalidateTags(['reg_core:analytics']);
print 'Removed ' . $deleted . " analytics acceptance aggregate(s).\n";
