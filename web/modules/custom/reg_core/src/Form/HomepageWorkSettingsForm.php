<?php

namespace Drupal\reg_core\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configures the CMS-driven What We Do section on the REG homepage.
 */
final class HomepageWorkSettingsForm extends ConfigFormBase {

  private const DEFAULT_ORDER = ['generation', 'transmission', 'distribution', 'access'];

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reg_core_homepage_work_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['reg_core.homepage_work'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('reg_core.homepage_work');
    $form['intro_text'] = [
      '#type' => 'details',
      '#title' => $this->t('Section introduction'),
      '#open' => TRUE,
    ];
    $fields = [
      'eyebrow' => [$this->t('Section label'), 'What We Do', 100],
      'heading' => [$this->t('Heading'), 'Powering Rwanda at every stage', 255],
      'intro' => [$this->t('Introduction'), 'Explore how REG supports generation, transmission, distribution and electricity access.', 400],
      'cta_label' => [$this->t('View-all link text'), 'Explore all', 100],
      'card_cta_label' => [$this->t('Card link text'), 'Explore', 100],
    ];
    foreach ($fields as $key => [$title, $default, $max_length]) {
      $form['intro_text'][$key] = [
        '#type' => $key === 'intro' ? 'textarea' : 'textfield',
        '#title' => $title,
        '#default_value' => $config->get($key) ?? $default,
        '#maxlength' => $max_length,
        '#required' => $key !== 'intro',
      ];
      if ($key === 'intro') {
        $form['intro_text'][$key]['#rows'] = 3;
      }
    }

    $form['cards'] = [
      '#type' => 'details',
      '#title' => $this->t('Homepage card order'),
      '#open' => TRUE,
      '#description' => $this->t('The four cards take their titles, descriptions, images and destination URLs from published What We Do pages. Edit those items under Content. Use the selectors below to choose their display order.'),
    ];
    $order = (array) ($config->get('order') ?: self::DEFAULT_ORDER);
    $options = [
      'generation' => $this->t('Generation'),
      'transmission' => $this->t('Transmission'),
      'distribution' => $this->t('Distribution'),
      'access' => $this->t('Electricity Access'),
    ];
    for ($i = 0; $i < count(self::DEFAULT_ORDER); $i++) {
      $form['cards']['position_' . $i] = [
        '#type' => 'select',
        '#title' => $this->t('Card position @position', ['@position' => $i + 1]),
        '#options' => $options,
        '#default_value' => $order[$i] ?? self::DEFAULT_ORDER[$i],
        '#required' => TRUE,
      ];
    }

    $form['pictures'] = [
      '#type' => 'details',
      '#title' => $this->t('Homepage card pictures'),
      '#open' => TRUE,
      '#description' => $this->t('Select a published public Image from Drupal Media for each card. Leave a picture blank to use its existing What We Do page image. To upload a new image first, open Content → Media → Add media.'),
    ];
    $saved_images = (array) ($config->get('image_overrides') ?? []);
    $saved_modes = (array) ($config->get('image_modes') ?? []);
    $media_storage = \Drupal::entityTypeManager()->getStorage('media');
    foreach ($options as $key => $title) {
      $existing = NULL;
      if (!empty($saved_images[$key])) {
        $matching = $media_storage->loadByProperties(['uuid' => $saved_images[$key]]);
        $existing = reset($matching) ?: NULL;
      }
      $form['pictures']['picture_' . $key] = [
        '#type' => 'entity_autocomplete',
        '#title' => $this->t('@name picture', ['@name' => $title]),
        '#target_type' => 'media',
        '#selection_handler' => 'default',
        '#selection_settings' => ['target_bundles' => ['image']],
        '#default_value' => $existing,
        '#required' => FALSE,
        '#description' => $this->t('Choose an existing public Image Media item. Clear this field to return to the picture selected on the What We Do page.'),
      ];
      $form['pictures']['picture_mode_' . $key] = [
        '#type' => 'select',
        '#title' => $this->t('@name image display', ['@name' => $title]),
        '#options' => [
          'default' => $this->t('Use What We Do page setting'),
          'cover' => $this->t('Cover — crop photograph to fill the card'),
          'contain' => $this->t('Contain — show complete diagram or chart'),
        ],
        '#default_value' => $saved_modes[$key] ?? 'default',
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $order = [];
    for ($i = 0; $i < count(self::DEFAULT_ORDER); $i++) {
      $order[] = $form_state->getValue('position_' . $i);
    }
    if (count(array_unique($order)) !== count(self::DEFAULT_ORDER)) {
      $form_state->setErrorByName('cards', $this->t('Each What We Do card must appear exactly once. Choose a different item for each position.'));
    }

    $storage = \Drupal::entityTypeManager()->getStorage('media');
    foreach (self::DEFAULT_ORDER as $key) {
      $id = $form_state->getValue('picture_' . $key);
      if (!$id) {
        continue;
      }
      $media = $storage->load($id);
      if (!$media || $media->bundle() !== 'image' || !$media->isPublished() || !$media->access('view')) {
        $form_state->setErrorByName('picture_' . $key, $this->t('Choose a published Image Media item.'));
        continue;
      }
      $source_field = $media->getSource()->getConfiguration()['source_field'] ?? '';
      $file = $source_field && $media->hasField($source_field) ? $media->get($source_field)->entity : NULL;
      if (!$file instanceof \Drupal\file\FileInterface || !str_starts_with($file->getFileUri(), 'public://')) {
        $form_state->setErrorByName('picture_' . $key, $this->t('Homepage pictures must use publicly accessible Drupal image files.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('reg_core.homepage_work');
    foreach (['eyebrow', 'heading', 'intro', 'cta_label', 'card_cta_label'] as $key) {
      $config->set($key, trim((string) $form_state->getValue($key)));
    }
    $order = [];
    for ($i = 0; $i < count(self::DEFAULT_ORDER); $i++) {
      $order[] = $form_state->getValue('position_' . $i);
    }
    $images = [];
    $modes = [];
    $storage = \Drupal::entityTypeManager()->getStorage('media');
    foreach (self::DEFAULT_ORDER as $key) {
      $id = $form_state->getValue('picture_' . $key);
      $media = $id ? $storage->load($id) : NULL;
      $images[$key] = $media ? $media->uuid() : '';
      $modes[$key] = in_array($form_state->getValue('picture_mode_' . $key), ['cover', 'contain'], TRUE)
        ? $form_state->getValue('picture_mode_' . $key)
        : 'default';
    }
    $config->set('order', $order)
      ->set('image_overrides', $images)
      ->set('image_modes', $modes)
      ->save();
    parent::submitForm($form, $form_state);
  }

}
