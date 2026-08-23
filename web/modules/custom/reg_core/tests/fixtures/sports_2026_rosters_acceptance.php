<?php

/**
 * @file
 * Verifies the administrator-supplied 2026 basketball roster import.
 */

use Drupal\node\NodeInterface;

$node_storage = \Drupal::entityTypeManager()->getStorage('node');
$term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$failures = [];

$term_id = static function (string $vocabulary, string $name) use ($term_storage, &$failures): int {
  $terms = $term_storage->loadByProperties(['vid' => $vocabulary, 'name' => $name]);
  if (count($terms) !== 1) {
    $failures["term:$vocabulary:$name"] = 'Expected exactly one taxonomy term.';
    return 0;
  }
  return (int) reset($terms)->id();
};
$nodes = static function (string $bundle, array $conditions = []) use ($node_storage): array {
  $query = $node_storage->getQuery()->accessCheck(FALSE)->condition('type', $bundle);
  foreach ($conditions as $field => $value) {
    $query->condition($field, $value, is_array($value) ? 'IN' : '=');
  }
  return array_values($node_storage->loadMultiple($query->execute()));
};
$one = static function (string $bundle, array $conditions, string $key) use ($nodes, &$failures): ?NodeInterface {
  $matches = $nodes($bundle, $conditions);
  if (count($matches) !== 1) {
    $failures[$key] = 'Expected exactly one matching node; found ' . count($matches) . '.';
    return NULL;
  }
  return $matches[0] instanceof NodeInterface ? $matches[0] : NULL;
};

$season_id = $term_id('reg_sports_season', '2026');
$competition_id = $term_id('reg_sports_competition', '2026 Rwanda Basketball League');
$men_team = $one('reg_sports_team', ['field_reg_team_key' => 'basketball_men'], 'team:men');
$women_team = $one('reg_sports_team', ['field_reg_team_key' => 'basketball_women'], 'team:women');

$men_names = [
  'Elliott Lamar Cole', 'Patrick Nshizirungu', 'Jean de Dieu Umuhoza', 'Enock Isezerano',
  'Cadeau de Dieu Furaha', 'Frank Kamndoh Betoudji', 'Fabrice Muhoza', 'Armel Sangwe',
  'Prince Muhizi', 'Emile Kazeneza', 'Hamady Barro Ndiaye', 'Sedar Sagamba',
  'Hubert Kabare Bugingo', 'Yves Shema', 'Garmine Kande Kieli', 'Steve Junior Nsumizi',
];
$women_names = [
  'Faustine Mwizerwa', 'Henriette Uwimpuhwe', 'Ange Nelly Irakoze', 'Sandrine Mushikiwabo',
  'Odile Tetero', 'Sandra Kantoré', 'Lamla Umunezero', 'Chantal Ramu Kiyobe',
  'Shauqunna Nicole Collins', 'Marie Chantal Utamuliza', 'Kankou Coulibaly', 'Nandy',
  'Kadidia Maiga', 'Taylor Lynn Hosendove',
];
$variants = [
  'Ramla Umunezero', 'Kiyobe Chantal', 'Collins Nicole Shauqunna',
  'Coulibally Kankou', 'Coulibaly Kankou', 'Kankou Coulibally', 'Hosendove Taylor Lynn',
];

foreach ([
  'men' => [$men_team, $men_names, 'verified'],
  'women' => [$women_team, $women_names, 'needs_review'],
] as $group => [$team, $expected_names, $verification]) {
  if (!$team instanceof NodeInterface) {
    continue;
  }
  $players = $nodes('reg_sports_player', [
    'field_reg_team.target_id' => $team->id(),
    'field_reg_season.target_id' => $season_id,
    'field_reg_player_status' => 'active',
  ]);
  $actual_names = array_map(static fn(NodeInterface $node): string => $node->label(), $players);
  if ($actual_names !== $expected_names) {
    $missing = array_values(array_diff($expected_names, $actual_names));
    $unexpected = array_values(array_diff($actual_names, $expected_names));
    if ($missing) {
      $failures["players:$group:missing"] = implode(', ', $missing);
    }
    if ($unexpected) {
      $failures["players:$group:unexpected"] = implode(', ', $unexpected);
    }
  }
  foreach ($players as $player) {
    if (!$player->get('field_reg_jersey_number')->isEmpty() || !$player->get('field_reg_position')->isEmpty()) {
      $failures['player:unverified-detail:' . $player->id()] = 'Jersey number and position must remain empty.';
    }
    if ($player->get('field_reg_source_organization')->value !== 'other_verified'
      || $player->get('field_reg_verification_status')->value !== $verification) {
      $failures['player:source:' . $player->id()] = 'Source or verification metadata is incorrect.';
    }
  }
  $memberships = $nodes('reg_sports_membership', [
    'field_reg_team.target_id' => $team->id(),
    'field_reg_season.target_id' => $season_id,
    'field_reg_membership_status' => 'active',
  ]);
  if (count($memberships) !== count($expected_names)) {
    $failures["memberships:$group"] = sprintf('Expected %d memberships; found %d.', count($expected_names), count($memberships));
  }
  foreach ($memberships as $membership) {
    if (!$membership->get('field_reg_jersey_number')->isEmpty() || !$membership->get('field_reg_position')->isEmpty()) {
      $failures['membership:unverified-detail:' . $membership->id()] = 'Season jersey number and position must remain empty.';
    }
    if ($membership->get('field_reg_verification_status')->value !== $verification) {
      $failures['membership:verification:' . $membership->id()] = 'Membership verification status is incorrect.';
    }
  }
}

if ($nodes('reg_sports_player', ['title' => $variants])) {
  $failures['players:spelling-variants'] = 'One or more non-canonical spelling variants were created.';
}

if ($men_team instanceof NodeInterface) {
  $captains = $nodes('reg_sports_membership', [
    'field_reg_team.target_id' => $men_team->id(),
    'field_reg_season.target_id' => $season_id,
    'field_reg_captain' => 1,
  ]);
  $captain_name = count($captains) === 1 ? $captains[0]->get('field_reg_player')->entity?->label() : '';
  if ($captain_name !== 'Prince Muhizi') {
    $failures['captain:men'] = 'Prince Muhizi must be the sole 2026 men’s captain.';
  }
  $standing = $one('reg_sports_standing', [
    'field_reg_team.target_id' => $men_team->id(),
    'field_reg_competition.target_id' => $competition_id,
    'field_reg_season.target_id' => $season_id,
  ], 'standing:men');
  if ($standing && [
    (int) $standing->get('field_reg_table_position')->value,
    (int) $standing->get('field_reg_played')->value,
    (int) $standing->get('field_reg_wins')->value,
    (int) $standing->get('field_reg_losses')->value,
  ] !== [3, 16, 11, 5]) {
    $failures['standing:men:values'] = 'Expected 3rd place with a 16–11–5 played/wins/losses record.';
  }
}

$repository = \Drupal::service('reg_core.sports_repository');
$men_public = $repository->team('basketball_men');
$women_public = $repository->team('basketball_women');
if (count($men_public['players'] ?? []) !== 16 || count($women_public['players'] ?? []) !== 14) {
  $failures['repository:rosters'] = 'Season-aware public team rosters were not returned in full.';
}

print json_encode([
  'season' => $season_id,
  'competition' => $competition_id,
  'men_team' => $men_team?->id(),
  'women_team' => $women_team?->id(),
  'men_players' => count($men_public['players'] ?? []),
  'women_players' => count($women_public['players'] ?? []),
  'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

if ($failures) {
  exit(1);
}
