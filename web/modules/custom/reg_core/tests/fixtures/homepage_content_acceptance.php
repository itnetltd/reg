<?php

declare(strict_types=1);

use Drupal\block\Entity\Block;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\field\Entity\FieldConfig;
use Drupal\image\Entity\ImageStyle;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\Entity\NodeType;
use Drupal\responsive_image\Entity\ResponsiveImageStyle;

require_once DRUPAL_ROOT . '/modules/custom/reg_core/reg_core.install';
reg_core_apply_homepage_content();

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};

$required_fields = [
  'reg_homepage_hero' => [
    'field_reg_subtitle',
    'field_reg_desktop_hero_image',
    'field_reg_mobile_hero_image',
    'field_reg_primary_cta_label',
    'field_reg_primary_cta_url',
    'field_reg_secondary_cta_label',
    'field_reg_secondary_cta_url',
    'field_reg_order',
    'field_reg_active',
  ],
  'reg_partner' => [
    'field_reg_partner_logo',
    'field_reg_partner_url',
    'field_reg_order',
    'field_reg_active',
    'field_reg_description',
  ],
];
foreach ($required_fields as $bundle => $fields) {
  $assert(NodeType::load($bundle) !== NULL, "Missing $bundle content type.");
  foreach ($fields as $field_name) {
    $assert(FieldConfig::loadByName('node', $bundle, $field_name) !== NULL, "Missing $bundle.$field_name.");
  }
}

$hero_title = BaseFieldOverride::loadByName('node', 'reg_homepage_hero', 'title');
$partner_title = BaseFieldOverride::loadByName('node', 'reg_partner', 'title');
$assert($hero_title !== NULL && $hero_title->isTranslatable(), 'Homepage Hero titles must be translatable.');
$assert($partner_title !== NULL && !$partner_title->isTranslatable(), 'Partner names should not be translatable by default.');
$hero_form = EntityFormDisplay::load('node.reg_homepage_hero.default');
$partner_form = EntityFormDisplay::load('node.reg_partner.default');
$assert(FieldConfig::loadByName('node', 'reg_homepage_hero', 'field_reg_desktop_hero_image')->isRequired(), 'Desktop hero images must be required for editor-created heroes.');
$assert(!FieldConfig::loadByName('node', 'reg_homepage_hero', 'field_reg_mobile_hero_image')->isRequired(), 'Mobile hero images must remain optional.');
$assert(($hero_form?->getComponent('field_reg_desktop_hero_image')['type'] ?? '') === 'media_library_widget', 'Desktop hero images must use Media Library.');
$assert(($hero_form?->getComponent('field_reg_mobile_hero_image')['type'] ?? '') === 'media_library_widget', 'Mobile hero images must use Media Library.');
$assert(($partner_form?->getComponent('field_reg_partner_logo')['type'] ?? '') === 'media_library_widget', 'Partner logos must use Media Library.');

$hero_language = ContentLanguageSettings::loadByEntityTypeBundle('node', 'reg_homepage_hero');
$partner_language = ContentLanguageSettings::loadByEntityTypeBundle('node', 'reg_partner');
$assert($hero_language->getThirdPartySetting('content_translation', 'enabled') === TRUE, 'Homepage Heroes must be translatable.');
$assert($partner_language->getThirdPartySetting('content_translation', 'enabled') === FALSE, 'Partners should not be translatable by default.');

foreach (['reg_homepage_hero_mobile', 'reg_homepage_hero_desktop', 'reg_partner_logo'] as $style) {
  $assert(ImageStyle::load($style) !== NULL, "Missing $style image style.");
}
foreach (['reg_homepage_hero', 'reg_partner_logo'] as $style) {
  $assert(ResponsiveImageStyle::load($style) !== NULL, "Missing $style responsive image style.");
}

$hero_block = Block::load('reg_homepage_hero');
$partner_block = Block::load('reg_homepage_partners');
$assert($hero_block !== NULL && $hero_block->status() && $hero_block->getRegion() === 'hero', 'Homepage hero block is not active in the hero region.');
$assert($partner_block !== NULL && $partner_block->status() && $partner_block->getRegion() === 'partners', 'Homepage partners block is not active in the partners region.');
$assert(($hero_block->get('visibility')['request_path']['pages'] ?? '') === '<front>', 'Homepage hero block must be restricted to the front page.');
$assert(($partner_block->get('visibility')['request_path']['pages'] ?? '') === '<front>', 'Homepage partners block must be restricted to the front page.');

$storage = \Drupal::entityTypeManager()->getStorage('node');
$hero_ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_homepage_hero')->execute();
$partner_ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_partner')->execute();
$demo_hero_ids = $storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'reg_homepage_hero')
  ->condition('title', 'Energy with a vision for the future')
  ->execute();
$assert(count($demo_hero_ids) === 1, 'The idempotent migration must not duplicate the demo hero.');
foreach (['MININFRA', 'RURA', 'EUCL', 'EDCL'] as $name) {
  $demo_partner_ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'reg_partner')
    ->condition('title', $name)
    ->execute();
  $assert(count($demo_partner_ids) === 1, "The idempotent migration must not duplicate $name.");
}

$repository = \Drupal::service('reg_core.homepage_repository');
$hero = $repository->hero();
$partners = $repository->partners();
$assert(is_array($hero) && $hero['title'] === 'Energy with a vision for the future', 'The active published demo hero was not returned.');
$partner_names = array_column($partners, 'name');
$demo_order = array_values(array_filter($partner_names, static fn(string $name): bool => in_array($name, ['MININFRA', 'RURA', 'EUCL', 'EDCL'], TRUE)));
$assert($demo_order === ['MININFRA', 'RURA', 'EUCL', 'EDCL'], 'Migrated partners were not returned in configured relative order.');

print json_encode([
  'hero_count' => count($hero_ids),
  'partner_count' => count($partner_ids),
  'active_partner_count' => count($partners),
  'hero_title' => $hero['title'],
  'partner_order' => $partner_names,
  'hero_translation' => TRUE,
  'partner_translation' => FALSE,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
