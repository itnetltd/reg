<?php

/**
 * @file
 * Exercises native homepage social-post limits with disposable DEMO records.
 */

use Drupal\node\Entity\Node;

$storage = \Drupal::entityTypeManager()->getStorage('node');
$repository = \Drupal::service('reg_core.social_post_repository');
$created_ids = [];
$results = [];

try {
  foreach ([0, 1, 2, 5] as $count) {
    if ($created_ids !== []) {
      $storage->delete($storage->loadMultiple($created_ids));
      $created_ids = [];
    }
    for ($index = 0; $index < $count; $index++) {
      $node = Node::create([
        'type' => 'reg_social_post',
        'title' => '[DEMO TEST] Native X acceptance ' . $count . '-' . $index,
        'langcode' => 'en',
        'status' => 1,
        'moderation_state' => 'published',
        'field_reg_social_platform' => 'x',
        'field_reg_social_post_url' => ['uri' => 'https://x.com/reg_rwanda/status/' . (900000000000000000 + ($count * 10) + $index)],
        'field_reg_social_post_id' => 'demo-' . $count . '-' . $index,
        'field_reg_social_post_text' => 'DEMO TEST record for native social-post acceptance. Not official REG content.',
        'field_reg_social_published_at' => gmdate('Y-m-d\TH:i:s', strtotime('2026-08-22 12:00:00 UTC') - ($index * 3600)),
        'field_reg_social_author_name' => 'Rwanda Energy Group',
        'field_reg_social_author_handle' => '@reg_rwanda',
        'field_reg_social_homepage' => 1,
        'field_reg_social_order' => 0,
        'field_reg_social_source' => 'manual',
      ]);
      $node->save();
      $created_ids[] = (int) $node->id();
    }

    $items = $repository->homepageXPosts(2)['items'];
    $expected = min(2, $count);
    if (count($items) !== $expected) {
      throw new RuntimeException("Expected $expected homepage posts for $count records; got " . count($items) . '.');
    }
    $results[(string) $count] = count($items);
  }
}
finally {
  if ($created_ids !== []) {
    $storage->delete($storage->loadMultiple($created_ids));
  }
}

echo json_encode(['records_to_homepage_items' => $results, 'temporary_records_removed' => TRUE], JSON_PRETTY_PRINT) . PHP_EOL;
