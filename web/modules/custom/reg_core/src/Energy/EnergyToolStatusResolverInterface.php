<?php

namespace Drupal\reg_core\Energy;

/**
 * Resolves the public state of a CMS-managed energy tool.
 */
interface EnergyToolStatusResolverInterface {

  /**
   * Returns a controlled public status code for an energy tool.
   */
  public function resolve(string $toolKey, string $editorStatus): string;

}
