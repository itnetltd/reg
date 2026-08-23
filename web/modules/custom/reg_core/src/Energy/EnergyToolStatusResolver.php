<?php

namespace Drupal\reg_core\Energy;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Derives calculator availability from approved system configuration.
 */
final class EnergyToolStatusResolver implements EnergyToolStatusResolverInterface {

  private const ALLOWED_STATUSES = [
    'available',
    'coming_soon',
    'awaiting_validation',
    'guidance',
    'disabled',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RouteProviderInterface $routeProvider,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolve(string $toolKey, string $editorStatus): string {
    $config = $this->configFactory->get('reg_core.settings');
    return self::determine(
      $toolKey,
      $editorStatus,
      trim((string) $config->get('tariffs.approved_schedule_effective')) !== ''
        && $this->routeExists('reg_core.bill_estimator'),
      (float) $config->get('carbon.emission_factor') > 0
        && $this->routeExists('reg_core.carbon_calculator'),
    );
  }

  /**
   * Provides deterministic status rules that can be tested without Drupal.
   */
  public static function determine(
    string $toolKey,
    string $editorStatus,
    bool $billEstimatorConfigured,
    bool $carbonCalculatorConfigured,
  ): string {
    return match ($toolKey) {
      'bill_estimator' => $billEstimatorConfigured ? 'available' : 'awaiting_validation',
      'carbon_footprint' => $carbonCalculatorConfigured ? 'available' : 'awaiting_validation',
      'safety_efficiency' => 'guidance',
      default => in_array($editorStatus, self::ALLOWED_STATUSES, TRUE) ? $editorStatus : 'disabled',
    };
  }

  /**
   * Confirms that an enabled tool has a real Drupal destination.
   */
  private function routeExists(string $routeName): bool {
    try {
      $this->routeProvider->getRouteByName($routeName);
      return TRUE;
    }
    catch (RouteNotFoundException) {
      return FALSE;
    }
  }

}
