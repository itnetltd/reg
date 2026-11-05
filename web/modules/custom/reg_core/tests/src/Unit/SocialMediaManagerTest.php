<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\reg_core\Social\SocialMediaManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests public filtering of configured REG social channels.
 */
#[CoversClass(SocialMediaManager::class)]
final class SocialMediaManagerTest extends TestCase {

  public function testYouTubeUsesOnlyEnabledConfiguredChannel(): void {
    $platform = ['url' => 'https://www.youtube.com/@approved-channel', 'enabled' => TRUE, 'videos_enabled' => TRUE];
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('platforms.youtube')->willReturnCallback(static function () use (&$platform): array { return $platform; });
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('reg_core.social_media')->willReturn($config);
    $manager = new SocialMediaManager($factory);
    self::assertSame($platform['url'], $manager->homepageYouTubeChannel()['url']);
    foreach (['https://example.com/@channel', 'http://www.youtube.com/@channel', 'https://user:password@www.youtube.com/@channel', 'https://www.youtube.com/'] as $url) {
      $platform['url'] = $url;
      self::assertSame([], $manager->homepageYouTubeChannel());
    }
    $platform['url'] = 'https://www.youtube.com/@approved-channel';
    $platform['videos_enabled'] = FALSE;
    self::assertFalse($manager->homepageYouTubeChannel()['videos_enabled']);
    $platform['videos_enabled'] = TRUE;
    $platform['enabled'] = FALSE;
    self::assertSame([], $manager->homepageYouTubeChannel());
  }

  public function testPlacementAndSafetyFiltering(): void {
    $platforms = [
      'x' => [
        'url' => 'https://x.com/verified_reg',
        'display_name' => 'Rwanda Energy Group',
        'enabled' => TRUE,
        'show_utility' => TRUE,
        'show_footer' => TRUE,
        'show_homepage_feed' => TRUE,
      ],
      'facebook' => [
        'url' => 'https://facebook.com/verified-reg',
        'enabled' => TRUE,
        'show_utility' => FALSE,
        'show_footer' => TRUE,
      ],
      'youtube' => [
        'url' => '',
        'enabled' => TRUE,
        'show_utility' => TRUE,
        'show_footer' => TRUE,
      ],
      'instagram' => [
        'url' => 'javascript:alert(1)',
        'enabled' => TRUE,
        'show_utility' => TRUE,
        'show_footer' => TRUE,
      ],
      'linkedin' => [
        'url' => 'https://linkedin.com/company/reg',
        'enabled' => FALSE,
        'show_utility' => TRUE,
        'show_footer' => TRUE,
      ],
    ];

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      static fn(string $key): ?array => $platforms[substr($key, strlen('platforms.'))] ?? NULL,
    );
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('reg_core.social_media')
      ->willReturn($config);

    $manager = new SocialMediaManager($config_factory);
    self::assertSame(['x'], array_column($manager->linksForPlacement('utility'), 'id'));
    self::assertSame(['x', 'facebook'], array_column($manager->linksForPlacement('footer'), 'id'));
    self::assertSame(['x', 'facebook'], array_column($manager->linksForPlacement('media_center'), 'id'));
    self::assertSame([], $manager->linksForPlacement('unsupported'));
    self::assertSame([
      'url' => 'https://x.com/verified_reg',
      'handle' => '@verified_reg',
      'display_name' => 'Rwanda Energy Group',
    ], $manager->homepageXFeed());
  }

  public function testSocialPairsSkipInvalidAccountsWithoutEmptySlots(): void {
    $platforms = [];
    foreach ([
      'x' => 'https://x.com/reg_rwanda',
      'youtube' => 'https://www.youtube.com/channel/UCstIekq5IuM1P3-ZJcxRCxQ',
      'instagram' => 'https://www.instagram.com/regrwanda/',
      'facebook' => 'https://web.facebook.com/reg.rw?_rdc=1&_rdr#',
      'tiktok' => 'https://www.tiktok.com/@reg_rwanda',
      'linkedin' => 'https://www.linkedin.com/company/rwanda-energy-group/',
    ] as $id => $url) {
      $platforms[$id] = ['enabled' => TRUE, 'show_homepage_feed' => TRUE, 'url' => $url];
    }
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static function (string $key) use (&$platforms): mixed {
      return str_starts_with($key, 'platforms.') ? ($platforms[substr($key, 10)] ?? NULL) : NULL;
    });
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $manager = new SocialMediaManager($factory);
    $pairs = static fn(): array => array_map(static fn(array $pair): array => array_column($pair, 'id'), $manager->homepageSocialCards()['pairs']);
    self::assertSame([['x', 'youtube'], ['instagram', 'facebook'], ['tiktok', 'linkedin']], $pairs());
    $platforms['tiktok']['enabled'] = FALSE;
    self::assertSame([['x', 'youtube'], ['instagram', 'facebook'], ['linkedin', 'x']], $pairs());
    $platforms['instagram']['url'] = 'https://example.com/regrwanda';
    self::assertSame([['x', 'youtube'], ['facebook', 'linkedin']], $pairs());
    foreach (['facebook', 'linkedin'] as $id) $platforms[$id]['enabled'] = FALSE;
    self::assertSame([['x', 'youtube']], $pairs());
    $platforms['youtube']['enabled'] = FALSE;
    self::assertSame([['x']], $pairs());
    $platforms['x']['enabled'] = FALSE;
    self::assertSame([], $pairs());
  }

  public function testSocialDestinationsRejectUnsafeOrUnrelatedProfiles(): void {
    foreach ([
      'http://www.tiktok.com/@reg_rwanda', 'https://www.tiktok.com.evil.test/@reg_rwanda',
      'https://user:pass@www.tiktok.com/@reg_rwanda', 'https://www.tiktok.com:443/@reg_rwanda',
      'https://www.tiktok.com/', 'javascript:alert(1)',
    ] as $url) {
      self::assertSame('', SocialMediaManager::validatedProfileUrl('tiktok', $url));
    }
    self::assertSame('', SocialMediaManager::validatedProfileUrl('instagram', 'https://www.instagram.com/accounts/login/'));
    self::assertSame('', SocialMediaManager::validatedPostUrl('tiktok', 'https://www.tiktok.com/@unrelated/video/123', 'https://www.tiktok.com/@reg_rwanda'));
    self::assertSame('', SocialMediaManager::validatedPostUrl('x', 'https://x.com/unrelated/status/123', 'https://x.com/reg_rwanda'));
    self::assertSame('https://www.linkedin.com/posts/rwanda-energy-group_activity-123', SocialMediaManager::validatedPostUrl('linkedin', 'https://www.linkedin.com/posts/rwanda-energy-group_activity-123', 'https://www.linkedin.com/company/rwanda-energy-group/'));
    self::assertSame('', SocialMediaManager::validatedPostUrl('instagram', 'https://example.com/p/123', 'https://www.instagram.com/regrwanda/'));
  }

}
