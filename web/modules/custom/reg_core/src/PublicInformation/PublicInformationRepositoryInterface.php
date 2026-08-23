<?php

namespace Drupal\reg_core\PublicInformation;

/**
 * Provides published, normalized public-information records.
 */
interface PublicInformationRepositoryInterface {

  /**
   * Returns homepage-ready current tenders using editorial feature controls.
   *
   * @return array<int, array<string, mixed>>
   *   Up to three normalized current tender records.
   */
  public function homepageTenders(int $limit = 3): array;

  /**
   * Searches one or more public-information bundles.
   *
   * @param string[] $bundles
   *   Supported node bundles.
   * @param array<string, mixed> $filters
   *   Search and listing filters.
   *
   * @return array<int, array<string, mixed>>
   *   Normalized published records.
   */
  public function search(array $bundles, array $filters = []): array;

  /**
   * Loads one published record by bundle and node ID.
   *
   * @return array<string, mixed>|null
   *   The normalized record or NULL when not publicly available.
   */
  public function find(string $bundle, int $id): ?array;

  /**
   * Returns controlled taxonomy options.
   *
   * @return array<int, string>
   *   Term IDs keyed to translated labels.
   */
  public function taxonomyOptions(string $vocabulary): array;

}
