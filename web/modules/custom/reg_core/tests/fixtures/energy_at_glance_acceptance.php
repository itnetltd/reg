<?php

declare(strict_types=1);

use Drupal\block\Entity\Block;
use Drupal\field\Entity\FieldConfig;

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};

foreach ([
  'field_reg_fact_category',
  'field_reg_fact_role',
  'field_reg_metric',
  'field_reg_fact_value',
  'field_reg_fact_unit',
  'field_reg_reporting_period',
  'field_reg_supporting_text',
  'field_reg_internal_source_note',
  'field_reg_order',
  'field_reg_show_homepage',
  'field_reg_active',
] as $field_name) {
  $assert(FieldConfig::loadByName('node', 'reg_fact', $field_name) !== NULL, "Missing reg_fact.$field_name.");
}

$cards = \Drupal::service('reg_core.homepage_repository')->energyAtGlance();
$assert(array_column($cards, 'category') === ['generation', 'transmission', 'access'], 'Energy cards are not in the required order.');
$expected = [
  'generation' => ['472.95', 'MW', 'June 2026', '/what-we-do/generation'],
  'transmission' => ['1,158', 'km', 'June 2026', '/what-we-do/transmission'],
  'access' => ['88.3', '%', 'June 2026', '/what-we-do/access'],
];
foreach ($cards as $card) {
  [$value, $unit, $period, $url] = $expected[$card['category']];
  $assert($card['value'] === $value, "Unexpected {$card['category']} value.");
  $assert($card['unit'] === $unit, "Unexpected {$card['category']} unit.");
  $assert($card['period'] === $period, "Unexpected {$card['category']} period.");
  $assert($card['url'] === $url, "Unexpected {$card['category']} URL.");
  $assert(!array_key_exists('internal_source_note', $card), 'Internal source notes must not be exposed to Twig.');
}

$block = Block::load('reg_energy_at_glance');
$assert($block === NULL, 'The duplicate Energy at a Glance homepage block must be removed.');

$route = \Drupal::service('router.route_provider')->getRouteByName('reg_core.energy_facts_admin');
$assert($route->getPath() === '/admin/content/energy-facts', 'Energy facts admin route is incorrect.');
$admin = \Drupal\reg_core\Controller\HomepageStatisticsAdminController::create(\Drupal::getContainer())->overview();
foreach (['category', 'period', 'published', 'featured'] as $filter) {
  $assert(isset($admin['filters'][$filter]), "Energy facts admin is missing the $filter filter.");
}
$assert(count($admin['table']['#rows'] ?? []) >= 3, 'Energy facts admin does not list the managed records.');

$html = html_entity_decode((string) \Drupal::httpClient()->get('http://localhost/')->getBody(), ENT_QUOTES | ENT_HTML5);
foreach (['REG At a Glance', 'Key figures', '472.95', '1,158', '88.3'] as $text) {
  $assert(str_contains($html, $text), "Homepage is missing $text.");
}
$assert(!str_contains($html, '>Energy at a Glance<'), 'The duplicate Energy at a Glance heading still renders.');
foreach (['Source validation required', 'GIS-ready visualization', 'Replace demonstration values', 'Approved operational statistics will be connected'] as $forbidden) {
  $assert(!str_contains($html, $forbidden), "Homepage still exposes prototype copy: $forbidden");
}
$assert(substr_count($html, 'class="energy-glance-card ') === 0, 'Duplicate energy cards still render on the homepage.');
$assert(substr_count($html, 'class="homepage-stat"') === 6, 'Homepage must render the six approved REG statistics.');

print json_encode([
  'content_type' => 'reg_fact',
  'categories' => array_column($cards, 'category'),
  'values' => array_column($cards, 'value'),
  'admin_path' => '/admin/content/energy-facts',
  'duplicate_block_removed' => TRUE,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
