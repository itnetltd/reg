<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\reg_core\Alert\PublicAlertRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies scheduling, scope, and priority rules for Public Alerts.
 */
#[CoversClass(PublicAlertRepository::class)]
final class PublicAlertRepositoryTest extends TestCase {

  private const NOW = 1_800_000_000;

  /**
   * Provides alert states and expected eligibility.
   */
  public static function eligibilityProvider(): array {
    return [
      'current homepage alert' => [self::candidate(), TRUE, TRUE],
      'sitewide on interior page' => [self::candidate(['display_location' => 'sitewide']), FALSE, TRUE],
      'homepage excluded from interior page' => [self::candidate(), FALSE, FALSE],
      'future alert' => [self::candidate(['start_timestamp' => self::NOW + 60]), TRUE, FALSE],
      'expired alert' => [self::candidate(['end_timestamp' => self::NOW - 1]), TRUE, FALSE],
      'unpublished alert' => [self::candidate(['published' => FALSE]), TRUE, FALSE],
      'inactive alert' => [self::candidate(['active' => FALSE]), TRUE, FALSE],
      'missing start' => [self::candidate(['start_timestamp' => 0]), TRUE, FALSE],
    ];
  }

  /**
   * Tests time and location eligibility.
   */
  #[DataProvider('eligibilityProvider')]
  public function testEligibility(array $candidate, bool $homepage, bool $expected): void {
    self::assertSame($expected, PublicAlertRepository::isEligible($candidate, self::NOW, $homepage));
  }

  /**
   * Tests severity, priority, and newest-start ordering.
   */
  public function testCandidateSorting(): void {
    $candidates = [
      self::candidate(['id' => 1, 'severity' => 'information', 'priority' => 999]),
      self::candidate(['id' => 2, 'severity' => 'high', 'priority' => 999]),
      self::candidate(['id' => 3, 'severity' => 'critical', 'priority' => 1]),
      self::candidate(['id' => 4, 'severity' => 'critical', 'priority' => 10, 'start_timestamp' => self::NOW - 30]),
      self::candidate(['id' => 5, 'severity' => 'critical', 'priority' => 10, 'start_timestamp' => self::NOW - 10]),
    ];
    PublicAlertRepository::sortCandidates($candidates);
    self::assertSame([5, 4, 3, 2, 1], array_column($candidates, 'id'));
  }

  /**
   * Builds a complete normalized candidate for unit tests.
   */
  private static function candidate(array $overrides = []): array {
    return array_replace([
      'id' => 1,
      'published' => TRUE,
      'active' => TRUE,
      'display_location' => 'homepage',
      'start_timestamp' => self::NOW - 60,
      'end_timestamp' => 0,
      'severity' => 'information',
      'priority' => 0,
    ], $overrides);
  }

}
