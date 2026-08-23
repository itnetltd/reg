<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\reg_core\Branch\BranchMapNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the public branch-marker allowlist.
 */
#[CoversClass(BranchMapNormalizer::class)]
final class BranchMapNormalizerTest extends TestCase {

  /**
   * Confirms private and editorial values are excluded from marker data.
   */
  public function testMarkerAllowlist(): void {
    $normalizer = new BranchMapNormalizer();
    $markers = $normalizer->normalize([[
      'id' => 9,
      'title' => 'Test branch',
      'district' => 'Test district',
      'address' => 'Public address',
      'telephone' => '2727',
      'opening_hours' => '08:00-17:00',
      'url' => '/branches/9',
      'latitude' => -1.9,
      'longitude' => 30.1,
      'email' => 'not-in-marker@example.invalid',
      'notes' => 'Not in marker',
    ]]);

    self::assertSame([
      'id',
      'name',
      'district',
      'address',
      'telephone',
      'opening_hours',
      'url',
      'latitude',
      'longitude',
    ], array_keys($markers[0]));
    self::assertArrayNotHasKey('email', $markers[0]);
    self::assertArrayNotHasKey('notes', $markers[0]);
  }

  /**
   * Branches without coordinates remain in the list but not the map.
   */
  public function testMissingCoordinatesAreSkipped(): void {
    $normalizer = new BranchMapNormalizer();
    self::assertSame([], $normalizer->normalize([['latitude' => NULL, 'longitude' => NULL]]));
  }

}
