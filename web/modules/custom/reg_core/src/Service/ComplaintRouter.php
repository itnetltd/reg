<?php

namespace Drupal\reg_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;

/**
 * Resolves complaint handoffs without enabling sensitive local storage.
 */
final class ComplaintRouter {

  public function __construct(
    private readonly ConfigFactoryInterface $regConfigFactory,
  ) {}

  /**
   * Returns the approved public complaint-routing state.
   */
  public function resolve(): array {
    $config = $this->regConfigFactory->get('reg_core.settings');
    $mode = (string) ($config->get('support.complaint_mode') ?: 'external');
    if (!in_array($mode, ['external', 'api', 'cms', 'disabled'], TRUE)) {
      $mode = 'disabled';
    }
    $enabled = (bool) $config->get('support.complaint_integration_enabled');
    $available = $mode === 'external'
      ? $this->safeUrl((string) $config->get('support.complaint_url')) !== ''
      : ($mode === 'api' && $enabled && $this->safeUrl((string) $config->get('support.complaint_api_endpoint')) !== '');
    if ($mode === 'cms') {
      // Private complaint storage is intentionally not implemented or enabled.
      $available = FALSE;
    }
    return [
      'mode' => $mode,
      'available' => $available,
      'integration_enabled' => $enabled,
      'complaint_url' => $this->safeUrl((string) $config->get('support.complaint_url')),
      'fault_url' => $this->safeUrl((string) $config->get('support.fault_reporting_url')),
      'call_center' => (string) ($config->get('support.call_center') ?: '2727'),
      'support_email' => trim((string) $config->get('support.complaint_email')),
    ];
  }

  /**
   * Allows configured HTTP(S) and internal URLs only.
   */
  private function safeUrl(string $uri): string {
    $uri = trim($uri);
    if ($uri === '') {
      return '';
    }
    try {
      return (str_starts_with($uri, '/') ? Url::fromUserInput($uri) : Url::fromUri($uri))->toString();
    }
    catch (\Exception) {
      return '';
    }
  }

}
