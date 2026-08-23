<?php

namespace Drupal\reg_core\Homepage;

/**
 * Provides published, CMS-managed homepage content.
 */
interface HomepageRepositoryInterface {

  /**
   * Returns the first active, published hero for the current language.
   */
  public function hero(): ?array;

  /**
   * Returns all currently eligible heroes and their cache lifetime.
   */
  public function heroes(): array;

  /**
   * Returns active homepage statistics in editor-defined order.
   */
  public function statistics(): array;

  /**
   * Returns the latest approved, manually managed sector indicators.
   */
  public function energyAtGlance(): array;

  /**
   * Returns active, published partners in editor-defined order.
   */
  public function partners(): array;

}
