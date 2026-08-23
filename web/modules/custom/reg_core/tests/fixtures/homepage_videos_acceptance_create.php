<?php

declare(strict_types=1);

use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;

$entity_type_manager = \Drupal::entityTypeManager();
$node_storage = $entity_type_manager->getStorage('node');
$existing_ids = $node_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'reg_video')
  ->condition('title', '[TEST] REG video', 'STARTS_WITH')
  ->execute();
if ($existing_ids) {
  $node_storage->delete($node_storage->loadMultiple($existing_ids));
}

$term_storage = $entity_type_manager->getStorage('taxonomy_term');
$term_ids = $term_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('vid', 'reg_video_category')
  ->condition('name', '[TEST] Video category')
  ->execute();
$term = $term_ids ? $term_storage->load(reset($term_ids)) : NULL;
if (!$term) {
  $term = Term::create([
    'vid' => 'reg_video_category',
    'name' => '[TEST] Video category',
    'langcode' => 'en',
  ]);
  $term->save();
}

$media_storage = $entity_type_manager->getStorage('media');
$media_ids = $media_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('bundle', 'image')
  ->condition('status', 1)
  ->condition('name', 'REG vs Patriots', 'CONTAINS')
  ->range(0, 1)
  ->execute();
$media_id = $media_ids ? (int) reset($media_ids) : 0;

$created = [];
foreach ([1, 2, 3, 4] as $index) {
  $published = $index < 4;
  $values = [
    'type' => 'reg_video',
    'langcode' => 'en',
    'uid' => 1,
    'title' => sprintf('[TEST] REG video %02d', $index),
    'field_reg_description' => sprintf('Temporary validation record %02d. Removed after browser checks.', $index),
    'field_reg_video_url' => ['uri' => 'https://www.youtube.com/watch?v=' . sprintf('REGtest%04d', $index)],
    'field_reg_video_provider' => 'youtube',
    'field_reg_video_category' => ['target_id' => $term->id()],
    'field_reg_publication_date' => sprintf('2026-08-%02dT12:00:00', $index),
    'field_reg_featured' => 1,
    'field_reg_order' => $index,
    'field_reg_related_entity' => 'reg',
    'status' => $published ? NodeInterface::PUBLISHED : NodeInterface::NOT_PUBLISHED,
  ];
  if ($media_id) {
    $values['field_reg_thumbnail'] = ['target_id' => $media_id];
  }
  $node = Node::create($values);
  $node->setNewRevision(TRUE);
  $node->setRevisionLogMessage('Temporary Featured Videos acceptance fixture.');
  $node->save();
  $created[] = ['id' => (int) $node->id(), 'published' => $published];
}

/** @var \Drupal\reg_core\Video\VideoRepositoryInterface $repository */
$repository = \Drupal::service(\Drupal\reg_core\Video\VideoRepositoryInterface::class);
$featured = $repository->featured(5);
$archive = $repository->archive(['search' => '', 'category' => 0, 'year' => ''], 0, 9);
$filtered = $repository->archive(['search' => 'REG video 02', 'category' => (int) $term->id(), 'year' => '2026'], 0, 9);
if (count($featured) !== 3 || $archive['total'] !== 3 || $filtered['total'] !== 1) {
  throw new RuntimeException('Published/featured video querying or archive filters did not return the expected temporary records.');
}
if (array_column($featured, 'title') !== ['[TEST] REG video 01', '[TEST] REG video 02', '[TEST] REG video 03']) {
  throw new RuntimeException('Homepage video ordering is not weight ascending, then publication date descending.');
}
foreach ($featured as $item) {
  if ($item['embed_url'] === '' || str_contains($item['embed_url'], 'autoplay=')) {
    throw new RuntimeException('Featured YouTube videos must use deferred, non-autoplay privacy-enhanced embeds.');
  }
  if (($item['thumbnail']['url'] ?? '') === '') {
    throw new RuntimeException('Every public video must resolve a thumbnail or branded fallback.');
  }
}

print json_encode([
  'created' => $created,
  'category' => (int) $term->id(),
  'thumbnail_media' => $media_id,
  'featured_count' => count($featured),
  'archive_count' => $archive['total'],
  'filtered_count' => $filtered['total'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
