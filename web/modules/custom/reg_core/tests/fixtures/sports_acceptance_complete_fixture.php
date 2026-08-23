<?php

/**
 * @file
 * Moves the temporary sports fixture into its completed result state.
 */

$fixtures = \Drupal::state()->get('reg_core.sports_acceptance') ?: [];
$fixture = !empty($fixtures['fixture']) ? \Drupal::entityTypeManager()->getStorage('node')->load($fixtures['fixture']) : NULL;
if (!$fixture) {
  throw new RuntimeException('Sports acceptance fixture was not found.');
}
$fixture
  ->set('field_reg_fixture_status', 'completed')
  ->set('field_reg_match_date', gmdate('Y-m-d\TH:i:s', \Drupal::time()->getRequestTime() - 3600))
  ->set('field_reg_home_score', 84)
  ->set('field_reg_away_score', 76)
  ->set('field_reg_match_report', 'Temporary completed match report for acceptance testing.')
  ->set('moderation_state', 'published');
$fixture->setNewRevision(TRUE);
$fixture->save();
\Drupal::service('cache_tags.invalidator')->invalidateTags(['node_list:reg_sports_fixture']);
print "Sports acceptance fixture marked completed with a confirmed 84-76 result.\n";
