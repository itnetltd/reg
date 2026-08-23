<?php

/**
 * @file
 * Reversible live acceptance checks for the official REG FAQ import.
 */

use Drupal\node\NodeInterface;

$storage = \Drupal::entityTypeManager()->getStorage('node');
$search = \Drupal::service('reg_core.faq_search');
$importer = \Drupal::service('reg_core.official_faq_importer');
$draft = NULL;

$assert = static function (bool $condition, string $label): void {
  if (!$condition) {
    throw new RuntimeException('FAIL: ' . $label);
  }
  print 'PASS: ' . $label . PHP_EOL;
};
$find = static function (string $query, string $question) use ($search): array {
  foreach ($search->search($query, '', 20) as $item) {
    if (($item['question'] ?? '') === $question) {
      return $item;
    }
  }
  return [];
};
$hasLink = static function (array $item, string $expected): bool {
  foreach ($item['related_links'] ?? [] as $link) {
    if (($link['url'] ?? '') === $expected) {
      return TRUE;
    }
  }
  return FALSE;
};

try {
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'reg_faq')
    ->condition('field_reg_source_id', 'reg_official_faq_', 'STARTS_WITH')
    ->execute();
  $nodes = $storage->loadMultiple($ids);
  $assert(count($nodes) === 36, '36 stable official FAQ records exist');

  $sourceIds = [];
  $published = 0;
  $needsReview = 0;
  foreach ($nodes as $node) {
    assert($node instanceof NodeInterface);
    $sourceIds[] = (string) $node->get('field_reg_source_id')->value;
    $published += $node->isPublished() ? 1 : 0;
    $needsReview += $node->get('field_reg_review_status')->value === 'needs_review' ? 1 : 0;
    $assert($node->get('field_reg_source_label')->value === 'REG Official FAQ', $node->label() . ' has the official source label');
    $assert($node->get('field_reg_source_url')->uri === 'https://www.reg.rw/public-information/faqs/', $node->label() . ' has the official source URL');
    $assert($node->get('field_reg_content_owner')->value === 'Customer Service', $node->label() . ' has the Customer Service owner');
    $assert(!$node->get('field_reg_review_date')->isEmpty(), $node->label() . ' has a configured review date');
  }
  $assert(count(array_unique($sourceIds)) === 36, 'official source IDs are unique');
  $assert($published === 36, 'all 36 reviewed public answers are published');
  $assert($needsReview === 9, 'nine high-risk answers are flagged Needs review');

  foreach ([
    'new connection' => 'How can I request a new connection?',
    'price of electricity' => 'What are the prices for residential households?',
    'token not working' => 'I have a token but it is not entering in the meter. What should I do?',
    'meter number' => 'How can I check my meter number?',
    'cash power' => 'I am trying to buy electricity units but I get a message that my cash power is not registered. What should I do?',
    'power cut' => 'Why do we sometimes have power cuts?',
    'branch' => 'How can I contact REG?',
    'arrears' => 'How can I know my due arrears?',
    'meter stolen' => 'My meter was stolen. What should I do?',
  ] as $query => $question) {
    $assert($find($query, $question) !== [], 'FAQ search matches “' . $query . '”');
  }

  $connection = $find('new connection', 'How can I request a new connection?');
  $assert($hasLink($connection, 'https://online.reg.rw/'), 'new connection includes Online Services');
  $tariff = $find('price of electricity', 'What are the prices for residential households?');
  $assert(str_contains($tariff['answer'] ?? '', '89 RWF/kWh') && str_contains($tariff['answer'] ?? '', '310 RWF/kWh') && str_contains($tariff['answer'] ?? '', '369 RWF/kWh'), 'residential FAQ uses the October 2025 tariff blocks');
  $assert($hasLink($tariff, '/tools/bill-estimator'), 'tariff FAQ includes the Bill Estimator action');
  $outage = $find('power cut', 'Why do we sometimes have power cuts?');
  $assert($hasLink($outage, '/outages'), 'outage FAQ points to the Outage Center');
  $complaint = $find('poor service', 'What can I do if I receive poor service from the toll-free line 2727?');
  $assert($hasLink($complaint, '/report-problem'), 'complaint FAQ points to Report a Problem');
  $branch = $find('branch', 'How can I contact REG?');
  $assert($hasLink($branch, '/branches'), 'branch FAQ points to the Branch Locator');

  $secondRun = $importer->import();
  $assert($secondRun === ['created' => 0, 'tagged_existing' => 0, 'unchanged' => 36], 'a second import is idempotent');

  $draft = $storage->create([
    'type' => 'reg_faq',
    'langcode' => 'en',
    'uid' => 1,
    'title' => 'Secret unpublished official FAQ acceptance fixture',
    'field_reg_answer' => 'This draft must never appear in public search.',
    'field_reg_faq_category' => 'online_services',
    'field_reg_faq_keywords' => 'secret-unpublished-official-faq-fixture',
    'field_reg_review_status' => 'needs_review',
    'field_reg_faq_priority' => 0,
    'status' => 0,
    'moderation_state' => 'draft',
  ]);
  $draft->save();
  $assert($search->search('secret-unpublished-official-faq-fixture', '', 5) === [], 'public search excludes unpublished FAQ content');
}
finally {
  if ($draft instanceof NodeInterface && !$draft->isNew()) {
    $draft->delete();
  }
}

print 'Official FAQ acceptance fixtures restored.' . PHP_EOL;
