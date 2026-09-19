<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\reg_core\Energy\BillEstimator;
use Drupal\reg_core\Exception\BillEstimatorUnavailableException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies tariff calculations, effective dates and safety validation.
 */
#[CoversClass(BillEstimator::class)]
final class BillEstimatorTest extends TestCase {

  public function testProgressiveCalculation(): void {
    $result = BillEstimator::calculateSchedule([
      'label' => 'Residential', 'category' => 'residential', 'tariff_type' => 'progressive', 'effective_from' => '2025-10-01',
      'bands' => [
        ['enabled' => TRUE, 'label' => 'First', 'min' => 0, 'max' => 20, 'rate' => 89, 'weight' => 0],
        ['enabled' => TRUE, 'label' => 'Second', 'min' => 20, 'max' => 50, 'rate' => 310, 'weight' => 1],
        ['enabled' => TRUE, 'label' => 'Third', 'min' => 50, 'max' => '', 'rate' => 369, 'weight' => 2],
      ],
    ], 70);
    self::assertSame(18460.0, $result['subtotal']);
    self::assertSame([1780.0, 9300.0, 7380.0], array_column($result['rows'], 'amount'));
  }

  public function testFlatCalculation(): void {
    $result = BillEstimator::calculateSchedule([
      'label' => 'Telecom towers', 'category' => 'telecom_towers', 'customer_group' => 'sector_specific',
      'tariff_type' => 'flat', 'flat_rate' => 289, 'effective_from' => '2025-10-01',
    ], 12.5);
    self::assertSame(3612.5, $result['total']);
  }

  public function testIndustrialCalculation(): void {
    $result = BillEstimator::calculateSchedule([
      'label' => 'Small industry', 'category' => 'industrial_smart_small', 'tariff_type' => 'industrial',
      'energy_rate' => 175, 'peak_demand_rate' => 11017, 'off_peak_demand_rate' => 0,
      'shoulder_demand_rate' => 4008, 'effective_from' => '2025-10-01',
    ], 100, ['peak' => 2, 'off_peak' => 5, 'shoulder' => 3]);
    self::assertSame(51558.0, $result['total']);
    self::assertCount(4, $result['rows']);
  }

  public function testFeeTogglesAndConfigurationChange(): void {
    $schedule = ['label' => 'Flat', 'category' => 'flat', 'customer_group' => 'sector_specific', 'tariff_type' => 'flat', 'flat_rate' => 10, 'effective_from' => '2025-10-01'];
    $fees = [
      ['enabled' => TRUE, 'included' => TRUE, 'type' => 'percentage', 'value' => 18, 'label' => 'VAT'],
      ['enabled' => TRUE, 'included' => TRUE, 'type' => 'fixed', 'value' => 50, 'label' => 'Approved fee'],
      ['enabled' => TRUE, 'included' => FALSE, 'type' => 'percentage', 'value' => 5, 'label' => 'Excluded fee'],
      ['enabled' => FALSE, 'included' => TRUE, 'type' => 'fixed', 'value' => 999, 'label' => 'Disabled fee'],
    ];
    self::assertSame(1230.0, BillEstimator::calculateSchedule($schedule, 100, [], $fees)['total']);
    $fees[0]['value'] = 20;
    self::assertSame(1250.0, BillEstimator::calculateSchedule($schedule, 100, [], $fees)['total']);
  }

  public function testEffectiveDateSelectionAndHistory(): void {
    $schedules = [
      ['category' => 'residential', 'effective_from' => '2025-01-01', 'effective_to' => '2025-09-30', 'id' => 'old'],
      ['category' => 'residential', 'effective_from' => '2025-10-01', 'effective_to' => '', 'id' => 'current'],
    ];
    self::assertSame('old', BillEstimator::selectApplicableSchedule($schedules, 'residential', '2025-09-30')['id']);
    self::assertSame('current', BillEstimator::selectApplicableSchedule($schedules, 'residential', '2025-10-01')['id']);
  }

  public function testInvalidBandOverlapIsRejected(): void {
    $errors = BillEstimator::validateBands([
      ['enabled' => TRUE, 'min' => 0, 'max' => 20, 'rate' => 89],
      ['enabled' => TRUE, 'min' => 15, 'max' => 50, 'rate' => 310],
    ]);
    self::assertContains('Enabled tariff bands overlap.', $errors);
  }

  public function testDateOverlapValidation(): void {
    self::assertTrue(BillEstimator::dateRangesOverlap('2025-01-01', '2025-12-31', '2025-10-01', ''));
    self::assertFalse(BillEstimator::dateRangesOverlap('2025-01-01', '2025-09-30', '2025-10-01', ''));
  }

  public function testInvalidFeePercentageAndToggleAreRejected(): void {
    $errors = BillEstimator::validateFee([
      'enabled' => FALSE, 'included' => TRUE, 'type' => 'percentage', 'value' => 101,
    ]);
    self::assertContains('Percentage fees cannot exceed 100%.', $errors);
    self::assertContains('A fee must be approved and available before it can be included.', $errors);
  }

  public function testNoApplicableScheduleFailsClosed(): void {
    $this->expectException(BillEstimatorUnavailableException::class);
    BillEstimator::selectApplicableSchedule([], 'residential', '2025-10-01');
  }

  public function testConflictingSchedulesFailClosed(): void {
    $this->expectException(BillEstimatorUnavailableException::class);
    BillEstimator::selectApplicableSchedule([
      ['category' => 'residential', 'effective_from' => '2025-01-01', 'effective_to' => ''],
      ['category' => 'residential', 'effective_from' => '2025-10-01', 'effective_to' => ''],
    ], 'residential', '2025-10-02');
  }

}
