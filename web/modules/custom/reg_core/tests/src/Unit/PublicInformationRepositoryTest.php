<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\reg_core\PublicInformation\PublicInformationRepository;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests public deadline presentation rules.
 */
#[CoversClass(PublicInformationRepository::class)]
#[Group('reg_core')]
final class PublicInformationRepositoryTest extends UnitTestCase {

  /**
   * Ensures public countdowns never become negative.
   */
  public function testDaysUntilNeverReturnsNegativeValues(): void {
    $now = strtotime('2026-08-08 12:00:00 UTC');
    self::assertNull(PublicInformationRepository::daysUntil('2026-08-07T12:00:00', $now));
    self::assertSame(0, PublicInformationRepository::daysUntil('2026-08-08T12:00:00', $now));
    self::assertSame(1, PublicInformationRepository::daysUntil('2026-08-09T11:59:59', $now));
    self::assertSame(2, PublicInformationRepository::daysUntil('2026-08-09T12:00:01', $now));
    self::assertNull(PublicInformationRepository::daysUntil(NULL, $now));
    self::assertNull(PublicInformationRepository::daysUntil('not-a-date', $now));
  }

  /** Ensures Media Center archive scopes are strictly allowlisted. */
  public function testPublicationScopeNormalization(): void {
    $repository = (new \ReflectionClass(PublicInformationRepository::class))
      ->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod(PublicInformationRepository::class, 'normalizeFilters');
    $newsletter = $method->invoke($repository, ['publication_scope' => 'newsletter']);
    $invalid = $method->invoke($repository, ['publication_scope' => 'reg_outage']);
    self::assertSame('newsletter', $newsletter['publication_scope']);
    self::assertSame('', $invalid['publication_scope']);
  }

}
