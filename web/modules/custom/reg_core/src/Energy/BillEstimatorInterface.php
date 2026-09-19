<?php

namespace Drupal\reg_core\Energy;

/**
 * Calculates public bill estimates from the applicable approved schedule.
 */
interface BillEstimatorInterface {

  /** Returns grouped category options that have one schedule on the date. */
  public function getCategoryOptions(?string $date = NULL): array;

  /** Returns whether at least one usable category is configured. */
  public function hasActiveSchedule(?string $date = NULL): bool;

  /** Returns the single calculation-ready schedule for a category. */
  public function getActiveSchedule(string $category, ?string $date = NULL): array;

  /** Calculates an estimate and its transparent charge breakdown. */
  public function calculate(string $category, float $consumption, array $demand = [], ?string $date = NULL): array;

  /** Returns public estimator notes and fee configuration. */
  public function getPublicSettings(): array;

}
