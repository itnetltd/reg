<?php

namespace Drupal\reg_core\Energy;

/**
 * Loads CMS-managed Energy Awareness cards for the homepage.
 */
interface EnergyToolRepositoryInterface {

  /**
   * Returns published, active and featured tools in editor-defined order.
   */
  public function homepageTools(int $limit = 3): array;

}
