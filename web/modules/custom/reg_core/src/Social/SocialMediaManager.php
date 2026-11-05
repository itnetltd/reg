<?php

namespace Drupal\reg_core\Social;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Provides validated, presentation-ready official REG social links.
 */
final class SocialMediaManager {

  /**
   * Supported platforms in their public display order.
   */
  private const PLATFORMS = [
    'x' => [
      'label' => 'X',
      'aria_label' => 'Follow REG on X',
    ],
    'facebook' => [
      'label' => 'Facebook',
      'aria_label' => 'Follow REG on Facebook',
    ],
    'youtube' => [
      'label' => 'YouTube',
      'aria_label' => 'Follow REG on YouTube',
    ],
    'instagram' => [
      'label' => 'Instagram',
      'aria_label' => 'Follow REG on Instagram',
    ],
    'linkedin' => [
      'label' => 'LinkedIn',
      'aria_label' => 'Follow REG on LinkedIn',
    ],
    'flickr' => [
      'label' => 'Flickr',
      'aria_label' => 'View REG photos on Flickr',
    ],
  ];

  /** Homepage-only order; other placements retain their approved ordering. */
  public const HOMEPAGE_PLATFORMS = [
    'x' => 'X / Twitter',
    'youtube' => 'YouTube',
    'instagram' => 'Instagram',
    'facebook' => 'Facebook',
    'tiktok' => 'TikTok',
    'linkedin' => 'LinkedIn',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ?SocialPostRepositoryInterface $socialPosts = NULL,
  ) {}

  /**
   * Returns enabled links for a supported public placement.
   *
   * Media Center intentionally includes every enabled official channel.
   *
   * @return array<int, array<string, mixed>>
   *   Validated social-link data safe for public rendering.
   */
  public function linksForPlacement(string $placement): array {
    if (!in_array($placement, ['utility', 'footer', 'media_center'], TRUE)) {
      return [];
    }

    $config = $this->configFactory->get('reg_core.social_media');
    $links = [];
    foreach (self::PLATFORMS as $id => $definition) {
      $platform = $config->get('platforms.' . $id);
      if (!is_array($platform) || empty($platform['enabled'])) {
        continue;
      }
      if ($placement === 'utility' && empty($platform['show_utility'])) {
        continue;
      }
      if ($placement === 'footer' && empty($platform['show_footer'])) {
        continue;
      }

      $url = trim((string) ($platform['url'] ?? ''));
      $url_parts = parse_url($url);
      if (!is_array($url_parts)) {
        continue;
      }
      $scheme = strtolower((string) ($url_parts['scheme'] ?? ''));
      if (
        $url === ''
        || !UrlHelper::isValid($url, TRUE)
        || !in_array($scheme, ['http', 'https'], TRUE)
        || isset($url_parts['user'])
        || isset($url_parts['pass'])
      ) {
        continue;
      }

      $links[] = [
        'id' => $id,
        'label' => $definition['label'],
        'aria_label' => $definition['aria_label'],
        'url' => $url,
      ];
    }

    return $links;
  }

  /**
   * Returns a validated homepage X feed derived from central configuration.
   */
  public function homepageXFeed(): array {
    $platform = $this->configFactory->get('reg_core.social_media')->get('platforms.x');
    if (!is_array($platform) || empty($platform['enabled']) || empty($platform['show_homepage_feed'])) {
      return [];
    }
    $url = trim((string) ($platform['url'] ?? ''));
    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $handle = trim((string) ($parts['path'] ?? ''), '/');
    if (
      !is_array($parts)
      || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
      || !in_array($host, ['x.com', 'www.x.com', 'twitter.com', 'www.twitter.com'], TRUE)
      || !preg_match('/^[A-Za-z0-9_]{1,15}$/', $handle)
      || isset($parts['user'])
      || isset($parts['pass'])
    ) {
      return [];
    }
    return [
      'url' => 'https://x.com/' . $handle,
      'handle' => '@' . $handle,
      'display_name' => trim((string) ($platform['display_name'] ?? '')) ?: 'Rwanda Energy Group',
    ];
  }

