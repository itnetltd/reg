<?php

/**
 * @file
 * Native, workflow-managed social-post content model helpers.
 */

/**
 * Creates the native social-post bundle and editorial configuration.
 */
function reg_core_apply_social_posts(): void {
  \Drupal::service('module_installer')->install([
    'image',
    'responsive_image',
    'media',
    'media_library',
    'language',
    'content_translation',
    'workflows',
    'content_moderation',
  ], TRUE);

  $type = \Drupal\node\Entity\NodeType::load('reg_social_post');
  if (!$type) {
    $type = \Drupal\node\Entity\NodeType::create([
      'type' => 'reg_social_post',
      'name' => 'Social post',
      'description' => 'Approved native social content for homepage and future Media Center presentation.',
      'new_revision' => TRUE,
      'preview_mode' => 0,
      'display_submitted' => FALSE,
    ]);
    $type->save();
  }

  $definitions = [
    'field_reg_social_platform' => [
      'type' => 'list_string',
      'label' => 'Platform',
      'required' => TRUE,
      'translatable' => FALSE,
      'storage_settings' => ['allowed_values' => [
        'x' => 'X',
        'facebook' => 'Facebook',
        'youtube' => 'YouTube',
        'other' => 'Other approved platform',
      ]],
      'widget' => 'options_select',
      'formatter' => 'list_default',
    ],
    'field_reg_social_post_url' => [
      'type' => 'link',
      'label' => 'Post URL',
      'description' => 'For X, use the official https://x.com/account/status/123 URL.',
      'required' => TRUE,
      'translatable' => FALSE,
      'field_settings' => ['link_type' => \Drupal\link\LinkItemInterface::LINK_EXTERNAL, 'title' => DRUPAL_DISABLED],
      'widget' => 'link_default',
      'formatter' => 'link',
    ],
    'field_reg_social_post_id' => array_replace(reg_core_string_field('Post ID', FALSE), [
      'description' => 'Optional platform identifier reserved for a future approved API importer.',
    ]),
    'field_reg_social_post_text' => array_replace(reg_core_long_text_field('Post text'), [
      'required' => TRUE,
      'description' => 'Paste only approved public copy. The homepage clamps this text to three lines.',
    ]),
    'field_reg_social_published_at' => [
      'type' => 'datetime',
      'label' => 'Publication date and time',
      'required' => TRUE,
      'translatable' => FALSE,
      'storage_settings' => ['datetime_type' => 'datetime'],
      'widget' => 'datetime_default',
      'formatter' => 'datetime_default',
    ],
    'field_reg_social_image' => array_replace(reg_core_media_reference_field('Image / thumbnail', 1, FALSE, 'image'), [
      'description' => 'Optional approved image shown as a small homepage thumbnail.',
      'translatable' => FALSE,
    ]),
    'field_reg_social_video_thumb' => array_replace(reg_core_media_reference_field('Video thumbnail', 1, FALSE, 'image'), [
      'description' => 'Optional future native video thumbnail; no video is embedded on the homepage.',
      'translatable' => FALSE,
    ]),
    'field_reg_social_author_name' => reg_core_string_field('Author display name'),
    'field_reg_social_author_handle' => reg_core_string_field('Author handle', FALSE),
    'field_reg_social_homepage' => array_replace(reg_core_boolean_field('Featured on homepage', FALSE), [
      'description' => 'Only published, featured X posts are eligible for the homepage.',
    ]),
    'field_reg_social_order' => array_replace(reg_core_integer_field('Display order', FALSE), [
      'description' => 'Lower numbers appear first; equal values fall back to latest publication date.',
    ]),
    'field_reg_social_source' => [
      'type' => 'list_string',
      'label' => 'Source type',
      'required' => TRUE,
      'translatable' => FALSE,
      'storage_settings' => ['allowed_values' => [
        'manual' => 'Manual Drupal entry',
        'official_api' => 'Official platform API',
      ]],
      'widget' => 'options_select',
      'formatter' => 'list_default',
    ],
  ];

  $weight = -20;
  foreach ($definitions as $field_name => $definition) {
    reg_core_ensure_field('reg_social_post', $field_name, $definition, $weight);
    $weight += 2;
  }

  $defaults = [
    'field_reg_social_platform' => 'x',
    'field_reg_social_author_name' => 'Rwanda Energy Group',
    'field_reg_social_author_handle' => '@reg_rwanda',
    'field_reg_social_homepage' => 1,
    'field_reg_social_order' => 0,
    'field_reg_social_source' => 'manual',
  ];
  foreach ($defaults as $field_name => $value) {
    $field = \Drupal\field\Entity\FieldConfig::loadByName('node', 'reg_social_post', $field_name);
    if ($field) {
      $field->setDefaultValue($value)->save();
    }
  }

  $title = \Drupal\Core\Field\Entity\BaseFieldOverride::loadByName('node', 'reg_social_post', 'title')
    ?: \Drupal\Core\Field\Entity\BaseFieldOverride::createFromBaseFieldDefinition(
      \Drupal::service('entity_field.manager')->getBaseFieldDefinitions('node')['title'],
      'reg_social_post',
    );
  $title->setLabel('Administrative title')->setDescription('Internal label for editors; public cards use Post Text.')->save();

  \Drupal\language\Entity\ContentLanguageSettings::loadByEntityTypeBundle('node', 'reg_social_post')
    ->setDefaultLangcode('site_default')
    ->setLanguageAlterable(TRUE)
    ->setThirdPartySetting('content_translation', 'enabled', TRUE)
    ->save();

  reg_core_configure_social_post_displays(array_keys($definitions));
  reg_core_configure_social_post_workflow();
  reg_core_create_roles();
}

