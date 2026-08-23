<?php

namespace Drupal\reg_core\About;

/**
 * Provides published, language-aware About REG content.
 */
interface AboutRepositoryInterface {

  /**
   * Returns one CMS-managed About page by its stable key.
   */
  public function page(string $key): ?array;

  /**
   * Returns active REG values in editorial order.
   */
  public function values(): array;

  /**
   * Returns active leadership records for one controlled group.
   */
  public function leaders(string $group): array;

  /**
   * Returns active partners, optionally filtered by partner type.
   */
  public function partners(string $type = ''): array;

  /**
   * Returns published employee-recognition records.
   */
  public function employees(): array;

  /**
   * Returns the number of active published branch records.
   */
  public function branchCount(): int;

}
