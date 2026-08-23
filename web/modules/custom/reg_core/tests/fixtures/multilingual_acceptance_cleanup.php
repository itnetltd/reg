<?php

/**
 * @file
 * Removes temporary multilingual acceptance translations.
 */

$state = \Drupal::state();
$fixtures = $state->get('reg_core.multilingual_acceptance') ?: [];
foreach ($fixtures['sources'] ?? [] as $lid) {
  \Drupal::database()->delete('locales_target')
    ->condition('lid', (int) $lid)
    ->condition('language', 'rw')
    ->condition('translation', '[IKIGERAGEZO]%', 'LIKE')
    ->execute();
}
$state->delete('reg_core.multilingual_acceptance');
\Drupal::service('cache_tags.invalidator')->invalidateTags(['rendered', 'locale', 'node_list']);
print "Multilingual acceptance translations removed.\n";
