<?php

namespace Drupal\reg_core\Analytics;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Configuration-only provider selection; never performs remote requests.
 */
final class ConfiguredAnalyticsProvider implements AnalyticsProviderInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public function isEnabled(): bool {
    $config = $this->configFactory->get('reg_core.settings');
    return (bool) $config->get('analytics.enabled')
      && $this->provider() !== 'none'
      && $this->measurementId() !== '';
  }

  public function provider(): string {
    $provider = (string) $this->configFactory->get('reg_core.settings')->get('analytics.provider');
    return in_array($provider, ['ga4', 'matomo', 'other'], TRUE) ? $provider : 'none';
  }

  public function publicSettings(): array {
    return [
      'enabled' => $this->isEnabled(),
      'provider' => $this->provider(),
      'measurementId' => $this->isEnabled() ? $this->measurementId() : '',
      'privacyMode' => $this->privacyMode(),
    ];
  }

  private function measurementId(): string {
    $id = trim((string) $this->configFactory->get('reg_core.settings')->get('analytics.measurement_id'));
    return preg_match('/^[A-Za-z0-9._:-]{2,80}$/', $id) ? $id : '';
  }

  private function privacyMode(): string {
    $mode = (string) $this->configFactory->get('reg_core.settings')->get('analytics.privacy_mode');
    return in_array($mode, ['strict', 'consent_required'], TRUE) ? $mode : 'strict';
  }

}
