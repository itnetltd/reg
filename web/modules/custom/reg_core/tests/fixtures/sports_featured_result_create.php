<?php

/**
 * @file
 * Idempotently creates the approved REG 74–71 Patriots development result.
 */

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

$entity_type_manager = \Drupal::entityTypeManager();
$node_storage = $entity_type_manager->getStorage('node');
$term_storage = $entity_type_manager->getStorage('taxonomy_term');

$basketball_ids = $term_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('vid', 'reg_sports_sport')
  ->condition('name', 'Basketball')
  ->range(0, 1)
  ->execute();
if (!$basketball_ids) {
  throw new RuntimeException('The Basketball sports term is missing. Run Drupal database updates first.');
}
$basketball_id = (int) reset($basketball_ids);

$team_ids = $node_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'reg_sports_team')
  ->condition('field_reg_team_key', 'basketball_men')
  ->range(0, 1)
  ->execute();
$team = $team_ids ? $node_storage->load(reset($team_ids)) : NULL;
if (!$team instanceof NodeInterface) {
  $team = Node::create([
    'type' => 'reg_sports_team',
    'title' => 'REG Basketball Men',
    'uid' => 1,
    'status' => 1,
    'moderation_state' => 'published',
    'field_reg_team_key' => 'basketball_men',
    'field_reg_sports_sport' => $basketball_id,
    'field_reg_gender_category' => 'men',
    'field_reg_active' => 1,
  ]);
}
else {
  $team
    ->set('status', 1)
    ->set('moderation_state', 'published')
    ->set('field_reg_sports_sport', $basketball_id)
    ->set('field_reg_active', 1);
}
$team->save();

$media = NULL;
$source = DRUPAL_ROOT . '/modules/custom/reg_core/assets/sports/reg-vs-patriots-74-71.jpg';
if (is_file($source)) {
  $directory = 'public://sports';
  $prepared = \Drupal::service('file_system')->prepareDirectory(
    $directory,
    FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
  );
  if (!$prepared) {
    throw new RuntimeException('The public sports image directory could not be prepared.');
  }

  $file = \Drupal::service('file.repository')->writeData(
    (string) file_get_contents($source),
    $directory . '/reg-vs-patriots-74-71.jpg',
    FileExists::Replace,
  );
  $file->setPermanent();
  $file->save();

  $media_storage = $entity_type_manager->getStorage('media');
  $media_ids = $media_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('bundle', 'image')
    ->condition('name', 'REG vs Patriots 74–71 match')
    ->range(0, 1)
    ->execute();
  $media = $media_ids ? $media_storage->load(reset($media_ids)) : NULL;
  if (!$media instanceof MediaInterface) {
    $media = Media::create([
      'bundle' => 'image',
      'name' => 'REG vs Patriots 74–71 match',
      'uid' => 1,
      'status' => 1,
    ]);
  }
  $media
    ->set('status', 1)
    ->set('field_media_image', [
      'target_id' => $file->id(),
      'alt' => 'REG basketball player during the REG 74–71 Patriots match',
    ]);
  $media->save();
}

$fixture_ids = $node_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'reg_sports_fixture')
  ->condition('field_reg_home_team_name', 'REG')
  ->condition('field_reg_away_team_name', 'Patriots')
  ->range(0, 1)
  ->execute();
$fixture = $fixture_ids ? $node_storage->load(reset($fixture_ids)) : NULL;
if (!$fixture instanceof NodeInterface) {
  $fixture = Node::create([
    'type' => 'reg_sports_fixture',
    'uid' => 1,
  ]);
}

$fixture
  ->setTitle('REG 74 – 71 Patriots')
  ->set('status', 1)
  ->set('moderation_state', 'published')
  ->set('field_reg_home_team_name', 'REG')
  ->set('field_reg_away_team_name', 'Patriots')
  ->set('field_reg_team', $team->id())
  ->set('field_reg_sports_sport', $basketball_id)
  ->set('field_reg_home_away', 'home')
  ->set('field_reg_fixture_status', 'completed')
  ->set('field_reg_result_label', 'Full Time')
  ->set('field_reg_home_score', 74)
  ->set('field_reg_away_score', 71)
  ->set('field_reg_featured', 1);
if ($media instanceof MediaInterface) {
  $fixture->set('field_reg_hero_image', $media->id());
}
$fixture->save();

print json_encode([
  'fixture' => (int) $fixture->id(),
  'team' => (int) $team->id(),
  'media' => $media instanceof MediaInterface ? (int) $media->id() : NULL,
  'image_source_found' => is_file($source),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
