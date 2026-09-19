<?php

namespace Drupal\reg_core\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Defines the contract for one dated REG bill-estimator tariff schedule.
 */
interface BillTariffScheduleInterface extends ConfigEntityInterface {

  /** Returns the public category identifier used by the estimator. */
  public function getCategory(): string;

  /** Returns the tariff calculation type. */
  public function getTariffType(): string;

  /** Returns whether this schedule applies on a YYYY-MM-DD date. */
  public function appliesOn(string $date): bool;

  /** Returns a calculation-ready schedule array. */
  public function toCalculationArray(): array;

}
