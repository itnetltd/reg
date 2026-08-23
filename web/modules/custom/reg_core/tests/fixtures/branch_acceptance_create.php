<?php

/**
 * @file
 * Creates temporary tagged Branch Locator acceptance fixtures.
 */

use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

$state = \Drupal::state();
if ($state->get('reg_core.branch_acceptance')) {
  throw new RuntimeException('Branch acceptance fixtures already exist.');
}
$term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$provinces = [];
foreach ($term_storage->loadTree('reg_province') as $term) {
  $provinces[$term->name] = (int) $term->tid;
}
$services = [];
foreach ($term_storage->loadTree('reg_branch_service') as $term) {
  $services[$term->name] = (int) $term->tid;
}
$district_ids = [];
foreach (['Kigali Test District', 'Musanze Test District', 'Huye Test District'] as $name) {
  $term = Term::create(['vid' => 'reg_district', 'name' => '[DEMO] ' . $name]);
  $term->save();
  $district_ids[$name] = (int) $term->id();
}
$node_ids = [];
$create = static function (array $values, bool $published = TRUE) use (&$node_ids): Node {
  $node = Node::create($values + [
    'uid' => 1,
    'status' => $published ? 1 : 0,
    'moderation_state' => $published ? 'published' : 'draft',
  ]);
  $node->save();
  $node_ids[] = (int) $node->id();
  return $node;
};
$common = [
  'type' => 'reg_branch',
  'field_reg_branch_type' => 'customer_service_center',
  'field_reg_phone' => '2727',
  'field_reg_email' => 'demo@example.invalid',
  'field_reg_opening_hours' => 'Monday to Friday, 08:00-17:00',
  'field_reg_branch_services' => [
    ['target_id' => $services['Billing support']],
    ['target_id' => $services['Fault reporting']],
  ],
  'field_reg_active' => 1,
  'field_reg_public_notes' => 'Temporary DEMO branch used only for acceptance testing.',
];
$kigali = $create($common + [
  'title' => 'Kigali Test Branch [DEMO]',
  'field_reg_entity' => 'reg',
  'field_reg_province' => $provinces['City of Kigali'],
  'field_reg_district_ref' => $district_ids['Kigali Test District'],
  'field_reg_sector' => 'Demo Sector',
  'field_reg_address' => 'Demo address, Kigali',
  'field_reg_latitude' => -1.9441,
  'field_reg_longitude' => 30.0619,
  'field_reg_featured' => 1,
]);
$musanze = $create($common + [
  'title' => 'Musanze Test Branch [DEMO]',
  'field_reg_entity' => 'eucl',
  'field_reg_province' => $provinces['Northern Province'],
  'field_reg_district_ref' => $district_ids['Musanze Test District'],
  'field_reg_address' => 'Demo address, Musanze',
  'field_reg_latitude' => -1.4998,
  'field_reg_longitude' => 29.6349,
]);
$huye = $create($common + [
  'title' => 'Huye Test Branch [DEMO]',
  'field_reg_entity' => 'edcl',
  'field_reg_province' => $provinces['Southern Province'],
  'field_reg_district_ref' => $district_ids['Huye Test District'],
  'field_reg_address' => 'Demo address, Huye',
  'field_reg_latitude' => -2.5967,
  'field_reg_longitude' => 29.7394,
]);
$hidden = $create($common + [
  'title' => 'Hidden Test Branch [DEMO]',
  'field_reg_entity' => 'reg',
  'field_reg_province' => $provinces['City of Kigali'],
  'field_reg_district_ref' => $district_ids['Kigali Test District'],
  'field_reg_address' => 'Hidden demo address',
], FALSE);

$fixtures = [
  'nodes' => $node_ids,
  'terms' => array_values($district_ids),
  'kigali' => (int) $kigali->id(),
  'musanze' => (int) $musanze->id(),
  'huye' => (int) $huye->id(),
  'hidden' => (int) $hidden->id(),
  'district_kigali' => $district_ids['Kigali Test District'],
  'province_kigali' => $provinces['City of Kigali'],
  'service' => $services['Billing support'],
];
$state->set('reg_core.branch_acceptance', $fixtures);
print json_encode($fixtures, JSON_PRETTY_PRINT) . PHP_EOL;
