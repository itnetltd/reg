<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\reg_core\Media\FlickrGallery;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Tests newest-album selection, photo limits and safe API usage.
 */
final class FlickrAlbumGalleryTest extends TestCase {

  private string|false $previousKey;

  protected function setUp(): void {
    parent::setUp();
    $this->previousKey = getenv('FLICKR_API_KEY');
    putenv('FLICKR_API_KEY=fixture-secret');
  }

  protected function tearDown(): void {
    $this->previousKey === FALSE ? putenv('FLICKR_API_KEY') : putenv('FLICKR_API_KEY=' . $this->previousKey);
    parent::tearDown();
  }

  public function testFourNewestAlbumsAndTwelveImages(): void {
    $sets = [];
    foreach ([101 => 12, 102 => 45, 103 => 27, 104 => 99, 105 => 67] as $id => $created) {
      $sets[] = [
        'id' => (string) $id, 'title' => ['_content' => 'REG Album ' . $id],
        'date_create' => (string) $created, 'photos' => '24',
        'primary' => '900' . $id, 'server' => '4321', 'secret' => 'a1b2c3',
      ];
    }
    $photos = [];
    for ($i = 1; $i <= 16; $i++) {
      $photos[] = [
        'id' => (string) (8000 + $i), 'server' => '4321',
        'secret' => 'deafbeef', 'title' => 'REG picture ' . $i,
      ];
    }
    $calls = [];
    $http = $this->createMock(ClientInterface::class);
    $http->expects(self::exactly(2))->method('request')->willReturnCallback(
      function (string $method, string $url, array $options) use (&$calls, $sets, $photos): Response {
        self::assertSame('GET', $method);
        self::assertSame('https://www.flickr.com/services/rest/', $url);
        self::assertSame('fixture-secret', $options['query']['api_key']);
        $calls[] = $options['query']['method'];
        if ($options['query']['method'] === 'flickr.photosets.getList') {
          return new Response(200, [], json_encode(['stat' => 'ok', 'photosets' => ['photoset' => $sets]]));
        }
        self::assertSame('102', $options['query']['photoset_id']);
        return new Response(200, [], json_encode(['stat' => 'ok', 'photoset' => ['photo' => $photos]]));
      }
    );
    $result = $this->service($http)->albumGallery('102');
    self::assertSame(['104', '105', '102', '103'], array_column($result['albums'], 'id'));
    self::assertSame('REG Album 102', $result['active_album']['title']);
    self::assertCount(12, $result['items']);
    self::assertSame('https://live.staticflickr.com/4321/8001_deafbeef_z.jpg', $result['items'][0]['image_url']);
    self::assertTrue($result['album_source_available']);
    self::assertSame(['flickr.photosets.getList', 'flickr.photosets.getPhotos'], $calls);
    self::assertStringNotContainsString('fixture-secret', json_encode($result));
  }

  public function testMissingApiKeyRetainsOriginalFeed(): void {
    putenv('FLICKR_API_KEY');
    $http = $this->createMock(ClientInterface::class);
    $http->expects(self::once())->method('request')->willReturn(new Response(200, [], json_encode([
      'items' => [['title' => 'Recent REG photo',
        'media' => ['m' => 'https://live.staticflickr.com/12/1_a_m.jpg'],
        'link' => 'https://www.flickr.com/photos/127491669@N07/123']],
    ])));
    $result = $this->service($http)->albumGallery();
    self::assertSame([], $result['albums']);
    self::assertFalse($result['api_key_configured']);
    self::assertCount(1, $result['items']);
  }

  private function service(ClientInterface $http): FlickrGallery {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('platforms.flickr')->willReturn([
      'enabled' => TRUE, 'gallery_enabled' => TRUE,
      'url' => 'https://www.flickr.com/photos/127491669@N07/albums/',
      'user_id' => '127491669@N07', 'album_id' => '',
    ]);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('reg_core.social_media')->willReturn($config);
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    return new FlickrGallery($http, $factory, $cache, $this->createMock(LoggerChannelFactoryInterface::class));
  }

}
