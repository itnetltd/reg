<?php

declare(strict_types=1);

use Drupal\block\Entity\Block;
use Drupal\field\Entity\FieldConfig;
use Drupal\user\Entity\Role;

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};

foreach (['field_reg_decimal_places', 'field_reg_supporting_text', 'field_reg_order', 'field_reg_active', 'field_reg_show_homepage'] as $field_name) {
  $assert(FieldConfig::loadByName('node', 'reg_fact', $field_name) !== NULL, "Missing reg_fact.$field_name.");
}

$expected = [
  'homepage-transmission-network-length' => ['Transmission network length', '1158.000', 'km', 'As of current approved reporting period', 0, 10],
  'homepage-street-lighting' => ['Street length and lamps installed', '88242.000', '', 'Street lighting infrastructure', 0, 20],
  'homepage-installed-generation-capacity' => ['Installed generation capacity', '472.950', 'MW', 'By June 2026', 2, 30],
  'homepage-national-electricity-access' => ['National electricity access rate (grid + off-grid)', '88.300', '%', 'By June 2026', 1, 40],
  'homepage-productive-use-areas' => ['Productive use areas (PUAs) connected', '10477.000', '', 'Cumulative to date', 0, 50],
  'homepage-clean-cooking-stoves' => ['Clean cooking stoves disseminated', '1820646.000', '', 'Cumulative dissemination', 0, 60],
];

$storage = \Drupal::entityTypeManager()->getStorage('node');
foreach ($expected as $key => [$title, $value, $suffix, $note, $decimals, $order]) {
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'reg_fact')
    ->condition('field_reg_fact_key', $key)
    ->execute();
  $assert(count($ids) === 1, "Expected one seeded statistic for $key.");
  $node = $storage->load(reset($ids));
  $assert($node->label() === $title, "Unexpected title for $key.");
  $assert((string) $node->get('field_reg_fact_value')->value === $value, "Unexpected value for $key.");
  $assert((string) $node->get('field_reg_fact_unit')->value === $suffix, "Unexpected suffix for $key.");
  $assert((string) $node->get('field_reg_supporting_text')->value === $note, "Unexpected supporting text for $key.");
  $assert((int) $node->get('field_reg_decimal_places')->value === $decimals, "Unexpected decimal precision for $key.");
  $assert((int) $node->get('field_reg_order')->value === $order, "Unexpected display order for $key.");
  $assert((bool) $node->get('field_reg_active')->value, "$key must be active.");
  $assert((bool) $node->get('field_reg_show_homepage')->value, "$key must be shown on the homepage.");
}

$statistics = \Drupal::service('reg_core.homepage_repository')->statistics();
$assert(count($statistics) === 6, 'The repository must return six homepage statistics.');
$assert(array_column($statistics, 'value') === ['1,158', '88,242', '472.95', '88.3', '10,477', '1,820,646'], 'Public values are not formatted exactly as approved.');
$hero_statistics = \Drupal::service('reg_core.homepage_repository')->heroStatistics();
$assert(count($hero_statistics) === 4, 'The hero repository selection must contain four statistics.');
$assert(array_column($hero_statistics, 'key') === [
  'homepage-installed-generation-capacity',
  'homepage-national-electricity-access',
  'homepage-transmission-network-length',
  'homepage-clean-cooking-stoves',
], 'The hero statistics must be selected by stable keys in the approved order.');
$assert(array_column($hero_statistics, 'value') === ['472.95', '88.3', '1,158', '1,820,646'], 'Hero values are not sourced and formatted as approved.');

$block = Block::load('reg_homepage_statistics');
$assert($block !== NULL, 'The REG At a Glance block is missing.');
$assert(!$block->status(), 'The large REG At a Glance homepage block must be disabled.');
$assert($block->getRegion() === 'homepage_stats', 'The block must use the homepage_stats region.');
$assert($block->getPluginId() === 'reg_core_homepage_statistics', 'The block plugin is incorrect.');
$assert(($block->get('visibility')['request_path']['pages'] ?? '') === '<front>', 'The block must be restricted to the front page.');

$energy_editor = Role::load('reg_energy_content_editor');
$approver = Role::load('reg_content_approver');
$translator = Role::load('reg_translator');
$assert($energy_editor?->hasPermission('create reg_fact content') === TRUE, 'Energy content editors must be able to create homepage statistics.');
$assert($energy_editor?->hasPermission('edit any reg_fact content') === TRUE, 'Energy content editors must be able to edit homepage statistics.');
$assert($approver?->hasPermission('edit any reg_fact content') === TRUE, 'Content approvers must be able to edit homepage statistics.');
$assert($translator?->hasPermission('translate reg_fact node') === TRUE, 'Translators must be able to translate homepage statistics.');

$admin_route = \Drupal::service('router.route_provider')->getRouteByName('reg_core.homepage_statistics_admin');
$assert($admin_route->getPath() === '/admin/content/homepage-stats', 'The homepage statistics administration route is incorrect.');
$admin_build = \Drupal\reg_core\Controller\HomepageStatisticsAdminController::create(\Drupal::getContainer())->overview();
$assert(count($admin_build['table']['#rows'] ?? []) >= 6, 'The administration overview must include all six homepage statistics.');

