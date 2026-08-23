<?php

namespace Drupal\reg_core\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configures official REG social media destinations and placements.
 */
final class SocialMediaSettingsForm extends ConfigFormBase {

  /**
   * Supported platforms in administration display order.
   */
  private const PLATFORMS = [
    'x' => 'X / Twitter',
    'facebook' => 'Facebook',
    'youtube' => 'YouTube',
    'instagram' => 'Instagram',
    'linkedin' => 'LinkedIn',
  ];

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reg_core_social_media_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['reg_core.social_media'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('reg_core.social_media');
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Add only verified official REG profile URLs. A platform remains hidden everywhere when it is disabled or its URL is empty.') . '</p>',
    ];
    $form['platforms'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];

    foreach (self::PLATFORMS as $id => $label) {
      $values = $config->get('platforms.' . $id) ?: [];
      $form['platforms'][$id] = [
        '#type' => 'details',
        '#title' => $this->t('@platform settings', ['@platform' => $label]),
        '#open' => TRUE,
      ];
      $form['platforms'][$id]['url'] = [
        '#type' => 'url',
        '#title' => $this->t('Official profile URL'),
        '#default_value' => $values['url'] ?? '',
        '#description' => $this->t('Use the complete https:// URL for the verified official account.'),
      ];
      $form['platforms'][$id]['enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enabled'),
        '#default_value' => (bool) ($values['enabled'] ?? FALSE),
      ];
      $form['platforms'][$id]['show_utility'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show in utility bar'),
        '#default_value' => (bool) ($values['show_utility'] ?? FALSE),
      ];
      $form['platforms'][$id]['show_footer'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show in footer'),
        '#default_value' => (bool) ($values['show_footer'] ?? FALSE),
      ];
      if ($id === 'x') {
        $form['platforms'][$id]['display_name'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Official display name'),
          '#default_value' => $values['display_name'] ?? 'Rwanda Energy Group',
          '#maxlength' => 120,
          '#weight' => -1,
        ];
        $form['platforms'][$id]['show_homepage_feed'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Show X feed on homepage'),
          '#default_value' => (bool) ($values['show_homepage_feed'] ?? TRUE),
          '#description' => $this->t('Shows up to two native, published X post records beneath the secondary homepage news cards.'),
        ];
      }
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory->getEditable('reg_core.social_media');
    foreach (array_keys(self::PLATFORMS) as $id) {
      $values = (array) $form_state->getValue(['platforms', $id]);
      $config->set('platforms.' . $id, [
        'url' => trim((string) ($values['url'] ?? '')),
        'display_name' => $id === 'x' ? trim((string) ($values['display_name'] ?? '')) : '',
        'enabled' => (bool) ($values['enabled'] ?? FALSE),
        'show_utility' => (bool) ($values['show_utility'] ?? FALSE),
        'show_footer' => (bool) ($values['show_footer'] ?? FALSE),
        'show_homepage_feed' => $id === 'x' ? (bool) ($values['show_homepage_feed'] ?? FALSE) : FALSE,
      ]);
    }
    $config->save();
    parent::submitForm($form, $form_state);
  }

}
