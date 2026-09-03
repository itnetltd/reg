<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\State\StateInterface;
use Drupal\reg_core\Social\XFeedService;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the server-side, cache-first public X feed.
 */
#[CoversClass(XFeedService::class)]
final class XFeedServiceTest extends TestCase {

  public function testMissingTokenUsesFallbackWithoutHttpRequest(): void {
    $previous = getenv('X_BEARER_TOKEN');
    putenv('X_BEARER_TOKEN');
    try {
      $http = $this->createMock(ClientInterface::class);
      $http->expects(self::never())->method('request');
      $cache = $this->createMock(CacheBackendInterface::class);
      $cache->method('get')->willReturn(FALSE);
      $state = $this->createMock(StateInterface::class);
      $state->method('get')->willReturn('');

      $service = $this->service($http, $cache, $state);
      $result = $service->homepageFeed();

      self::assertSame([], $result['items']);
      self::assertFalse($result['from_cache']);
      self::assertFalse($result['stale']);
      self::assertSame('X API bearer token is not configured.', $result['error']);
    }
    finally {
      $this->restoreEnvironment($previous);
    }
  }

  public function testSuccessfulPostsAreNormalizedAndSecondReadUsesCache(): void {
    $previous = getenv('X_BEARER_TOKEN');
    putenv('X_BEARER_TOKEN=unit-test-token');
    try {
      $responses = [
        new Response(200, [], json_encode(['data' => ['id' => '12345']], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode([
          'data' => [[
            'id' => '98765',
            'text' => '<b>Public electricity update</b>',
            'created_at' => '2026-08-27T08:00:00.000Z',
            'attachments' => ['media_keys' => ['photo-1']],
          ]],
          'includes' => [
            'media' => [[
              'media_key' => 'photo-1',
              'type' => 'photo',
              'url' => 'https://pbs.twimg.com/media/example.jpg',
              'alt_text' => 'REG field team',
              'width' => 1200,
              'height' => 800,
            ]],
          ],
        ], JSON_THROW_ON_ERROR)),
      ];
      $http = $this->createMock(ClientInterface::class);
      $http->expects(self::exactly(2))
        ->method('request')
        ->willReturnOnConsecutiveCalls(...$responses);

      $items = [];
      $cache = $this->createMock(CacheBackendInterface::class);
      $cache->method('get')->willReturnCallback(
        static function (string $cid, bool $allow_invalid = FALSE) use (&$items): object|false {
          return isset($items[$cid])
            ? (object) ['data' => $items[$cid], 'valid' => TRUE]
            : FALSE;
        },
      );
      $cache->method('set')->willReturnCallback(
        static function (string $cid, mixed $data) use (&$items): void {
          $items[$cid] = $data;
        },
      );
      $state = $this->createMock(StateInterface::class);

      $service = $this->service($http, $cache, $state);
      $first = $service->homepageFeed();
      $second = $service->homepageFeed();

      self::assertSame('Public electricity update', $first['items'][0]['text']);
      self::assertSame('https://x.com/reg_rwanda/status/98765', $first['items'][0]['url']);
      self::assertSame('https://pbs.twimg.com/media/example.jpg', $first['items'][0]['image']['url']);
      self::assertFalse($first['from_cache']);
      self::assertTrue($second['from_cache']);
      self::assertSame($first['items'], $second['items']);
    }
    finally {
      $this->restoreEnvironment($previous);
    }
  }

  public function testRateLimitReturnsStaleCachedPosts(): void {
    $previous = getenv('X_BEARER_TOKEN');
    putenv('X_BEARER_TOKEN=unit-test-token');
    try {
      $stale = [['id' => '1', 'text' => 'Cached update']];
      $cache = $this->createMock(CacheBackendInterface::class);
      $cache->method('get')->willReturn(
        (object) ['data' => $stale, 'valid' => FALSE],
      );
      $http = $this->createMock(ClientInterface::class);
      $http->expects(self::once())
        ->method('request')
        ->willReturn(new Response(429, [], '{}'));
      $state = $this->createMock(StateInterface::class);
      $state->method('get')->willReturn('');

      $service = $this->service($http, $cache, $state, ['api_user_id' => '12345']);
      $result = $service->homepageFeed();

      self::assertSame($stale, $result['items']);
      self::assertTrue($result['from_cache']);
      self::assertTrue($result['stale']);
      self::assertSame('X API rate limit reached.', $result['error']);
    }
    finally {
      $this->restoreEnvironment($previous);
    }
  }

  /**
   * Builds the service with safe defaults and test doubles.
   *
   * @param array<string, mixed> $overrides
   *   X platform configuration overrides.
   */
  private function service(
    ClientInterface $http,
    CacheBackendInterface $cache,
    StateInterface $state,
    array $overrides = [],
  ): XFeedService {
    $platform = array_replace([
      'enabled' => TRUE,
      'show_homepage_feed' => TRUE,
      'api_enabled' => TRUE,
      'api_username' => 'reg_rwanda',
      'api_user_id' => '',
      'api_cache_lifetime' => 900,
      'api_homepage_post_count' => 3,
      'api_exclude_replies' => TRUE,
      'api_exclude_reposts' => TRUE,
    ], $overrides);
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('platforms.x')
      ->willReturn($platform);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('reg_core.social_media')
      ->willReturn($config);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1787817600);
    $date_formatter = $this->createMock(DateFormatterInterface::class);
    $date_formatter->method('format')->willReturn('Aug 27, 2026');

    return new XFeedService(
      $http,
      $config_factory,
      $cache,
      $this->createMock(CacheTagsInvalidatorInterface::class),
      $state,
      $time,
      $date_formatter,
      $this->createMock(LoggerInterface::class),
    );
  }

  private function restoreEnvironment(string|false $value): void {
    $value === FALSE
      ? putenv('X_BEARER_TOKEN')
      : putenv('X_BEARER_TOKEN=' . $value);
  }

}
