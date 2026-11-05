<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\reg_core\Procurement\SimpleTenderWorkflow;
use Drupal\reg_core\Procurement\TenderSubmissionPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Protects disabled feature defaults and external-only notice destinations.
 */
final class SimpleTenderWorkflowTest extends TestCase {

  /**
   * Rejects unsafe URL schemes and embedded credentials.
   */
  public function testApprovedExternalDestinationsAreWebLinksOnly(): void {
    foreach (['https://example.invalid/tenders/QA', 'http://example.invalid/tenders/QA'] as $url) {
      self::assertTrue(SimpleTenderWorkflow::externalUrl($url));
    }
    foreach (['javascript:alert(1)', 'data:text/html,QA', 'file:///private/QA', 'internal:/supplier/bids', '/supplier/bids', 'https://user:secret@example.invalid/QA', 'not a URL'] as $url) {
      self::assertFalse(SimpleTenderWorkflow::externalUrl($url));
    }
  }

  /**
   * Requires explicit feature opt-in in addition to security approval.
   */
  public function testFlagsAreIndependentAndOldApprovalsCannotEnableBidding(): void {
    $previous = \Drupal::hasContainer() ? \Drupal::getContainer() : NULL;
    try {
      foreach ([[NULL, NULL, TRUE, TRUE, FALSE], [FALSE, FALSE, TRUE, TRUE, FALSE], [TRUE, FALSE, TRUE, TRUE, FALSE], [FALSE, TRUE, FALSE, TRUE, FALSE], [FALSE, TRUE, TRUE, FALSE, FALSE], [FALSE, TRUE, TRUE, TRUE, TRUE]] as [$plans, $bids, $workflow, $security, $expected]) {
        $data = ['public_information.procurement_plan_integration' => $plans, 'public_information.reg_online_bidding' => $bids, 'public_information.enable_tender_submission' => $workflow, 'public_information.tender_security_approved' => $security];
        $config = $this->createMock(ImmutableConfig::class);
        $config->method('get')->willReturnCallback(static fn($key) => $data[$key] ?? NULL);
        $factory = $this->createMock(ConfigFactoryInterface::class);
        $factory->method('get')->with('reg_core.settings')->willReturn($config);
        $container = new ContainerBuilder();
        $container->set('config.factory', $factory);
        \Drupal::setContainer($container);
        self::assertSame((bool) $plans, TenderSubmissionPolicy::plansEnabled());
        self::assertSame($expected, TenderSubmissionPolicy::onlineEnabled());
      }
    }
    finally {
      if ($previous) {
        \Drupal::setContainer($previous);
      }
      else {
        \Drupal::unsetContainer();
      }
    }
  }

}
