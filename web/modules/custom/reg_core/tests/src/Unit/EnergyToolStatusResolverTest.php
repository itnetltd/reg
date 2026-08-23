<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\reg_core\Energy\EnergyToolStatusResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies automatic Energy Awareness status rules.
 */
#[CoversClass(EnergyToolStatusResolver::class)]
final class EnergyToolStatusResolverTest extends TestCase {

  /**
   * Provides configured and unconfigured calculator states.
   */
  public static function statusProvider(): array {
    return [
      'approved bill schedule' => ['bill_estimator', 'disabled', TRUE, FALSE, 'available'],
      'missing bill schedule' => ['bill_estimator', 'available', FALSE, FALSE, 'awaiting_validation'],
      'approved carbon factor' => ['carbon_footprint', 'awaiting_validation', TRUE, TRUE, 'available'],
      'missing carbon factor' => ['carbon_footprint', 'available', TRUE, FALSE, 'awaiting_validation'],
      'safety is guidance' => ['safety_efficiency', 'disabled', TRUE, TRUE, 'guidance'],
      'valid editor state' => ['other', 'coming_soon', TRUE, TRUE, 'coming_soon'],
      'invalid editor state' => ['other', 'invented', TRUE, TRUE, 'disabled'],
    ];
  }

  /**
   * Tests that configuration overrides editor state for known calculators.
   */
  #[DataProvider('statusProvider')]
  public function testDetermine(
    string $key,
    string $editorStatus,
    bool $billConfigured,
    bool $carbonConfigured,
    string $expected,
  ): void {
    self::assertSame($expected, EnergyToolStatusResolver::determine(
      $key,
      $editorStatus,
      $billConfigured,
      $carbonConfigured,
    ));
  }

}
