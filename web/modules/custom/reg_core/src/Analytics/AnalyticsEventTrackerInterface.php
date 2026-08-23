<?php

namespace Drupal\reg_core\Analytics;

/**
 * Records allowlisted, aggregate-only REG application events.
 */
interface AnalyticsEventTrackerInterface {

  /**
   * Increments one aggregate without storing a visitor identifier.
   */
  public function record(string $event, array $context = []): bool;

}
