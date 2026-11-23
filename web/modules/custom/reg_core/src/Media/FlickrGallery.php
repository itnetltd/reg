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
   * Returns the four newest public Flickr albums with selected album photos.
   *
   * The Flickr public album API needs an app key, but no OAuth access.
   * Without a key, the existing public photostream stays available.
   */
  public function albumGallery(?string $selectedAlbumId = NULL, int $albumLimit = 4, int $photoLimit = 12): array {
    $albumLimit = max(1, min(4, $albumLimit));
    $photoLimit = max(8, min(12, $photoLimit));
    $flickr = $this->configFactory->get('reg_core.social_media')->get('platforms.flickr');
    $flickr = is_array($flickr) ? $flickr : [];
    $enabled = !empty($flickr['enabled']) && !empty($flickr['gallery_enabled']);
    $userId = trim((string) ($flickr['user_id'] ?? ''));
    $apiKey = trim((string) (getenv('FLICKR_API_KEY') ?: ''));
    $result = [
      'enabled' => $enabled,
      'profile_url' => $this->validUrl((string) ($flickr['url'] ?? ''), ['flickr.com', 'www.flickr.com']),
      'items' => [],
      'albums' => [],
      'active_album' => [],
      'source_available' => FALSE,
      'album_source_available' => FALSE,
      'api_key_configured' => $apiKey !== '',
    ];
    if (!$enabled) {
      return $result;
    }
    if ($userId === '' || !preg_match('/^[A-Za-z0-9@._-]+$/D', $userId)) {
      return $this->albumFallback($result, $photoLimit);
    }

    // No-key mode: a Communications editor chooses the four public album
    // links and titles. Photos then come from Flickr's public photoset feeds.
    $manualAlbums = self::parseManualAlbums((string) ($flickr['manual_albums'] ?? ''));
    if ($manualAlbums !== []) {
      return $this->manualAlbumGallery($result, $userId, $manualAlbums, $selectedAlbumId, $photoLimit);
    }
    if ($apiKey === '') {
      return $this->albumFallback($result, $photoLimit);
    }

    $selectedAlbumId = is_string($selectedAlbumId) && preg_match('/^[0-9]{1,30}$/D', $selectedAlbumId)
      ? $selectedAlbumId : NULL;
    $cid = 'reg_core:flickr:albums:' . hash('sha256', $userId . ':' . $albumLimit . ':' . $photoLimit . ':' . ($selectedAlbumId ?? ''));
    if ($cached = $this->cache->get($cid)) {
      if (is_array($cached->data)) {
        return $cached->data;
      }
    }

    try {
      $sets = $this->flickrApi('flickr.photosets.getList', $apiKey, [
        'user_id' => $userId,
        'per_page' => 500,
        'page' => 1,
        'primary_photo_extras' => 'url_m,url_o',
      ]);
      $rawSets = $sets['photosets']['photoset'] ?? [];
      if (!is_array($rawSets)) {
        throw new \RuntimeException('Flickr album list unavailable');
      }
      $albums = [];
      foreach ($rawSets as $raw) {
        if (!is_array($raw)) {
          continue;
        }
        $id = (string) ($raw['id'] ?? '');
        if (!preg_match('/^[0-9]{1,30}$/D', $id)) {
          continue;
        }
        $rawTitle = $raw['title'] ?? '';
        $title = is_array($rawTitle) ? (string) ($rawTitle['_content'] ?? '') : (string) $rawTitle;
        $title = trim(strip_tags(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $created = ctype_digit((string) ($raw['date_create'] ?? '')) ? (int) $raw['date_create'] : 0;
        $albums[] = [
          'id' => $id,
          'title' => $title !== '' ? $title : 'REG photo album',
          'url' => 'https://www.flickr.com/photos/' . rawurlencode($userId) . '/albums/' . $id,
          'photo_count' => max(0, (int) ($raw['photos'] ?? 0)),
          'date_create' => $created,
          'image_url' => $this->flickrPhotoImage([
            'url_m' => (string) ($raw['url_m'] ?? ''),
            'id' => (string) ($raw['primary'] ?? ''),
            'server' => (string) ($raw['server'] ?? ''),
            'secret' => (string) ($raw['secret'] ?? ''),
          ]),
        ];
      }
      // Flickr normally returns user-defined album ordering, not latest first.
      usort($albums, static fn(array $a, array $b): int =>
        ($b['date_create'] <=> $a['date_create']) ?: strcmp($b['id'], $a['id']));
      $albums = array_slice($albums, 0, $albumLimit);
      $result['albums'] = $albums;
      $result['album_source_available'] = TRUE;

      if ($albums !== []) {
        $selected = $albums[0];
        foreach ($albums as $album) {
          if ($album['id'] === $selectedAlbumId) {
            $selected = $album;
            break;
          }
        }
        $result['active_album'] = $selected;
        $photos = $this->flickrApi('flickr.photosets.getPhotos', $apiKey, [
          'user_id' => $userId,
          'photoset_id' => $selected['id'],
          'media' => 'photos',
          'extras' => 'url_z,url_m,date_upload,media',
          'per_page' => $photoLimit,
          'page' => 1,
        ]);
        $rawPhotos = $photos['photoset']['photo'] ?? [];
        if (!is_array($rawPhotos)) {
          throw new \RuntimeException('Flickr photo list unavailable');
        }
        foreach (array_slice($rawPhotos, 0, $photoLimit) as $rawPhoto) {
          if (!is_array($rawPhoto) || ($rawPhoto['media'] ?? 'photo') !== 'photo') {
            continue;
          }
          $photoId = (string) ($rawPhoto['id'] ?? '');
          $imageUrl = $this->flickrPhotoImage($rawPhoto);
          if (!preg_match('/^[0-9]{1,30}$/D', $photoId) || $imageUrl === '') {
            continue;
          }
          $title = trim(strip_tags((string) ($rawPhoto['title'] ?? '')));
          $result['items'][] = [
            'title' => $title !== '' ? $title : $selected['title'],
            'image_url' => $imageUrl,
            'url' => 'https://www.flickr.com/photos/' . rawurlencode($userId) . '/' . $photoId,
            'date_iso' => ctype_digit((string) ($rawPhoto['dateupload'] ?? ''))
              ? gmdate('Y-m-d', (int) $rawPhoto['dateupload']) : '',
          ];
        }
      }
      $result['source_available'] = $result['items'] !== [];
      $this->cache->set($cid, $result, time() + 900, ['config:reg_core.social_media']);
      return $result;
    }
    catch (\Throwable $exception) {
      // Guzzle exceptions may contain URLs with API keys. Do not log details.
      $this->loggerFactory->get('reg_core')->warning('Flickr album listing unavailable; using public photo feed.');
      return $this->albumFallback($result, $photoLimit);
    }
  }


  /**
   * Parses up to four manually selected public Flickr album IDs and titles.
   *
   * Input order is display order (newest first). Lines accept:
   * 721... | Album title
   * https://www.flickr.com/photos/USER/albums/721... | Album title
   */
  public static function parseManualAlbums(string $raw): array {
    $albums = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
      $line = trim($line);
      if ($line === '' || count($albums) >= 4) {
        continue;
      }
      [$source, $title] = array_pad(explode('|', $line, 2), 2, '');
      $source = trim($source);
      $title = trim(strip_tags(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
      $id = $source;
      if (str_starts_with($source, 'https://')) {
        $url = parse_url($source);
        $host = strtolower((string) ($url['host'] ?? ''));
        $path = (string) ($url['path'] ?? '');
        if (!is_array($url) || !in_array($host, ['www.flickr.com', 'flickr.com'], TRUE)
          || isset($url['user']) || isset($url['pass']) || isset($url['port'])
          || !preg_match('#^/photos/[A-Za-z0-9@._-]+/(?:albums|sets)/([0-9]{1,30})/?$#D', $path, $match)) {
          continue;
        }
        $id = $match[1];
      }
      if (!preg_match('/^[0-9]{1,30}$/D', $id) || $title === '' || mb_strlen($title) > 140
        || isset($albums[$id])) {
        continue;
      }
      $albums[$id] = ['id' => $id, 'title' => $title];
    }
    return array_values($albums);
  }

  /**
   * Album navigation using the public no-key Flickr photoset JSON feeds.
   */
  private function manualAlbumGallery(array $result, string $userId, array $manual, ?string $selectedId, int $photoLimit): array {
    $selectedId = is_string($selectedId) && preg_match('/^[0-9]{1,30}$/D', $selectedId) ? $selectedId : NULL;
    $itemsForAlbum = [];
    foreach ($manual as $album) {
      $id = $album['id'];
      $photos = $this->publicAlbumFeed($userId, $id, $photoLimit);
      $itemsForAlbum[$id] = $photos;
      $result['albums'][] = [
        'id' => $id,
        'title' => $album['title'],
        'url' => 'https://www.flickr.com/photos/' . rawurlencode($userId) . '/albums/' . $id,
        'image_url' => $photos[0]['image_url'] ?? '',
        'preview_count' => count($photos),
        'date_create' => 0,
      ];
    }
    if ($result['albums'] !== []) {
      $selected = $result['albums'][0];
      foreach ($result['albums'] as $album) {
        if ($album['id'] === $selectedId) {
          $selected = $album;
          break;
        }
      }
      $result['active_album'] = $selected;
      $result['items'] = $itemsForAlbum[$selected['id']] ?? [];
    }
    $result['album_source_available'] = TRUE;
    $result['source_available'] = $result['items'] !== [];
    return $result;
  }

  /**
   * Reads one known public album, caching its photo feed for 15 minutes.
   * No Flickr app key, account token, user login, or HTML scraping.
   */
  private function publicAlbumFeed(string $userId, string $albumId, int $photoLimit): array {
    $cid = 'reg_core:flickr:public_album:' . hash('sha256', $userId . ':' . $albumId . ':' . $photoLimit);
    if ($cached = $this->cache->get($cid)) {
      if (is_array($cached->data)) {
        return $cached->data;
      }
    }
    $items = [];
    try {
      $response = $this->httpClient->request('GET', 'https://www.flickr.com/services/feeds/photoset.gne', [
        'query' => [
          'set' => $albumId,
          'nsid' => $userId,
          'format' => 'json',
          'nojsoncallback' => '1',
          'lang' => 'en-us',
        ],
        'connect_timeout' => 3,
        'timeout' => 7,
        'http_errors' => FALSE,
        'allow_redirects' => FALSE,
        'headers' => ['Accept' => 'application/json'],
      ]);
      if ($response->getStatusCode() !== 200) {
        throw new \RuntimeException('Public Flickr album feed returned an unexpected status');
      }
      $payload = json_decode((string) $response->getBody(), TRUE);
      if (!is_array($payload) || !is_array($payload['items'] ?? NULL)
        || (isset($payload['stat']) && $payload['stat'] !== 'ok')) {
        throw new \RuntimeException('Public Flickr album feed is unavailable');
      }
      foreach (array_slice($payload['items'], 0, $photoLimit) as $rawPhoto) {
        if (!is_array($rawPhoto)) {
          continue;
        }
        $image = $this->validImageUrl((string) ($rawPhoto['media']['m'] ?? ''));
        $link = $this->validUrl((string) ($rawPhoto['link'] ?? ''), ['flickr.com', 'www.flickr.com']);
        if ($image === '' || $link === '') {
          continue;
        }
        $title = trim(strip_tags((string) ($rawPhoto['title'] ?? '')));
        $items[] = [
          'image_url' => $image,
          'url' => $link,
          'title' => $title !== '' ? $title : 'REG album photo',
          'date_iso' => preg_match('/^\d{4}-\d{2}-\d{2}/', (string) ($rawPhoto['published'] ?? ''), $match)
            ? $match[0] : '',
        ];
      }
      $this->cache->set($cid, $items, time() + 900, ['config:reg_core.social_media']);
    }
    catch (\Throwable $exception) {
      $this->loggerFactory->get('reg_core')->warning('A public Flickr album feed is unavailable.');
      $this->cache->set($cid, [], time() + 300, ['config:reg_core.social_media']);
    }
    return $items;
  }

  /**
   * Reads public Flickr REST API data. Never passes API keys to the browser.
   */
  private function flickrApi(string $method, string $apiKey, array $query): array {
    $response = $this->httpClient->request('GET', 'https://www.flickr.com/services/rest/', [
      'query' => ['method' => $method, 'api_key' => $apiKey, 'format' => 'json', 'nojsoncallback' => '1'] + $query,
      'connect_timeout' => 3,
      'timeout' => 8,
      'http_errors' => FALSE,
      'allow_redirects' => FALSE,
      'headers' => ['Accept' => 'application/json'],
    ]);
    if ($response->getStatusCode() !== 200) {
      throw new \RuntimeException('Flickr API returned an unexpected status');
    }
    $payload = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($payload) || ($payload['stat'] ?? '') !== 'ok') {
      throw new \RuntimeException('Flickr API returned an invalid result');
    }
    return $payload;
  }

  /**
   * Prefer returned Flickr URLs, then construct the documented public path.
   */
  private function flickrPhotoImage(array $raw): string {
    foreach (['url_z', 'url_m'] as $field) {
      $url = $this->validImageUrl((string) ($raw[$field] ?? ''));
      if ($url !== '') {
        return $url;
      }
    }
    $id = (string) ($raw['id'] ?? '');
    $server = (string) ($raw['server'] ?? '');
    $secret = (string) ($raw['secret'] ?? '');
    if (preg_match('/^[0-9]{1,30}$/D', $id) && preg_match('/^[0-9]{1,20}$/D', $server)
      && preg_match('/^[A-Za-z0-9]{1,32}$/D', $secret)) {
      return 'https://live.staticflickr.com/' . $server . '/' . $id . '_' . $secret . '_z.jpg';
    }
    return '';
  }

  /**
   * Keeps the existing public photo feed if albums cannot be retrieved.
   */
  private function albumFallback(array $result, int $photoLimit): array {
    $feed = $this->gallery($photoLimit);
    $result['items'] = $feed['items'] ?? [];
    $result['source_available'] = $feed['source_available'] ?? FALSE;
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
