<?php

namespace Drupal\reg_core\Social;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Fetches and caches read-only public posts from X API v2.
 */
final class XFeedService implements XFeedServiceInterface {

  private const API_BASE = 'https://api.x.com/2';
  private const TOKEN_ENVIRONMENT_VARIABLE = 'X_BEARER_TOKEN';
  private const STATE_PREFIX = 'reg_core.x_feed.';

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CacheBackendInterface $cache,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function homepageFeed(bool $force_refresh = FALSE): array {
    $settings = $this->settings();
    if (!$settings['enabled']) {
      return $this->result([], FALSE, FALSE, '');
    }

    $cache_id = $this->postsCacheId($settings['username']);
    $cached = $this->cache->get($cache_id, TRUE);
    if (!$force_refresh && $cached && $cached->valid && is_array($cached->data)) {
      return $this->result($cached->data, TRUE, FALSE, '');
    }

    $stale_items = ($cached && is_array($cached->data)) ? $cached->data : [];
    $token = $this->bearerToken();
    if ($token === '') {
      $message = 'X API bearer token is not configured.';
      $this->recordError($message);
      return $this->result($stale_items, $stale_items !== [], $stale_items !== [], $message);
    }

    try {
      $user_id = $this->resolveUserId($settings, $token);
      $items = $this->fetchPosts($user_id, $settings, $token);
      $now = $this->time->getRequestTime();
      $this->cacheTagsInvalidator->invalidateTags(['reg_core:x_feed']);
      $this->cache->set(
        $cache_id,
        $items,
        $now + $settings['cache_lifetime'],
        ['config:reg_core.social_media', 'reg_core:x_feed'],
      );
      $this->state->setMultiple([
        self::STATE_PREFIX . 'last_success' => $now,
        self::STATE_PREFIX . 'cached_posts' => count($items),
        self::STATE_PREFIX . 'last_error' => '',
      ]);
      return $this->result($items, FALSE, FALSE, '');
    }
    catch (XFeedException $exception) {
      $message = $this->safeMessage($exception->getMessage());
      $this->recordError($message);
      $this->logger->notice('X feed refresh for @account failed: @message', [
        '@account' => '@' . $settings['username'],
        '@message' => $message,
      ]);
      return $this->result($stale_items, $stale_items !== [], $stale_items !== [], $message);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function refreshIfDue(): void {
    if ($this->settings()['enabled']) {
      $this->homepageFeed();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function status(): array {
    $settings = $this->settings();
    $cached = $this->cache->get($this->postsCacheId($settings['username']), TRUE);
    $cached_count = ($cached && is_array($cached->data)) ? count($cached->data) : 0;
    return [
      'configured' => $this->bearerToken() !== '',
      'enabled' => $settings['enabled'],
      'account' => '@' . $settings['username'],
      'last_success' => (int) $this->state->get(self::STATE_PREFIX . 'last_success', 0),
      'cached_posts' => $cached_count,
      'last_error' => $this->safeMessage((string) $this->state->get(self::STATE_PREFIX . 'last_error', '')),
    ];
  }

  /**
   * Resolves the configured username without repeating successful lookups.
   *
   * @param array<string, mixed> $settings
   *   Normalized X configuration.
   */
  private function resolveUserId(array $settings, string $token): string {
    if ($settings['user_id'] !== '') {
      return $settings['user_id'];
    }

    $cache_id = 'reg_core:x:user:' . strtolower($settings['username']);
    $cached = $this->cache->get($cache_id);
    if ($cached && preg_match('/^\d+$/', (string) $cached->data)) {
      return (string) $cached->data;
    }

    $payload = $this->request(
      '/users/by/username/' . rawurlencode($settings['username']),
      ['user.fields' => 'id,username,name'],
      $token,
    );
    $user_id = trim((string) ($payload['data']['id'] ?? ''));
    if (!preg_match('/^\d+$/', $user_id)) {
      throw new XFeedException('X API did not return a valid user ID for the configured account.');
    }
    $this->cache->set(
      $cache_id,
      $user_id,
      Cache::PERMANENT,
      ['config:reg_core.social_media'],
    );
    return $user_id;
  }

  /**
   * Fetches and normalizes recent posts.
   *
   * @param array<string, mixed> $settings
   *   Normalized X configuration.
   *
   * @return array<int, array<string, mixed>>
   *   Presentation-ready public posts.
   */
  private function fetchPosts(string $user_id, array $settings, string $token): array {
    $exclude = [];
    if ($settings['exclude_replies']) {
      $exclude[] = 'replies';
    }
    if ($settings['exclude_reposts']) {
      $exclude[] = 'retweets';
    }
    $query = [
      'max_results' => max(5, $settings['post_count']),
      'tweet.fields' => 'created_at,attachments,entities,public_metrics',
      'expansions' => 'attachments.media_keys',
      'media.fields' => 'media_key,type,url,preview_image_url,width,height,alt_text',
    ];
    if ($exclude !== []) {
      $query['exclude'] = implode(',', $exclude);
    }

    $payload = $this->request('/users/' . $user_id . '/tweets', $query, $token);
    $media = [];
    foreach (($payload['includes']['media'] ?? []) as $item) {
      if (!is_array($item) || ($item['type'] ?? '') !== 'photo') {
        continue;
      }
      $url = $this->safeMediaUrl((string) ($item['url'] ?? ''));
      if ($url === '') {
        continue;
      }
      $media[(string) ($item['media_key'] ?? '')] = [
        'url' => $url,
        'alt' => trim(strip_tags((string) ($item['alt_text'] ?? ''))),
        'width' => max(0, (int) ($item['width'] ?? 0)),
        'height' => max(0, (int) ($item['height'] ?? 0)),
      ];
    }

    $posts = [];
    foreach (($payload['data'] ?? []) as $post) {
      if (!is_array($post) || count($posts) >= $settings['post_count']) {
        break;
      }
      $id = trim((string) ($post['id'] ?? ''));
      $text = $this->safeText((string) ($post['text'] ?? ''));
      if (!preg_match('/^\d+$/', $id) || $text === '') {
        continue;
      }
      $created_at = trim((string) ($post['created_at'] ?? ''));
      $timestamp = $created_at !== '' ? strtotime($created_at) : FALSE;
      $image = [];
      foreach (($post['attachments']['media_keys'] ?? []) as $media_key) {
        if (isset($media[(string) $media_key])) {
          $image = $media[(string) $media_key];
          break;
        }
      }
      $posts[] = [
        'id' => $id,
        'text' => $text,
        'url' => 'https://x.com/' . rawurlencode($settings['username']) . '/status/' . $id,
        'date_iso' => $timestamp !== FALSE ? gmdate('c', $timestamp) : '',
        'date' => $timestamp !== FALSE ? $this->dateFormatter->format($timestamp, 'custom', 'M j, Y') : '',
        'image' => $image,
      ];
    }
    return $posts;
  }

  /**
   * Performs one credential-bearing X API request.
   *
   * @param array<string, int|string> $query
   *   Query parameters.
   *
   * @return array<string, mixed>
   *   Decoded response payload.
   */
  private function request(string $path, array $query, string $token): array {
    try {
      $response = $this->httpClient->request('GET', self::API_BASE . $path, [
        'headers' => [
          'Accept' => 'application/json',
          'Authorization' => 'Bearer ' . $token,
          'User-Agent' => 'REG-Drupal-X-Feed/1.0',
        ],
        'query' => $query,
        'connect_timeout' => 3,
        'timeout' => 6,
        'http_errors' => FALSE,
      ]);
    }
    catch (GuzzleException $exception) {
      throw new XFeedException('X API is temporarily unavailable.', 0, $exception);
    }

    $status = $response->getStatusCode();
    if ($status === 429) {
      throw new XFeedException('X API rate limit reached.');
    }
    if (in_array($status, [401, 403], TRUE)) {
      throw new XFeedException('X API authentication was rejected.');
    }
    if ($status === 404) {
      throw new XFeedException('The configured X account was not found.');
    }
    if ($status < 200 || $status >= 300) {
      throw new XFeedException('X API is temporarily unavailable.');
    }

    try {
      $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new XFeedException('X API returned an invalid response.', 0, $exception);
    }
    if (!is_array($payload)) {
      throw new XFeedException('X API returned an invalid response.');
    }
    return $payload;
  }

  /**
   * Returns validated, bounded configuration.
   *
   * @return array<string, mixed>
   *   Normalized settings.
   */
  private function settings(): array {
    $platform = $this->configFactory->get('reg_core.social_media')->get('platforms.x');
    $platform = is_array($platform) ? $platform : [];
    $username = ltrim(trim((string) ($platform['api_username'] ?? 'reg_rwanda')), '@');
    if (!preg_match('/^[A-Za-z0-9_]{1,15}$/', $username)) {
      $username = 'reg_rwanda';
    }
    $user_id = trim((string) ($platform['api_user_id'] ?? ''));
    return [
      'enabled' => !empty($platform['api_enabled']) && !empty($platform['enabled']) && !empty($platform['show_homepage_feed']),
      'username' => $username,
      'user_id' => preg_match('/^\d+$/', $user_id) ? $user_id : '',
      'cache_lifetime' => max(300, min(86400, (int) ($platform['api_cache_lifetime'] ?? 900))),
      'post_count' => max(1, min(5, (int) ($platform['api_homepage_post_count'] ?? 3))),
      'exclude_replies' => !array_key_exists('api_exclude_replies', $platform) || !empty($platform['api_exclude_replies']),
      'exclude_reposts' => !array_key_exists('api_exclude_reposts', $platform) || !empty($platform['api_exclude_reposts']),
    ];
  }

  private function bearerToken(): string {
    $value = getenv(self::TOKEN_ENVIRONMENT_VARIABLE);
    return is_string($value) ? trim($value) : '';
  }

  private function postsCacheId(string $username): string {
    return 'reg_core:x:posts:' . strtolower($username);
  }

  private function recordError(string $message): void {
    if ($this->state->get(self::STATE_PREFIX . 'last_error') !== $message) {
      $this->state->set(self::STATE_PREFIX . 'last_error', $message);
    }
  }

  private function safeMessage(string $message): string {
    $message = trim((string) preg_replace('/[\r\n\t]+/', ' ', strip_tags($message)));
    return mb_substr($message, 0, 240);
  }

  private function safeText(string $text): string {
    $text = strip_tags($text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    return mb_substr(trim($text), 0, 1000);
  }

  private function safeMediaUrl(string $url): string {
    $parts = parse_url(trim($url));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (
      !is_array($parts)
      || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
      || ($host !== 'pbs.twimg.com' && !str_ends_with($host, '.twimg.com'))
      || isset($parts['user'])
      || isset($parts['pass'])
    ) {
      return '';
    }
    return trim($url);
  }

  /**
   * Builds a consistent feed result.
   *
   * @param array<int, array<string, mixed>> $items
   *   Normalized posts.
   *
   * @return array<string, mixed>
   *   Feed result.
   */
  private function result(array $items, bool $from_cache, bool $stale, string $error): array {
    return [
      'items' => $items,
      'from_cache' => $from_cache,
      'stale' => $stale,
      'error' => $error,
      'cache_tags' => ['config:reg_core.social_media', 'reg_core:x_feed'],
    ];
  }

}
