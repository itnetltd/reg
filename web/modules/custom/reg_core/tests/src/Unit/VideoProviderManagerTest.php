<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\reg_core\Video\VideoProviderManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests provider allowlisting and deferred playback URLs.
 */
#[CoversClass(VideoProviderManager::class)]
final class VideoProviderManagerTest extends TestCase {

  /**
   * @return array<string, array{string, string}>
   */
  public static function youtubeUrls(): array {
    return [
      'watch' => ['https://www.youtube.com/watch?v=abcdefghijk', 'abcdefghijk'],
      'short' => ['https://youtu.be/abcdefghijk', 'abcdefghijk'],
      'shorts' => ['https://youtube.com/shorts/abcdefghijk', 'abcdefghijk'],
      'nocookie' => ['https://www.youtube-nocookie.com/embed/abcdefghijk', 'abcdefghijk'],
    ];
  }

  #[DataProvider('youtubeUrls')]
  public function testYouTubeUsesPrivacyEnhancedEmbed(string $url, string $id): void {
    $resolved = (new VideoProviderManager())->resolve('youtube', $url);
    self::assertSame('youtube', $resolved['provider']);
    self::assertSame('https://www.youtube-nocookie.com/embed/' . $id . '?rel=0', $resolved['embed_url']);
    self::assertSame('https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg', $resolved['thumbnail_url']);
    self::assertSame('', $resolved['direct_video_url']);
  }

  public function testApprovedExternalDirectVideoIsNotIframeEmbed(): void {
    $resolved = (new VideoProviderManager())->resolve('approved_external', 'https://media.example.org/approved/update.mp4');
    self::assertSame('', $resolved['embed_url']);
    self::assertSame('https://media.example.org/approved/update.mp4', $resolved['direct_video_url']);
  }

  /**
   * @return array<string, array{string, string}>
   */
  public static function rejectedUrls(): array {
    return [
      'http' => ['youtube', 'http://youtube.com/watch?v=abcdefghijk'],
      'wrong youtube host' => ['youtube', 'https://example.org/watch?v=abcdefghijk'],
      'invalid id' => ['youtube', 'https://youtube.com/watch?v=short'],
      'credentials' => ['approved_external', 'https://user@example.org/video.mp4'],
      'unknown provider' => ['iframe', 'https://example.org/video'],
    ];
  }

  #[DataProvider('rejectedUrls')]
  public function testUnsafeOrUnsupportedUrlsAreRejected(string $provider, string $url): void {
    self::assertSame([], (new VideoProviderManager())->resolve($provider, $url));
  }

}
