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
    $config->set('order', $order)->save();
    parent::submitForm($form, $form_state);
  }

}
