<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\reg_core\Social\YouTubeFeedService;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/** Exercises provider boundaries; fixtures are never published as REG content. */
final class YouTubeFeedServiceTest extends TestCase {

  private const CHANNEL = 'UCaaaaaaaaaaaaaaaaaaaaaa';
  private int $now = 1793700000;
  private array $cache = [];
  private array $state = [];
  private string|false $previousKey;

  protected function setUp(): void {
    parent::setUp();
    $this->previousKey = getenv('YOUTUBE_API_KEY');
    putenv('YOUTUBE_API_KEY');
  }

  protected function tearDown(): void {
    $this->previousKey === FALSE ? putenv('YOUTUBE_API_KEY') : putenv('YOUTUBE_API_KEY=' . $this->previousKey);
    parent::tearDown();
  }

  public function testFeedPicksLatestVerifiedUploadAndReusesCacheWithoutApiKey(): void {
    $http = $this->createMock(ClientInterface::class);
    $http->expects(self::once())->method('request')->with('GET', 'https://www.youtube.com/feeds/videos.xml', self::callback(function (array $options): bool {
      self::assertFalse($options['allow_redirects']);
      self::assertSame(self::CHANNEL, $options['query']['channel_id']);
      self::assertArrayNotHasKey('X-Goog-Api-Key', $options['headers']);
      return TRUE;
    }))->willReturn(new Response(200, [], $this->feed()));
    $service = $this->service($http);
    $result = $service->homepageFeed();
    self::assertSame('testvideo02', $result['video']['id']);
    self::assertSame('https://www.youtube.com/watch?v=testvideo02', $result['video']['public_url']);
    self::assertSame('https://i.ytimg.com/vi/testvideo02/mqdefault.jpg', $result['video']['thumbnail']['url']);
    self::assertNotEmpty($result['video']['date']);
    self::assertTrue($service->homepageFeed()['from_cache']);
    self::assertSame('uploads_feed', $service->status()['source']);
    self::assertFalse($service->status()['api_configured']);
  }

  public function testStreamShortReadsAreFullyCollectedBeforeParsing(): void {
    $xml = $this->feed();
    $offset = 0;
    $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
    $stream->method('eof')->willReturnCallback(static function () use (&$offset, $xml): bool { return $offset >= strlen($xml); });
    $stream->method('read')->willReturnCallback(static function () use (&$offset, $xml): string {
      $chunk = substr($xml, $offset, 19);
      $offset += strlen($chunk);
      return $chunk;
    });
    $stream->expects(self::once())->method('close');
    $http = $this->createMock(ClientInterface::class);
    $http->expects(self::once())->method('request')->willReturn(new Response(200, [], $stream));
    self::assertSame('testvideo02', $this->service($http)->homepageFeed()['video']['id']);
  }

  public function testExpiryTimeoutAndCacheEvictionKeepLastVerifiedDataWithCooldown(): void {
    $http = $this->createMock(ClientInterface::class);
    $calls = 0;
    $http->expects(self::exactly(2))->method('request')->willReturnCallback(function () use (&$calls) {
      if (++$calls === 1) {
        return new Response(200, [], $this->feed());
      }
      throw new ConnectException('sensitive transport detail', new Request('GET', 'https://www.youtube.com/'));
    });
    $service = $this->service($http);
    $valid = $service->homepageFeed();
    $this->now += 3601;
    $this->cache = [];
    $result = $service->homepageFeed();
    self::assertSame($valid['video'], $result['video']);
    self::assertTrue($result['stale']);
    self::assertStringNotContainsString('sensitive', $result['error']);
    self::assertSame($result, $service->homepageFeed());
    self::assertNotEmpty($service->status()['last_error']);
  }

  public function testEmptyFeedIsAValidEmptyResult(): void {
    $http = $this->createMock(ClientInterface::class);
    $http->expects(self::once())->method('request')->willReturn(new Response(200, [], $this->feed(FALSE)));
    $service = $this->service($http);
    self::assertSame([], $service->homepageFeed()['video']);
    self::assertSame('', $service->status()['last_error']);
    self::assertTrue($service->homepageFeed()['from_cache']);
  }

  public function testCronSkipsFreshCacheAndRefreshesAfterExpiry(): void {
    $http = $this->createMock(ClientInterface::class);
    $http->expects(self::exactly(2))->method('request')->willReturnOnConsecutiveCalls(new Response(200, [], $this->feed()), new Response(200, [], $this->feed()));
    $service = $this->service($http);
    $service->refreshIfDue();
    $service->refreshIfDue();
    $first = $service->status()['last_success'];
    $this->now += 3601;
    $service->refreshIfDue();
    self::assertGreaterThan($first, $service->status()['last_success']);
    self::assertTrue($service->homepageFeed()['from_cache']);
  }

  public function testInvalidChannelAndDisabledFeedNeverContactProviders(): void {
    $http = $this->createMock(ClientInterface::class);
    $http->expects(self::never())->method('request');
    foreach (['', '../private', 'https://localhost/private', 'UCshort'] as $channel) {
      $result = $this->service($http, ['channel_id' => $channel])->homepageFeed();
      self::assertSame([], $result['video']);
      self::assertNotEmpty($result['error']);
    }
    self::assertSame([], $this->service($http, ['videos_enabled' => FALSE])->homepageFeed()['video']);
  }

