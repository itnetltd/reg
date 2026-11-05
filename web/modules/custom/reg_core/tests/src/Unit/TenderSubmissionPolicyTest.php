<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\reg_core\Procurement\TenderSubmissionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Checks trusted tender destinations without fetching remote pages. */
final class TenderSubmissionPolicyTest extends TestCase {

  /**
   * Accepts explicit tender destinations on the approved UMUCYO hosts.
   */
  public function testSpecificApprovedDestinations(): void {
    self::assertTrue(TenderSubmissionPolicy::umucyoUrl('https://www.umucyo.gov.rw/eb/tender/QA-ONLY'));
    self::assertTrue(TenderSubmissionPolicy::umucyoUrl('https://umucyo.gov.rw/?tenderId=QA-ONLY'));
  }

  /**
   * Rejects portal roots, credentials, unsafe schemes and unapproved hosts.
   */
  public function testUnsafeOrGenericDestinationsAreRejected(): void {
    foreach ([
      'https://www.umucyo.gov.rw/',
      'https://evil.example/tender/1',
      'https://www.umucyo.gov.rw.evil.example/tender/1',
      'http://www.umucyo.gov.rw/tender/1',
      'javascript:alert(1)',
      'https://user:secret@www.umucyo.gov.rw/tender/1',
      'https://www.umucyo.gov.rw:8443/tender/1',
      'not a URL',
    ] as $url) {
      self::assertFalse(TenderSubmissionPolicy::umucyoUrl($url));
    }
  }

}
