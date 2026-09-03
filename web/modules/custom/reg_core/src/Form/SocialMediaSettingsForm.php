<?php

namespace Drupal\reg_core\Form;

use Drupal\Component\Utility\Html;
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
          '#description' => $this->t('Shows cached public posts from the server-side X API integration beneath the secondary homepage news cards.'),
        ];
        $form['platforms'][$id]['api_enabled'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('X API enabled'),
          '#default_value' => (bool) ($values['api_enabled'] ?? FALSE),
          '#description' => $this->t('Requires the X_BEARER_TOKEN environment variable. The token is never stored in Drupal configuration.'),
        ];
        $form['platforms'][$id]['api_username'] = [
          '#type' => 'textfield',
          '#title' => $this->t('X account username'),
          '#default_value' => $values['api_username'] ?? 'reg_rwanda',
          '#maxlength' => 15,
          '#description' => $this->t('Enter the username without @.'),
        ];
        $form['platforms'][$id]['api_user_id'] = [
          '#type' => 'textfield',
          '#title' => $this->t('X user ID'),
          '#default_value' => $values['api_user_id'] ?? '',
          '#maxlength' => 30,
          '#description' => $this->t('Optional numeric ID. Leave empty to resolve and cache it from the username.'),
        ];
        $form['platforms'][$id]['api_cache_lifetime'] = [
          '#type' => 'number',
          '#title' => $this->t('Cache lifetime'),
          '#default_value' => (int) ($values['api_cache_lifetime'] ?? 900),
          '#min' => 300,
          '#max' => 86400,
          '#step' => 60,
          '#field_suffix' => $this->t('seconds'),
        ];
        $form['platforms'][$id]['api_homepage_post_count'] = [
          '#type' => 'number',
          '#title' => $this->t('Number of homepage posts'),
          '#default_value' => (int) ($values['api_homepage_post_count'] ?? 3),
          '#min' => 1,
          '#max' => 5,
        ];
        $form['platforms'][$id]['api_exclude_replies'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Exclude replies'),
          '#default_value' => !array_key_exists('api_exclude_replies', $values) || !empty($values['api_exclude_replies']),
        ];
        $form['platforms'][$id]['api_exclude_reposts'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Exclude reposts'),
          '#default_value' => !array_key_exists('api_exclude_reposts', $values) || !empty($values['api_exclude_reposts']),
        ];
      }
    }

    $status = \Drupal::service('reg_core.x_feed')->status();
    $last_success = $status['last_success']
      ? \Drupal::service('date.formatter')->format($status['last_success'], 'medium')
      : $this->t('Never');
    $last_error = $status['last_error'] !== '' ? Html::escape($status['last_error']) : $this->t('None');
    $form['x_api_status'] = [
      '#type' => 'details',
      '#title' => $this->t('X API status'),
      '#open' => TRUE,
      '#weight' => 10,
    ];
    $form['x_api_status']['account'] = [
      '#type' => 'item',
      '#title' => $this->t('X account'),
      '#markup' => Html::escape($status['account']),
    ];
    $form['x_api_status']['enabled'] = [
      '#type' => 'item',
      '#title' => $this->t('API enabled'),
      '#markup' => $status['enabled'] ? $this->t('Yes') : $this->t('No'),
    ];
    $form['x_api_status']['credential'] = [
      '#type' => 'item',
      '#title' => $this->t('API credential status'),
      '#markup' => $status['configured'] ? $this->t('Configured') : $this->t('Not configured'),
    ];
    $form['x_api_status']['last_success'] = [
      '#type' => 'item',
      '#title' => $this->t('Last successful refresh'),
      '#markup' => $last_success,
    ];
    $form['x_api_status']['cached_posts'] = [
      '#type' => 'item',
      '#title' => $this->t('Cached posts'),
      '#markup' => (string) $status['cached_posts'],
    ];
    $form['x_api_status']['last_error'] = [
      '#type' => 'item',
      '#title' => $this->t('Last error'),
      '#markup' => $last_error,
    ];
    $form['x_api_status']['refresh'] = [
      '#type' => 'submit',
      '#value' => $this->t('Refresh X feed'),
      '#submit' => ['::refreshXFeed'],
      '#limit_validation_errors' => [],
      '#description' => $this->t('Uses saved settings and performs at most one controlled API refresh.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $values = (array) $form_state->getValue(['platforms', 'x']);
    $username = ltrim(trim((string) ($values['api_username'] ?? '')), '@');
    if (!preg_match('/^[A-Za-z0-9_]{1,15}$/', $username)) {
      $form_state->setErrorByName(
        'platforms][x][api_username',
        $this->t('Enter a valid X username using up to 15 letters, numbers, or underscores.'),
      );
    }
    $user_id = trim((string) ($values['api_user_id'] ?? ''));
    if ($user_id !== '' && !preg_match('/^\d+$/', $user_id)) {
      $form_state->setErrorByName(
        'platforms][x][api_user_id',
        $this->t('The optional X user ID must contain numbers only.'),
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory->getEditable('reg_core.social_media');
    foreach (array_keys(self::PLATFORMS) as $id) {
      $values = (array) $form_state->getValue(['platforms', $id]);
      $platform_config = [
        'url' => trim((string) ($values['url'] ?? '')),
        'display_name' => $id === 'x' ? trim((string) ($values['display_name'] ?? '')) : '',
        'enabled' => (bool) ($values['enabled'] ?? FALSE),
        'show_utility' => (bool) ($values['show_utility'] ?? FALSE),
        'show_footer' => (bool) ($values['show_footer'] ?? FALSE),
        'show_homepage_feed' => $id === 'x' ? (bool) ($values['show_homepage_feed'] ?? FALSE) : FALSE,
      ];
      if ($id === 'x') {
        $platform_config += [
          'api_enabled' => (bool) ($values['api_enabled'] ?? FALSE),
          'api_username' => ltrim(trim((string) ($values['api_username'] ?? 'reg_rwanda')), '@'),
          'api_user_id' => trim((string) ($values['api_user_id'] ?? '')),
          'api_cache_lifetime' => max(300, min(86400, (int) ($values['api_cache_lifetime'] ?? 900))),
          'api_homepage_post_count' => max(1, min(5, (int) ($values['api_homepage_post_count'] ?? 3))),
          'api_exclude_replies' => (bool) ($values['api_exclude_replies'] ?? TRUE),
          'api_exclude_reposts' => (bool) ($values['api_exclude_reposts'] ?? TRUE),
        ];
      }
      $config->set('platforms.' . $id, $platform_config);
    }
    $config->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Performs a controlled manual refresh using saved configuration.
   */
  public function refreshXFeed(array &$form, FormStateInterface $form_state): void {
    $result = \Drupal::service('reg_core.x_feed')->homepageFeed(TRUE);
    if ($result['items'] !== []) {
      $this->messenger()->addStatus($this->t('The X feed cache contains @count posts.', [
        '@count' => count($result['items']),
      ]));
    }
    else {
      $this->messenger()->addWarning($this->t('No X posts were refreshed. The homepage fallback remains active.'));
    }
    $form_state->setRebuild();
  }

}
