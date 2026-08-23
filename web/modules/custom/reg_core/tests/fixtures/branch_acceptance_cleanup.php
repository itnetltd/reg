<?php

/**
 * @file
 * Removes all temporary Branch Locator acceptance fixtures.
 */

$state = \Drupal::state();
$fixtures = $state->get('reg_core.branch_acceptance') ?: [];
if (!empty($fixtures['nodes'])) {
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $storage->delete($storage->loadMultiple($fixtures['nodes']));
}
if (!empty($fixtures['terms'])) {
  $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  $storage->delete($storage->loadMultiple($fixtures['terms']));
}
$state->delete('reg_core.branch_acceptance');
\Drupal::service('cache_tags.invalidator')->invalidateTags(['node_list:reg_branch', 'taxonomy_term_list:reg_district']);
print "Branch acceptance fixtures removed.\n";