$html = html_entity_decode((string) \Drupal::httpClient()->get('http://localhost/')->getBody(), ENT_QUOTES | ENT_HTML5);
$hero_position = strpos($html, '<section class="hero">');
$statistics_position = strpos($html, '<section class="reg-hero-stats"');
$outage_position = strpos($html, '<section class="outage-lookup"');
$assert($hero_position !== FALSE && $statistics_position !== FALSE && $outage_position !== FALSE, 'Homepage section markup is incomplete.');
$assert($hero_position < $statistics_position && $statistics_position < $outage_position, 'REG At a Glance must render inside the hero before the outage lookup.');
foreach (['472.95', '88.3', '1,158', '1,820,646'] as $value) {
  $assert(str_contains($html, $value), "Homepage is missing $value.");
}
$assert(substr_count($html, 'class="reg-hero-stat"') === 4, 'Hero must render exactly four statistic highlights.');
$assert(!str_contains($html, '<section class="homepage-stats"'), 'The large statistics section must not render on the homepage.');
$track_end = strpos($html, '</div>', strpos($html, 'class="reg-hero-carousel__track"'));
$assert($track_end !== FALSE && $track_end < $statistics_position, 'Hero statistics must remain outside the rotating slide track.');
$assert(str_contains($html, 'data-reg-counter-group'), 'Hero statistics must expose one counter animation group.');

$library = \Drupal::service('library.discovery')->getLibraryByName('reg_core', 'homepage_statistics');
$library_css = array_column($library['css'] ?? [], 'data');
$assert(in_array('modules/custom/reg_core/css/homepage-statistics.css', $library_css, TRUE), 'The homepage statistics component stylesheet is not registered.');
$counter_library = \Drupal::service('library.discovery')->getLibraryByName('reg_core', 'homepage_counters');
$library_js = array_column($counter_library['js'] ?? [], 'data');
$assert(in_array('modules/custom/reg_core/js/homepage-statistics.js', $library_js, TRUE), 'The homepage statistics behavior is not registered.');

foreach ([
  ['472.95', 2],
  ['88.3', 1],
  ['1158', 0],
  ['1820646', 0],
] as [$target, $decimals]) {
  $pattern = sprintf('/data-counter-value="%s"\s+data-counter-decimals="%d"/', preg_quote($target, '/'), $decimals);
  $assert((bool) preg_match($pattern, $html), "Counter metadata is incorrect for $target.");
}

$css = file_get_contents(DRUPAL_ROOT . '/modules/custom/reg_core/css/homepage-statistics.css');
$assert(is_string($css) && str_contains($css, 'repeat(3, minmax(0, 1fr))'), 'Desktop statistics grid must use three columns.');
$assert(str_contains($css, '@media (max-width: 1024px)') && str_contains($css, 'repeat(2, minmax(0, 1fr))'), 'Tablet statistics grid must use two columns.');
$assert(str_contains($css, '@media (max-width: 560px)') && str_contains($css, 'grid-template-columns: 1fr'), 'Mobile statistics grid must use one column.');
$assert(!str_contains($css, 'http://') && !str_contains($css, 'https://'), 'Statistics styling must not hotlink external assets.');

$hero_css = file_get_contents(DRUPAL_ROOT . '/modules/custom/reg_core/css/homepage-hero.css');
$assert(is_string($hero_css) && str_contains($hero_css, '.reg-hero-stats { position: absolute;'), 'Hero statistics must be an absolute overlay.');
$assert(str_contains($hero_css, 'grid-template-columns: repeat(4, minmax(0, 1fr))'), 'Hero statistics must use four desktop columns.');
$assert(str_contains($hero_css, '@media (max-width: 768px)') && str_contains($hero_css, 'grid-template-columns: repeat(2, minmax(0, 1fr))'), 'Hero statistics must use a 2x2 mobile grid.');
$assert(str_contains($hero_css, 'backdrop-filter: blur(10px)'), 'Hero statistics must use the restrained glass treatment.');

$javascript = file_get_contents(DRUPAL_ROOT . '/modules/custom/reg_core/js/homepage-statistics.js');
$assert(is_string($javascript) && str_contains($javascript, 'Drupal.behaviors.regHomepageCounters'), 'The Drupal counter behavior is missing.');
foreach (['once(', 'IntersectionObserver', 'requestAnimationFrame', 'prefers-reduced-motion: reduce', 'Intl.NumberFormat', 'observer.unobserve(section)', 'threshold: [0.25]', 'duration = 1800', 'index * 80'] as $feature) {
  $assert(str_contains($javascript, $feature), "Counter behavior is missing $feature support.");
}
foreach (['1158', '88242', '472.95', '88.3', '10477', '1820646'] as $hardcoded_value) {
  $assert(!str_contains($javascript, $hardcoded_value), "JavaScript must not hard-code the Drupal target $hardcoded_value.");
}

print json_encode([
  'content_type' => 'reg_fact',
  'statistics_count' => count($statistics),
  'hero_statistics_count' => count($hero_statistics),
  'values' => array_column($statistics, 'value'),
  'block_region' => $block->getRegion(),
  'admin_path' => '/admin/content/homepage-stats',
  'hero_overlay_valid' => TRUE,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
