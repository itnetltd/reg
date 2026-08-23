<?php

namespace Drupal\reg_core\Video;

/**
 * Allowlisted video provider resolver with privacy-enhanced YouTube embeds.
 */
final class VideoProviderManager implements VideoProviderManagerInterface {

  private const YOUTUBE_HOSTS = [
    'youtube.com',
    'www.youtube.com',
    'm.youtube.com',
    'youtu.be',
    'www.youtu.be',
    'youtube-nocookie.com',
    'www.youtube-nocookie.com',
  ];

  /**
   * {@inheritdoc}
   */
  public function providerLabels(): array {
    return [
      'youtube' => 'YouTube',
      'approved_external' => 'Approved external video URL',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(string $provider, string $url): array {
    $provider = mb_strtolower(trim($provider));
    $url = trim($url);
    if (!isset($this->providerLabels()[$provider]) || !$this->isSafeHttpsUrl($url)) {
      return [];
    }

    if ($provider === 'youtube') {
      return $this->youtube($url);
    }

    $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
    $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return [
      'provider' => $provider,
      'public_url' => $url,
      'embed_url' => '',
      'direct_video_url' => in_array($extension, ['mp4', 'webm', 'ogv', 'ogg'], TRUE) ? $url : '',
      'thumbnail_url' => '',
    ];
  }

  /**
   * Resolves common YouTube URLs without accepting arbitrary embed markup.
   */
  private function youtube(string $url): array {
    $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
    if (!in_array($host, self::YOUTUBE_HOSTS, TRUE)) {
      return [];
    }

    $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
    $parts = $path === '' ? [] : explode('/', $path);
    $video_id = '';
    if (in_array($host, ['youtu.be', 'www.youtu.be'], TRUE)) {
      $video_id = (string) ($parts[0] ?? '');
    }
    elseif (($parts[0] ?? '') === 'watch') {
      parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
      $video_id = (string) ($query['v'] ?? '');
    }
    elseif (in_array(($parts[0] ?? ''), ['embed', 'shorts', 'live'], TRUE)) {
      $video_id = (string) ($parts[1] ?? '');
    }

    if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $video_id)) {
      return [];
    }
    return [
      'provider' => 'youtube',
      'public_url' => $url,
      'embed_url' => 'https://www.youtube-nocookie.com/embed/' . $video_id . '?rel=0',
      'direct_video_url' => '',
      'thumbnail_url' => 'https://i.ytimg.com/vi/' . $video_id . '/hqdefault.jpg',
    ];
  }

  /**
   * Restricts public video records to credential-free HTTPS URLs.
   */
  private function isSafeHttpsUrl(string $url): bool {
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === FALSE) {
      return FALSE;
    }
    $parts = parse_url($url);
    return is_array($parts)
      && mb_strtolower((string) ($parts['scheme'] ?? '')) === 'https'
      && (string) ($parts['host'] ?? '') !== ''
      && !isset($parts['user'])
      && !isset($parts['pass']);
  }

}
