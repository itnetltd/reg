<?php

/**
 * @file
 * Removes temporary Public Information Hub acceptance fixtures.
 */

$state = \Drupal::state();
$fixtures = $state->get('reg_core.public_information_acceptance') ?: [];

if (!empty($fixtures['nodes'])) {
  \Drupal::database()->delete('reg_core_document_download')
    ->condition('nid', $fixtures['nodes'], 'IN')
    ->execute();
}
\Drupal::database()->delete('reg_core_tender_subscription')
  ->condition('email_hash', hash('sha256', 'reg-public-information-acceptance@example.invalid'))
  ->execute();

if (!empty($fixtures['nodes'])) {
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $storage->delete($storage->loadMultiple($fixtures['nodes']));
}
if (!empty($fixtures['media'])) {
  $storage = \Drupal::entityTypeManager()->getStorage('media');
  $storage->delete($storage->loadMultiple($fixtures['media']));
}
if (!empty($fixtures['files'])) {
  $storage = \Drupal::entityTypeManager()->getStorage('file');
  $storage->delete($storage->loadMultiple($fixtures['files']));
}

// Remove any file entity left by a previously interrupted fixture setup.
$file_storage = \Drupal::entityTypeManager()->getStorage('file');
$orphan_files = [];
foreach (['acceptance-tender.pdf', 'acceptance-job.pdf', 'acceptance-publication.pdf'] as $filename) {
  $orphan_files += $file_storage->loadByProperties(['uri' => 'public://reg-acceptance/' . $filename]);
}
if ($orphan_files) {
  $file_storage->delete($orphan_files);
}

$state->delete('reg_core.public_information_acceptance');
\Drupal::service('cache_tags.invalidator')->invalidateTags([
  'node_list:reg_tender',
  'node_list:reg_job',
  'node_list:reg_publication',
]);
print "Public information acceptance fixtures removed.\n";
