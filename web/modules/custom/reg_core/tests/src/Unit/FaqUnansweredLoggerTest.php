<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\reg_core\Service\FaqUnansweredLogger;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests privacy filtering for unanswered FAQ analytics.
 */
#[CoversClass(FaqUnansweredLogger::class)]
#[Group('reg_core')]
final class FaqUnansweredLoggerTest extends UnitTestCase {

  /**
   * Confirms useful phrases are normalized and likely identifiers are refused.
   */
  public function testSanitizePhrase(): void {
    self::assertSame('how do i connect electricity?', FaqUnansweredLogger::sanitizePhrase('  How do I connect <b>electricity</b>? '));
    self::assertNull(FaqUnansweredLogger::sanitizePhrase('Please reply to customer@example.com'));
    self::assertNull(FaqUnansweredLogger::sanitizePhrase('My phone is +250 788 123 456'));
    self::assertNull(FaqUnansweredLogger::sanitizePhrase('Account 1234567890 has a problem'));
    self::assertSame('call 2727', FaqUnansweredLogger::sanitizePhrase('Call 2727'));
  }

}
