<?php

/**
 * @file
 * Editor-controlled targets for content-managed menu links.
 */

use Drupal\Core\Form\FormStateInterface;
use Drupal\menu_link_content\MenuLinkContentInterface;

/**
 * Implements hook_form_BASE_FORM_ID_alter() for menu_link_content_form.
 */
function reg_core_form_menu_link_content_form_alter(array &$form, FormStateInterface $form_state): void {
  // Defer to a dedicated attributes widget if one is installed in the future.
  $modules = \Drupal::moduleHandler();
  if ($modules->moduleExists('link_attributes') || $modules->moduleExists('menu_link_attributes')) {
    return;
  }
  $entity = $form_state->getFormObject()->getEntity();
  if (!$entity instanceof MenuLinkContentInterface) {
    return;
  }
  $options = $entity->get('link')->first()?->options ?? [];
  $form_state->set('reg_menu_link_options', $options);
  $form['reg_open_in_new_tab'] = [
    '#type' => 'checkbox',
    '#title' => t('Open in new tab'),
    '#description' => t('Leave unchecked to open this link in the current tab.'),
    '#default_value' => ($options['attributes']['target'] ?? '') === '_blank',
    '#weight' => -1.5,
  ];
  $form['#entity_builders'][] = 'reg_core_menu_link_target_builder';
}

/**
 * Copies the checkbox into the actual menu link, preserving other URL options.
 */
function reg_core_menu_link_target_builder(string $entity_type, MenuLinkContentInterface $entity, array &$form, FormStateInterface $form_state): void {
  if (!$form_state->hasValue('reg_open_in_new_tab') || $entity->get('link')->isEmpty()) {
    return;
  }
  $link = $entity->get('link')->first();
  $options = array_replace_recursive($form_state->get('reg_menu_link_options') ?? [], $link->options ?? []);
  if ($form_state->getValue('reg_open_in_new_tab')) {
    $options['attributes']['target'] = '_blank';
    $rel = $options['attributes']['rel'] ?? '';
    $rel = is_array($rel) ? $rel : preg_split('/\s+/', trim($rel));
    $rel = array_filter($rel, static fn ($token) => $token !== '' && strtolower($token) !== 'opener');
    $rel[] = 'noopener';
    $options['attributes']['rel'] = implode(' ', array_unique($rel));
  }
  else {
    unset($options['attributes']['target']);
  }
  $link->options = $options;
}
