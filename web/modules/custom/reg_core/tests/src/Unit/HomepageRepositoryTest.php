<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\reg_core\Homepage\HomepageRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies homepage hero scheduling and editorial ordering rules.
 */
#[CoversClass(HomepageRepository::class)]
final class HomepageRepositoryTest extends TestCase {

  private const NOW = 1_800_000_000;

  /**
   * Provides normalized hero states and expected eligibility.
   */
  public static function eligibilityProvider(): array {
    return [
      'unscheduled active hero' => [self::hero(), TRUE],
      'current scheduled hero' => [self::hero(['start_timestamp' => self::NOW - 60, 'end_timestamp' => self::NOW + 60]), TRUE],
      'future hero' => [self::hero(['start_timestamp' => self::NOW + 1]), FALSE],
      'expired hero' => [self::hero(['end_timestamp' => self::NOW - 1]), FALSE],
      'unpublished hero' => [self::hero(['published' => FALSE]), FALSE],
      'inactive hero' => [self::hero(['active' => FALSE]), FALSE],
    ];
  }

  /**
   * Tests publication, active, and time-window eligibility.
   */
  #[DataProvider('eligibilityProvider')]
  public function testEligibility(array $hero, bool $expected): void {
    self::assertSame($expected, HomepageRepository::heroIsEligible($hero, self::NOW));
  }

  /**
   * Tests ascending weight followed by newest creation time.
   */
  public function testHeroSorting(): void {
    $heroes = [
      self::hero(['id' => 1, 'weight' => 10, 'created' => 500]),
      self::hero(['id' => 2, 'weight' => -10, 'created' => 100]),
      self::hero(['id' => 3, 'weight' => 0, 'created' => 100]),
      self::hero(['id' => 4, 'weight' => 0, 'created' => 200]),
      self::hero(['id' => 5, 'weight' => 10, 'created' => 900]),
    ];
    HomepageRepository::sortHeroes($heroes);
    self::assertSame([2, 4, 3, 5, 1], array_column($heroes, 'id'));
  }

  /**
   * Builds a complete normalized hero for unit tests.
   */
  private static function hero(array $overrides = []): array {
    return array_replace([
      'id' => 1,
      'published' => TRUE,
      'active' => TRUE,
      'start_timestamp' => 0,
      'end_timestamp' => 0,
      'weight' => 0,
      'created' => 1,
    ], $overrides);
  }

}
