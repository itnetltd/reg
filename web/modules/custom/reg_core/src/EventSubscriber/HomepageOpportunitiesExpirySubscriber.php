<?php

namespace Drupal\reg_core\EventSubscriber;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableResponseInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Bounds page-cache lifetimes for the time-sensitive opportunity section. */
final class HomepageOpportunitiesExpirySubscriber implements EventSubscriberInterface {

  public function __construct(private readonly TimeInterface $time) {}

  public static function getSubscribedEvents(): array {
    // FinishResponseSubscriber runs at 0. Apply after its default Expires value,
    // before Dynamic Page Cache (-100). Internal Page Cache ignores render TTLs.
    return [KernelEvents::RESPONSE => ['onResponse', -10]];
  }

  public function onResponse(ResponseEvent $event): void {
    $response = $event->getResponse();
    if (!$event->isMainRequest() || !$response instanceof CacheableResponseInterface) return;
    $metadata = $response->getCacheableMetadata();
    if (!in_array('reg_core:homepage_opportunities', $metadata->getCacheTags(), TRUE)) return;
    $max_age = $metadata->getCacheMaxAge();
    if ($max_age <= 0) return;
    $expires = $this->time->getRequestTime() + $max_age;
    $existing = $response->getExpires()?->getTimestamp();
    if ($existing && $existing > $this->time->getRequestTime()) $expires = min($expires, $existing);
    $max_age = min($max_age, $expires - $this->time->getRequestTime());
    $response->setExpires(new \DateTimeImmutable('@' . $expires));
    // Keep all privacy/no-cache directives; only shorten explicit HTTP TTLs.
    foreach (['max-age', 's-maxage'] as $directive) {
      $current = $response->headers->getCacheControlDirective($directive);
      if (is_numeric($current)) $response->headers->addCacheControlDirective($directive, min((int) $current, $max_age));
    }
  }

}