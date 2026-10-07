<?php

/**
 * @file
 * DDEV/Drush acceptance check for category-driven sports news. Cleans fixtures.
 */

use Drupal\node\Entity\Node;

$check = static function (bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
  echo 'PASS: ' . $message . PHP_EOL;
};
$storage = \Drupal::entityTypeManager()->getStorage('node');
$news = \Drupal::service('reg_core.news_repository');
$sports = \Drupal::service('reg_core.sports_repository');
$teams = $storage->loadByProperties(['type' => 'reg_sports_team', 'status' => 1]);
$team = reset($teams);
$check((bool) $team, 'Existing team available; no team created');
$image = Node::load(807)?->get('field_reg_featured_image')->target_id;
$created = [];
$client = \Drupal::httpClient();
$get = static fn(string $path, bool $redirects = TRUE) => $client->get('http://127.0.0.1' . $path, ['headers' => ['Host' => 'reg-website.ddev.site:33000'], 'http_errors' => FALSE, 'allow_redirects' => $redirects]);
$dom = static function (string $html): DOMXPath {
  $doc = new DOMDocument();
  @$doc->loadHTML($html);
  return new DOMXPath($doc);
};
try {
  $story = Node::create([
    'type' => 'reg_news', 'title' => 'REG Sports Test Story', 'langcode' => 'en',
    'status' => 1, 'moderation_state' => 'published',
    'field_reg_news_category_term' => reg_core_sports_news_category_ids()[0],
    'field_reg_team' => $team->id(),
    'field_reg_featured_image' => $image,
    'field_reg_publication_date' => gmdate('Y-m-d\TH:i:s'),
    'field_reg_summary' => 'Development verification of the single News editorial workflow.',
    'body' => ['value' => '<p>REG Sports Test Story acceptance body.</p>', 'format' => 'basic_html'],
  ]);
  $story->save(); $created[] = $story;
  $url = $story->toUrl()->toString();
  $check(str_starts_with($url, '/news/'), 'News uses a canonical /news/slug alias');
  $check($story->get('field_reg_news_section')->value === 'sports', 'Category alone synchronizes legacy metadata');
  $check((int) $news->sportsHomepage(1)[0]['id'] === (int) $story->id(), 'Sports latest story uses newest publication date');
  $check((int) $news->homepage()['primary']['id'] === (int) $story->id(), 'Same News node appears in News & Insights');
  $filtered = $news->archive(['section' => 'sports', 'team' => $team->id()], 0, 12);
  $check(in_array((int) $story->id(), array_column($filtered['items'], 'id')), 'Existing team filter finds the News node');
  $excluded = $news->archive(['section' => 'sports', 'team' => 2147483647], 0, 12);
  $check(!$excluded['items'], 'Unmatched team does not leak unrelated sports stories');
  foreach (['/news', '/sports', '/sports/news', '/'] as $path) {
    $response = $get($path);
    $html = (string) $response->getBody();
    $check($response->getStatusCode() === 200 && str_contains($html, 'REG Sports Test Story'), 'Test News appears at ' . $path);
    if ($path === '/') {
      $xpath = $dom($html);
      $links = $xpath->query('//a[contains(@class,"sports-card")][.//h3[contains(.,"REG Sports Test Story")]]');
      $check($links->length === 1 && $links->item(0)->getAttribute('href') === $url, 'Homepage Sports card links once to the same canonical News article');
      $check(str_contains($links->item(0)->textContent, $team->label()), 'Homepage sports card shows existing team');
      $check($xpath->query('.//img', $links->item(0))->length === 1, 'Homepage sports card includes featured image');
    }
  }
  $response = $get($url);
  $xpath = $dom((string) $response->getBody());
  $check($response->getStatusCode() === 200 && str_contains($xpath->evaluate('string(//h1)'), 'REG Sports Test Story'), 'Following homepage story loads correct article title without 404');
  $check(str_contains((string) $response->getBody(), 'REG Sports Test Story acceptance body.'), 'Canonical article renders story body');
  $check(str_contains((string) $response->getBody(), gmdate('Y-m-d')), 'Article renders correct publication date');
  $check($xpath->query('//link[@rel="canonical"]')->length > 0, 'Article declares canonical URL');
  $check(str_contains((string) $response->getBody(), 'news_article_hero') || str_contains((string) $response->getBody(), 'news_featured'), 'Article uses responsive featured image');
  $legacy = $get('/sports/news/1082', FALSE);
  $check($legacy->getStatusCode() === 301 && str_contains($legacy->getHeaderLine('Location'), '/news/'), 'Broken legacy sports URL redirects to canonical News');
  $check($get(parse_url($legacy->getHeaderLine('Location'), PHP_URL_PATH))->getStatusCode() === 200, 'Legacy redirect destination loads');
  $other_language = Node::create([
    'type' => 'reg_news', 'title' => 'RW Sports language verification', 'langcode' => 'rw',
    'status' => 1, 'moderation_state' => 'published',
    'field_reg_news_category_term' => reg_core_sports_news_category_ids()[0],
    'field_reg_publication_date' => gmdate('Y-m-d\TH:i:s', time() + 3600),
  ]);
  $other_language->save(); $created[] = $other_language;
  $check(!in_array((int) $other_language->id(), array_column($news->sportsHomepage(), 'id')), 'English Sports query excludes Kinyarwanda-only News');
  $rw_items = $news->archive(['section' => 'sports', 'language' => 'rw'], 0, 12)['items'];
  $check(in_array((int) $other_language->id(), array_column($rw_items, 'id')) && !in_array('en', array_column($rw_items, 'language')), 'Explicit Kinyarwanda filter returns Kinyarwanda articles');
  $check(str_contains((string) $get('/sports/news?team=' . $team->id())->getBody(), 'REG Sports Test Story'), 'Public team-filtered Sports News includes the selected team story');

  $story->setNewRevision(TRUE); $story->set('moderation_state', 'archived'); $story->setUnpublished(); $story->save();
  $check(!in_array((int) $story->id(), array_column($news->sportsHomepage(), 'id')), 'Unpublishing invalidates sports latest-story cache');
  $check(!str_contains((string) $get('/')->getBody(), 'REG Sports Test Story'), 'Unpublishing removes homepage story without manual cache clear');
  $story->setNewRevision(TRUE); $story->set('moderation_state', 'published'); $story->setPublished();
  $corporate = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => 'reg_news_category', 'name' => 'Corporate News']);
  $story->set('field_reg_news_category_term', reset($corporate)->id()); $story->save();
  $check(!in_array((int) $story->id(), array_column($news->sportsHomepage(), 'id')), 'Changing category removes the story from Sports');
  $check(str_contains((string) $get('/news')->getBody(), 'REG Sports Test Story'), 'Recategorized News remains in normal archive');
  $count = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_news')->count()->execute();
  $rerun = reg_core_migrate_sports_news();
  $check(!$rerun['created'] && !$rerun['reused'] && !$rerun['archived'] && $count === $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_news')->count()->execute(), 'Migration rerun is idempotent and creates no duplicate');
  $check(!Node::load(1082)->isPublished() && Node::load(1082)->get('body')->value !== '', 'Legacy source remains archived with original body');
  echo json_encode(['test_id' => $story->id(), 'canonical' => $url, 'team' => $team->label(), 'migration_map' => \Drupal::state()->get('reg_core.sports_news_migration')], JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
finally {
  foreach ($created as $node) {
    foreach (\Drupal::entityTypeManager()->getStorage('path_alias')->loadByProperties(['path' => '/node/' . $node->id()]) as $alias) $alias->delete();
    $node->delete();
  }
  echo 'Development test content removed.' . PHP_EOL;
}
