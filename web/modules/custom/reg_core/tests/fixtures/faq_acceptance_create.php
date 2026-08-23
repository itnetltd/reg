<?php

/**
 * @file
 * Creates temporary FAQ acceptance fixtures in a bootstrapped Drupal site.
 */

use Drupal\node\NodeInterface;

$storage = \Drupal::entityTypeManager()->getStorage('node');
$titles = [
  'How do I check a power outage?',
  'REG FAQ draft acceptance fixture',
];
$existing = $storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'reg_faq')
  ->condition('title', $titles, 'IN')
  ->execute();
if ($existing) {
  throw new \RuntimeException('Temporary FAQ acceptance fixtures already exist. Run the cleanup script first.');
}

$published = $storage->create([
  'type' => 'reg_faq',
  'langcode' => 'en',
  'title' => 'How do I check a power outage?',
  'field_reg_answer' => 'Acceptance test answer: open the REG Outage Center to view the latest approved service status.',
  'field_reg_faq_category' => 'outages',
  'field_reg_faq_keywords' => 'interruption map, outage lookup, electricity status',
  'field_reg_related_url' => ['uri' => 'internal:/outages', 'title' => 'Open the Outage Center'],
  'field_reg_content_owner' => 'Acceptance Test',
  'field_reg_review_date' => '2027-08-08T00:00:00',
  'field_reg_faq_priority' => 1,
  'field_reg_faq_escalation' => 'call_center',
  'moderation_state' => 'draft',
]);
$published->save();

foreach (['needs_review', 'published'] as $state) {
  $published = $storage->loadUnchanged($published->id());
  assert($published instanceof NodeInterface);
  $published->setNewRevision(TRUE);
  $published->set('moderation_state', $state);
  $published->save();
}

$published = $storage->loadUnchanged($published->id());
$published->addTranslation('rw', [
  'title' => 'Nagenzura nte ikibazo cy\'umuriro?',
  'field_reg_answer' => 'Igisubizo cy\'igerageza: fungura urubuga rw\'ibura ry\'umuriro rwa REG urebe amakuru yemejwe.',
  'field_reg_faq_category' => 'outages',
  'field_reg_faq_keywords' => 'umuriro, ibura ry\'amashanyarazi, amakuru',
  'field_reg_related_url' => ['uri' => 'internal:/outages', 'title' => 'Fungura amakuru y\'umuriro'],
  'field_reg_content_owner' => 'Acceptance Test',
  'field_reg_review_date' => '2027-08-08T00:00:00',
  'field_reg_faq_priority' => 1,
  'field_reg_faq_escalation' => 'call_center',
  'moderation_state' => 'draft',
]);
$published->save();

foreach (['needs_review', 'published'] as $state) {
  $published = $storage->loadUnchanged($published->id());
  assert($published instanceof NodeInterface);
  $translation = $published->getTranslation('rw');
  $translation->setNewRevision(TRUE);
  $translation->set('moderation_state', $state);
  $translation->save();
}

$draft = $storage->create([
  'type' => 'reg_faq',
  'langcode' => 'en',
  'title' => 'REG FAQ draft acceptance fixture',
  'field_reg_answer' => 'Secret draft answer must never be public.',
  'field_reg_faq_category' => 'safety',
  'field_reg_faq_keywords' => 'secret-draft-fixture',
  'field_reg_content_owner' => 'Acceptance Test',
  'field_reg_faq_priority' => 99,
  'moderation_state' => 'draft',
]);
$draft->save();

echo json_encode([
  'published_id' => (int) $published->id(),
  'draft_id' => (int) $draft->id(),
], JSON_THROW_ON_ERROR) . PHP_EOL;
