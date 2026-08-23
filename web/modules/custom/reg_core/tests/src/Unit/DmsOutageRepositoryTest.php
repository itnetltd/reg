<?php

declare(strict_types=1);

namespace Drupal\Tests\reg_core\Unit;

use Drupal\reg_core\Outage\OutageRepository;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests source-neutral ordering in the manual outage repository.
 */
#[CoversClass(OutageRepository::class)]
final class DmsOutageRepositoryTest extends UnitTestCase {

  public function testOperationalPriorityAndPlannedTimeOrdering(): void {
    $outages = [
      ['status' => 'restored', 'start_time' => '2026-01-01T08:00:00+00:00', 'last_updated' => '2026-01-02T00:00:00+00:00'],
      ['status' => 'scheduled', 'start_time' => '2026-08-23T10:00:00+00:00', 'last_updated' => '2026-01-01T00:00:00+00:00'],
      ['status' => 'ongoing', 'start_time' => '2026-08-22T10:00:00+00:00', 'last_updated' => '2026-01-01T00:00:00+00:00'],
      ['status' => 'scheduled', 'start_time' => '2026-08-23T08:00:00+00:00', 'last_updated' => '2026-01-01T00:00:00+00:00'],
    ];
    usort($outages, [OutageRepository::class, 'sortOutages']);
    self::assertSame(['ongoing', 'scheduled', 'scheduled', 'restored'], array_column($outages, 'status'));
    self::assertSame('2026-08-23T08:00:00+00:00', $outages[1]['start_time']);
  }

}
