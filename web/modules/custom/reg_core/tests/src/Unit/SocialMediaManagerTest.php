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

}
