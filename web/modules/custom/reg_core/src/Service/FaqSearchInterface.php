<?php

namespace Drupal\reg_core\Service;

/**
 * Searches the published, language-aware REG FAQ knowledge base.
 */
interface FaqSearchInterface {

  /**
   * Returns ranked approved FAQ records.
   *
   * @return array<int, array<string, mixed>>
   *   Public FAQ view models.
   */
  public function search(string $query = '', string $category = '', int $limit = 100): array;

  /**
   * Returns configured FAQ category labels keyed by machine value.
   *
   * @return array<string, string>
   *   Category options.
   */
  public function categories(): array;

}
