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
    private readonly BillEstimatorInterface $billEstimator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolve(string $toolKey, string $editorStatus): string {
    $config = $this->configFactory->get('reg_core.settings');
    return self::determine(
      $toolKey,
      $editorStatus,
      $this->billEstimator->hasActiveSchedule()
        && $this->routeExists('reg_core.bill_estimator'),
      self::isCarbonFactorUsable((array) $config->get('carbon'))
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
   * Shares publication and factor checks between the card and calculator.
   *
   * Publication permits indicative estimates; only "approved" records approval.
   */
  public static function isCarbonFactorUsable(array $carbon): bool {
    $factor = $carbon['emission_factor'] ?? NULL;
    $source = (string) ($carbon['source_url'] ?? '');
    return is_numeric($factor)
      && is_finite((float) $factor)
      && (float) $factor > 0
      && in_array($carbon['status'] ?? '', ['published', 'approved'], TRUE)
      && ($carbon['unit'] ?? '') === 'kg CO2e/kWh'
      && (int) ($carbon['data_year'] ?? 0) > 0
      && trim((string) ($carbon['source_title'] ?? '')) !== ''
      && trim((string) ($carbon['methodology_note'] ?? '')) !== ''
      && \Drupal\Component\Utility\UrlHelper::isValid($source, TRUE)
      && in_array(strtolower((string) parse_url($source, PHP_URL_SCHEME)), ['http', 'https'], TRUE);
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
