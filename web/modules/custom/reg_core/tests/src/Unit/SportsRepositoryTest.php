<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\reg_core\Sports\SportsRepository;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests public sports score formatting rules.
 */
#[CoversClass(SportsRepository::class)]
#[Group('reg_core')]
final class SportsRepositoryTest extends UnitTestCase {

  /**
   * Ensures only complete, editor-confirmed scores are shown.
   */
  public function testScoreFormatting(): void {
    self::assertSame('84–76', SportsRepository::formatScore(84, 76));
    self::assertSame('3–1', SportsRepository::formatScore(80, 72, 3, 1));
    self::assertSame('', SportsRepository::formatScore(84, NULL));
    self::assertSame('', SportsRepository::formatScore(NULL, NULL));
  }

}
