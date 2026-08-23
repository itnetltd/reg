<?php

namespace Drupal\reg_core\Analytics;

/**
 * Exposes safe public settings for an approved analytics adapter.
 */
interface AnalyticsProviderInterface {

  public function isEnabled(): bool;

  public function provider(): string;

  public function publicSettings(): array;

}
