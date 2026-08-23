<?php

namespace Drupal\reg_core\Alert;

/**
 * Selects the current CMS-managed public alerts.
 */
interface PublicAlertRepositoryInterface {

  /**
   * Returns the selected alert and its time-aware render-cache lifetime.
   */
  public function current(bool $homepage): array;

}
