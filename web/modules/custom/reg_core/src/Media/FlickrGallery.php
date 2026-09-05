<?php

namespace Drupal\reg_core\Media;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Loads a small cached, public Flickr feed from central REG configuration.
 */
final class FlickrGallery {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Returns presentation-ready gallery data, or a safe configured fallback.
   */
  public function gallery(int $limit = 12): array {
    $limit = max(1, min(20, $limit));
    $settings = $this->configFactory->get('reg_core.social_media');
    $flickr = $settings->get('platforms.flickr');
    $flickr = is_array($flickr) ? $flickr : [];
    $profile_url = $this->validUrl((string) ($flickr['url'] ?? ''), ['flickr.com', 'www.flickr.com']);
    $user_id = trim((string) ($flickr['user_id'] ?? ''));
    $album_id = trim((string) ($flickr['album_id'] ?? ''));
    $enabled = !empty($flickr['enabled']) && !empty($flickr['gallery_enabled']);
    $result = [
      'enabled' => $enabled,
      'profile_url' => $profile_url,
      'items' => [],
      'source_available' => FALSE,
    ];
    if (!$enabled || $user_id === '' || !preg_match('/^[A-Za-z0-9@._-]+$/', $user_id)) {
      return $result;
    }

    $cid = 'reg_core:flickr:' . hash('sha256', $user_id . ':' . $album_id . ':' . $limit);
    if ($cached = $this->cache->get($cid)) {
      return is_array($cached->data) ? $cached->data : $result;
    }

    $endpoint = $album_id !== ''
      ? 'https://www.flickr.com/services/feeds/photoset.gne'
      : 'https://www.flickr.com/services/feeds/photos_public.gne';
    $query = [
      'format' => 'json',
      'nojsoncallback' => '1',
      'lang' => 'en-us',
    ];
    if ($album_id !== '' && preg_match('/^[A-Za-z0-9@._-]+$/', $album_id)) {
      $query['set'] = $album_id;
      $query['nsid'] = $user_id;
    }
    else {
      $query['id'] = $user_id;
    }

    try {
      $response = $this->httpClient->request('GET', $endpoint, [
        'query' => $query,
        'connect_timeout' => 3,
        'timeout' => 5,
        'headers' => ['Accept' => 'application/json'],
      ]);
      $payload = json_decode((string) $response->getBody(), TRUE);
      foreach (array_slice(is_array($payload['items'] ?? NULL) ? $payload['items'] : [], 0, $limit) as $item) {
        $image_url = $this->validImageUrl((string) ($item['media']['m'] ?? ''));
        $item_url = $this->validUrl((string) ($item['link'] ?? ''), ['flickr.com', 'www.flickr.com']);
        if ($image_url === '' || $item_url === '') {
          continue;
        }
        $title = trim(strip_tags((string) ($item['title'] ?? '')));
        $result['items'][] = [
          'image_url' => $image_url,
          'url' => $item_url,
          'title' => $title !== '' ? $title : 'REG photo',
          'date_iso' => preg_match('/^\d{4}-\d{2}-\d{2}/', (string) ($item['published'] ?? ''), $match) ? $match[0] : '',
        ];
      }
      $result['source_available'] = $result['items'] !== [];
      $this->cache->set($cid, $result, time() + 900, ['config:reg_core.social_media']);
    }
    catch (\Throwable $exception) {
      $this->loggerFactory->get('reg_core')->warning('The configured Flickr feed could not be loaded: @message', [
        '@message' => $exception->getMessage(),
      ]);
    }
    return $result;
  }

  /**
   * Validates an HTTPS public URL against exact allowed hosts.
   */
  private function validUrl(string $url, array $hosts): string {
    $url = trim($url);
    $parts = parse_url($url);
    if (
      $url === ''
      || !UrlHelper::isValid($url, TRUE)
      || !is_array($parts)
      || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
      || !in_array(strtolower((string) ($parts['host'] ?? '')), $hosts, TRUE)
      || isset($parts['user'])
      || isset($parts['pass'])
    ) {
      return '';
    }
    return $url;
  }

  /**
   * Accepts Flickr's HTTPS image hosts only.
   */
  private function validImageUrl(string $url): string {
    $parts = parse_url(trim($url));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (
      !is_array($parts)
      || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
      || !str_ends_with($host, '.staticflickr.com')
      || isset($parts['user'])
      || isset($parts['pass'])
    ) {
      return '';
    }
    return trim($url);
  }

}
