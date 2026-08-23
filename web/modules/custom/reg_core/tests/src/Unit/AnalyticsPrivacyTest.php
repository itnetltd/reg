<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\reg_core\Analytics\AnalyticsEventTracker;
use Drupal\reg_core\Controller\DashboardController;
use Drupal\reg_core\Dashboard\DashboardRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the aggregate analytics privacy boundary.
 */
#[CoversClass(AnalyticsEventTracker::class)]
#[CoversClass(DashboardController::class)]
#[CoversClass(DashboardRepository::class)]
final class AnalyticsPrivacyTest extends TestCase {

  public function testEventAllowlistAndAliases(): void {
    self::assertSame('sports_result_view', AnalyticsEventTracker::normalizeEvent('result_view'));
    self::assertSame('complaint_handoff', AnalyticsEventTracker::normalizeEvent('complaint_external_handoff'));
    self::assertSame('branch_view', AnalyticsEventTracker::normalizeEvent('branch_view'));
    self::assertSame('news_share', AnalyticsEventTracker::normalizeEvent('news_share'));
    self::assertSame('tender_download', AnalyticsEventTracker::normalizeEvent('tender_document_download'));
    self::assertNull(AnalyticsEventTracker::normalizeEvent('customer_email_capture'));
  }

  public function testContextRejectsFreeTextAndIdentifiers(): void {
    self::assertSame([
      'dimension_type' => 'entity',
      'dimension_value' => 'eucl',
      'entity_id' => 42,
      'langcode' => 'rw',
    ], AnalyticsEventTracker::normalizeContext([
      'dimension_type' => 'entity',
      'dimension_value' => 'eucl',
      'entity_id' => 42,
      'langcode' => 'rw',
    ]));
    self::assertNull(AnalyticsEventTracker::normalizeContext([
      'dimension_type' => 'category',
      'dimension_value' => 'person@example.invalid',
    ]));
    self::assertNull(AnalyticsEventTracker::normalizeContext([
      'dimension_type' => 'complaint_text',
      'dimension_value' => 'meter issue',
    ]));
  }

  public function testDashboardFiltersAndCsvAreBounded(): void {
    $filters = DashboardRepository::normalizeFilters([
      'date_from' => '2026-08-01',
      'date_to' => 'not-a-date',
      'entity' => 'eucl',
      'language' => 'rw',
      'department' => '<script>alert(1)</script>',
    ]);
    self::assertSame('2026-08-01', $filters['date_from']);
    self::assertSame('', $filters['date_to']);
    self::assertSame('eucl', $filters['entity']);
    self::assertSame('rw', $filters['language']);
    self::assertSame('', $filters['department']);
    self::assertSame("'=SUM(A1:A2)", DashboardController::safeCsvCell('=SUM(A1:A2)'));
  }

}
