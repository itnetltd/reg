<?php

/**
 * Live acceptance checks for the structured About REG section.
 */

use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\workflows\Entity\Workflow;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
  print ($condition ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
  if (!$condition) {
    $failures++;
  }
};

$entityTypeManager = \Drupal::entityTypeManager();
$nodeStorage = $entityTypeManager->getStorage('node');
$typeStorage = $entityTypeManager->getStorage('node_type');
$fieldStorage = $entityTypeManager->getStorage('field_config');

foreach (['reg_about_page', 'reg_value', 'reg_leader', 'reg_employee_recognition'] as $bundle) {
  $assert((bool) $typeStorage->load($bundle), "$bundle content type exists");
  $settings = ContentLanguageSettings::loadByEntityTypeBundle('node', $bundle);
  $assert((bool) $settings?->getThirdPartySetting('content_translation', 'enabled', FALSE), "$bundle supports content translation");
}

$requiredFields = [
  'reg_about_page' => ['field_reg_about_key', 'field_reg_summary', 'field_reg_vision', 'field_reg_mission', 'field_reg_source_url', 'field_reg_review_status'],
  'reg_value' => ['field_reg_description', 'field_reg_value_icon', 'field_reg_order'],
  'reg_leader' => ['field_reg_leadership_group', 'field_reg_role_title', 'field_reg_entity', 'field_reg_portrait', 'field_reg_last_verified'],
  'reg_employee_recognition' => ['field_reg_employee_role', 'field_reg_entity', 'field_reg_portrait', 'field_reg_recognition_period'],
  'reg_partner' => ['field_reg_partner_type', 'field_reg_source_url', 'field_reg_review_status'],
];
foreach ($requiredFields as $bundle => $fields) {
  foreach ($fields as $field) {
    $assert((bool) $fieldStorage->load("node.$bundle.$field"), "$bundle has $field");
  }
}

$counts = ['reg_about_page' => 11, 'reg_value' => 6, 'reg_leader' => 9, 'reg_employee_recognition' => 3];
foreach ($counts as $bundle => $expected) {
  $ids = $nodeStorage->getQuery()->accessCheck(FALSE)->condition('type', $bundle)->condition('status', 1)->execute();
  $assert(count($ids) === $expected, "$bundle has $expected published source records");
  foreach ($nodeStorage->loadMultiple($ids) as $node) {
    $assert(!$node->hasTranslation('rw'), $node->label() . ' has no unreviewed machine-published Kinyarwanda translation');
    $assert((string) $node->get('field_reg_source_site')->value === 'official_reg', $node->label() . ' retains official source-site metadata');
  }
}

$pages = [];
$pageIds = $nodeStorage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_about_page')->execute();
foreach ($nodeStorage->loadMultiple($pageIds) as $page) {
  $pages[(string) $page->get('field_reg_about_key')->value] = $page;
}
$assert(count($pages) === 11, 'all 11 canonical About page keys are unique');
$assert((string) $pages['vision_mission_values']->get('field_reg_vision')->value === 'To be the leading regional provider of innovative and sustainable energy solutions for national development', 'official vision is exact');
$assert((string) $pages['vision_mission_values']->get('field_reg_mission')->value === 'Developing and providing reliable and affordable energy while creating value for our stakeholders', 'official mission is exact');
foreach (['history', 'edcl', 'eucl'] as $key) {
  $assert((string) $pages[$key]->get('field_reg_review_status')->value === 'needs_review', "$key is flagged for historical/dating review");
}

$leaderIds = $nodeStorage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_leader')->execute();
$leaders = $nodeStorage->loadMultiple($leaderIds);
$groups = ['board' => 0, 'executive' => 0];
$portraitCount = 0;
$actingTitleFound = FALSE;
foreach ($leaders as $leader) {
  $groups[(string) $leader->get('field_reg_leadership_group')->value]++;
  $portraitCount += $leader->get('field_reg_portrait')->isEmpty() ? 0 : 1;
  $actingTitleFound = $actingTitleFound || (
    $leader->label() === 'Claver GAKWAVU'
    && $leader->get('field_reg_role_title')->value === 'Acting Managing Director/EUCL'
  );
}
$assert($groups === ['board' => 6, 'executive' => 3], 'leadership has 6 Board and 3 Executive records');
$assert($portraitCount === 9, 'all leadership records use Drupal Media portraits');
$assert($actingTitleFound, 'Claver GAKWAVU retains the current Acting designation');

$employeeIds = $nodeStorage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_employee_recognition')->execute();
$employeePortraits = 0;
foreach ($nodeStorage->loadMultiple($employeeIds) as $employee) {
  $employeePortraits += $employee->get('field_reg_portrait')->isEmpty() ? 0 : 1;
  $assert((string) $employee->get('field_reg_recognition_period')->value === '2024/2025', $employee->label() . ' has the official recognition period');
}
$assert($employeePortraits === 3, 'all employee-recognition records use Drupal Media portraits');

$partnerCounts = [];
foreach (['stakeholder', 'development_partner'] as $type) {
  $ids = $nodeStorage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_partner')->condition('status', 1)->condition('field_reg_partner_type', $type)->execute();
  $partnerCounts[$type] = count($ids);
}
$assert($partnerCounts === ['stakeholder' => 5, 'development_partner' => 5], 'existing partner Media records are classified 5/5');

$workflow = Workflow::load('reg_about_editorial');
$assert((bool) $workflow, 'REG About Editorial workflow exists');
if ($workflow) {
  $plugin = $workflow->getTypePlugin();
  foreach (['reg_about_page', 'reg_value', 'reg_leader', 'reg_employee_recognition', 'reg_partner'] as $bundle) {
    $assert($plugin->appliesToEntityTypeAndBundle('node', $bundle), "$bundle is governed by the About workflow");
  }
}

$routes = [
  'reg_core.about', 'reg_core.about_history', 'reg_core.about_vision', 'reg_core.about_group',
  'reg_core.about_edcl', 'reg_core.about_eucl', 'reg_core.about_leadership', 'reg_core.about_board',
  'reg_core.about_executive', 'reg_core.about_partners', 'reg_core.about_people',
];
$provider = \Drupal::service('router.route_provider');
foreach ($routes as $route) {
  $assert((bool) $provider->getRouteByName($route), "$route is registered");
}

$repository = \Drupal::service('reg_core.about_repository');
$assert($repository->branchCount() === 33, 'About landing reuses all 33 active published branch records');
$assert(count($repository->values()) === 6, 'public repository returns six values');
$assert(count($repository->leaders('board')) === 6, 'public repository returns six Board records');
$assert(count($repository->leaders('executive')) === 3, 'public repository returns three Executive records');
$assert(count($repository->employees()) === 3, 'public repository returns three employee-recognition records');

if ($failures) {
  throw new RuntimeException($failures . ' About REG acceptance check(s) failed.');
}
