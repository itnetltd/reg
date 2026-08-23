<?php

/**
 * @file
 * Creates or removes clearly labelled local-only social-post visual fixtures.
 */

use Drupal\node\Entity\Node;

$action = $extra[0] ?? 'clean';
$storage = \Drupal::entityTypeManager()->getStorage('node');
$existing_ids = $storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'reg_social_post')
  ->condition('title', '[DEMO TEST] Native X visual', 'STARTS_WITH')
  ->execute();
if ($existing_ids !== []) {
  $storage->delete($storage->loadMultiple($existing_ids));
}

if ($action !== 'create') {
  echo "Temporary visual social posts removed.\n";
  return;
}

$created = [];
for ($index = 0; $index < 5; $index++) {
  $node = Node::create([
    'type' => 'reg_social_post',
    'title' => '[DEMO TEST] Native X visual ' . $index,
    'langcode' => 'en',
    'status' => 1,
    'moderation_state' => 'published',
    'field_reg_social_platform' => 'x',
    'field_reg_social_post_url' => ['uri' => 'https://x.com/reg_rwanda/status/' . (910000000000000000 + $index)],
    'field_reg_social_post_id' => 'visual-demo-' . $index,
    'field_reg_social_post_text' => 'DEMO TEST post for responsive layout verification only. This is not official REG content.',
    'field_reg_social_published_at' => gmdate('Y-m-d\TH:i:s', strtotime('2026-08-22 18:00:00 UTC') - ($index * 3600)),
    'field_reg_social_author_name' => 'Rwanda Energy Group',
    'field_reg_social_author_handle' => '@reg_rwanda',
    'field_reg_social_homepage' => 1,
    'field_reg_social_order' => 0,
    'field_reg_social_source' => 'manual',
  ]);
  $node->save();
  $created[] = (int) $node->id();
}

echo 'Created local-only visual post IDs: ' . implode(', ', $created) . PHP_EOL;
