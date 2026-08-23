<?php

namespace Drupal\reg_core\Sports;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

/**
 * Cached CMS-backed sports data repository, ready for a future API adapter.
 */
final class SportsRepository implements SportsRepositoryInterface {

  private const BUNDLES = [
    'reg_sports_team',
    'reg_sports_player',
    'reg_sports_staff',
    'reg_sports_fixture',
    'reg_sports_standing',
    'reg_sports_update',
    'reg_sports_gallery',
    'reg_sports_video',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $regEntityTypeManager,
    private readonly LanguageManagerInterface $regLanguageManager,
    private readonly DateFormatterInterface $regDateFormatter,
    private readonly FileUrlGeneratorInterface $regFileUrlGenerator,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function landing(): array {
    $next_match = $this->listing('reg_sports_fixture', ['mode' => 'fixtures'])[0] ?? NULL;
    $latest_result = $this->listing('reg_sports_fixture', ['mode' => 'results'])[0] ?? NULL;
    $featured_result = $this->listing('reg_sports_fixture', [
      'mode' => 'results',
      'featured' => 1,
    ])[0] ?? NULL;
    $latest_story = $this->listing('reg_sports_update')[0] ?? NULL;

    return [
      'teams' => $this->listing('reg_sports_team', ['active' => 1]),
      'hero' => $featured_result ?: $next_match ?: $latest_story,
      'next_match' => $next_match,
      'latest_result' => $latest_result,
      'standings' => array_slice($this->listing('reg_sports_standing'), 0, 8),
      'news' => array_slice($this->listing('reg_sports_update'), 0, 4),
      'players' => array_slice($this->listing('reg_sports_player', ['featured' => 1]), 0, 4),
      'galleries' => array_slice($this->listing('reg_sports_gallery'), 0, 3),
      'videos' => array_slice($this->listing('reg_sports_video'), 0, 3),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function team(string $team_key): ?array {
    if (!in_array($team_key, ['basketball_men', 'basketball_women', 'volleyball_men'], TRUE)) {
      return NULL;
    }
    $teams = $this->listing('reg_sports_team', ['team_key' => $team_key, 'active' => 1]);
    $team = $teams[0] ?? NULL;
    if ($team === NULL) {
      return NULL;
    }
    $team_id = (int) $team['id'];
    return $team + [
      'players' => $this->rosterForTeam($team_id, (int) $team['season_id']),
      'staff' => $this->listing('reg_sports_staff', ['team' => $team_id, 'active' => 1]),
      'fixtures' => array_slice($this->listing('reg_sports_fixture', ['team' => $team_id, 'mode' => 'fixtures']), 0, 5),
      'results' => array_slice($this->listing('reg_sports_fixture', ['team' => $team_id, 'mode' => 'results']), 0, 5),
      'standings' => $this->listing('reg_sports_standing', ['team_context' => $team_id]),
      'news' => array_slice($this->listing('reg_sports_update', ['team' => $team_id]), 0, 4),
      'galleries' => array_slice($this->listing('reg_sports_gallery', ['team' => $team_id]), 0, 3),
      'videos' => array_slice($this->listing('reg_sports_video', ['team' => $team_id]), 0, 3),
    ];
  }

  /**
   * Loads the published roster for one team-season membership context.
   */
  private function rosterForTeam(int $team_id, int $season_id): array {
    $node_type = $this->regEntityTypeManager->getStorage('node_type')->load('reg_sports_membership');
    if (!$node_type) {
      return $this->listing('reg_sports_player', ['team' => $team_id, 'active_player' => 1]);
    }

    $langcode = $this->regLanguageManager->getCurrentLanguage()->getId();
    $cid = 'reg_core:sports:roster:' . hash('sha256', serialize([$team_id, $season_id, $langcode]));
    if ($cached = $this->cache->get($cid)) {
      return is_array($cached->data) ? $cached->data : [];
    }

    $storage = $this->regEntityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reg_sports_membership')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('field_reg_team.target_id', $team_id)
      ->condition('field_reg_membership_status', 'active');
    if ($season_id > 0) {
      $query->condition('field_reg_season.target_id', $season_id);
    }
    $query->sort('field_reg_roster_order', 'ASC')->sort('nid', 'ASC');

    $players = [];
    foreach ($storage->loadMultiple($query->execute()) as $membership) {
      if (!$membership instanceof NodeInterface || !$membership->access('view')) {
        continue;
      }
      $player = $membership->get('field_reg_player')->entity;
      if (!$player instanceof NodeInterface || !$player->isPublished() || !$player->access('view')) {
        continue;
      }
      if ($player->hasTranslation($langcode) && $player->getTranslation($langcode)->isPublished()) {
        $player = $player->getTranslation($langcode);
      }
      $item = $this->normalize($player);
      $membership_position = $this->reference($membership, 'field_reg_position');
      $membership_season = $this->reference($membership, 'field_reg_season');
      $membership_team = $this->reference($membership, 'field_reg_team');
      $item['position'] = $membership_position['label'] ?: $item['position'];
      $item['jersey'] = $this->value($membership, 'field_reg_jersey_number') ?: $item['jersey'];
      $item['captain'] = (bool) $this->value($membership, 'field_reg_captain');
      $item['season'] = $membership_season['label'] ?: $item['season'];
      $item['season_id'] = $membership_season['id'] ?: $item['season_id'];
      $item['team'] = $membership_team['label'] ?: $item['team'];
      $item['team_id'] = $membership_team['id'] ?: $item['team_id'];
      $players[] = $item;
    }

    if (!$players) {
      $players = $this->listing('reg_sports_player', ['team' => $team_id, 'active_player' => 1]);
    }
    $this->cache->set($cid, $players, $this->time->getRequestTime() + 300, [
      'node_list:reg_sports_membership',
      'node_list:reg_sports_player',
    ]);
    return $players;
  }

  /**
   * {@inheritdoc}
   */
  public function listing(string $bundle, array $filters = []): array {
    if (!in_array($bundle, self::BUNDLES, TRUE)) {
      return [];
    }
    $filters = $this->filters($filters);
    $langcode = $this->regLanguageManager->getCurrentLanguage()->getId();
    $cid = 'reg_core:sports:' . hash('sha256', serialize([$bundle, $filters, $langcode]));
    if ($cached = $this->cache->get($cid)) {
      return is_array($cached->data) ? $cached->data : [];
    }

    $storage = $this->regEntityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', $bundle)
      ->condition('status', NodeInterface::PUBLISHED);
    if ($filters['query'] !== '') {
      $group = $query->orConditionGroup()
        ->condition('title', $filters['query'], 'CONTAINS')
        ->condition('field_reg_summary', $filters['query'], 'CONTAINS')
        ->condition('body', $filters['query'], 'CONTAINS');
      $query->condition($group);
    }
    if ($filters['team'] > 0 && $bundle !== 'reg_sports_team') {
      $query->condition('field_reg_team.target_id', $filters['team']);
    }
    if ($filters['sport'] > 0) {
      $query->condition('field_reg_sports_sport.target_id', $filters['sport']);
    }
    if ($filters['competition'] > 0) {
      $query->condition('field_reg_competition.target_id', $filters['competition']);
    }
    if ($filters['season'] > 0) {
      $query->condition('field_reg_season.target_id', $filters['season']);
    }
    if ($filters['position'] > 0 && $bundle === 'reg_sports_player') {
      $query->condition('field_reg_position.target_id', $filters['position']);
    }
    if ($filters['team_key'] !== '' && $bundle === 'reg_sports_team') {
      $query->condition('field_reg_team_key', $filters['team_key']);
    }
    if ($filters['active'] && in_array($bundle, ['reg_sports_team', 'reg_sports_staff'], TRUE)) {
      $query->condition('field_reg_active', 1);
    }
    if ($filters['active_player'] && $bundle === 'reg_sports_player') {
      $query->condition('field_reg_player_status', 'active');
    }
    $featureable_bundles = [
      'reg_sports_fixture',
      'reg_sports_player',
      'reg_sports_update',
      'reg_sports_gallery',
      'reg_sports_video',
    ];
    if ($filters['featured'] && in_array($bundle, $featureable_bundles, TRUE)) {
      $query->condition('field_reg_featured', 1);
    }

    if ($bundle === 'reg_sports_fixture') {
      $this->fixtureConditions($query, $filters);
    }
    if ($filters['team_context'] > 0 && $bundle === 'reg_sports_standing') {
      $team = $storage->load($filters['team_context']);
      if ($team instanceof NodeInterface) {
        $competition = $this->referenceId($team, 'field_reg_competition');
        $season = $this->referenceId($team, 'field_reg_season');
        if ($competition > 0) {
          $query->condition('field_reg_competition.target_id', $competition);
        }
        if ($season > 0) {
          $query->condition('field_reg_season.target_id', $season);
        }
        if ($competition < 1 || $season < 1) {
          $query->condition('nid', 0);
        }
      }
    }

    [$sort, $direction] = $this->sort($bundle, $filters['mode']);
    $query->sort($sort, $direction)->sort('nid', 'DESC');
    $nodes = $storage->loadMultiple($query->execute());
    $items = [];
    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface || !$node->access('view')) {
        continue;
      }
      if ($node->hasTranslation($langcode) && $node->getTranslation($langcode)->isPublished()) {
        $node = $node->getTranslation($langcode);
      }
      $items[] = $this->normalize($node);
    }
    $this->cache->set($cid, $items, $this->time->getRequestTime() + 300, ['node_list:' . $bundle]);
    return $items;
  }

  /**
   * Applies trusted, editor-managed fixture lifecycle filtering.
   */
  private function fixtureConditions(object $query, array $filters): void {
    if ($filters['status'] !== '') {
      $query->condition('field_reg_fixture_status', $filters['status']);
    }
    elseif ($filters['mode'] === 'fixtures') {
      $query
        ->condition('field_reg_fixture_status', ['scheduled', 'postponed'], 'IN')
        ->condition('field_reg_match_date', gmdate('Y-m-d\TH:i:s', $this->time->getRequestTime()), '>=');
    }
    elseif ($filters['mode'] === 'results') {
      $query->condition('field_reg_fixture_status', 'completed');
    }
    if ($filters['month'] !== '' && preg_match('/^\d{4}-\d{2}$/', $filters['month'])) {
      $start = $filters['month'] . '-01T00:00:00';
      $end = gmdate('Y-m-d\TH:i:s', strtotime($start . ' UTC +1 month'));
      $query->condition('field_reg_match_date', $start, '>=')->condition('field_reg_match_date', $end, '<');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function detail(string $bundle, int $id): ?array {
    if (!in_array($bundle, self::BUNDLES, TRUE) || $id < 1) {
      return NULL;
    }
    $node = $this->regEntityTypeManager->getStorage('node')->load($id);
    if (!$node instanceof NodeInterface || $node->bundle() !== $bundle || !$node->isPublished() || !$node->access('view')) {
      return NULL;
    }
    $langcode = $this->regLanguageManager->getCurrentLanguage()->getId();
    if ($node->hasTranslation($langcode) && $node->getTranslation($langcode)->isPublished()) {
      $node = $node->getTranslation($langcode);
    }
    $item = $this->normalize($node);
    if ($bundle === 'reg_sports_player') {
      $item['news'] = $this->relatedNewsForPlayer($id);
    }
    return $item;
  }

  /**
   * {@inheritdoc}
   */
  public function search(string $keywords): array {
    $keywords = mb_substr(trim(strip_tags($keywords)), 0, 120);
    if ($keywords === '') {
      return [];
    }
    $items = [];
    foreach (['reg_sports_team', 'reg_sports_player', 'reg_sports_update', 'reg_sports_fixture'] as $bundle) {
      $items = array_merge($items, $this->listing($bundle, ['query' => $keywords]));
    }
    usort($items, static fn(array $a, array $b): int => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));
    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function taxonomyOptions(string $vocabulary): array {
    $allowed_vocabularies = [
      'reg_sports_sport',
      'reg_sports_position',
      'reg_sports_competition',
      'reg_sports_season',
    ];
    if (!in_array($vocabulary, $allowed_vocabularies, TRUE)) {
      return [];
    }
    $options = [];
    foreach ($this->regEntityTypeManager->getStorage('taxonomy_term')->loadTree($vocabulary) as $term) {
      $options[(int) $term->tid] = $term->name;
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function teamOptions(): array {
    $options = [];
    foreach ($this->listing('reg_sports_team', ['active' => 1]) as $team) {
      $options[$team['id']] = $team['title'];
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function homepageHighlights(): array {
    $items = [];
    $next = $this->listing('reg_sports_fixture', ['mode' => 'fixtures'])[0] ?? NULL;
    if ($next) {
      $items[] = [
        'kind' => 'fixture',
        'team' => 'Next fixture',
        'title' => $next['matchup'],
        'meta' => $next['date'],
        'url' => $next['url'],
        'image' => $next['image'],
        'result_label' => '',
      ];
    }
    $result = $this->listing('reg_sports_fixture', ['mode' => 'results'])[0] ?? NULL;
    if ($result) {
      $items[] = [
        'kind' => 'result',
        'team' => 'Latest result',
        'title' => $result['matchup'],
        'meta' => $result['score_label'],
        'url' => $result['url'],
        'image' => $result['image'],
        'result_label' => $result['result_label'] ?: $result['status'],
      ];
    }
    $news = $this->listing('reg_sports_update')[0] ?? NULL;
    if ($news) {
      $items[] = [
        'kind' => 'story',
        'team' => 'Latest story',
        'title' => $news['title'],
        'meta' => $news['date'],
        'url' => $news['url'],
        'image' => $news['image'],
        'result_label' => '',
      ];
    }
    return $items;
  }

  /**
   * Normalizes a sports node for safe Twig presentation.
   */
  private function normalize(NodeInterface $node): array {
    $bundle = $node->bundle();
    $team = $this->reference($node, 'field_reg_team');
    $match_date = $this->date($node, 'field_reg_match_date');
    $home = $this->value($node, 'field_reg_home_team_name');
    $away = $this->value($node, 'field_reg_away_team_name');
    $home_score = $this->optionalInt($node, 'field_reg_home_score');
    $away_score = $this->optionalInt($node, 'field_reg_away_score');
    $home_sets = $this->optionalInt($node, 'field_reg_home_sets');
    $away_sets = $this->optionalInt($node, 'field_reg_away_sets');
    $score = self::formatScore($home_score, $away_score, $home_sets, $away_sets);
    $route = $this->route($node);
    $publication_date = $this->date($node, 'field_reg_publication_date');
    $event_date = $this->date($node, 'field_reg_event_date');
    $gallery_date = $this->date($node, 'field_reg_gallery_date');
    $birth_date = $this->dateOnly($node, 'field_reg_date_of_birth');

    return [
      'id' => (int) $node->id(),
      'bundle' => $bundle,
      'kind' => $this->kind($bundle),
      'title' => $node->label(),
      'url' => $route,
      'summary' => $this->value($node, 'field_reg_summary'),
      'description' => $this->value($node, 'field_reg_description') ?: $this->value($node, 'field_reg_biography') ?: $this->value($node, 'body'),
      'team_key' => $this->value($node, 'field_reg_team_key'),
      'team' => $team['label'],
      'team_id' => $team['id'],
      'sport' => $this->reference($node, 'field_reg_sports_sport')['label'],
      'sport_id' => $this->reference($node, 'field_reg_sports_sport')['id'],
      'competition' => $this->reference($node, 'field_reg_competition')['label'],
      'competition_id' => $this->reference($node, 'field_reg_competition')['id'],
      'season' => $this->reference($node, 'field_reg_season')['label'],
      'season_id' => $this->reference($node, 'field_reg_season')['id'],
      'position' => $this->reference($node, 'field_reg_position')['label'],
      'category' => $this->listLabel($node, 'field_reg_update_type'),
      'gender' => $this->listLabel($node, 'field_reg_gender_category'),
      'status' => $this->listLabel($node, 'field_reg_fixture_status') ?: $this->listLabel($node, 'field_reg_player_status'),
      'status_key' => $this->value($node, 'field_reg_fixture_status') ?: $this->value($node, 'field_reg_player_status'),
      'date' => $bundle === 'reg_sports_fixture'
        ? $match_date['display']
        : ($publication_date['display'] ?: $gallery_date['display'] ?: $event_date['display'] ?: $this->regDateFormatter->format($node->getCreatedTime(), 'medium')),
      'date_iso' => $bundle === 'reg_sports_fixture'
        ? $match_date['iso']
        : ($publication_date['iso'] ?: $gallery_date['iso'] ?: $event_date['iso']),
      'timestamp' => $match_date['timestamp'] ?: $publication_date['timestamp'] ?: $gallery_date['timestamp'] ?: $event_date['timestamp'] ?: $node->getCreatedTime(),
      'matchup' => trim($home . ($home && $away ? ' vs ' : '') . $away),
      'home_team' => $home,
      'away_team' => $away,
      'home_score' => $home_score,
      'away_score' => $away_score,
      'score' => $score,
      'score_label' => $score && $home && $away ? $home . ' ' . str_replace('–', ' – ', $score) . ' ' . $away : $score,
      'result_label' => $this->value($node, 'field_reg_result_label'),
      'round' => $this->value($node, 'field_reg_match_round'),
      'venue' => $this->value($node, 'field_reg_venue') ?: $this->value($node, 'field_reg_home_venue'),
      'home_away' => $this->listLabel($node, 'field_reg_home_away'),
      'ticket_url' => $this->link($node, 'field_reg_ticket_url'),
      'broadcast_url' => $this->link($node, 'field_reg_broadcast_url'),
      'match_notes' => $this->value($node, 'field_reg_match_notes'),
      'match_report' => $this->value($node, 'field_reg_match_report'),
      'match_statistics' => $this->value($node, 'field_reg_match_statistics'),
      'quarter_scores' => $this->value($node, 'field_reg_quarter_scores'),
      'set_scores' => $this->value($node, 'field_reg_set_scores'),
      'player_of_match' => $this->reference($node, 'field_reg_player_of_match')['label'],
      'jersey' => $this->value($node, 'field_reg_jersey_number'),
      'nationality' => $this->value($node, 'field_reg_nationality'),
      'birth_date' => $birth_date['display'],
      'birth_date_iso' => $birth_date['iso'],
      'height' => $this->value($node, 'field_reg_height_cm'),
      'captain' => (bool) $this->value($node, 'field_reg_captain'),
      'featured' => (bool) $this->value($node, 'field_reg_featured'),
      'role' => $this->listLabel($node, 'field_reg_staff_role'),
      'coach' => $this->reference($node, 'field_reg_head_coach')['label'],
      'technical_summary' => $this->value($node, 'field_reg_technical_summary'),
      'active' => (bool) $this->value($node, 'field_reg_active'),
      'publication_source' => $this->value($node, 'field_reg_author_source'),
      'photo_credit' => $this->value($node, 'field_reg_photo_credit'),
      'logo' => $this->image($node, 'field_reg_team_logo'),
      'image' => $this->image($node, 'field_reg_hero_image', $bundle === 'reg_sports_fixture') ?: $this->image($node, 'field_reg_profile_photo') ?: $this->image($node, 'field_reg_thumbnail') ?: $this->firstImage($node, 'field_reg_photos'),
      'images' => $this->images($node, 'field_reg_photos'),
      'video_url' => $this->remoteVideoUrl($node),
      'social_links' => $this->links($node, 'field_reg_social_links'),
      'stats' => $this->stats($node),
      'standing' => $this->standing($node),
    ];
  }

  /**
   * Returns route URLs for public sports record types.
   */
  private function route(NodeInterface $node): string {
    return match ($node->bundle()) {
      'reg_sports_team' => Url::fromRoute('reg_core.sports_team_' . $this->value($node, 'field_reg_team_key'))->toString(),
      'reg_sports_player' => Url::fromRoute('reg_core.sports_player', ['player' => $node->id()])->toString(),
      'reg_sports_fixture' => Url::fromRoute('reg_core.sports_fixture', ['fixture' => $node->id()])->toString(),
      'reg_sports_update' => Url::fromRoute('reg_core.sports_news_detail', ['node' => $node->id()])->toString(),
      'reg_sports_gallery' => Url::fromRoute('reg_core.sports_gallery_detail', ['gallery' => $node->id()])->toString(),
      default => Url::fromRoute('reg_core.sports')->toString(),
    };
  }

  /**
   * Returns a display kind for search and cards.
   */
  private function kind(string $bundle): string {
    return match ($bundle) {
      'reg_sports_team' => 'Team',
      'reg_sports_player' => 'Player',
      'reg_sports_staff' => 'Technical staff',
      'reg_sports_fixture' => 'Fixture or result',
      'reg_sports_standing' => 'Standing',
      'reg_sports_update' => 'Sports news',
      'reg_sports_gallery' => 'Gallery',
      'reg_sports_video' => 'Video',
      default => 'Sports',
    };
  }

  /**
   * Returns standings values without applying competition-specific rules.
   */
  private function standing(NodeInterface $node): array {
    if ($node->bundle() !== 'reg_sports_standing') {
      return [];
    }
    $fields = [
      'table_position',
      'played',
      'wins',
      'losses',
      'points_for',
      'points_against',
      'points_difference',
      'sets_won',
      'sets_lost',
      'league_points',
    ];
    $values = ['team' => $this->value($node, 'field_reg_standing_team')];
    foreach ($fields as $field) {
      $values[$field] = $this->optionalInt($node, 'field_reg_' . $field);
    }
    $values['other'] = $this->value($node, 'field_reg_other_stats');
    return $values;
  }

  /**
   * Returns optional editor-managed player statistics.
   */
  private function stats(NodeInterface $node): array {
    if ($node->bundle() !== 'reg_sports_player') {
      return [];
    }
    $values = [];
    foreach (['games_played', 'points', 'rebounds', 'assists', 'steals', 'blocks', 'kills', 'aces'] as $field) {
      $value = $this->optionalInt($node, 'field_reg_' . $field);
      if ($value !== NULL) {
        $values[$field] = $value;
      }
    }
    return $values;
  }

  /**
   * Finds published news that references one player.
   */
  private function relatedNewsForPlayer(int $player_id): array {
    $storage = $this->regEntityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reg_sports_update')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('field_reg_related_players.target_id', $player_id)
      ->sort('field_reg_publication_date', 'DESC')
      ->range(0, 6)
      ->execute();
    return array_map(fn(NodeInterface $node): array => $this->normalize($node), array_values($storage->loadMultiple($ids)));
  }

  /**
   * Returns a bounded image-style derivative and alt text.
   */
  private function image(NodeInterface $node, string $field_name, bool $original = FALSE): array {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return [];
    }
    $media = $node->get($field_name)->entity;
    if (!$media instanceof MediaInterface || !$media->isPublished() || !$media->access('view')) {
      return [];
    }
    return $this->mediaImage($media, $field_name === 'field_reg_team_logo', $original);
  }

  /**
   * Returns all approved gallery derivatives.
   */
  private function images(NodeInterface $node, string $field_name): array {
    if (!$node->hasField($field_name)) {
      return [];
    }
    $images = [];
    foreach ($node->get($field_name)->referencedEntities() as $media) {
      if (!$media instanceof MediaInterface || !$media->isPublished() || !$media->access('view')) {
        continue;
      }
      $image = $this->mediaImage($media);
      if ($image) {
        $images[] = $image;
      }
    }
    return $images;
  }

  /**
   * Builds safe responsive image data from an approved image Media item.
   */
  private function mediaImage(MediaInterface $media, bool $logo = FALSE, bool $original = FALSE): array {
    $source = (string) ($media->getSource()->getConfiguration()['source_field'] ?? '');
    $source_item = $source !== '' ? $media->get($source)->first() : NULL;
    $file = $source_item?->entity;
    if (!$file) {
      return [];
    }
    $alt = trim((string) ($source_item->get('alt')->getValue() ?? '')) ?: (string) $media->label();
    if ($original) {
      return [
        'url' => $this->regFileUrlGenerator->generateString($file->getFileUri()),
        'alt' => $alt,
        'srcset' => '',
        'sizes' => '100vw',
      ];
    }
    $storage = $this->regEntityTypeManager->getStorage('image_style');
    $style_names = $logo
      ? ['reg_sports_logo' => 256]
      : ['reg_sports_card_small' => 360, 'reg_sports_card' => 720, 'reg_sports_card_large' => 1440];
    $sources = [];
    foreach ($style_names as $style_name => $width) {
      $style = $storage->load($style_name);
      if ($style) {
        $sources[$width] = $style->buildUrl($file->getFileUri());
      }
    }
    if (!$sources) {
      return [];
    }
    $default_width = $logo ? 256 : (isset($sources[720]) ? 720 : array_key_last($sources));
    return [
      'url' => $sources[$default_width],
      'alt' => $alt,
      'srcset' => implode(', ', array_map(static fn(string $url, int $width): string => $url . ' ' . $width . 'w', $sources, array_keys($sources))),
      'sizes' => $logo ? '160px' : '(max-width: 48rem) 100vw, 720px',
    ];
  }

  /**
   * Returns the first approved image derivative.
   */
  private function firstImage(NodeInterface $node, string $field_name): array {
    return $this->images($node, $field_name)[0] ?? [];
  }

  /**
   * Returns an approved remote-video provider URL; editors cannot inject HTML.
   */
  private function remoteVideoUrl(NodeInterface $node): string {
    if (!$node->hasField('field_reg_video_media') || $node->get('field_reg_video_media')->isEmpty()) {
      return '';
    }
    $media = $node->get('field_reg_video_media')->entity;
    if (!$media instanceof MediaInterface || !$media->isPublished() || !$media->access('view')) {
      return '';
    }
    $source = (string) ($media->getSource()->getConfiguration()['source_field'] ?? '');
    return $source !== '' ? trim((string) ($media->get($source)->value ?? '')) : '';
  }

  /**
   * Returns a referenced entity ID and label.
   */
  private function reference(NodeInterface $node, string $field_name): array {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return ['id' => 0, 'label' => ''];
    }
    $entity = $node->get($field_name)->entity;
    return $entity ? ['id' => (int) $entity->id(), 'label' => $entity->label()] : ['id' => 0, 'label' => ''];
  }

  /**
   * Returns a referenced entity ID.
   */
  private function referenceId(NodeInterface $node, string $field_name): int {
    return $this->reference($node, $field_name)['id'];
  }

  /**
   * Returns a plain first field value.
   */
  private function value(NodeInterface $node, string $field_name): string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return '';
    }
    return trim(strip_tags((string) ($node->get($field_name)->first()?->get('value')->getValue() ?? '')));
  }

  /**
   * Returns an integer while preserving empty as NULL.
   */
  private function optionalInt(NodeInterface $node, string $field_name): ?int {
    $value = $this->value($node, $field_name);
    return $value === '' ? NULL : (int) $value;
  }

  /**
   * Returns a configured list value label.
   */
  private function listLabel(NodeInterface $node, string $field_name): string {
    $value = $this->value($node, $field_name);
    if ($value === '' || !$node->hasField($field_name)) {
      return '';
    }
    $allowed = $node->getFieldDefinition($field_name)->getFieldStorageDefinition()->getSetting('allowed_values') ?: [];
    return (string) ($allowed[$value] ?? $value);
  }

  /**
   * Returns a safely rendered link field URL.
   */
  private function link(NodeInterface $node, string $field_name): string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return '';
    }
    $uri = (string) ($node->get($field_name)->uri ?? '');
    try {
      return $uri ? Url::fromUri($uri)->toString() : '';
    }
    catch (\Exception) {
      return '';
    }
  }

  /**
   * Returns all safely rendered link field values.
   */
  private function links(NodeInterface $node, string $field_name): array {
    if (!$node->hasField($field_name)) {
      return [];
    }
    $links = [];
    foreach ($node->get($field_name) as $item) {
      try {
        $links[] = [
          'url' => Url::fromUri((string) $item->uri)->toString(),
          'title' => (string) ($item->title ?: 'Official social link'),
        ];
      }
      catch (\Exception) {
        continue;
      }
    }
    return $links;
  }

  /**
   * Formats one date for visible and machine-readable output.
   */
  private function date(NodeInterface $node, string $field_name): array {
    $value = $this->value($node, $field_name);
    if ($value === '') {
      return ['display' => '', 'iso' => '', 'timestamp' => 0];
    }
    $timestamp = strtotime($value . (str_contains($value, 'T') ? ' UTC' : ''));
    return $timestamp === FALSE
      ? ['display' => '', 'iso' => '', 'timestamp' => 0]
      : [
        'display' => $this->regDateFormatter->format($timestamp, 'medium'),
        'iso' => gmdate(DATE_ATOM, $timestamp),
        'timestamp' => $timestamp,
      ];
  }

  /**
   * Formats an optional date-only field without implying a time of day.
   */
  private function dateOnly(NodeInterface $node, string $field_name): array {
    $value = $this->value($node, $field_name);
    $timestamp = $value !== '' ? strtotime($value . ' UTC') : FALSE;
    return $timestamp === FALSE
      ? ['display' => '', 'iso' => '']
      : [
        'display' => $this->regDateFormatter->format($timestamp, 'custom', 'j F Y'),
        'iso' => gmdate('Y-m-d', $timestamp),
      ];
  }

  /**
   * Returns bundle-specific chronological sorting.
   */
  private function sort(string $bundle, string $mode): array {
    return match ($bundle) {
      'reg_sports_fixture' => ['field_reg_match_date', $mode === 'fixtures' ? 'ASC' : 'DESC'],
      'reg_sports_standing' => ['field_reg_table_position', 'ASC'],
      'reg_sports_update', 'reg_sports_video' => ['field_reg_publication_date', 'DESC'],
      'reg_sports_gallery' => ['field_reg_gallery_date', 'DESC'],
      default => ['created', 'DESC'],
    };
  }

  /**
   * Whitelists public sports query parameters.
   */
  private function filters(array $filters): array {
    return [
      'query' => mb_substr(trim(strip_tags((string) ($filters['query'] ?? ''))), 0, 120),
      'team' => max(0, (int) ($filters['team'] ?? 0)),
      'team_context' => max(0, (int) ($filters['team_context'] ?? 0)),
      'sport' => max(0, (int) ($filters['sport'] ?? 0)),
      'competition' => max(0, (int) ($filters['competition'] ?? 0)),
      'season' => max(0, (int) ($filters['season'] ?? 0)),
      'position' => max(0, (int) ($filters['position'] ?? 0)),
      'team_key' => in_array(($filters['team_key'] ?? ''), ['basketball_men', 'basketball_women', 'volleyball_men'], TRUE) ? (string) $filters['team_key'] : '',
      'status' => in_array(($filters['status'] ?? ''), ['scheduled', 'postponed', 'cancelled', 'live', 'completed'], TRUE) ? (string) $filters['status'] : '',
      'mode' => in_array(($filters['mode'] ?? ''), ['fixtures', 'results'], TRUE) ? (string) $filters['mode'] : '',
      'month' => preg_match('/^\d{4}-\d{2}$/', (string) ($filters['month'] ?? '')) ? (string) $filters['month'] : '',
      'active' => !empty($filters['active']),
      'active_player' => !empty($filters['active_player']),
      'featured' => !empty($filters['featured']),
    ];
  }

  /**
   * Formats an editor-confirmed result without inventing missing values.
   */
  public static function formatScore(?int $home_score, ?int $away_score, ?int $home_sets = NULL, ?int $away_sets = NULL): string {
    if ($home_sets !== NULL && $away_sets !== NULL) {
      return $home_sets . '–' . $away_sets;
    }
    if ($home_score !== NULL && $away_score !== NULL) {
      return $home_score . '–' . $away_score;
    }
    return '';
  }

}
