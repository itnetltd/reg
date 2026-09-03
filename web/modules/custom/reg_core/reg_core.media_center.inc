<?php

/**
 * @file
 * Idempotent schema helpers for the legacy REG Media Center migration.
 */

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\language\Entity\ConfigurableLanguage;

/**
 * Prepares the existing REG News bundle for legacy content migration.
 */
function reg_core_apply_media_center_migration(): void {
  $review = reg_core_list_field('Migration review status', [
    'verified' => 'Verified',
    'needs_date' => 'Needs Date Verification',
    'needs_language' => 'Needs Language Review',
    'needs_classification' => 'Needs Classification Review',
    'needs_file' => 'Needs File Review',
    'duplicate_candidate' => 'Duplicate Candidate',
  ], TRUE, FALSE);
  $status = reg_core_list_field('Migration status', [
    'imported' => 'Imported',
    'updated' => 'Updated',
    'unchanged' => 'Existing / Unchanged',
    'needs_review' => 'Needs Review',
  ], TRUE, FALSE);
  $legacy_source_url = [
    'type' => 'link',
    'label' => 'Legacy Source URL',
    'required' => FALSE,
    'translatable' => FALSE,
    'field_settings' => [
      'title' => 1,
      'link_type' => 17,
    ],
    'widget' => 'link_default',
    'formatter' => 'link',
  ];
  $news_fields = [
    'field_reg_news_section' => reg_core_list_field('News section', [
      'corporate' => 'Corporate News',
      'sports' => 'Sports News',
    ], TRUE, FALSE),
    // Reuse the existing publication-date and legacy-URL fields.
    'field_reg_publication_date' => array_replace(
      reg_core_date_field('Original Publication Date', FALSE),
      ['required' => TRUE],
    ),
    'field_reg_external_url' => $legacy_source_url,
    'field_reg_source_id' => reg_core_string_field('Legacy source ID', FALSE),
    'field_reg_imported_date' => reg_core_date_field('Imported Date', FALSE),
    'field_reg_source_hash' => reg_core_string_field('Source Hash', FALSE),
    'field_reg_migration_status' => $status,
    'field_reg_migration_review' => $review,
    'field_reg_related_translation' => reg_core_node_reference_field('Related translation', 'reg_news', 1, FALSE, FALSE),
    'field_reg_sports_sport' => reg_core_term_reference_field('Related sport', 'reg_sports_sport', FALSE, FALSE),
  ];

  $weight = 25;
  foreach ($news_fields as $name => $definition) {
    reg_core_ensure_field('reg_news', $name, $definition, $weight++);
  }
  foreach (['en', 'rw'] as $langcode) {
    if (!ConfigurableLanguage::load($langcode)) {
      ConfigurableLanguage::createFromLangcode($langcode)->save();
    }
  }

  ContentLanguageSettings::loadByEntityTypeBundle('node', 'reg_news')
    ->setDefaultLangcode('site_default')
    ->setLanguageAlterable(TRUE)
    ->setThirdPartySetting('content_translation', 'enabled', TRUE)
    ->save();

  $display = EntityViewDisplay::load('node.reg_news.default');
  if ($display) {
    foreach ([
      'field_reg_source_id',
      'field_reg_imported_date',
      'field_reg_source_hash',
      'field_reg_migration_status',
      'field_reg_migration_review',
      'field_reg_related_translation',
    ] as $name) {
      $display->removeComponent($name);
    }
    $display->save();
  }

  // Existing news remains corporate unless editors already classified it.
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'reg_news')
    ->notExists('field_reg_news_section')
    ->execute();
  foreach ($storage->loadMultiple($ids) as $node) {
    $node->set('field_reg_news_section', 'corporate')->save();
  }

  // Keep field_reg_external_url as the single canonical legacy URL field.
  // Remove an earlier duplicate instance only when it contains no content.
  $duplicate = FieldConfig::loadByName('node', 'reg_news', 'field_reg_source_url');
  if ($duplicate) {
    $duplicate_values = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'reg_news')
      ->exists('field_reg_source_url')
      ->range(0, 1)
      ->execute();
    if (!$duplicate_values) {
      $duplicate->delete();
    }
  }
}

/**
 * Prepares the existing REG Publication bundle for legacy document imports.
 */
function reg_core_apply_media_document_migration(): void {
  $legacy_source_url = [
    'type' => 'link',
    'label' => 'Legacy Source URL',
    'required' => FALSE,
    'translatable' => FALSE,
    'field_settings' => ['title' => 1, 'link_type' => 17],
    'widget' => 'link_default',
    'formatter' => 'link',
  ];
  $fields = [
    'field_reg_external_url' => $legacy_source_url,
    'field_reg_source_id' => reg_core_string_field('Legacy source ID', FALSE),
    'field_reg_imported_date' => reg_core_date_field('Imported Date', FALSE),
    'field_reg_source_hash' => reg_core_string_field('Source Hash', FALSE),
    'field_reg_migration_status' => reg_core_list_field('Migration status', [
      'imported' => 'Imported',
      'updated' => 'Updated',
      'unchanged' => 'Existing / Unchanged',
      'needs_review' => 'Needs Review',
    ], FALSE, FALSE),
    'field_reg_migration_review' => reg_core_list_field('Migration review status', [
      'verified' => 'Verified',
      'needs_date' => 'Needs Date Verification',
      'needs_language' => 'Needs Language Review',
      'needs_classification' => 'Needs Classification Review',
      'needs_file' => 'Needs File Review',
      'duplicate_candidate' => 'Duplicate Candidate',
    ], FALSE, FALSE),
  ];
  $weight = 32;
  foreach ($fields as $name => $definition) {
    reg_core_ensure_field('reg_publication', $name, $definition, $weight++);
  }

  // Preserve the established categories and add only the two source classes.
  reg_core_ensure_field('reg_publication', 'field_reg_publication_category', reg_core_list_field('Publication category', [
    'report' => 'Report',
    'policy' => 'Policy',
    'plan' => 'Plan',
    'safeguard' => 'Safeguard',
    'form' => 'Form',
    'press_release' => 'Press Release',
    'publication' => 'Publication',
  ]), 11);
  reg_core_ensure_field('reg_publication', 'field_reg_publication_date', reg_core_date_field('Original Publication Date'), 13);

  $display = EntityViewDisplay::load('node.reg_publication.default');
  if ($display) {
    foreach (['field_reg_source_id', 'field_reg_imported_date', 'field_reg_source_hash', 'field_reg_migration_status', 'field_reg_migration_review'] as $name) {
      $display->removeComponent($name);
    }
    $display->save();
  }
}
