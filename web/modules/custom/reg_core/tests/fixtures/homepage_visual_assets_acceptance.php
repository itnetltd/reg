<?php

declare(strict_types=1);

use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

require_once DRUPAL_ROOT . '/modules/custom/reg_core/reg_core.install';

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};

$first = reg_core_apply_featured_result_image();
$second = reg_core_apply_featured_result_image();
$assert($first['fixture'] !== NULL && $first['media'] !== NULL, 'The approved result fixture and Media must resolve.');
$assert($first['fixture'] === $second['fixture'] && $first['media'] === $second['media'], 'The result image repair must be idempotent.');
$assert($second['updated'] === FALSE, 'A second result image repair must not create another revision or Media change.');

$entity_type_manager = \Drupal::entityTypeManager();
$fixture = $entity_type_manager->getStorage('node')->load($first['fixture']);
$media = $entity_type_manager->getStorage('media')->load($first['media']);
$assert($fixture instanceof NodeInterface && $media instanceof MediaInterface, 'The fixture and Media entities must load.');
$assert((int) $fixture->get('field_reg_hero_image')->target_id === (int) $media->id(), 'The result must reference the approved Media image.');
$image = $media->get('field_media_image')->first();
$assert($image !== NULL && $image->entity !== NULL, 'The Media item must contain an image file.');
$assert($image->entity->getFileUri() === 'public://sports/reg-vs-patriots-74-71.jpg', 'The result must use the supplied REG–Patriots photograph.');
$assert($image->alt === 'REG basketball player during the REG 74–71 Patriots match', 'The public match-image alt text is incorrect.');

$highlights = \Drupal::service('reg_core.sports_repository')->homepageHighlights();
$result_cards = array_values(array_filter($highlights, static fn(array $item): bool => ($item['kind'] ?? '') === 'result'));
$result_card = $result_cards[0] ?? [];
$assert(($result_card['image']['url'] ?? '') !== '', 'The homepage result card must receive its Media image URL.');
$assert(($result_card['image']['alt'] ?? '') === $image->alt, 'The homepage result card must receive the Media alt text.');
$assert(($result_card['meta'] ?? '') === 'REG 74 – 71 Patriots', 'The score must remain public HTML text.');
$assert(($result_card['result_label'] ?? '') === 'Full Time', 'The public result label must remain Full Time.');
$assert(($result_card['url'] ?? '') === '/sports/match/' . $fixture->id(), 'The card must link to the fixture detail route.');

$logo_svg = DRUPAL_ROOT . '/themes/custom/reg_theme/assets/images/reg-logo.svg';
$logo_png = DRUPAL_ROOT . '/themes/custom/reg_theme/assets/images/reg-logo.png';
$svg = is_file($logo_svg) ? (string) file_get_contents($logo_svg) : '';
$png_header = is_file($logo_png) ? (string) file_get_contents($logo_png, FALSE, NULL, 0, 8) : '';
$assert(str_starts_with($svg, '<?xml') && str_contains($svg, 'data:image/png;base64,'), 'The fallback logo must be a valid self-contained SVG wrapper around the approved raster mark.');
$assert($png_header === "\x89PNG\r\n\x1a\n", 'The approved fallback logo raster is not a valid PNG.');

$front_template = (string) file_get_contents(DRUPAL_ROOT . '/themes/custom/reg_theme/templates/layout/page--front.html.twig');
$layout_css = (string) file_get_contents(DRUPAL_ROOT . '/themes/custom/reg_theme/css/layout.css');
$assert(str_contains($front_template, '{% if item.image.url %}'), 'The homepage must branch on CMS image availability.');
$assert(str_contains($layout_css, '.sports-card::before'), 'The existing no-image sports gradient fallback must remain available.');

print json_encode([
  'fixture' => (int) $fixture->id(),
  'media' => (int) $media->id(),
  'file_uri' => $image->entity->getFileUri(),
  'result_image_url' => $result_card['image']['url'],
  'result_url' => $result_card['url'],
  'idempotent' => !$second['updated'],
  'logo_svg_valid' => TRUE,
  'fallback_gradient_present' => TRUE,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
