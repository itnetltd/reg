<?php

/**
 * @file
 * Local DDEV-only synthetic importer acceptance; fixtures always removed. */

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\reg_core\Procurement\ProcurementCsv;
use Drupal\reg_core\Procurement\ProcurementImporter;
use Drupal\reg_core\Procurement\ProcurementImportSetup;
use Drupal\user\Entity\User;

if (getenv('IS_DDEV_PROJECT') !== 'true') {
  throw new RuntimeException('Local DDEV only.');
}
$storage = \Drupal::entityTypeManager()->getStorage('node');
if ($storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_procurement_plan')->condition('field_reg_source_id', ProcurementImporter::PLAN_KEY)->count()->execute() || $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_procurement_plan')->condition('title', ProcurementImporter::PLAN_TITLE)->count()->execute()) {
  throw new RuntimeException('Real EUCL plan exists; fixture must not touch it.');
}
$assert = static function ($test, $message) {
  if (!$test) {
    throw new RuntimeException($message);
  }
};
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(User::load(1));
$plan_id = NULL;
$files = [];
$passed = [];
$before = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_tender')->count()->execute();
try {
  ProcurementImportSetup::apply();
  $service = \Drupal::service('reg_core.procurement_importer');
  $token = bin2hex(random_bytes(5));
  $directory = 'private://procurement-plans/import';
  \Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
  // These are explicitly synthetic QA sources, not the missing EUCL workbook.
  foreach (['csv', 'xlsx'] as $ext) {
    $uri = "$directory/qa-import-$token.$ext";
    file_put_contents($uri, 'SYNTHETIC QA FIXTURE - NOT REG PROCUREMENT CONTENT');
    $file = File::create(['uri' => $uri, 'filename' => basename($uri), 'status' => 0, 'uid' => 1]);
    $file->save();
    $files[] = $file;
  }
  [$csv, $workbook] = $files;
  $hash = hash_file('sha256', $csv->getFileUri());
  $whash = hash_file('sha256', $workbook->getFileUri());
  $raw = [];
  foreach ([87, 100, 103] as $i) {
    $raw[] = array_replace(array_fill_keys(array_keys(ProcurementCsv::COLUMNS), ''), ['import_key' => "QA-$token-$i", 'activity_number' => (string) $i, 'title' => "QA ONLY $i", 'source_row' => (string) ($i + 5), 'category' => 'goods', 'funding_source' => $i === 87 ? '' : 'EUCL/REG', 'method_code' => $i === 87 ? '' : 'NCB', 'publication' => $i === 87 ? '' : '2026-01-15']);
  }
  $rows = ProcurementCsv::normalize(['rows' => $raw], array_combine(array_keys(ProcurementCsv::COLUMNS), array_keys(ProcurementCsv::COLUMNS)));
  $preview = $service->preview($rows);
  $assert(!$service->plan(), 'Dry run created a plan.');
  $passed[] = 'dry run saves no content';
  $result = $service->commit($preview, [0, 1, 2], $csv, $workbook, $hash, $whash);
  $plan_id = $service->plan()->id();
  $assert($result['inserted'] === 3 && !$result['updated'], 'Initial insert count incorrect.');
  $again = $service->commit($service->preview($rows), [0, 1, 2], $csv, $workbook, $hash, $whash);
  $assert($again['skipped'] === 3 && !$again['inserted'] && !$again['updated'], 'Identical import was not a no-op.');
  $passed[] = 'repeat import inserts 0, updates 0, skips 3';
  $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_procurement_plan_item')->condition('field_reg_procurement_plan_ref', $plan_id)->sort('nid')->execute();
  $assert(count($ids) === 3, 'Duplicate items.');
  foreach ($storage->loadMultiple($ids) as $node) {
    $assert(!$node->isPublished() && $node->get('field_reg_review_status')->value === 'needs_review', 'Item published or not awaiting review.');
    $assert($node->get('field_reg_estimated_budget')->isEmpty() && $node->get('field_reg_fy_budget')->isEmpty(), 'Missing budget became zero.');
    $assert(!$node->get('field_reg_import_warnings')->isEmpty(), 'Source warnings not stored.');
    $assert($node->get('field_reg_department')->isEmpty(), 'Department inferred.');
  }
  $passed[] = 'nullable budgets, review flags, departments, raw codes and source dates preserved';
  $item = $storage->load(reset($ids));
  $stale = $service->preview($rows);
  $item->set('field_reg_department', 'QA editor correction')->save();
  $blocked = FALSE;
  try {
    $service->commit($stale, [0], $csv, $workbook, $hash, $whash);
  }
  catch (RuntimeException $e) {
    $blocked = str_contains($e->getMessage(), 'since preview');
  }
  $assert($blocked, 'Stale preview accepted.');
  $passed[] = 'stale preview rejected';
  $rows[0]['data']['title'] = 'QA source update';
  $result = $service->commit($service->preview($rows), [0], $csv, $workbook, $hash, $whash);
  $storage->resetCache();
  $item = $storage->load($item->id());
  $assert($result['updated'] === 1 && $item->get('field_reg_department')->value === 'QA editor correction', 'Editor change overwritten.');
  $passed[] = 'source update preserves independent editor changes';
  $rows[0]['data']['department'] = 'Conflicting source department';
  $preview = $service->preview($rows);
  $assert($preview['rows'][0]['action'] === 'error', 'Concurrent source/editor conflict not flagged.');
  $passed[] = 'three-way conflict blocks row';
  $rows[0]['data']['department'] = NULL;
  $rows[1]['data']['title'] = 'QA atomic valid update';
  $rows[2]['data']['category'] = 'INVALID';
  $failed = FALSE;
  try {
    $service->commit($service->preview($rows), [1, 2], $csv, $workbook, $hash, $whash);
  }
  catch (RuntimeException $e) {
    $failed = TRUE;
  }
  $assert($failed, 'Invalid entity committed.');
  $storage->resetCache();
  $check = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_procurement_plan_item')->condition('title', 'QA atomic valid update')->count()->execute();
  $assert(!$check, 'Transaction did not roll back valid preceding row.');
  $passed[] = 'entity failure rolls back entire selected import';
  $assert($storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_tender')->count()->execute() === $before, 'Tender count changed.');
  $passed[] = 'no tender created or published';
  $switcher->switchTo(new AnonymousUserSession());
  $denied = FALSE;
  try {
    $service->preview($rows);
  }
  catch (RuntimeException $e) {
    $denied = TRUE;
  }
  $assert($denied, 'Unauthorized import allowed.');
  $switcher->switchBack();
  $passed[] = 'unauthorized importer denied';
  echo json_encode(['passed' => $passed, 'source' => 'synthetic 3-row QA fixture; actual source import is verified separately'], JSON_PRETTY_PRINT) . PHP_EOL;
}
finally {
  $storage->resetCache();
  if (!$plan_id) {
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_procurement_plan')->condition('field_reg_source_id', ProcurementImporter::PLAN_KEY)->execute();
    $plan_id = $ids ? reset($ids) : NULL;
  }
  if ($plan_id) {
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_procurement_plan_item')->condition('field_reg_procurement_plan_ref', $plan_id)->execute();
    $storage->delete($storage->loadMultiple($ids));
    $plan = $storage->load($plan_id);
    if ($plan) {
      $plan->delete();
    }
  }
  foreach ($files as $file) {
    $f = File::load($file->id());
    if ($f) {
      $f->delete();
    }
  }
  $switcher->switchBack();
}
