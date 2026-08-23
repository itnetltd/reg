<?php

/**
 * @file
 * Content model and installation helpers for official What We Do content.
 */

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\Entity\NodeType;

/**
 * Creates the structured, source-traceable What We Do content model.
 */
function reg_core_apply_what_we_do_content_model(): void {
  \Drupal::service('module_installer')->install([
    'datetime',
    'file',
    'image',
    'link',
    'media',
    'media_library',
    'options',
    'path',
    'text',
  ], TRUE);

  $types = [
    'reg_what_we_do_page' => ['What We Do page', 'Official institutional content migrated from the current REG website.'],
    'reg_project' => ['REG project', 'Structured REG generation, transmission, distribution, and access projects.'],
    'reg_power_plant' => ['Power plant', 'Source-preserving power-plant directory records.'],
    'reg_fact' => ['REG fact', 'Dated, sourced statistical facts used by public pages.'],
    'reg_access_statistic' => ['Access statistic', 'District-level total, on-grid, and off-grid electricity access rates.'],
  ];
  foreach ($types as $id => [$label, $description]) {
    if (!NodeType::load($id)) {
      NodeType::create([
        'type' => $id,
        'name' => $label,
        'description' => $description,
        'new_revision' => TRUE,
        'preview_mode' => 1,
        'display_submitted' => FALSE,
      ])->save();
    }
  }

  $source = [
    'field_reg_source_url' => reg_core_link_field('Official source URL'),
    'field_reg_source_label' => reg_core_string_field('Source label', FALSE),
    'field_reg_source_id' => reg_core_string_field('Stable source ID', FALSE, TRUE),
    'field_reg_source_hash' => reg_core_string_field('Source content hash', FALSE),
    'field_reg_imported_date' => reg_core_date_field('Last imported', FALSE),
    'field_reg_content_review' => reg_core_list_field('Internal review status', [
      'approved' => 'Approved',
      'imported_needs_review' => 'Imported – Needs Review',
      'needs_current_data' => 'Needs Current Data Review',
      'needs_technical_review' => 'Needs Technical Review',
      'needs_contact_verification' => 'Needs Contact Verification',
      'needs_content_enhancement' => 'Needs Content Enhancement',
    ], TRUE, FALSE),
  ];

  $definitions = [
    'reg_what_we_do_page' => array_merge([
      'body' => reg_core_what_we_do_body_field(),
      'field_reg_wwd_key' => reg_core_string_field('Page identifier', FALSE, TRUE),
      'field_reg_summary' => reg_core_what_we_do_summary_field(),
      'field_reg_wwd_group' => reg_core_list_field('Section', [
        'overview' => 'Overview',
        'generation' => 'Generation',
        'transmission' => 'Transmission',
        'distribution' => 'Distribution',
        'access' => 'Electricity Access',
        'energy_solutions' => 'Energy Solutions',
        'projects' => 'Projects',
        'programs' => 'Programs',
        'investment' => 'Investment',
      ], TRUE, FALSE),
      'field_reg_featured_image' => array_replace(reg_core_media_reference_field('Official page image', 1, FALSE, 'image'), ['translatable' => FALSE]),
    ], $source),
    'reg_project' => array_merge([
      'body' => reg_core_what_we_do_body_field(),
      'field_reg_summary' => reg_core_what_we_do_summary_field(),
      'field_reg_project_category' => reg_core_string_field('Project category', FALSE),
      'field_reg_project_status' => reg_core_string_field('Project status', FALSE),
      'field_reg_project_funder' => reg_core_string_field('Donor / funder', FALSE),
      'field_reg_project_scope' => array_replace(reg_core_long_text_field('Project scope'), ['translatable' => TRUE]),
      'field_reg_effectiveness_date' => reg_core_date_field('Effectiveness date', FALSE),
      'field_reg_closure_date' => reg_core_date_field('Closure date', FALSE),
      'field_reg_geographic_coverage' => reg_core_string_field('Geographic coverage'),
      'field_reg_featured_image' => array_replace(reg_core_media_reference_field('Official project image', 1, FALSE, 'image'), ['translatable' => FALSE]),
    ], $source),
    'reg_power_plant' => array_merge([
      'field_reg_plant_technology' => reg_core_string_field('Technology', FALSE, TRUE),
      'field_reg_grid_status' => reg_core_list_field('Grid connection', ['on_grid' => 'On-grid', 'off_grid' => 'Off-grid', 'unspecified' => 'Not specified'], TRUE, FALSE),
      'field_reg_capacity_mw' => reg_core_decimal_field('Installed capacity', 12, 3, FALSE),
      'field_reg_ownership' => reg_core_string_field('Ownership', FALSE),
      'field_reg_commissioning_year' => reg_core_integer_field('Year', FALSE),
    ], $source),
    'reg_fact' => array_merge([
      'field_reg_fact_key' => reg_core_string_field('Fact identifier', FALSE, TRUE),
      'field_reg_fact_category' => reg_core_list_field('Category', [
        'generation' => 'Generation',
        'transmission' => 'Transmission',
        'access' => 'Electricity Access',
        'other' => 'Other REG indicator',
      ], TRUE, FALSE),
      'field_reg_fact_role' => reg_core_list_field('Homepage card role', [
        'headline' => 'Headline metric',
        'secondary' => 'Supporting breakdown',
      ], TRUE, FALSE),
      'field_reg_metric' => reg_core_string_field('Metric', TRUE, TRUE),
      'field_reg_fact_value' => reg_core_decimal_field('Value', 14, 3, FALSE),
      'field_reg_decimal_places' => reg_core_integer_field('Display decimal places', FALSE),
      'field_reg_fact_unit' => reg_core_string_field('Unit', FALSE, TRUE),
      'field_reg_reporting_period' => reg_core_string_field('Reporting period / date', FALSE, TRUE),
      'field_reg_wwd_key' => reg_core_string_field('Related page identifier', FALSE, TRUE),
      'field_reg_supporting_text' => reg_core_long_text_field('Public supporting text'),
      'field_reg_order' => reg_core_integer_field('Display order', FALSE),
      'field_reg_active' => reg_core_boolean_field('Active', FALSE),
      'field_reg_show_homepage' => reg_core_boolean_field('Featured on homepage', FALSE),
      'field_reg_internal_source_note' => reg_core_long_text_field('Internal source note'),
    ], $source),
    'reg_access_statistic' => array_merge([
      'field_reg_district_name' => reg_core_string_field('District', FALSE, TRUE),
      'field_reg_access_type' => reg_core_list_field('Access type', ['total' => 'Total access', 'on_grid' => 'On-grid', 'off_grid' => 'Off-grid'], TRUE, FALSE),
      'field_reg_access_rate' => reg_core_decimal_field('Access rate', 6, 2, FALSE),
      'field_reg_reporting_period' => reg_core_string_field('Reporting period / date', FALSE, TRUE),
    ], $source),
  ];

  foreach ($definitions as $bundle => $fields) {
    $weight = 5;
    foreach ($fields as $field_name => $definition) {
      reg_core_ensure_field($bundle, $field_name, $definition, $weight);
      $weight += 2;
    }
    $base = \Drupal::service('entity_field.manager')->getBaseFieldDefinitions('node');
    $title = BaseFieldOverride::loadByName('node', $bundle, 'title')
      ?: BaseFieldOverride::createFromBaseFieldDefinition($base['title'], $bundle);
    $title->setTranslatable($bundle !== 'reg_power_plant' && $bundle !== 'reg_access_statistic')->save();
    ContentLanguageSettings::loadByEntityTypeBundle('node', $bundle)
      ->setDefaultLangcode('site_default')
      ->setLanguageAlterable(TRUE)
      ->setThirdPartySetting('content_translation', 'enabled', TRUE)
      ->save();
  }

  foreach (array_keys($definitions) as $bundle) {
    $display = EntityViewDisplay::load("node.$bundle.default");
    if (!$display) {
      continue;
    }
    foreach (['field_reg_source_url', 'field_reg_source_label', 'field_reg_source_id', 'field_reg_source_hash', 'field_reg_imported_date', 'field_reg_content_review'] as $field_name) {
      $display->removeComponent($field_name);
    }
    $display->save();
  }

  reg_core_ensure_official_media_source_fields();
}

