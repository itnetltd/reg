<?php

namespace Drupal\reg_core\Branch;

/**
 * Provides language-aware published branch information.
 */
interface BranchRepositoryInterface {

  /**
   * Returns filtered, logically sorted active branches.
   */
  public function search(array $filters = []): array;

  /**
   * Returns one published active branch.
   */
  public function find(int $id): ?array;

  /**
   * Returns term options for an approved branch vocabulary.
   */
  public function taxonomyOptions(string $vocabulary): array;

}
