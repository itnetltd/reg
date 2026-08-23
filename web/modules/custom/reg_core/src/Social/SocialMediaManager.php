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
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
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

}
