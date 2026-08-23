<?php

declare(strict_types=1);

$entity_type_manager = \Drupal::entityTypeManager();
$node_storage = $entity_type_manager->getStorage('node');
$node_ids = $node_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'reg_video')
  ->condition('title', '[TEST] REG video', 'STARTS_WITH')
  ->execute();
if ($node_ids) {
  $node_storage->delete($node_storage->loadMultiple($node_ids));
}
$term_storage = $entity_type_manager->getStorage('taxonomy_term');
$term_ids = $term_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('vid', 'reg_video_category')
  ->condition('name', '[TEST] Video category')
  ->execute();
if ($term_ids) {
  $term_storage->delete($term_storage->loadMultiple($term_ids));
}
\Drupal::service('cache_tags.invalidator')->invalidateTags([
  'node_list',
  'node_list:reg_video',
  'taxonomy_term_list:reg_video_category',
]);
print json_encode([
  'removed_video_ids' => array_map('intval', array_values($node_ids)),
  'removed_category_ids' => array_map('intval', array_values($term_ids)),
], JSON_PRETTY_PRINT) . PHP_EOL;
