<?php

namespace Drupal\reg_core\Social;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Retrieves one verified public upload without creating or changing CMS videos.
 */
final class YouTubeFeedService {

  private const TAGS = ['config:reg_core.social_media', 'reg_core:youtube_feed'];

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CacheBackendInterface $cache,
    private readonly CacheTagsInvalidatorInterface $invalidator,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly LockBackendInterface $lock,
  ) {}

  /** Returns cached data, a controlled refresh, or the last verified result. */
  public function homepageFeed(bool $force_refresh = FALSE): array {
    $settings = $this->settings();
    if (!$settings['enabled'] || !$settings['valid']) {
      return $this->result([], FALSE, FALSE, $settings['error']);
    }
    $cid = 'reg_core:youtube:' . $settings['channel_id'];
    $key = 'reg_core.youtube_feed.' . $settings['channel_id'];
    $cached = $this->cache->get($cid, TRUE);
    if (!$force_refresh && $cached && $cached->valid && is_array($cached->data)) {
      return $this->result($cached->data, TRUE, FALSE);
    }
    // State preserves the last good public result even after cache garbage collection.
    $stale = $this->state->get($key . '.last_good', []);
    $stale = is_array($stale) ? $stale : [];
    if ($cached && is_array($cached->data)) {
      $stale = $cached->data;
    }
    if ((!$force_refresh && (int) $this->state->get($key . '.next_attempt', 0) > $this->time->getRequestTime())
      || !$this->lock->acquire($cid, 60)) {
      return $this->result($stale, $stale !== [], $stale !== [], (string) $this->state->get($key . '.last_error', ''));
    }
    try {
      // A bounded cooldown also protects requests arriving after a failed refresh.
      $this->state->set($key . '.next_attempt', $this->time->getRequestTime() + 300);
      $source = 'uploads_feed';
      try {
        $xml = $this->request('https://www.youtube.com/feeds/videos.xml', ['channel_id' => $settings['channel_id']]);
        $video = $this->parseFeed($xml, $settings['channel_id']);
      }
      catch (YouTubeFeedException $exception) {
        $api_key = trim((string) getenv('YOUTUBE_API_KEY'));
        if ($api_key === '') {
          throw new YouTubeFeedException($exception->getMessage() . ' Optional YOUTUBE_API_KEY is not configured.');
        }
        $video = $this->apiVideo($settings['channel_id'], $api_key);
        $source = 'data_api';
      }
      $now = $this->time->getRequestTime();
      $this->invalidator->invalidateTags(['reg_core:youtube_feed']);
      $this->cache->set($cid, $video, $now + $settings['cache_lifetime'], self::TAGS);
      $this->state->setMultiple([
        $key . '.last_good' => $video,
        $key . '.last_success' => $now,
        $key . '.last_error' => '',
        $key . '.source' => $source,
        $key . '.next_attempt' => $now + $settings['cache_lifetime'],
      ]);
      return $this->result($video, FALSE, FALSE);
    }
    catch (YouTubeFeedException $exception) {
      // Only our fixed diagnostics reach state/UI; never exception request URLs.
      $error = $exception->getMessage();
      $this->state->set($key . '.last_error', $error);
      return $this->result($stale, $stale !== [], $stale !== [], $error);
    }
    finally {
      $this->lock->release($cid);
    }
  }

  /** Refreshes on Drupal cron only when the cache/cooldown is due. */
  public function refreshIfDue(): void {
    $this->homepageFeed();
  }

  /** Returns safe administrator diagnostics, never credentials. */
  public function status(): array {
    $settings = $this->settings();
    $key = 'reg_core.youtube_feed.' . $settings['channel_id'];
    return [
      'enabled' => $settings['enabled'],
      'channel_id' => $settings['channel_id'],
      'api_configured' => trim((string) getenv('YOUTUBE_API_KEY')) !== '',
      'last_success' => (int) $this->state->get($key . '.last_success', 0),
      'last_error' => $settings['error'] ?: (string) $this->state->get($key . '.last_error', ''),
      'source' => (string) $this->state->get($key . '.source', ''),
      'next_attempt' => (int) $this->state->get($key . '.next_attempt', 0),
    ];
  }

  private function settings(): array {
    $platform = $this->configFactory->get('reg_core.social_media')->get('platforms.youtube') ?: [];
    $channel = trim((string) ($platform['channel_id'] ?? ''));
    $valid = (bool) preg_match('/^UC[A-Za-z0-9_-]{22}$/D', $channel);
    return [
      'enabled' => !empty($platform['enabled']) && !empty($platform['videos_enabled']),
      'valid' => $valid,
      'channel_id' => $channel,
      'cache_lifetime' => max(1800, min(3600, (int) ($platform['feed_cache_lifetime'] ?? 3600))),
      'error' => !$valid ? 'Configure a verified YouTube channel ID: UC followed by 22 letters, numbers, underscores or hyphens.' : '',
    ];
  }

  /** Calls only fixed provider endpoints. Redirects and unbounded bodies are forbidden. */
  private function request(string $endpoint, array $query, string $api_key = ''): string {
    try {
      $response = $this->httpClient->request('GET', $endpoint, [
        'query' => $query,
        'headers' => $api_key === '' ? ['Accept' => 'application/atom+xml'] : ['Accept' => 'application/json', 'X-Goog-Api-Key' => $api_key],
        'allow_redirects' => FALSE,
        'http_errors' => FALSE,
        'connect_timeout' => 2,
        'timeout' => 5,
        'read_timeout' => 5,
        'stream' => TRUE,
      ]);
      if ($response->getStatusCode() !== 200) {
        throw new YouTubeFeedException('YouTube provider returned HTTP ' . $response->getStatusCode() . '.');
      }
      $stream = $response->getBody();
      $body = '';
      $deadline = microtime(TRUE) + 5;
      try {
        while (!$stream->eof()) {
          if (microtime(TRUE) > $deadline || strlen($body) > 1048576) {
            throw new YouTubeFeedException('YouTube response exceeded the allowed time or size.');
          }
          $chunk = $stream->read(8192);
          if ($chunk === '' && !$stream->eof()) {
            throw new YouTubeFeedException('YouTube response was incomplete.');
          }
          $body .= $chunk;
        }
        if (strlen($body) > 1048576) {
          throw new YouTubeFeedException('YouTube response exceeded the allowed size.');
        }
        return $body;
      }
      finally {
        $stream->close();
      }
    }
    catch (YouTubeFeedException $exception) {
      throw $exception;
    }
    catch (GuzzleException | \RuntimeException $exception) {
      throw new YouTubeFeedException('YouTube provider timed out or is temporarily unavailable.');
    }
  }

  /** Parses Atom with no DTD, entity expansion, external resources or network access. */
  private function parseFeed(string $xml, string $channel): array {
    if (trim($xml) === '') {
      throw new YouTubeFeedException('YouTube returned an empty response.');
    }
    if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml) || str_contains($xml, "\0")) {
      throw new YouTubeFeedException('YouTube returned unsafe XML.');
    }
    $previous = libxml_use_internal_errors(TRUE);
    try {
      $document = new \DOMDocument();
      $document->resolveExternals = FALSE;
      $document->substituteEntities = FALSE;
      if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
        throw new YouTubeFeedException('YouTube returned invalid XML.');
      }
      $xpath = new \DOMXPath($document);
      $xpath->registerNamespace('a', 'http://www.w3.org/2005/Atom');
      $xpath->registerNamespace('yt', 'http://www.youtube.com/xml/schemas/2015');
      // YouTube currently omits the UC prefix on the feed-level ID, while
      // entry-level IDs retain it. Accept only these two forms of this channel.
      $feed_channel = trim($xpath->evaluate('string(/a:feed/yt:channelId)'));
      if (!in_array($feed_channel, [$channel, substr($channel, 2)], TRUE)) {
        throw new YouTubeFeedException('YouTube response channel did not match the configured channel.');
      }
      $entries = $xpath->query('/a:feed/a:entry');
      $videos = [];
      foreach ($entries as $entry) {
        if ($xpath->evaluate('string(yt:channelId)', $entry) !== $channel) {
          continue;
        }
        $video = $this->video(
          $xpath->evaluate('string(yt:videoId)', $entry),
          $xpath->evaluate('string(a:title)', $entry),
          $xpath->evaluate('string(a:published)', $entry),
        );
        if ($video !== []) {
          $videos[] = $video;
        }
      }
      if ($entries->length && !$videos) {
        throw new YouTubeFeedException('YouTube returned no valid public video entries.');
      }
      usort($videos, static fn(array $a, array $b): int => strcmp($b['date_iso'], $a['date_iso']));
      return $videos[0] ?? [];
    }
    finally {
      libxml_clear_errors();
      libxml_use_internal_errors($previous);
    }
  }

  /** Uses the API only after an uploads-feed failure and only with an environment key. */
  private function apiVideo(string $channel, string $key): array {
    $channels = $this->json('channels', ['part' => 'contentDetails', 'id' => $channel], $key);
    $record = $channels['items'][0] ?? [];
    $playlist = $record['contentDetails']['relatedPlaylists']['uploads'] ?? '';
    if (!is_array($record) || ($record['id'] ?? '') !== $channel || !is_string($playlist) || !preg_match('/^[A-Za-z0-9_-]{10,64}$/D', $playlist)) {
      throw new YouTubeFeedException('YouTube API could not verify the configured uploads playlist.');
    }
    $items = $this->json('playlistItems', ['part' => 'contentDetails', 'playlistId' => $playlist, 'maxResults' => 10], $key);
    $ids = [];
    foreach ($items['items'] as $item) {
      if (!is_array($item)) {
        continue;
      }
      $id = $item['contentDetails']['videoId'] ?? '';
      if (is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/D', $id)) {
        $ids[] = $id;
      }
    }
    if (!$ids) {
      return [];
    }
    $videos = $this->json('videos', ['part' => 'snippet,status', 'id' => implode(',', $ids)], $key);
    $result = [];
    foreach ($videos['items'] as $item) {
      if (!is_array($item)) {
        continue;
      }
      if (($item['snippet']['channelId'] ?? '') !== $channel || ($item['status']['privacyStatus'] ?? '') !== 'public'
        || ($item['snippet']['liveBroadcastContent'] ?? '') === 'upcoming' || !in_array($item['id'] ?? '', $ids, TRUE)) {
        continue;
      }
      $video = $this->video($item['id'], $item['snippet']['title'] ?? '', $item['snippet']['publishedAt'] ?? '');
      if ($video !== []) {
        $result[] = $video;
      }
    }
    usort($result, static fn(array $a, array $b): int => strcmp($b['date_iso'], $a['date_iso']));
    return $result[0] ?? [];
  }

  private function json(string $resource, array $query, string $key): array {
    try {
      $data = json_decode($this->request('https://www.googleapis.com/youtube/v3/' . $resource, $query, $key), TRUE, 32, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new YouTubeFeedException('YouTube API returned invalid JSON.');
    }
    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
      throw new YouTubeFeedException('YouTube API returned an invalid video listing.');
    }
    return $data;
  }

  private function video(mixed $id, mixed $title, mixed $published): array {
    if (!is_string($id) || !is_string($title) || !is_string($published)) {
      return [];
    }
    $timestamp = $published !== '' ? strtotime($published) : FALSE;
    $title = trim(strip_tags($title));
    if (!preg_match('/^[A-Za-z0-9_-]{11}$/D', $id) || $title === '' || $timestamp === FALSE || $timestamp > $this->time->getRequestTime() + 300) {
      return [];
    }
    return [
      'id' => $id,
      'title' => mb_substr($title, 0, 500),
      'public_url' => 'https://www.youtube.com/watch?v=' . $id,
      'thumbnail' => ['url' => 'https://i.ytimg.com/vi/' . $id . '/mqdefault.jpg', 'alt' => $title],
      'date' => $this->dateFormatter->format($timestamp, 'custom', 'd M Y'),
      'date_iso' => gmdate(DATE_ATOM, $timestamp),
    ];
  }

  private function result(array $video, bool $from_cache, bool $stale, string $error = ''): array {
    return ['video' => $video, 'from_cache' => $from_cache, 'stale' => $stale, 'error' => $error, 'cache_tags' => self::TAGS];
  }

}
