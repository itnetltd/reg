<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\reg_core\Dms\MockDmsClient;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the development DMS adapter contract.
 */
#[CoversClass(MockDmsClient::class)]
#[Group('reg_core')]
final class MockDmsClientTest extends UnitTestCase {

  /**
   * Verifies the mock supplies representative lifecycle records.
   */
  public function testFetchOutages(): void {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1786176000);
    $client = new MockDmsClient($time);

    $payload = $client->fetchOutages();

    self::assertCount(3, $payload['outages']);
    self::assertSame('current', $payload['outages'][0]['outageStatus']);
    self::assertSame('planned', $payload['outages'][1]['outageType']);
    self::assertSame('resolved', $payload['outages'][2]['outageStatus']);
    self::assertTrue($client->testConnectivity()['success']);
  }

}
