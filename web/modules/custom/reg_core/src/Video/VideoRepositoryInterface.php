<?php

namespace Drupal\reg_core\Video;

/**
 * Provides published, language-aware REG video listings.
 */
interface VideoRepositoryInterface {

  /**
   * Returns homepage-featured videos in editor-defined order.
   */
  public function featured(int $limit = 5): array;

  /**
   * Returns a filtered archive page and total result count.
   */
  public function archive(array $filters, int $page, int $limit = 9): array;

  /**
   * Returns current-language category and year filter options.
   */
  public function filterOptions(): array;

}
