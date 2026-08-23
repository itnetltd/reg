<?php

namespace Drupal\reg_core\Dms;

use Drupal\Component\Datetime\TimeInterface;

/**
 * Development DMS adapter with safe, representative public outage data.
 */
final class MockDmsClient implements DmsClientInterface {

  public function __construct(
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function fetchOutages(): array {
    $now = $this->time->getRequestTime();

    return ['outages' => [
      [
        'outageId' => 'REG-MOCK-1001',
        'outageType' => 'unplanned',
        'outageStatus' => 'current',
        'startTime' => date(DATE_ATOM, $now - 5400),
        'expectedRestoration' => date(DATE_ATOM, $now + 5400),
        'district' => 'Gasabo',
        'sector' => 'Kacyiru',
        'affectedArea' => 'Parts of Kacyiru and Kimihurura',
        'publicReason' => 'Our teams are responding to an unplanned supply interruption.',
        'customersAffected' => 1240,
        'latitude' => -1.9441,
        'longitude' => 30.0892,
        'lastUpdated' => date(DATE_ATOM, $now - 600),
      ],
      [
        'outageId' => 'REG-MOCK-1002',
        'outageType' => 'planned',
        'outageStatus' => 'planned',
        'startTime' => date(DATE_ATOM, $now + 86400),
        'expectedRestoration' => date(DATE_ATOM, $now + 108000),
        'district' => 'Kicukiro',
        'sector' => 'Gatenga',
        'affectedArea' => 'Selected areas of Gatenga',
        'publicReason' => 'Planned maintenance to improve service reliability.',
        'customersAffected' => 680,
        'geoJson' => [
          'type' => 'Polygon',
          'coordinates' => [[[30.08, -1.99], [30.10, -1.99], [30.10, -2.01], [30.08, -2.01], [30.08, -1.99]]],
        ],
        'lastUpdated' => date(DATE_ATOM, $now - 1800),
      ],
      [
        'outageId' => 'REG-MOCK-0998',
        'outageType' => 'unplanned',
        'outageStatus' => 'resolved',
        'startTime' => date(DATE_ATOM, $now - 172800),
        'actualRestoration' => date(DATE_ATOM, $now - 165600),
        'district' => 'Nyarugenge',
        'sector' => 'Kigali',
        'affectedArea' => 'Parts of Kigali sector',
        'publicReason' => 'Supply was restored after field repairs.',
        'customersAffected' => 310,
        'lastUpdated' => date(DATE_ATOM, $now - 165300),
      ],
    ]];
  }

  /**
   * {@inheritdoc}
   */
  public function testConnectivity(): array {
    return [
      'success' => TRUE,
      'status_code' => NULL,
      'message' => 'The development mock DMS client is active.',
    ];
  }

}
