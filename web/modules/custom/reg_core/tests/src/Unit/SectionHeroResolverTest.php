<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\reg_core\Service\SectionHeroResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies centralized public route-to-section mapping.
 */
#[CoversClass(SectionHeroResolver::class)]
final class SectionHeroResolverTest extends TestCase {

  /**
   * Provides representative public paths and routes.
   */
  public static function mappingProvider(): array {
    return [
      'about subtree' => ['/about-reg/governance', '', 'about_reg'],
      'what we do generation' => ['/generation', '', 'what_we_do'],
      'customer outage by route' => ['/outages/current', 'reg_core.outages_current', 'customer_services'],
      'customer FAQ language prefix' => ['/rw/help/faq', '', 'customer_services'],
      'public tenders' => ['/tenders/current', 'reg_core.tenders_current', 'public_information'],
      'media archive' => ['/media/videos', 'reg_core.videos', 'media_center'],
      'sports detail' => ['/sports/match/12', 'reg_core.sports_fixture', 'sports'],
      'branch detail' => ['/branches/9', 'reg_core.branch_detail', 'contact_branches'],
      'report problem' => ['/report-problem', 'reg_core.report_problem', 'contact_branches'],
      'unmapped homepage' => ['/', '<front>', ''],
    ];
  }

  /**
   * Tests deterministic route and path mapping.
   */
  #[DataProvider('mappingProvider')]
  public function testSectionMapping(string $path, string $route_name, string $expected): void {
    self::assertSame($expected, SectionHeroResolver::mapSectionKey($path, $route_name));
  }

  /**
   * Tests the automatic three-size internal hero design system.
   */
  public function testHeroSizeMapping(): void {
    self::assertSame('landing', SectionHeroResolver::mapHeroSize('/about', 'reg_core.about'));
    self::assertSame('landing', SectionHeroResolver::mapHeroSize('/what-we-do', 'entity.node.canonical', 'reg_what_we_do_page'));
    self::assertSame('standard', SectionHeroResolver::mapHeroSize('/about/history', 'reg_core.about_history'));
    self::assertSame('standard', SectionHeroResolver::mapHeroSize('/tenders', 'reg_core.tenders'));
    self::assertSame('compact', SectionHeroResolver::mapHeroSize('/tenders/234', 'reg_core.tender_detail', 'reg_tender'));
    self::assertSame('compact', SectionHeroResolver::mapHeroSize('/node/42', 'entity.node.canonical', 'reg_project'));
    self::assertSame('compact', SectionHeroResolver::mapHeroSize('/news/example', 'entity.node.canonical', 'reg_news'));
  }

}
