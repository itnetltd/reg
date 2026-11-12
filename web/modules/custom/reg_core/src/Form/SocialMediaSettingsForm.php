<?php

namespace Drupal\reg_core\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\reg_core\Social\SocialMediaManager;

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
    'flickr' => 'Flickr',
    'tiktok' => 'TikTok',
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
    $form['homepage_rotation'] = [
      '#type' => 'details', '#title' => $this->t('Homepage social card rotation'), '#open' => TRUE, '#tree' => TRUE,
    ];
    $form['homepage_rotation']['enabled'] = [
      '#type' => 'checkbox', '#title' => $this->t('Enable automatic homepage social rotation'),
      '#default_value' => (bool) ($config->get('homepage_rotation.enabled') ?? TRUE),
      '#description' => $this->t('Both visible cards switch together. Manual controls remain available when automatic rotation is disabled. Motion pauses on hover, focus, hidden tabs and reduced-motion preferences.'),
    ];
    $form['homepage_rotation']['interval'] = [
      '#type' => 'number', '#title' => $this->t('Social rotation interval'), '#min' => 3, '#max' => 60, '#required' => TRUE,
      '#default_value' => (int) ($config->get('homepage_rotation.interval') ?? 5), '#field_suffix' => $this->t('seconds'),
      '#description' => $this->t('Default: 5 seconds. The independent middle news rotation remains at 4 seconds.'),
    ];
    $form['homepage_rotation']['posts'] = [
      '#type' => 'link', '#title' => $this->t('Manage approved Drupal social posts'), '#url' => Url::fromRoute('reg_core.social_posts_admin'),
      '#access' => Url::fromRoute('reg_core.social_posts_admin')->access(),
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
      if (isset(SocialMediaManager::HOMEPAGE_PLATFORMS[$id])) {
        $form['platforms'][$id]['display_name'] = [
          '#type' => 'textfield', '#title' => $this->t('Official display name'), '#maxlength' => 120,
          '#default_value' => $values['display_name'] ?? '', '#description' => $this->t('Leave empty to use Rwanda Energy Group.'),
        ];
        $form['platforms'][$id]['show_homepage_card'] = [
          '#type' => 'checkbox', '#title' => $this->t('Include in homepage social cards'),
          '#default_value' => (bool) ($values['show_homepage_card'] ?? TRUE),
        ];
        $form['platforms'][$id]['homepage_order'] = [
          '#type' => 'number', '#title' => $this->t('Homepage display order'), '#min' => -100, '#max' => 100,
          '#default_value' => (int) ($values['homepage_order'] ?? array_search($id, array_keys(SocialMediaManager::HOMEPAGE_PLATFORMS), TRUE) * 10),
          '#description' => $this->t('Lower numbers appear first. Default pairs: X / YouTube; Instagram / Facebook; TikTok / LinkedIn. Disabled or invalid accounts are skipped. An odd final pair reuses the first enabled account so both slots stay filled. This order does not change the header or footer.'),
        ];
      }
      if ($id === 'tiktok') {
        // TikTok is introduced for this homepage feature only.
        $form['platforms'][$id]['show_utility']['#access'] = FALSE;
        $form['platforms'][$id]['show_footer']['#access'] = FALSE;
      }
      if ($id === 'youtube') {
        $form['platforms'][$id]['channel_id'] = [
          '#type' => 'textfield',
          '#title' => $this->t('YouTube Channel ID'),
          '#default_value' => $values['channel_id'] ?? '',
          '#maxlength' => 24,
          '#description' => $this->t('Verified channel ID: UC followed by 22 letters, numbers, underscores or hyphens. The public uploads feed needs no key; optional YOUTUBE_API_KEY must remain in the server environment.'),
        ];
        $form['platforms'][$id]['videos_enabled'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Videos enabled'),
          '#default_value' => (bool) ($values['videos_enabled'] ?? FALSE),
        ];
      }
      if ($id === 'youtube') {
        $form['platforms'][$id]['feed_cache_lifetime'] = [
          '#type' => 'select',
          '#title' => $this->t('YouTube refresh interval'),
          '#options' => [1800 => $this->t('30 minutes'), 3600 => $this->t('60 minutes')],
          '#default_value' => (int) ($values['feed_cache_lifetime'] ?? 3600),
          '#description' => $this->t('Drupal cron refreshes due feeds. Failed requests retry after five minutes and retain the last verified result.'),
        ];
      }
      if ($id === 'flickr') {
        $form['platforms'][$id]['user_id'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Flickr User ID'),
          '#default_value' => $values['user_id'] ?? '',
          '#maxlength' => 100,
          '#description' => $this->t('Used by the public Flickr feed. Do not enter API credentials here.'),
        ];
        $form['platforms'][$id]['album_id'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Flickr Gallery/Album ID'),
          '#default_value' => $values['album_id'] ?? '',
          '#maxlength' => 100,
          '#description' => $this->t('Optional. Leave empty to use the account public photo feed.'),
        ];
        $form['platforms'][$id]['gallery_enabled'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Photo Gallery enabled'),
          '#default_value' => (bool) ($values['gallery_enabled'] ?? FALSE),
        ];
      }
      if ($id === 'x') {
        $form['platforms'][$id]['feed_mode'] = [
          '#type' => 'select',
          '#title' => $this->t('Homepage X feed source'),
          '#options' => [
            'api' => $this->t('Option 1 — X API (paid credits)'),
            'embed' => $this->t('Option 2 — Free X Embedded Timeline'),
          ],
          '#default_value' => in_array($values['feed_mode'] ?? '', ['api', 'embed'], TRUE) ? $values['feed_mode'] : 'embed',
          '#description' => $this->t('Free Embed uses the official X widget without API credits. Visitors click to load posts from X. Paid API requires X_BEARER_TOKEN and X API credits. Changing the source does not remove your paid API settings.'),
        ];
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
          '#description' => $this->t('Shows the selected X feed source in the homepage social column.'),
        ];
        $form['platforms'][$id]['api_enabled'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('X API enabled'),
          '#default_value' => (bool) ($values['api_enabled'] ?? FALSE),
          '#description' => $this->t('Used only when Option 1 (paid API) is selected. Requires X_BEARER_TOKEN and API credits. Token is never stored in Drupal configuration.'),
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
          '#default_value' => (int) ($values['api_homepage_post_count'] ?? 4),
          '#min' => 3,
          '#max' => 4,
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
    $saved_x = (array) ($config->get('platforms.x') ?? []);
    $form['x_api_status']['refresh'] = [
      '#type' => 'submit',
      '#access' => ($saved_x['feed_mode'] ?? 'embed') === 'api',
      '#value' => $this->t('Refresh X feed'),
      '#submit' => ['::refreshXFeed'],
      '#limit_validation_errors' => [],
      '#description' => $this->t('Uses saved settings and performs at most one controlled API refresh.'),
    ];

    $youtube = \Drupal::service('reg_core.youtube_feed')->status();
    $form['youtube_status'] = [
      '#type' => 'details', '#title' => $this->t('YouTube feed status'), '#open' => TRUE, '#weight' => 11,
    ];
    foreach ([
      'channel_id' => $this->t('Configured channel ID'),
      'last_success' => $this->t('Last successful refresh'),
      'last_error' => $this->t('Last error'),
      'source' => $this->t('Last successful source'),
    ] as $key => $label) {
      $value = $youtube[$key];
      if ($key === 'last_success') {
        $value = $value ? \Drupal::service('date.formatter')->format($value, 'medium') : $this->t('Never');
      }
      $form['youtube_status'][$key] = ['#type' => 'item', '#title' => $label, '#plain_text' => (string) ($value ?: $this->t('None'))];
    }
    $form['youtube_status']['enabled'] = ['#type' => 'item', '#title' => $this->t('Feed enabled'), '#markup' => $youtube['enabled'] ? $this->t('Yes') : $this->t('No')];
    $form['youtube_status']['api'] = ['#type' => 'item', '#title' => $this->t('Optional YouTube API credential'), '#markup' => $youtube['api_configured'] ? $this->t('Configured') : $this->t('Not configured; public uploads feed will be used')];
    $automatic_cron = (int) $this->configFactory->get('automated_cron.settings')->get('interval');
    $form['youtube_status']['cron'] = [
      '#type' => 'item', '#title' => $this->t('Cron scheduling'),
      '#markup' => $automatic_cron > 0
        ? $this->t('Automatic cron is enabled. Run cron at least every five minutes for timely feed refreshes.')
        : $this->t('Automatic cron is disabled. Schedule Drupal cron at least every five minutes; due page requests can also refresh feeds.'),
    ];
    $form['youtube_status']['refresh'] = [
      '#type' => 'submit', '#value' => $this->t('Refresh YouTube feed'), '#submit' => ['::refreshYouTubeFeed'], '#limit_validation_errors' => [],
      '#description' => $this->t('Refreshes once using saved settings. Save configuration changes before refreshing.'),
    ];

    $summary = \Drupal::service('reg_core.social_media_manager')->homepageSocialCards();
    $rows = [];
    foreach ($summary['cards'] as $card) {
      $post = $card['post'];
      $source = match ($card['id']) {
        'x' => $status['cached_posts'] ? $this->t('Existing cached X API feed') : ($post ? $this->t('Approved Drupal social post') : $this->t('Official profile only; see X diagnostics below')),
        'youtube' => $youtube['last_success'] ? $this->t('Existing YouTube feed; see refresh status below') : $this->t('Official channel only; see YouTube diagnostics below'),
        default => $post ? $this->t('Approved Drupal social post') : $this->t('Official profile only; no approved homepage post available'),
      };
      $rows[] = [$card['label'], $source, $post['date'] ?? $this->t('See feed status / not available')];
    }
    $form['homepage_status'] = [
      '#type' => 'details', '#title' => $this->t('Homepage social content status'), '#open' => TRUE,
      'summary' => ['#type' => 'table', '#header' => [$this->t('Platform'), $this->t('Content source'), $this->t('Publication date')], '#rows' => $rows, '#empty' => $this->t('No enabled, correctly configured homepage accounts.')],
      'help' => ['#markup' => '<p>' . $this->t('Instagram, Facebook, TikTok and LinkedIn use published homepage-featured posts approved through the existing Social Post Editorial workflow. Confirm each permalink belongs to the official account. Changes appear automatically after saving/publishing in Drupal; no scraper or provider login is used. X and YouTube keep their existing automatic cache/refresh behavior. TikTok stays disabled until an official profile is supplied.') . '</p>'],
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    foreach (array_keys(SocialMediaManager::HOMEPAGE_PLATFORMS) as $id) {
      $platform = (array) $form_state->getValue(['platforms', $id]);
      $url = trim((string) ($platform['url'] ?? ''));
      if (($url !== '' || !empty($platform['enabled'])) && SocialMediaManager::validatedProfileUrl($id, $url) === '') {
        $form_state->setErrorByName('platforms][' . $id . '][url', $this->t('Enter a valid official HTTPS profile URL for @platform, or disable it and clear its URL.', ['@platform' => SocialMediaManager::HOMEPAGE_PLATFORMS[$id]]));
      }
    }
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
    $profile = parse_url(trim((string) ($values['url'] ?? '')));
    if (!empty($values['api_enabled']) && (!is_array($profile) || ($profile['scheme'] ?? '') !== 'https'
      || !in_array(strtolower($profile['host'] ?? ''), ['x.com', 'www.x.com', 'twitter.com', 'www.twitter.com'], TRUE)
      || isset($profile['user']) || isset($profile['pass']) || isset($profile['port'])
      || strcasecmp(trim($profile['path'] ?? '', '/'), $username) !== 0)) {
      $form_state->setErrorByName('platforms][x][api_username', $this->t('The API username must match the official HTTPS X profile URL.'));
    }
    $youtube = (array) $form_state->getValue(['platforms', 'youtube']);
    $channel = trim((string) ($youtube['channel_id'] ?? ''));
    if (($channel !== '' || !empty($youtube['videos_enabled'])) && !preg_match('/^UC[A-Za-z0-9_-]{22}$/D', $channel)) {
      $form_state->setErrorByName('platforms][youtube][channel_id', $this->t('Enter a verified channel ID beginning with UC followed by 22 letters, numbers, underscores or hyphens.'));
    }
    $flickr = (array) $form_state->getValue(['platforms', 'flickr']);
    foreach (['user_id', 'album_id'] as $field) {
      $value = trim((string) ($flickr[$field] ?? ''));
      if ($value !== '' && !preg_match('/^[A-Za-z0-9@._-]+$/', $value)) {
        $form_state->setErrorByName(
          'platforms][flickr][' . $field,
          $this->t('Use only letters, numbers, periods, underscores, hyphens, or @.'),
        );
      }
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
      if (isset(SocialMediaManager::HOMEPAGE_PLATFORMS[$id])) {
        $platform_config['show_homepage_card'] = (bool) ($values['show_homepage_card'] ?? FALSE);
        $platform_config['homepage_order'] = max(-100, min(100, (int) ($values['homepage_order'] ?? 0)));
      }
      if ($id === 'x') {
        $platform_config += [
          'feed_mode' => in_array($values['feed_mode'] ?? '', ['api', 'embed'], TRUE) ? $values['feed_mode'] : 'embed',
          'api_enabled' => (bool) ($values['api_enabled'] ?? FALSE),
          'api_username' => ltrim(trim((string) ($values['api_username'] ?? 'reg_rwanda')), '@'),
          'api_user_id' => trim((string) ($values['api_user_id'] ?? '')),
          'api_cache_lifetime' => max(300, min(86400, (int) ($values['api_cache_lifetime'] ?? 900))),
          'api_homepage_post_count' => max(3, min(4, (int) ($values['api_homepage_post_count'] ?? 4))),
          'api_exclude_replies' => (bool) ($values['api_exclude_replies'] ?? TRUE),
          'api_exclude_reposts' => (bool) ($values['api_exclude_reposts'] ?? TRUE),
        ];
      }
      if ($id === 'youtube') {
        $platform_config += [
          'channel_id' => trim((string) ($values['channel_id'] ?? '')),
          'videos_enabled' => (bool) ($values['videos_enabled'] ?? FALSE),
          'feed_cache_lifetime' => max(1800, min(3600, (int) ($values['feed_cache_lifetime'] ?? 3600))),
        ];
      }
      if ($id === 'flickr') {
        $platform_config += [
          'user_id' => trim((string) ($values['user_id'] ?? '')),
          'album_id' => trim((string) ($values['album_id'] ?? '')),
          'gallery_enabled' => (bool) ($values['gallery_enabled'] ?? FALSE),
        ];
      }
      $config->set('platforms.' . $id, $platform_config);
    }
    $config->set('homepage_rotation', [
      'enabled' => (bool) $form_state->getValue(['homepage_rotation', 'enabled']),
      'interval' => max(3, min(60, (int) $form_state->getValue(['homepage_rotation', 'interval']))),
    ]);
    $config->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Performs a controlled manual refresh using saved configuration.
   */
  public function refreshXFeed(array &$form, FormStateInterface $form_state): void {
    $result = \Drupal::service('reg_core.x_feed')->homepageFeed(TRUE);
    if ($result['error'] !== '') {
      $this->messenger()->addWarning($this->t('X refresh failed: @message Previously cached posts or the official account link remain available.', ['@message' => $result['error']]));
    }
    elseif ($result['items'] !== []) {
      $this->messenger()->addStatus($this->t('The X feed cache contains @count posts.', [
        '@count' => count($result['items']),
      ]));
    }
    else {
      $this->messenger()->addWarning($this->t('No X posts were refreshed. The homepage fallback remains active.'));
    }
    $form_state->setRebuild();
  }

  /** Performs a controlled refresh without saving pending configuration edits. */
  public function refreshYouTubeFeed(array &$form, FormStateInterface $form_state): void {
    $result = \Drupal::service('reg_core.youtube_feed')->homepageFeed(TRUE);
    if ($result['error'] !== '') {
      $this->messenger()->addWarning($this->t('YouTube refresh failed: @message Previously verified data or the official channel link remain available.', ['@message' => $result['error']]));
    }
    elseif ($result['video'] !== []) {
      $this->messenger()->addStatus($this->t('The latest verified YouTube upload is cached.'));
    }
    else {
      $this->messenger()->addWarning($this->t('No public video is available or the YouTube feed is disabled.'));
    }
    $form_state->setRebuild();
  }

}
