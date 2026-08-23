<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\PrivateKey;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\reg_core\Analytics\AnalyticsEventTrackerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Accepts same-origin, allowlisted aggregate events without visitor data.
 */
final class AnalyticsEventController implements ContainerInjectionInterface {

  public function __construct(
    private readonly AnalyticsEventTrackerInterface $tracker,
    private readonly FloodInterface $flood,
    private readonly PrivateKey $privateKey,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(AnalyticsEventTrackerInterface::class),
      $container->get('flood'),
      $container->get('private_key'),
    );
  }

  public function record(Request $request): JsonResponse {
    $fetch_site = strtolower((string) $request->headers->get('Sec-Fetch-Site', ''));
    if ($request->headers->get('X-REG-Analytics') !== 'aggregate-v1' || $fetch_site === 'cross-site') {
      return $this->response(403);
    }
    $identifier = hash_hmac('sha256', (string) ($request->getClientIp() ?: 'unknown'), $this->privateKey->get());
    if (!$this->flood->isAllowed('reg_core.analytics_event', 120, 60, $identifier)) {
      return $this->response(429);
    }
    $this->flood->register('reg_core.analytics_event', 60, $identifier);

    try {
      $payload = json_decode($request->getContent(), TRUE, 8, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return $this->response(400);
    }
    if (!is_array($payload) || !$this->tracker->record(
      (string) ($payload['event'] ?? ''),
      is_array($payload['context'] ?? NULL) ? $payload['context'] : [],
    )) {
      return $this->response(400);
    }
    return $this->response(204);
  }

  private function response(int $status): JsonResponse {
    $response = new JsonResponse(NULL, $status);
    $response->headers->set('Cache-Control', 'no-store, private');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    return $response;
  }

}
