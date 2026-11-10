<?php

/** Read-only acceptance: existing records only; no entity/configuration saves. */
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AnonymousUserSession;

$check = static function (bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
  echo "PASS: $message\n";
};
$config_before = \Drupal::config('reg_core.settings')->getRawData();
$check(empty($config_before['public_information']['procurement_plan_integration']) && empty($config_before['public_information']['recruitment_plan_integration']), 'Both annual plan dependencies remain OFF');
$storage = \Drupal::entityTypeManager()->getStorage('node');
$ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', ['reg_tender', 'reg_job', 'reg_procurement_plan', 'reg_procurement_plan_item', 'reg_recruitment_plan', 'reg_recruitment_plan_item', 'reg_job_application'], 'IN')->execute();
$snapshot = static function () use ($storage, $ids): string {
  $storage->resetCache($ids);
  $data = [];
  foreach ($storage->loadMultiple($ids) as $id => $node) $data[$id] = $node->toArray();
  ksort($data);
  return hash('sha256', serialize($data));
};
$before = $snapshot();
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(new AnonymousUserSession());
try {
  $build = \Drupal::service('reg_core.public_information_repository')->homepageOpportunities();
  $check(count($build['#items']) <= 3, 'At most three real tenders');
  foreach (array_merge($build['#items'], $build['#job'] ? [$build['#job']] : []) as $item) {
    $node = $storage->load($item['id']);
    $check($node && $node->isPublished() && $node->access('view'), 'Card uses a real publicly visible published record');
    $check($item['is_open'] && strtotime($item['closing_date_raw'] . ' UTC') > \Drupal::time()->getRequestTime(), 'Card deadline is in the future and lifecycle is open');
    $check(in_array('node:' . $item['id'], $build['#cache']['tags'], TRUE), 'Selected record cache tag included');
  }
  foreach (['node_list:reg_tender', 'node_list:reg_job', 'media_list', 'file_list', 'reg_core:homepage_opportunities'] as $tag) $check(in_array($tag, $build['#cache']['tags'], TRUE), $tag . ' invalidation present');
  $check($build['#cache']['max-age'] > 0 && $build['#cache']['max-age'] <= 300, 'Finite deadline-aware render cache lifetime');
  $render = static function (array $case): string {
    return (string) \Drupal::service('renderer')->renderRoot($case);
  };
  $xpath = static function (string $html): DOMXPath {
    $dom = new DOMDocument(); @$dom->loadHTML($html);
    return new DOMXPath($dom);
  };
  $markup = $render($build);
  $dom = $xpath($markup);
  $check($dom->query('//article')->length === count($build['#items']) + 1, 'Real tender cards plus exactly one vacancy slot');
  $check(str_contains($markup, 'View All Tenders') && str_contains($markup, 'View All Jobs'), 'Both existing archive links rendered');
  // Pure Twig test inputs: these arrays are never saved or served publicly.
  $empty = $build; $empty['#items'] = []; $empty['#job'] = [];
  $empty_markup = $render($empty);
  $check($xpath($empty_markup)->query('//article')->length === 1 && str_contains($empty_markup, 'No current vacancies'), 'Empty data renders vacancy message without invented tender cards');
  $case = $build;
  $case['#items'] = array_fill(0, 3, ['title' => 'In-memory tender markup test', 'url' => '/tenders', 'entity_short' => 'EUCL', 'status_key' => 'active', 'status_short' => 'OPEN', 'reference' => 'TEST', 'category' => 'Goods', 'closing_date' => 'Test deadline', 'closing_date_raw' => '2099-01-01T00:00:00', 'primary_document' => ['url' => '/tenders']]);
  $case['#job'] = ['title' => '<script>escaped vacancy title</script>', 'url' => '/jobs', 'entity_short' => 'EDCL', 'location' => 'Kigali', 'closing_date' => 'Test deadline', 'closing_date_raw' => '2099-01-01T00:00:00', 'reference' => 'MUST-NOT-RENDER', 'category' => 'MUST-NOT-RENDER'];
  $case_markup = $render($case);
  $case_dom = $xpath($case_markup);
  $check($case_dom->query('//article')->length === 4, 'Three tender arrays plus one job render four cards');
  $check(!str_contains($case_markup, 'MUST-NOT-RENDER') && !str_contains($case_markup, '<script>'), 'Job excludes procurement fields and titles remain escaped');
  $check(str_contains($case_markup, 'Location') && str_contains($case_markup, 'Application deadline') && str_contains($case_markup, 'View Job'), 'Job-specific metadata and link rendered');
  $url = 'http://localhost/?opportunities-check=' . bin2hex(random_bytes(4));
  $response = \Drupal::httpClient()->get($url, ['headers' => ['Host' => 'reg-website.ddev.site'], 'timeout' => 240]);
  $html = (string) $response->getBody();
  $page = $xpath($html);
  $check($page->query('//*[@id="homepage-opportunities-title" and normalize-space(.)="Tenders & Jobs"]')->length === 1, 'Combined section appears on actual homepage');
  $check($page->query('//section[@aria-labelledby="homepage-opportunities-title"]//article')->length === count($build['#items']) + 1, 'Actual homepage contains only real selected records plus vacancy slot');
  $expires = strtotime($response->getHeaderLine('Expires'));
  $check($expires > time() && $expires <= time() + 300, 'Internal Page Cache receives a finite future Expires header');
  $second = \Drupal::httpClient()->get($url, ['headers' => ['Host' => 'reg-website.ddev.site'], 'timeout' => 240]);
  $check($second->getHeaderLine('X-Drupal-Cache') === 'HIT', 'Repeated anonymous homepage uses Internal Page Cache');
  $check($response->getHeaderLine('Expires') === $second->getHeaderLine('Expires'), 'Cache hits do not extend opportunity expiry');
  echo 'Existing data: ' . count($build['#items']) . ' open tenders; ' . ($build['#job'] ? 'one vacancy' : 'No current vacancies') . PHP_EOL;
}
finally { $switcher->switchBack(); }
$check($snapshot() === $before, 'All existing content values and relationships unchanged');
$check(\Drupal::config('reg_core.settings')->getRawData() === $config_before, 'Configuration and plan-disable flags unchanged');