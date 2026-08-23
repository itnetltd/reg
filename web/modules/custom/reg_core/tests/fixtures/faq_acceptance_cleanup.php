<?php

/**
 * @file
 * Deletes only the temporary FAQ acceptance fixtures created by this module.
 */

$storage = \Drupal::entityTypeManager()->getStorage('node');
$ids = $storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'reg_faq')
  ->condition('title', [
    'How do I check a power outage?',
    'REG FAQ draft acceptance fixture',
  ], 'IN')
  ->execute();
$storage->delete($storage->loadMultiple($ids));
\Drupal::database()->delete('reg_core_faq_unanswered')
  ->condition('phrase', 'quantum banana transformer')
  ->condition('langcode', 'en')
  ->execute();
echo 'Deleted ' . count($ids) . ' temporary FAQ acceptance fixture(s).' . PHP_EOL;
