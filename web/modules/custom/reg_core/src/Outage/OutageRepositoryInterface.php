<?php

namespace Drupal\reg_core\Outage;

/**
 * Provides public outage data without exposing its authoritative source.
 */
interface OutageRepositoryInterface {

  /**
   * Returns published normalized Drupal outage announcements.
   *
   * @param array{district?: string, sector?: string, search?: string, mode?: string} $filters
   *   Supported public filters.
   */
  public function getOutages(array $filters = []): OutageRepositoryResult;

  /**
   * Returns one public outage by its external public identifier.
   */
  public function getOutage(string $outage_id): ?array;

  /**
   * Rebuilds normalized public data from the current Drupal records.
   */
  public function refresh(): OutageRepositoryResult;

}