  /** Returns the approved, enabled YouTube channel from Drupal configuration. */
  public function homepageYouTubeChannel(): array {
    $platform = $this->configFactory->get('reg_core.social_media')->get('platforms.youtube');
    $channel = trim((string) ($platform['channel_id'] ?? ''));
    $url = preg_match('/^UC[A-Za-z0-9_-]{22}$/D', $channel)
      ? 'https://www.youtube.com/channel/' . $channel
      : trim((string) ($platform['url'] ?? ''));
    $parts = parse_url($url);
    if (empty($platform['enabled'])
      || !UrlHelper::isValid($url, TRUE) || !is_array($parts)
      || ($parts['scheme'] ?? '') !== 'https'
      || !in_array(strtolower($parts['host'] ?? ''), ['youtube.com', 'www.youtube.com'], TRUE)
      || empty(trim($parts['path'] ?? '', '/'))
      || isset($parts['user']) || isset($parts['pass'])) {
      return [];
    }
    return [
      'url' => $url,
      'title' => trim((string) ($platform['display_name'] ?? '')) ?: 'Rwanda Energy Group',
      'videos_enabled' => !empty($platform['videos_enabled']),
    ];
  }

  /** Builds paired cards using existing feed results and approved CMS records. */
  public function homepageSocialCards(array $x_feed = [], array $youtube = []): array {
    $config = $this->configFactory->get('reg_core.social_media');
    $cards = [];
    $tags = ['config:reg_core.social_media', 'node_list:reg_social_post', 'reg_core:x_feed', 'reg_core:youtube_feed', 'node_list:reg_video', 'media_list', 'file_list'];
    foreach (self::HOMEPAGE_PLATFORMS as $id => $label) {
      $platform = $config->get('platforms.' . $id) ?? [];
      if (empty($platform['enabled']) || !($platform['show_homepage_card'] ?? TRUE)
        || ($id === 'x' && empty($platform['show_homepage_feed']))) {
        continue;
      }
      $url = self::validatedProfileUrl($id, (string) ($platform['url'] ?? ''));
      if ($url === '') {
        continue;
      }
      $post = [];
      if ($id !== 'youtube' && ($id !== 'x' || empty($x_feed['posts'])) && $this->socialPosts) {
        $native = $this->socialPosts->latestHomepagePost($id);
        $post = $native['item'];
        $tags = array_merge($tags, $native['cache_tags']);
      }
      $card = [
        'id' => $id, 'label' => $label, 'url' => $url,
        'name' => trim((string) ($platform['display_name'] ?? '')) ?: 'Rwanda Energy Group',
        'order' => (int) ($platform['homepage_order'] ?? array_search($id, array_keys(self::HOMEPAGE_PLATFORMS), TRUE) * 10),
        'post' => $post,
      ];
      if ($id === 'x') {
        $card['feed'] = $x_feed ?: $this->homepageXFeed();
        $card['feed']['posts'] = $x_feed['posts'] ?? [];
        if ($card['feed']['posts'] === [] && $post !== []) {
          $card['feed']['posts'] = [$post];
        }
      }
      elseif ($id === 'youtube') {
        $card['feed'] = $youtube ?: $this->homepageYouTubeChannel();
      }
      $cards[] = $card;
    }
    // Stable tie-breaking retains the defined pair sequence for equal weights.
    usort($cards, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
    $pairs = array_chunk($cards, 2);
    if (count($cards) > 1 && count(end($pairs)) === 1) {
      $pairs[array_key_last($pairs)][] = $cards[0];
    }
    $tags = array_values(array_unique($tags));
    return [
      'cards' => $cards,
      'pairs' => $pairs,
      'enabled' => (bool) ($config->get('homepage_rotation.enabled') ?? TRUE),
      'interval' => max(3, min(60, (int) ($config->get('homepage_rotation.interval') ?? 5))),
      'cache_tags' => $tags,
      // Render this carrier even in the theme's fallback path to bubble metadata.
      'cache' => ['#markup' => '', '#cache' => [
        'tags' => $tags,
        'contexts' => ['languages:language_content', 'languages:language_interface', 'user.permissions', 'user.node_grants:view'],
        'max-age' => 300,
      ]],
    ];
  }

  /** Validates platform-specific HTTPS profile URLs, never fetching them. */
  public static function validatedProfileUrl(string $platform, string $url): string {
    $url = trim($url);
    $p = parse_url($url);
    if (!is_array($p) || !UrlHelper::isValid($url, TRUE) || ($p['scheme'] ?? '') !== 'https'
      || isset($p['user']) || isset($p['pass']) || isset($p['port'])) {
      return '';
    }
    $host = strtolower($p['host'] ?? '');
    $path = trim($p['path'] ?? '', '/');
    $valid = match ($platform) {
      'x' => in_array($host, ['x.com', 'www.x.com', 'twitter.com', 'www.twitter.com'], TRUE) && preg_match('/^[A-Za-z0-9_]{1,15}$/D', $path),
      'youtube' => in_array($host, ['youtube.com', 'www.youtube.com'], TRUE) && preg_match('#^(?:channel/UC[A-Za-z0-9_-]{22}|@[A-Za-z0-9_.-]+|(?:user|c)/[A-Za-z0-9_.-]+)$#D', $path),
      'instagram' => in_array($host, ['instagram.com', 'www.instagram.com'], TRUE) && preg_match('/^[A-Za-z0-9_.]{1,30}$/D', $path)
        && !in_array(strtolower($path), ['p', 'reel', 'reels', 'tv', 'accounts', 'explore', 'stories', 'direct'], TRUE),
      'facebook' => in_array($host, ['facebook.com', 'www.facebook.com', 'web.facebook.com', 'm.facebook.com'], TRUE) && preg_match('/^[A-Za-z0-9.]+$/D', $path)
        && !in_array(strtolower($path), ['login', 'watch', 'reel', 'reels', 'posts', 'groups', 'share', 'sharer.php', 'dialog'], TRUE),
      'tiktok' => in_array($host, ['tiktok.com', 'www.tiktok.com'], TRUE) && preg_match('/^@[A-Za-z0-9_.]{1,24}$/D', $path),
      'linkedin' => in_array($host, ['linkedin.com', 'www.linkedin.com'], TRUE) && preg_match('#^company/[A-Za-z0-9_-]+$#D', $path),
      default => FALSE,
    };
    return $valid ? $url : '';
  }

  /** Accepts canonical post destinations on the approved profile's platform. */
  public static function validatedPostUrl(string $platform, string $url, string $profile): string {
    if (self::validatedProfileUrl($platform, $profile) === '') {
      return '';
    }
    $p = parse_url(trim($url));
    if (!is_array($p) || !UrlHelper::isValid($url, TRUE) || ($p['scheme'] ?? '') !== 'https'
      || isset($p['user']) || isset($p['pass']) || isset($p['port'])) {
      return '';
    }
    $hosts = match ($platform) {
      'x' => ['x.com', 'www.x.com', 'twitter.com', 'www.twitter.com'],
      'instagram' => ['instagram.com', 'www.instagram.com'],
      'facebook' => ['facebook.com', 'www.facebook.com', 'web.facebook.com', 'm.facebook.com'],
      'tiktok' => ['tiktok.com', 'www.tiktok.com'],
      'linkedin' => ['linkedin.com', 'www.linkedin.com'],
      default => [],
    };
    if (!in_array(strtolower($p['host'] ?? ''), $hosts, TRUE)) {
      return '';
    }
    $account = trim(parse_url($profile, PHP_URL_PATH) ?? '', '/');
    $path = trim($p['path'] ?? '', '/');
    $slug = basename($account);
    $account = preg_quote($account, '#');
    $valid = match ($platform) {
      'x' => preg_match('#^' . $account . '/status/[0-9]+$#iD', $path),
      // Instagram permalinks contain no account. Editorial approval verifies ownership.
      'instagram' => preg_match('#^(?:p|reel|tv)/[A-Za-z0-9_-]+$#D', $path),
      'facebook' => preg_match('#^' . $account . '/(?:posts|videos|reels)/[A-Za-z0-9_.-]+$#iD', $path),
      'tiktok' => preg_match('#^' . $account . '/video/[0-9]+$#iD', $path),
      'linkedin' => preg_match('#^posts/' . preg_quote($slug, '#') . '_[A-Za-z0-9_-]+$#iD', $path),
      default => FALSE,
    };
    return $valid ? trim($url) : '';
  }

}