/**
 * Orders the editor form and prevents raw fields becoming a public page.
 */
function reg_core_configure_social_post_displays(array $field_names): void {
  $form = \Drupal\Core\Entity\Entity\EntityFormDisplay::load('node.reg_social_post.default')
    ?: \Drupal\Core\Entity\Entity\EntityFormDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'reg_social_post',
      'mode' => 'default',
      'status' => TRUE,
    ]);
  $components = [
    'title' => ['string_textfield', -30],
    'langcode' => ['language_select', -28],
    'field_reg_social_platform' => ['options_select', -26],
    'field_reg_social_post_url' => ['link_default', -24],
    'field_reg_social_post_id' => ['string_textfield', -22],
    'field_reg_social_post_text' => ['text_textarea', -20],
    'field_reg_social_published_at' => ['datetime_default', -18],
    'field_reg_social_image' => ['media_library_widget', -16],
    'field_reg_social_video_thumb' => ['media_library_widget', -14],
    'field_reg_social_author_name' => ['string_textfield', -12],
    'field_reg_social_author_handle' => ['string_textfield', -10],
    'field_reg_social_homepage' => ['boolean_checkbox', -8],
    'field_reg_social_order' => ['number', -6],
    'field_reg_social_source' => ['options_select', -4],
    'moderation_state' => ['moderation_state_default', 90],
    'revision_log' => ['string_textarea', 92],
  ];
  foreach ($components as $name => [$widget, $weight]) {
    $form->setComponent($name, ['type' => $widget, 'weight' => $weight, 'settings' => []]);
  }
  foreach (['uid', 'created', 'status', 'promote', 'sticky', 'path'] as $hidden) {
    $form->removeComponent($hidden);
  }
  $form->save();

  $view = \Drupal\Core\Entity\Entity\EntityViewDisplay::load('node.reg_social_post.default')
    ?: \Drupal\Core\Entity\Entity\EntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'reg_social_post',
      'mode' => 'default',
      'status' => TRUE,
    ]);
  foreach (array_merge($field_names, ['links']) as $name) {
    $view->removeComponent($name);
  }
  $view->save();
}

/**
 * Adds four-state editorial governance for social posts.
 */
function reg_core_configure_social_post_workflow(): void {
  $workflow = \Drupal\workflows\Entity\Workflow::load('reg_social_post_editorial');
  if (!$workflow) {
    $workflow = \Drupal\workflows\Entity\Workflow::create([
      'id' => 'reg_social_post_editorial',
      'label' => 'REG Social Post Editorial',
      'type' => 'content_moderation',
      'type_settings' => [
        'states' => [
          'draft' => ['label' => 'Draft', 'published' => FALSE, 'default_revision' => FALSE, 'weight' => 0],
          'needs_review' => ['label' => 'Needs review', 'published' => FALSE, 'default_revision' => FALSE, 'weight' => 5],
          'published' => ['label' => 'Published', 'published' => TRUE, 'default_revision' => TRUE, 'weight' => 10],
          'archived' => ['label' => 'Archived', 'published' => FALSE, 'default_revision' => TRUE, 'weight' => 20],
        ],
        'transitions' => [
          'create_new_draft' => ['label' => 'Save as Draft', 'from' => ['draft', 'needs_review', 'published', 'archived'], 'to' => 'draft', 'weight' => 0],
          'submit_for_review' => ['label' => 'Submit for review', 'from' => ['draft'], 'to' => 'needs_review', 'weight' => 5],
          'return_to_draft' => ['label' => 'Return to draft', 'from' => ['needs_review'], 'to' => 'draft', 'weight' => 8],
          'publish' => ['label' => 'Publish', 'from' => ['needs_review', 'published'], 'to' => 'published', 'weight' => 10],
          'archive' => ['label' => 'Archive', 'from' => ['published', 'archived'], 'to' => 'archived', 'weight' => 20],
        ],
        'entity_types' => [],
        'default_moderation_state' => 'draft',
      ],
    ]);
  }
  $moderation = $workflow->getTypePlugin();
  if ($moderation instanceof \Drupal\content_moderation\Plugin\WorkflowType\ContentModerationInterface) {
    $moderation->addEntityTypeAndBundle('node', 'reg_social_post');
  }
  $workflow->save();
}
