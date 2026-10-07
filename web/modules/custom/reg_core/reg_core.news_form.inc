<?php

/**
 * @file
 * Editorial presentation and migration field access for the News form.
 */

/** Technical fields retained for migration administrators only. */
function reg_core_news_migration_fields(): array {
  return [
    'field_reg_external_url', 'field_reg_source_id', 'field_reg_imported_date',
    'field_reg_source_site', 'field_reg_legacy_assets', 'field_reg_source_hash',
    'field_reg_migration_status', 'field_reg_migration_review',
    'field_reg_legacy_sports_update',
  ];
}

/** Groups widgets without changing their parents, storage or submitted values. */
function reg_core_news_editor_form(array &$form): void {
  reg_core_sports_news_form($form);
  $groups = [
    'basic' => ['Basic information', ['title', 'langcode', 'field_reg_news_category_term', 'field_reg_sports_sport', 'field_reg_team', 'field_reg_related_players']],
    'content' => ['Content', ['field_reg_summary', 'body']],
    'media' => ['Media', ['field_reg_featured_image', 'field_reg_image_caption', 'field_reg_news_gallery', 'field_reg_video_media']],
    'publishing' => ['Publishing', ['field_reg_content_department', 'field_reg_publication_date', 'field_reg_featured', 'field_reg_homepage_priority', 'field_tags', 'field_reg_related_news', 'moderation_state', 'revision_log']],
    'advanced' => ['Advanced', []],
    'attribution' => ['Additional attribution', ['field_reg_author_source', 'field_reg_photo_credit']],
    'seo' => ['SEO', ['field_reg_meta_title', 'field_reg_meta_description', 'field_reg_canonical_url', 'path']],
    'translation' => ['Translation', ['field_reg_translation_requested', 'field_reg_translation_priority', 'field_reg_translation_review', 'field_reg_related_translation', 'field_reg_translation_group']],
    'governance' => ['Content governance', ['field_reg_last_verified', 'field_reg_review_status']],
    'migration' => ['Migration metadata', reg_core_news_migration_fields()],
  ];
  $advanced = FALSE;
  $weight = -50;
  foreach ($groups as $key => [$title, $fields]) {
    $name = 'reg_news_' . $key;
    $form[$name] = ['#type' => 'details', '#title' => t($title), '#open' => !$advanced && $key !== 'advanced', '#weight' => $weight++];
    if ($advanced) $form[$name]['#group'] = 'reg_news_advanced';
    if ($key === 'advanced') $advanced = TRUE;
    foreach ($fields as $delta => $field) {
      if (!isset($form[$field])) continue;
      $form[$field]['#group'] = $name;
      $form[$field]['#weight'] = $delta;
      $form['#reg_news_field_weights'][$field] = $delta;
    }
  }
  // EntityFormDisplay reapplies configured weights during processing. Run
  // after it so these presentation-only groups retain their editorial order.
  $form['#process'][] = 'reg_core_news_editor_form_order';
  $can_manage = \Drupal::currentUser()->hasPermission('manage reg migration metadata');
  $form['reg_news_migration']['#access'] = $can_manage;
  foreach (reg_core_news_migration_fields() as $field) {
    if (isset($form[$field]) && !$can_manage) $form[$field]['#access'] = FALSE;
  }
  $form['#cache']['contexts'][] = 'user.permissions';
  if (isset($form['field_reg_homepage_priority'])) {
    $form['field_reg_homepage_priority']['#states']['visible'] = [
      ':input[name="field_reg_featured[value]"]' => ['checked' => TRUE],
    ];
  }
  $copy = [
    'field_reg_summary' => ['Summary / Teaser', 'Short introduction used on homepage, listings and social previews.'],
    'body' => ['Body / Article Content', ''],
    'field_reg_featured_image' => ['Featured Image', 'Main image used for listings, article header and social sharing.'],
    'field_reg_news_gallery' => ['Photo Gallery', 'Optional additional photographs.'],
    'field_reg_video_media' => ['Video', 'Optional related video.'],
    'field_reg_content_department' => ['Publishing Department', 'Department responsible for publishing this article, for example Communications, EDCL, EUCL or Sports.'],
    'field_reg_author_source' => ['Content Source', 'Original author or approved source of the article, for example REG, EDCL, EUCL or External Source.'],
    'field_reg_publication_date' => ['Original Publication Date', 'Date the article was originally published.'],
    'field_reg_homepage_priority' => ['Homepage Priority', 'Higher values receive priority when several featured stories are eligible.'],
    'field_reg_meta_title' => ['Meta Title', 'Leave empty to use the News title.'],
    'field_reg_meta_description' => ['Meta Description', 'Leave empty to use the Summary / Teaser.'],
    'field_reg_canonical_url' => ['Canonical URL', 'Normally leave empty. The News article URL is generated automatically.'],
  ];
  foreach ($copy as $field => [$title, $description]) {
    if (!isset($form[$field]['widget'])) continue;
    // Single-value widgets put their label on the item; media and multiple
    // value widgets put it on the widget wrapper. Keep nested input labels.
    $widget = &$form[$field]['widget'];
    if (isset($widget['#title'])) {
      $widget['#title'] = t($title);
      $widget['#description'] = $description === '' ? '' : t($description);
    }
    if (isset($widget[0]['value']['#title'])) {
      $widget[0]['value']['#title'] = t($title);
      $widget[0]['value']['#description'] = $description === '' ? '' : t($description);
    }
    if (isset($widget[0]['#title'])) {
      $widget[0]['#title'] = t($title);
      $widget[0]['#description'] = $description === '' ? '' : t($description);
    }
    unset($widget);
  }
}

/** Restores editorial ordering after the form display processes its widgets. */
function reg_core_news_editor_form_order(array $form, \Drupal\Core\Form\FormStateInterface $form_state, array &$complete_form): array {
  foreach ($form['#reg_news_field_weights'] ?? [] as $field => $weight) {
    if (isset($form[$field])) $form[$field]['#weight'] = $weight;
  }
  $form['#sorted'] = FALSE;
  return $form;
}
