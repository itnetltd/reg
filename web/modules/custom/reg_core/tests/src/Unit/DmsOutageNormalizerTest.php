<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\reg_core\Dms\DmsOutageNormalizer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests normalization of GE DMS outage payloads.
 */
#[CoversClass(DmsOutageNormalizer::class)]
#[Group('reg_core')]
final class DmsOutageNormalizerTest extends UnitTestCase {

  /**
   * Verifies GE-style aliases normalize to every public outage field.
   */
  public function testNormalizeGePayload(): void {
    $normalizer = new DmsOutageNormalizer();
    $outages = $normalizer->normalizeList([
      'data' => [
        'items' => [[
          'eventId' => 'GE-123',
          'outageType' => 'Planned',
          'outageStatus' => 'Current',
          'startTime' => '2026-08-06T08:00:00+02:00',
          'estimatedRestorationTime' => '2026-08-06T12:00:00+02:00',
          'restoredAt' => '2026-08-06T11:30:00+02:00',
          'location' => [
            'district' => 'Gasabo',
            'sector' => 'Kacyiru',
          ],
          'district' => 'Gasabo',
          'sector' => 'Kacyiru',
          'affectedArea' => 'Parts of Kacyiru',
          'publicReason' => '<b>Scheduled maintenance</b>',
          'customersAffected' => 1200,
          'latitude' => -1.94,
          'longitude' => 30.08,
          'geoJson' => [
            'type' => 'Feature',
            'properties' => ['feeder' => 'SECRET-FEEDER'],
            'geometry' => [
              'type' => 'Point',
              'coordinates' => [30.08, -1.94],
            ],
          ],
          'updatedAt' => '2026-08-06T09:00:00+02:00',
        ]],
      ],
    ]);

    self::assertCount(1, $outages);
    self::assertSame([
      'outage_id',
      'outage_type',
      'status',
      'start_time',
      'expected_restoration',
      'actual_restoration',
      'district',
      'sector',
      'affected_area',
      'public_reason',
      'customers_affected',
      'latitude',
      'longitude',
      'geojson',
      'last_updated',
    ], array_keys($outages[0]));
    self::assertSame('GE-123', $outages[0]['outage_id']);
    self::assertSame('planned', $outages[0]['outage_type']);
    self::assertSame('current', $outages[0]['status']);
    self::assertSame('Scheduled maintenance', $outages[0]['public_reason']);
    self::assertSame(1200, $outages[0]['customers_affected']);
    self::assertSame(30.08, $outages[0]['longitude']);
    self::assertSame(-1.94, $outages[0]['latitude']);
    self::assertSame('Point', $outages[0]['geojson']['type']);
    self::assertArrayNotHasKey('properties', $outages[0]['geojson']);
  }

  /**
   * Verifies invalid records and unsafe optional geometry are rejected safely.
   */
  public function testInvalidRecordsAreHandledSafely(): void {
    $normalizer = new DmsOutageNormalizer();
    $outages = $normalizer->normalizeList([
      'outages' => [
        ['status' => 'current'],
        [
          'id' => 'GE-456',
          'startDate' => 'not-a-date',
          'latitude' => 400,
          'longitude' => 500,
          'geoJson' => '{invalid',
        ],
      ],
    ]);

    self::assertCount(1, $outages);
    self::assertSame('GE-456', $outages[0]['outage_id']);
    self::assertNull($outages[0]['start_time']);
    self::assertNull($outages[0]['latitude']);
    self::assertNull($outages[0]['longitude']);
    self::assertNull($outages[0]['geojson']);
  }

}
