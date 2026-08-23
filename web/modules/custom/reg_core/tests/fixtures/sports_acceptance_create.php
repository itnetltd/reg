<?php

/**
 * @file
 * Creates temporary, clearly tagged REG Sports Portal acceptance fixtures.
 */

use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

$state = \Drupal::state();
if ($state->get('reg_core.sports_acceptance')) {
  throw new RuntimeException('Sports acceptance fixtures already exist. Run the cleanup script first.');
}

$term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$sports = [];
foreach ($term_storage->loadTree('reg_sports_sport') as $term) {
  $sports[$term->name] = (int) $term->tid;
}
$positions = [];
foreach ($term_storage->loadTree('reg_sports_position') as $term) {
  $positions[$term->name] = (int) $term->tid;
}
$competition = Term::create(['vid' => 'reg_sports_competition', 'name' => '[REG Sports acceptance] Test competition']);
$competition->save();
$season = Term::create(['vid' => 'reg_sports_season', 'name' => '[REG Sports acceptance] Test season']);
$season->save();

$node_ids = [];
$create = static function (array $values, bool $published = TRUE) use (&$node_ids): Node {
  $node = Node::create($values + [
    'uid' => 1,
    'status' => $published ? 1 : 0,
    'moderation_state' => $published ? 'published' : 'draft',
  ]);
  $node->save();
  $node_ids[] = (int) $node->id();
  return $node;
};

$team = $create([
  'type' => 'reg_sports_team',
  'title' => '[REG Sports acceptance] REG Basketball Men',
  'field_reg_team_key' => 'basketball_men',
  'field_reg_sports_sport' => $sports['Basketball'],
  'field_reg_gender_category' => 'men',
  'field_reg_description' => 'Temporary published team used only for Sports Portal acceptance testing.',
  'field_reg_home_venue' => 'Acceptance Arena',
  'field_reg_competition' => $competition->id(),
  'field_reg_season' => $season->id(),
  'field_reg_social_links' => [['uri' => 'https://example.invalid/reg-sports', 'title' => 'Acceptance official channel']],
  'field_reg_active' => 1,
]);

$player = $create([
  'type' => 'reg_sports_player',
  'title' => '[REG Sports acceptance] Test Player',
  'field_reg_team' => $team->id(),
  'field_reg_sports_sport' => $sports['Basketball'],
  'field_reg_jersey_number' => 12,
  'field_reg_position' => $positions['Point Guard'],
  'field_reg_date_of_birth' => '2000-01-02',
  'field_reg_biography' => 'Temporary public athlete biography.',
  'field_reg_season' => $season->id(),
  'field_reg_player_status' => 'active',
  'field_reg_featured' => 1,
  'field_reg_games_played' => 1,
  'field_reg_points' => 14,
  'field_reg_social_links' => [['uri' => 'https://example.invalid/test-player', 'title' => 'Acceptance player channel']],
]);

$now = \Drupal::time()->getRequestTime();
$fixture = $create([
  'type' => 'reg_sports_fixture',
  'title' => '[REG Sports acceptance] REG vs Test Opponent',
  'field_reg_home_team_name' => '[REG Sports acceptance] REG Basketball Men',
  'field_reg_away_team_name' => 'Test Opponent',
  'field_reg_team' => $team->id(),
  'field_reg_sports_sport' => $sports['Basketball'],
  'field_reg_competition' => $competition->id(),
  'field_reg_season' => $season->id(),
  'field_reg_match_round' => 'Acceptance matchday',
  'field_reg_match_date' => gmdate('Y-m-d\TH:i:s', $now + (2 * 86400)),
  'field_reg_venue' => 'Acceptance Arena',
  'field_reg_home_away' => 'home',
  'field_reg_fixture_status' => 'scheduled',
  'field_reg_featured' => 1,
]);

$standing = $create([
  'type' => 'reg_sports_standing',
  'title' => '[REG Sports acceptance] Standing row',
  'field_reg_sports_sport' => $sports['Basketball'],
  'field_reg_competition' => $competition->id(),
  'field_reg_season' => $season->id(),
  'field_reg_standing_team' => '[REG Sports acceptance] REG Basketball Men',
  'field_reg_team' => $team->id(),
  'field_reg_table_position' => 1,
  'field_reg_played' => 1,
  'field_reg_wins' => 1,
  'field_reg_losses' => 0,
  'field_reg_points_for' => 84,
  'field_reg_points_against' => 76,
  'field_reg_points_difference' => 8,
  'field_reg_league_points' => 2,
]);

$news = $create([
  'type' => 'reg_sports_update',
  'title' => '[REG Sports acceptance] Team prepares for next match',
  'field_reg_summary' => 'Temporary published sports story.',
  'body' => ['value' => 'Acceptance story body.', 'format' => 'restricted_html'],
  'field_reg_sport' => 'basketball_men',
  'field_reg_update_type' => 'match_preview',
  'field_reg_team' => $team->id(),
  'field_reg_sports_sport' => $sports['Basketball'],
  'field_reg_publication_date' => gmdate('Y-m-d\TH:i:s', $now),
  'field_reg_related_fixture' => $fixture->id(),
  'field_reg_related_players' => [['target_id' => $player->id()]],
  'field_reg_featured' => 1,
]);

$create([
  'type' => 'reg_sports_player',
  'title' => '[REG Sports acceptance] Unpublished Player',
  'field_reg_team' => $team->id(),
  'field_reg_sports_sport' => $sports['Basketball'],
  'field_reg_position' => $positions['Point Guard'],
  'field_reg_player_status' => 'active',
], FALSE);
$create([
  'type' => 'reg_sports_update',
  'title' => '[REG Sports acceptance] Unpublished Sports Story',
  'field_reg_sport' => 'basketball_men',
  'field_reg_update_type' => 'team_news',
  'field_reg_team' => $team->id(),
  'field_reg_sports_sport' => $sports['Basketball'],
], FALSE);
$create([
  'type' => 'reg_sports_fixture',
  'title' => '[REG Sports acceptance] Unpublished Fixture',
  'field_reg_home_team_name' => 'Hidden Home',
  'field_reg_away_team_name' => 'Hidden Away',
  'field_reg_team' => $team->id(),
  'field_reg_sports_sport' => $sports['Basketball'],
  'field_reg_competition' => $competition->id(),
  'field_reg_season' => $season->id(),
  'field_reg_match_date' => gmdate('Y-m-d\TH:i:s', $now + 86400),
  'field_reg_home_away' => 'home',
  'field_reg_fixture_status' => 'scheduled',
], FALSE);

$fixtures = [
  'nodes' => $node_ids,
  'terms' => [(int) $competition->id(), (int) $season->id()],
  'team' => (int) $team->id(),
  'player' => (int) $player->id(),
  'fixture' => (int) $fixture->id(),
  'standing' => (int) $standing->id(),
  'news' => (int) $news->id(),
  'sport' => $sports['Basketball'],
  'competition' => (int) $competition->id(),
  'season' => (int) $season->id(),
];
$state->set('reg_core.sports_acceptance', $fixtures);
print json_encode($fixtures, JSON_PRETTY_PRINT) . PHP_EOL;
