<?php

/**
 * @file
 * Removes all temporary REG Sports Portal acceptance data.
 */

$state = \Drupal::state();
$fixtures = $state->get('reg_core.sports_acceptance') ?: [];
if (!empty($fixtures['nodes'])) {
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $storage->delete($storage->loadMultiple($fixtures['nodes']));
}
if (!empty($fixtures['terms'])) {
  $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  $storage->delete($storage->loadMultiple($fixtures['terms']));
}
$state->delete('reg_core.sports_acceptance');
foreach (['reg_sports_team', 'reg_sports_player', 'reg_sports_fixture', 'reg_sports_standing', 'reg_sports_update'] as $bundle) {
  \Drupal::service('cache_tags.invalidator')->invalidateTags(['node_list:' . $bundle]);
}
print "Sports acceptance fixtures removed.\n";