/**
 * Returns a reusable external link field definition.
 */
function reg_core_link_field(string $label): array {
  return [
    'type' => 'link',
    'label' => $label,
    'required' => FALSE,
    'translatable' => FALSE,
    'storage_settings' => [],
    'field_settings' => ['link_type' => \Drupal\link\LinkItemInterface::LINK_EXTERNAL],
    'widget' => 'link_default',
    'formatter' => 'link',
  ];
}

/**
 * Returns the official body field definition.
 */
function reg_core_what_we_do_body_field(): array {
  return [
    'type' => 'text_with_summary',
    'label' => 'Official page content',
    'required' => FALSE,
    'translatable' => TRUE,
    'storage_settings' => [],
    'field_settings' => ['display_summary' => TRUE, 'required_summary' => FALSE],
    'widget' => 'text_textarea_with_summary',
    'formatter' => 'text_default',
  ];
}

/**
 * Returns a multi-line summary definition.
 */
function reg_core_what_we_do_summary_field(): array {
  return [
    'type' => 'string_long',
    'label' => 'Public summary',
    'required' => TRUE,
    'translatable' => TRUE,
    'storage_settings' => [],
    'field_settings' => [],
    'widget' => 'string_textarea',
    'formatter' => 'basic_string',
  ];
}

/**
 * Adds official-source trace fields to imported image Media.
 */
function reg_core_ensure_official_media_source_fields(): void {
  $fields = [
    'field_reg_official_source_url' => ['type' => 'link', 'label' => 'Official source URL', 'settings' => ['link_type' => \Drupal\link\LinkItemInterface::LINK_EXTERNAL]],
    'field_reg_official_alt' => ['type' => 'string', 'label' => 'Source alt text', 'settings' => ['max_length' => 255]],
    'field_reg_official_caption' => ['type' => 'string_long', 'label' => 'Source caption', 'settings' => []],
  ];
  foreach ($fields as $name => $definition) {
    $storage = FieldStorageConfig::loadByName('media', $name);
    if (!$storage) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'media',
        'type' => $definition['type'],
        'settings' => $definition['settings'],
      ])->save();
    }
    if (!FieldConfig::loadByName('media', 'image', $name)) {
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'media',
        'bundle' => 'image',
        'label' => $definition['label'],
        'translatable' => FALSE,
      ])->save();
    }
  }
}
