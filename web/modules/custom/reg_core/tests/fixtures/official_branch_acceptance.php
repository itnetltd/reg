<?php

/**
 * Live acceptance checks for the official REG branch migration.
 */

use Drupal\reg_core\Content\OfficialBranchContent;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
  print ($condition ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
  if (!$condition) {
    $failures++;
  }
};

$storage = \Drupal::entityTypeManager()->getStorage('node');
$ids = $storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'reg_branch')
  ->condition('field_reg_source_label', OfficialBranchContent::SOURCE_LABEL)
  ->execute();
$nodes = $storage->loadMultiple($ids);
$assert(count($nodes) === 33, '33 official branch records exist');

$sourceIds = [];
$published = 0;
$review = 0;
$missingCoordinates = 0;
$mappedDistricts = 0;
foreach ($nodes as $node) {
  $sourceId = (string) $node->get('field_reg_source_id')->value;
  $sourceIds[] = $sourceId;
  $published += $node->isPublished() && (bool) $node->get('field_reg_active')->value ? 1 : 0;
  $review += $node->get('field_reg_branch_review_status')->value === 'imported_requires_validation' ? 1 : 0;
  $missingCoordinates += $node->get('field_reg_latitude')->isEmpty() && $node->get('field_reg_longitude')->isEmpty() ? 1 : 0;
  $mappedDistricts += $node->get('field_reg_district_ref')->isEmpty() ? 0 : 1;
  $assert((string) $node->get('field_reg_source_url')->uri === OfficialBranchContent::SOURCE_URL, $node->label() . ' has the official source URL');
  $assert(!$node->get('field_reg_manager_name')->isEmpty(), $node->label() . ' has a manager');
  $assert(!$node->get('field_reg_phone_ph')->isEmpty(), $node->label() . ' has PH');
  $assert(!$node->get('field_reg_phone_te')->isEmpty(), $node->label() . ' has TE');
  $assert(!$node->get('field_reg_email')->isEmpty(), $node->label() . ' has email');
}
$assert(count(array_unique($sourceIds)) === 33, 'stable source IDs are unique');
$assert($published === 33, 'all official branches are active and published');
$assert($review === 33, 'all imported records require REG validation');
$assert($missingCoordinates === 33, 'all coordinates remain empty');
$assert($mappedDistricts === 28, 'only the 28 approved district mappings are applied');

$repository = \Drupal::service('reg_core.branch_repository');
$all = $repository->search();
$assert(count($all) === 33, 'public Branch Locator returns 33 branches');
$assert(count($repository->search(['query' => 'Ernest'])) === 1, 'manager-name search works');
$assert(count($repository->search(['query' => 'eringabire@eucl.reg.rw'])) === 1, 'email search works');
$assert(count($repository->search(['query' => 'Kacyiru'])) === 1, 'branch-name search works');
$assert(count($repository->search(['entity' => 'eucl'])) === 33, 'entity filter works');
$assert(\Drupal::service('reg_core.branch_map_normalizer')->normalize($all) === [], 'branches without coordinates create no map markers');

$rutsiroIds = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_branch')->condition('title', 'Rutsiro')->execute();
$rutsiro = $storage->load(reset($rutsiroIds));
$assert($rutsiro && $rutsiro->get('field_reg_email')->value === 'bbahoranimana@eucl.reg.rw', 'Rutsiro email matches the directly verified official source');

$config = \Drupal::config('reg_core.settings');
$assert($config->get('support.general_email') === 'info@reg.rw', 'official REG email is configured');
$assert($config->get('support.general_telephone') === '(+250)(0)788385025', 'official REG telephone is configured');
$assert($config->get('support.eucl_emails') === ['info@eucl.reg.rw', 'eucl@reg.rw'], 'official EUCL emails are configured');
$assert($config->get('support.edcl_emails') === ['info@edcl.reg.rw', 'edcl@reg.rw'], 'official EDCL emails are configured');

$again = \Drupal::service('reg_core.official_branch_importer')->import();
$assert($again === ['created' => 0, 'tagged_existing' => 0, 'unchanged' => 33], 'second import is idempotent');

if ($failures) {
  throw new RuntimeException($failures . ' official branch acceptance check(s) failed.');
}