  public function testUnsafeXmlWrongChannelAndInvalidVideoAreRejected(): void {
    foreach ([
      '',
      '<!DOCTYPE feed [<!ENTITY x SYSTEM "file:///etc/passwd">]><feed>&x;</feed>',
      '<feed>',
      str_replace(self::CHANNEL, 'UCbbbbbbbbbbbbbbbbbbbbbb', $this->feed()),
      str_replace(['testvideo01', 'testvideo02'], 'invalid', $this->feed()),
    ] as $xml) {
      $this->state = $this->cache = [];
      $http = $this->createMock(ClientInterface::class);
      $http->expects(self::once())->method('request')->willReturn(new Response(200, [], $xml));
      $result = $this->service($http)->homepageFeed();
      self::assertSame([], $result['video']);
      self::assertNotEmpty($result['error']);
    }
  }

  public function testProviderErrorsDoNotLeakTransportDetailsOrAttemptApiWithoutCredentials(): void {
    $http = $this->createMock(ClientInterface::class);
    $http->expects(self::once())->method('request')->willReturn(new Response(403, [], 'private debug response'));
    $result = $this->service($http)->homepageFeed();
    self::assertStringContainsString('HTTP 403', $result['error']);
    self::assertStringContainsString('YOUTUBE_API_KEY is not configured', $result['error']);
    self::assertStringNotContainsString('private debug', $result['error']);
  }

  public function testApiFallbackVerifiesChannelAndPublicVideoAndDoesNotStoreKey(): void {
    putenv('YOUTUBE_API_KEY=fixture-secret-not-real');
    $http = $this->createMock(ClientInterface::class);
    $responses = [
      new Response(404),
      new Response(200, [], json_encode(['items' => [['id' => self::CHANNEL, 'contentDetails' => ['relatedPlaylists' => ['uploads' => 'UUaaaaaaaaaaaaaaaaaaaaaa']]]]])),
      new Response(200, [], json_encode(['items' => [['contentDetails' => ['videoId' => 'testvideo02']]]])),
      new Response(200, [], json_encode(['items' => [['id' => 'testvideo02', 'status' => ['privacyStatus' => 'public'], 'snippet' => ['channelId' => self::CHANNEL, 'title' => 'Unit fixture upload', 'publishedAt' => '2026-11-01T10:00:00Z']]]])),
    ];
    $http->expects(self::exactly(4))->method('request')->willReturnOnConsecutiveCalls(...$responses);
    $service = $this->service($http);
    self::assertSame('testvideo02', $service->homepageFeed()['video']['id']);
    self::assertSame('data_api', $service->status()['source']);
    self::assertStringNotContainsString('fixture-secret', json_encode([$this->state, $this->cache, $service->status()]));
  }

  private function feed(bool $entries = TRUE): string {
    $xml = '<feed xmlns="http://www.w3.org/2005/Atom" xmlns:yt="http://www.youtube.com/xml/schemas/2015"><yt:channelId>' . substr(self::CHANNEL, 2) . '</yt:channelId>';
    if ($entries) {
      foreach (['testvideo01' => '2026-10-01T10:00:00Z', 'testvideo02' => '2026-11-01T10:00:00Z'] as $id => $date) {
        $xml .= '<entry><yt:channelId>' . self::CHANNEL . '</yt:channelId><yt:videoId>' . $id . '</yt:videoId><title>Unit fixture upload</title><published>' . $date . '</published></entry>';
      }
    }
    return $xml . '</feed>';
  }

  private function service(ClientInterface $http, array $overrides = []): YouTubeFeedService {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('platforms.youtube')->willReturn(array_replace(['enabled' => TRUE, 'videos_enabled' => TRUE, 'channel_id' => self::CHANNEL, 'feed_cache_lifetime' => 3600], $overrides));
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('reg_core.social_media')->willReturn($config);
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturnCallback(function (string $cid) {
      if (!isset($this->cache[$cid])) {
        return FALSE;
      }
      return (object) ['data' => $this->cache[$cid]['data'], 'valid' => $this->cache[$cid]['expires'] > $this->now];
    });
    $cache->method('set')->willReturnCallback(function (string $cid, mixed $data, int $expires) { $this->cache[$cid] = ['data' => $data, 'expires' => $expires]; });
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(fn(string $key, mixed $default = NULL) => $this->state[$key] ?? $default);
    $state->method('set')->willReturnCallback(function (string $key, mixed $value) { $this->state[$key] = $value; });
    $state->method('setMultiple')->willReturnCallback(function (array $values) { $this->state = array_replace($this->state, $values); });
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturnCallback(fn() => $this->now);
    $formatter = $this->createMock(DateFormatterInterface::class);
    $formatter->method('format')->willReturn('01 Nov 2026');
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    return new YouTubeFeedService($http, $factory, $cache, $this->createMock(CacheTagsInvalidatorInterface::class), $state, $time, $formatter, $lock);
  }

}
