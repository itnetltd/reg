<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\reg_core\EventSubscriber\HomepageOpportunitiesExpirySubscriber;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/** Protects deadline expiry in Drupal's Internal Page Cache and HTTP headers. */
final class HomepageOpportunitiesExpiryTest extends UnitTestCase {

  private function respond(CacheableResponse $response, bool $main = TRUE): void {
    $clock = $this->createMock(TimeInterface::class);
    $clock->method('getRequestTime')->willReturn(1800000000);
    $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), Request::create('/'), $main ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST, $response);
    (new HomepageOpportunitiesExpirySubscriber($clock))->onResponse($event);
  }

  public function testPageCacheAndPublicHttpTtlEndAtDeadline(): void {
    $response = new CacheableResponse('cards');
    $response->addCacheableDependency((new CacheableMetadata())->setCacheTags(['reg_core:homepage_opportunities'])->setCacheMaxAge(45));
    $response->headers->set('Cache-Control', 'public, max-age=600, s-maxage=900');
    $this->respond($response);
    self::assertSame(1800000045, $response->getExpires()->getTimestamp());
    self::assertSame('45', (string) $response->headers->getCacheControlDirective('max-age'));
    self::assertSame('45', (string) $response->headers->getCacheControlDirective('s-maxage'));
  }

  public function testNeverExtendsEarlierExpiryOrRemovesPrivacyDirectives(): void {
    $response = new CacheableResponse('cards');
    $response->addCacheableDependency((new CacheableMetadata())->setCacheTags(['reg_core:homepage_opportunities'])->setCacheMaxAge(60));
    $response->headers->set('Cache-Control', 'private, no-cache, must-revalidate');
    $response->setExpires(new \DateTimeImmutable('@1800000010'));
    $this->respond($response);
    self::assertSame(1800000010, $response->getExpires()->getTimestamp());
    self::assertTrue($response->headers->hasCacheControlDirective('private'));
    self::assertTrue($response->headers->hasCacheControlDirective('no-cache'));
  }

  public function testLeavesUnrelatedPagesAndSubrequestsAlone(): void {
    $response = new CacheableResponse('another section');
    $response->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(45));
    $this->respond($response);
    self::assertNull($response->getExpires());
    $response->addCacheableDependency((new CacheableMetadata())->setCacheTags(['reg_core:homepage_opportunities']));
    $this->respond($response, FALSE);
    self::assertNull($response->getExpires());
  }

}